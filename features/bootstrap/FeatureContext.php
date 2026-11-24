<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
// php/init-ee.php may already have defined it (same value on Linux).
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

if ( ! defined( 'EE_TEST_PROXY_CONTAINER' ) ) {
	define( 'EE_TEST_PROXY_CONTAINER', 'services_global-nginx-proxy_1' );
}

class FeatureContext implements Context
{
	/**
	 * Every site, hosts entry, file and remote backup the suite creates or removes must contain this prefix.
	 * Packages copying this context change only this constant.
	 */
	const SITE_PREFIX = 'bh-site-command-';

	public $command;
	public $commands;
	public $webroot_path;
	public $ee_path;

	/**
	 * Variables usable as {NAME} in step arguments.
	 *
	 * @var array
	 */
	private $variables = [];

	/**
	 * Last HTTP response: code, headers (lowercased name => value), raw_headers, body, error.
	 *
	 * @var array|null
	 */
	private $response = null;

	/**
	 * Container start times noted by "I note the start time of ...".
	 *
	 * @var array
	 */
	private $start_times = [];

	/**
	 * Hosts entries added by the current scenario, removed afterwards.
	 *
	 * @var array
	 */
	private $added_hosts = [];

	/**
	 * Per-scenario scratch directory ({TMP}).
	 *
	 * @var string
	 */
	private $tmp_dir = '';

	/**
	 * Unique id of this Behat run ({RUN_ID}), used to keep Let's Encrypt names distinct across runs.
	 *
	 * @var string
	 */
	private static $run_id = '';

	/**
	 * Command started by "I start ... in the background": proc, stdout and stderr files, command, start time, exit code.
	 *
	 * @var array|null
	 */
	private $background = null;

	/**
	 * Byte offsets noted by "I note the size of the file ...".
	 *
	 * @var array
	 */
	private $noted_sizes = [];

	/**
	 * Whether the scenario ran php-compat scans, whose containers and temporary directories are purged afterwards.
	 *
	 * @var bool
	 */
	private $compat_used = false;

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
		if ( empty( self::$run_id ) ) {
			self::$run_id = date( 'mdHis' );
		}
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * Runs a shell command from the core root; {NAME} variables are expanded.
	 *
	 * @When /^I run (['"])(.*)\1$/
	 */
	public function iRun($quote, $command)
	{
		$this->commands[] = EE::launch( $this->v( $command ), false, true );
	}

	/**
	 * @When I create subsite :subsite in :site
	 */
	public function iCreateSubsiteInOfType($subsite, $site )
	{
		$php_container = implode( explode( '.', $subsite ) ) . '_php_1';

		EE::launch( "docker exec -it --user='www-data' $php_container sh -c 'wp site create --slug=$site'", false, true );
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception( "Actual return code is not zero:\n" . $this->describe( $this->commands[0] ) );
		}
	}

	/**
	 * @Then /^return value of command (\d+) should be 0$/
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception( "Actual return code is not zero:\n" . $this->describe( $this->commands[ $index - 1 ] ) );
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * Backup ids have one-second resolution: back-to-back backups need a second boundary between them.
	 *
	 * @When I wait for the next second
	 */
	public function iWaitForTheNextSecond()
	{
		$now = microtime( true );
		usleep( (int) ( ( floor( $now ) + 1 - $now ) * 1000000 ) + 1000 );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return exactly$/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = $this->strip_colors( $command_output );

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return exactly$/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = $this->strip_colors( $command_output );

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return something like$/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return something like$/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		if ( false !== $this->get_site_row( $site ) ) {
			throw new Exception("$site db entry not been removed!");
		}
	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		foreach ( $table->getHash() as $container ) {
			$ids = $this->site_container_ids( $site, $container['container'], true );
			if ( ! empty( $ids ) ) {
				throw new Exception( "The {$container['container']} container of $site has not been removed: " . implode( ', ', $ids ) );
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$response = $this->http_request( 'http://' . $site . '/', 'HEAD' );

		foreach ( $table->getHash() as $row ) {
			if ( strpos( $response['raw_headers'], $row['header'] ) === false ) {
				throw new Exception( "Unable to find " . $row['header'] . "\nActual output is : " . $response['raw_headers'] . $response['error'] );
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$filters = '';
		foreach ( $pyStringNode->getStrings() as $label ) {
			$filters .= ' --filter ' . escapeshellarg( 'label=' . trim( $this->v( $label ) ) );
		}

		$result             = EE::launch( "docker ps -q $filters | wc -l", false, true );
		$running_containers = (int) trim( $result->stdout );

		if ( (int) $expected_running_containers !== $running_containers ) {
			throw new Exception( "Expected $expected_running_containers running containers. Found: $running_containers" );
		}
	}

	/* =====================================================================
	 * Shared generic steps.
	 *
	 * Written to be copied as-is into other packages' contexts: only SITE_PREFIX changes.
	 * Every string argument goes through v(), so {NAME} variables work everywhere:
	 * built-ins are {EE}, {EE_ROOT_DIR}, {SITES_DIR}, {PROXY_DIR}, {BACKUP_DIR}, {TMP}, {SYS_TMP}, {RUN_ID}, {PUBLIC_IP},
	 * {RCLONE_PATH} and {LE_ISSUER}; others come from the "I save ..." steps.
	 * ===================================================================== */

	/**
	 * @BeforeScenario
	 */
	public function prepareScenario( BeforeScenarioScope $scope )
	{
		$this->tmp_dir = '/var/tmp/' . self::SITE_PREFIX . 'behat-' . getmypid() . '-' . mt_rand( 1000, 9999 );
		@mkdir( $this->tmp_dir, 0755, true );
		$this->variables   = [];
		$this->response    = null;
		$this->start_times = [];
		$this->noted_sizes = [];
		$this->compat_used = false;
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		// A failed --output check could have replaced /dev/null with a file; never leave the runner without it.
		clearstatcache( true, '/dev/null' );
		self::repairDevNull( @lstat( '/dev/null' ) );
		$this->commands = [];
		chdir($this->ee_path);

		foreach ( $this->added_hosts as $host ) {
			$this->remove_hosts_entry( $host );
		}
		$this->added_hosts = [];

		// Before {TMP} goes: the background command writes its output there.
		$this->stop_background();
		if ( $this->compat_used ) {
			self::purge_compat_leftovers();
		}

		if ( $this->tmp_dir && 0 === strpos( $this->tmp_dir, '/var/tmp/' . self::SITE_PREFIX ) ) {
			EE::launch( 'rm -rf ' . escapeshellarg( $this->tmp_dir ), false, true );
		}

		// Leftovers of failed backups/restores of our own sites would make the next one fail.
		foreach ( glob( EE_ROOT_DIR . '/.backup/' . self::SITE_PREFIX . '*' ) ?: [] as $path ) {
			EE::launch( 'rm -rf ' . escapeshellarg( $path ), false, true );
		}
	}

	/**
	 * On CI, EE's hardcoded Let's Encrypt host must point at Pebble or a blackhole (.github/behat/pebble-setup.sh), never the real service.
	 *
	 * @BeforeSuite
	 */
	public static function refuseRealAcmeOnCi()
	{
		if ( getenv( 'CI' ) && ! self::acme_host_is_local() ) {
			throw new Exception( 'acme-v02.api.letsencrypt.org resolves to ' . gethostbyname( 'acme-v02.api.letsencrypt.org' ) . ' on CI; run `.github/behat/pebble-setup.sh setup` (le lanes) or `block` (every other lane) first.' );
		}
	}

	/**
	 * @le issues certificates: only against Pebble, or on a real test host with EE_BEHAT_LE_REAL=1 (ignored on CI).
	 *
	 * @BeforeScenario @le
	 */
	public function requireTestAcme()
	{
		if ( getenv( 'EE_BEHAT_LE_ISSUER' ) && self::acme_host_is_local() ) {
			return;
		}
		if ( '1' === getenv( 'EE_BEHAT_LE_REAL' ) && ! getenv( 'CI' ) ) {
			return;
		}
		throw new Exception( '@le needs Pebble (.github/behat/pebble-setup.sh setup), or EE_BEHAT_LE_REAL=1 on a host with real DNS for *.ee4-test.rt.gw.' );
	}

	/**
	 * True when the LE host resolves to a private, loopback or TEST-NET address (Pebble's front proxy or the blackhole).
	 */
	private static function acme_host_is_local()
	{
		$ip = gethostbyname( 'acme-v02.api.letsencrypt.org' );

		return (bool) preg_match( '/^(10\.|127\.|192\.168\.|192\.0\.2\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip );
	}

	/**
	 * Deletes only the sites (and remote backups) of this suite; never touches other sites.
	 *
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		foreach ( self::own_sites() as $site ) {
			EE::launch( 'bin/ee site delete ' . escapeshellarg( $site ) . ' --yes', false, true );
		}

		self::purge_own_leftovers();

		if ( in_array( 'remote', $scope->getFeature()->getTags(), true ) ) {
			self::purge_own_remote_backups();
		}

		if(file_exists('ee.phar')) {
			unlink('ee.phar');
		}
		if(file_exists('ee-old.phar')) {
			unlink('ee-old.phar');
		}
	}

	/* ----- command execution and output ----- */

	/**
	 * Runs a command, killing it after the given time (for commands that could hang, e.g. the proxy cache purge without a terminal).
	 *
	 * @When /^I run (['"])(.*)\1 with a timeout of (\d+) seconds$/
	 */
	public function iRunWithATimeout( $quote, $command, $seconds )
	{
		$this->commands[] = EE::launch( sprintf( 'timeout -k 10 %d sh -c %s', $seconds, escapeshellarg( $this->v( $command ) ) ), false, true );
	}

	/**
	 * @Then the command should succeed
	 */
	public function theCommandShouldSucceed()
	{
		$run = $this->last_command();
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Expected exit code 0.\n" . $this->describe( $run ) );
		}
	}

	/**
	 * @Then the command should fail
	 */
	public function theCommandShouldFail()
	{
		$run = $this->last_command();
		if ( 0 === $run->return_code ) {
			throw new Exception( "Expected a non-zero exit code.\n" . $this->describe( $run ) );
		}
	}

	/**
	 * @Then /^the exit code should be (\d+)$/
	 */
	public function theExitCodeShouldBe( $code )
	{
		$run = $this->last_command();
		if ( (int) $code !== $run->return_code ) {
			throw new Exception( "Expected exit code $code.\n" . $this->describe( $run ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should (not )?contain "(.*)"$/
	 */
	public function outputShouldContain( $stream, $not, $text )
	{
		$this->assert_output_contains( $stream, $not, $this->v( $text ) );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should (not )?contain:$/
	 */
	public function outputShouldContainBlock( $stream, $not, PyStringNode $text )
	{
		$this->assert_output_contains( $stream, $not, $this->v( $text->getRaw() ) );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should (not )?match "(.*)"$/
	 */
	public function outputShouldMatch( $stream, $not, $regex )
	{
		$run    = $this->last_command();
		$output = $this->stream_of( $run, $stream );
		$regex  = $this->v( $regex );
		$found  = preg_match( $regex, $output );
		if ( false === $found ) {
			throw new Exception( "Invalid regular expression: $regex" );
		}
		if ( (bool) $found === (bool) $not ) {
			throw new Exception( sprintf( "Expected %s %sto match %s.\n%s", $stream, $not, $regex, $this->describe( $run ) ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 */
	public function outputShouldBeEmpty( $stream )
	{
		$run = $this->last_command();
		if ( '' !== trim( $this->stream_of( $run, $stream ) ) ) {
			throw new Exception( "Expected $stream to be empty.\n" . $this->describe( $run ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be exactly:$/
	 */
	public function outputShouldBeExactly( $stream, PyStringNode $expected )
	{
		$run = $this->last_command();
		if ( trim( $this->v( $expected->getRaw() ) ) !== trim( $this->stream_of( $run, $stream ) ) ) {
			throw new Exception( "Expected $stream to be exactly:\n" . $this->v( $expected->getRaw() ) . "\n" . $this->describe( $run ) );
		}
	}

	/**
	 * @Then STDOUT should be valid JSON
	 */
	public function stdoutShouldBeValidJson()
	{
		$this->decode_stdout_json();
	}

	/**
	 * Dot path into the decoded STDOUT JSON, e.g. "site_ssl" or "0.ssl_type".
	 *
	 * @Then /^the JSON field "([^"]*)" in STDOUT should (not )?be "(.*)"$/
	 */
	public function theJsonFieldShouldBe( $path, $not, $expected )
	{
		$actual   = $this->json_path( $this->decode_stdout_json(), $path );
		$expected = $this->v( $expected );
		$actual   = is_bool( $actual ) ? ( $actual ? 'true' : 'false' ) : ( is_scalar( $actual ) || null === $actual ? (string) $actual : json_encode( $actual ) );
		if ( ( $actual === $expected ) === (bool) $not ) {
			throw new Exception( sprintf( "Expected JSON field %s %sto be %s, got %s.\n%s", $path, $not, var_export( $expected, true ), var_export( $actual, true ), $this->describe( $this->last_command() ) ) );
		}
	}

	/**
	 * Strict: "should be" reads null and "" alike.
	 *
	 * @Then /^the JSON field "([^"]*)" in STDOUT should be null$/
	 */
	public function theJsonFieldShouldBeNull( $path )
	{
		$actual = $this->json_path( $this->decode_stdout_json(), $path );
		if ( null !== $actual ) {
			throw new Exception( sprintf( "Expected JSON field %s to be null, got %s.\n%s", $path, var_export( $actual, true ), $this->describe( $this->last_command() ) ) );
		}
	}

	/**
	 * @Then /^the JSON field "([^"]*)" in STDOUT should contain "(.*)"$/
	 */
	public function theJsonFieldShouldContain( $path, $expected )
	{
		$actual   = $this->json_path( $this->decode_stdout_json(), $path );
		$actual   = is_scalar( $actual ) ? (string) $actual : json_encode( $actual );
		$expected = $this->v( $expected );
		if ( false === strpos( $actual, $expected ) ) {
			throw new Exception( sprintf( "Expected JSON field %s to contain %s, got %s.\n%s", $path, var_export( $expected, true ), var_export( $actual, true ), $this->describe( $this->last_command() ) ) );
		}
	}

	/**
	 * @Then /^the JSON array in STDOUT should (not )?contain an item with "([^"]*)" set to "(.*)"$/
	 */
	public function theJsonArrayShouldContainItem( $not, $key, $value )
	{
		$items = $this->decode_stdout_json();
		$value = $this->v( $value );
		if ( ! is_array( $items ) ) {
			throw new Exception( "STDOUT JSON is not an array.\n" . $this->describe( $this->last_command() ) );
		}
		$found = false;
		foreach ( $items as $item ) {
			if ( is_array( $item ) && array_key_exists( $key, $item ) && (string) $item[ $key ] === $value ) {
				$found = true;
				break;
			}
		}
		if ( $found === (bool) $not ) {
			throw new Exception( sprintf( "Expected the JSON array %sto contain an item with %s = %s.\n%s", $not, $key, $value, $this->describe( $this->last_command() ) ) );
		}
	}

	/**
	 * @When /^I save the JSON field "([^"]*)" in STDOUT as "([A-Z][A-Z0-9_]*)"$/
	 */
	public function iSaveTheJsonField( $path, $name )
	{
		$value = $this->json_path( $this->decode_stdout_json(), $path );
		$this->variables[ $name ] = is_scalar( $value ) ? (string) $value : json_encode( $value );
	}

	/**
	 * Saves the first capture group (or the whole match) of a regex applied to STDOUT.
	 *
	 * @When /^I save the first match of "(.*)" in STDOUT as "([A-Z][A-Z0-9_]*)"$/
	 */
	public function iSaveTheFirstMatch( $regex, $name )
	{
		$run = $this->last_command();
		if ( ! preg_match( $regex, $this->stream_of( $run, 'STDOUT' ), $matches ) ) {
			throw new Exception( "No match for $regex in STDOUT.\n" . $this->describe( $run ) );
		}
		$this->variables[ $name ] = isset( $matches[1] ) ? $matches[1] : $matches[0];
	}

	/**
	 * @Given /^the variable "([A-Z][A-Z0-9_]*)" is "(.*)"$/
	 */
	public function theVariableIs( $name, $value )
	{
		$this->variables[ $name ] = $this->v( $value );
	}

	/* ----- sites (database state) ----- */

	/**
	 * @Given /^the html site "([^"]*)" exists$/
	 */
	public function theHtmlSiteExists( $site )
	{
		$this->ensure_site( $this->v( $site ), '--type=html' );
	}

	/**
	 * @Given /^the site "([^"]*)" exists, created with "(.*)"$/
	 */
	public function theSiteExistsCreatedWith( $site, $flags )
	{
		$this->ensure_site( $this->v( $site ), $this->v( $flags ) );
	}

	/**
	 * @Given /^the site "([^"]*)" does not exist$/
	 */
	public function theSiteDoesNotExist( $site )
	{
		$site = $this->v( $site );
		$this->assert_own_name( $site );
		if ( false !== $this->get_site_row( $site ) ) {
			$run = EE::launch( 'bin/ee site delete ' . escapeshellarg( $site ) . ' --yes', false, true );
			if ( 0 !== $run->return_code ) {
				throw new Exception( "Could not delete $site.\n" . $this->describe( $run ) );
			}
		}
	}

	/**
	 * @Given /^the site "([^"]*)" is (enabled|disabled)$/
	 */
	public function theSiteIs( $site, $state )
	{
		$site = $this->v( $site );
		$row  = $this->get_site_row( $site );
		if ( false === $row ) {
			throw new Exception( "Site $site does not exist." );
		}
		if ( ( 'enabled' === $state ) !== (bool) $row['site_enabled'] ) {
			$run = EE::launch( 'bin/ee site ' . ( 'enabled' === $state ? 'enable' : 'disable' ) . ' ' . escapeshellarg( $site ), false, true );
			if ( 0 !== $run->return_code ) {
				throw new Exception( "Could not make $site $state.\n" . $this->describe( $run ) );
			}
		}
	}

	/**
	 * @Then /^the site "([^"]*)" should (not )?exist$/
	 */
	public function theSiteShouldExist( $site, $not = '' )
	{
		$site = $this->v( $site );
		$row  = $this->get_site_row( $site );
		if ( ( false !== $row ) === (bool) $not ) {
			throw new Exception( "Expected site $site " . ( $not ? 'not ' : '' ) . 'to exist in the EasyEngine database.' );
		}
	}

	/**
	 * @Then /^the site "([^"]*)" should be (enabled|disabled)$/
	 */
	public function theSiteShouldBe( $site, $state )
	{
		$site = $this->v( $site );
		$row  = $this->get_site_row( $site );
		if ( false === $row ) {
			throw new Exception( "Site $site does not exist in the EasyEngine database." );
		}
		if ( ( 'enabled' === $state ) !== (bool) $row['site_enabled'] ) {
			throw new Exception( "Expected $site to be $state, site_enabled is " . var_export( $row['site_enabled'], true ) );
		}
	}

	/**
	 * @Then /^the "([^"]*)" field of site "([^"]*)" in the database should (not )?be "(.*)"$/
	 */
	public function theFieldOfSiteShouldBe( $field, $site, $not, $expected )
	{
		$site = $this->v( $site );
		$row  = $this->get_site_row( $site );
		if ( false === $row ) {
			throw new Exception( "Site $site does not exist in the EasyEngine database." );
		}
		if ( ! array_key_exists( $field, $row ) ) {
			throw new Exception( "Unknown column $field. Columns: " . implode( ', ', array_keys( $row ) ) );
		}
		$expected = $this->v( $expected );
		$actual   = null === $row[ $field ] ? '' : (string) $row[ $field ];
		if ( ( $actual === $expected ) === (bool) $not ) {
			throw new Exception( sprintf( 'Expected %s of %s %sto be %s, got %s', $field, $site, $not, var_export( $expected, true ), var_export( $actual, true ) ) );
		}
	}

	/**
	 * Simulates a site record that disagrees with what nginx serves.
	 *
	 * @When /^I set the "([a-z_]+)" field of site "([^"]*)" in the database to "(.*)"$/
	 */
	public function iSetTheFieldOfSite( $field, $site, $value )
	{
		$site = $this->v( $site );
		$this->assert_own_name( $site );
		$row = $this->get_site_row( $site );
		if ( false === $row || ! array_key_exists( $field, $row ) ) {
			throw new Exception( "No $field for site $site in the EasyEngine database." );
		}
		$stmt = self::db()->prepare( "UPDATE sites SET $field = ? WHERE site_url = ?" );
		$stmt->execute( [ $this->v( $value ), $site ] );
	}

	/* ----- files ----- */

	/**
	 * @Then /^the (file|directory|symlink) "([^"]*)" should (not )?exist$/
	 */
	public function thePathShouldExist( $type, $path, $not = '' )
	{
		// PHP 7.4 serves is_file() of the last path it stat'ed from its stat cache, even after ee deleted it.
		clearstatcache();
		$path   = $this->v( $path );
		$exists = 'file' === $type ? is_file( $path ) : ( 'directory' === $type ? is_dir( $path ) : is_link( $path ) );
		if ( $exists === (bool) $not ) {
			$listing = EE::launch( 'ls -la ' . escapeshellarg( dirname( $path ) ), false, true );
			throw new Exception( sprintf( "Expected %s %s %sto exist.\nls -la %s:\n%s", $type, $path, $not, dirname( $path ), $listing->stdout . $listing->stderr ) );
		}
	}

	/**
	 * @Then /^the file "([^"]*)" should (not )?contain "(.*)"$/
	 */
	public function theFileShouldContain( $path, $not, $text )
	{
		$this->assert_file_contains( $this->v( $path ), $not, $this->v( $text ) );
	}

	/**
	 * @Then /^the file "([^"]*)" should (not )?contain:$/
	 */
	public function theFileShouldContainBlock( $path, $not, PyStringNode $text )
	{
		$this->assert_file_contains( $this->v( $path ), $not, $this->v( $text->getRaw() ) );
	}

	/**
	 * @Then /^the file "([^"]*)" should be identical to the file "([^"]*)"$/
	 */
	public function theFileShouldBeIdenticalTo( $path, $other )
	{
		$path  = $this->v( $path );
		$other = $this->v( $other );
		if ( ! is_file( $path ) || ! is_file( $other ) || sha1_file( $path ) !== sha1_file( $other ) ) {
			throw new Exception( "Expected $path to be identical to $other." );
		}
	}

	/**
	 * @Then /^the directory "([^"]*)" should be empty$/
	 */
	public function theDirectoryShouldBeEmpty( $path )
	{
		$path    = $this->v( $path );
		$entries = is_dir( $path ) ? array_diff( scandir( $path ), [ '.', '..' ] ) : false;
		if ( false === $entries || ! empty( $entries ) ) {
			throw new Exception( "Expected $path to be an empty directory, found: " . var_export( $entries, true ) );
		}
	}

	/**
	 * Writes only inside {TMP} or paths containing the site prefix.
	 *
	 * @When /^I (write|append) "(.*)" to the file "([^"]*)"$/
	 */
	public function iWriteToTheFile( $mode, $content, $path )
	{
		$path = $this->v( $path );
		$this->assert_writable_path( $path );
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		$content = str_replace( '\n', "\n", $this->v( $content ) );
		if ( false === file_put_contents( $path, $content, 'append' === $mode ? FILE_APPEND : 0 ) ) {
			throw new Exception( "Could not write to $path" );
		}
	}

	/**
	 * @When /^I link "([^"]*)" to "([^"]*)"$/
	 */
	public function iLinkTo( $path, $target )
	{
		$path = $this->v( $path );
		$this->assert_writable_path( $path );
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		if ( ! symlink( $this->v( $target ), $path ) ) {
			throw new Exception( "Could not link $path" );
		}
	}

	/**
	 * Puts /dev/null back before failing, so a regression can't break the rest of the run.
	 *
	 * @Then /^\/dev\/null should still be the null device$/
	 */
	public function devNullShouldStillBeTheNullDevice()
	{
		clearstatcache( true, '/dev/null' );
		$stat = @lstat( '/dev/null' );
		// 259 is 1:3, the null device's numbers on Linux only.
		if ( $stat && 0020000 === ( $stat['mode'] & 0170000 ) && ( 'Linux' !== PHP_OS_FAMILY || 259 === $stat['rdev'] ) ) {
			return;
		}
		self::repairDevNull( $stat );
		throw new Exception( '/dev/null was replaced: ' . var_export( $stat, true ) );
	}

	/**
	 * Recreate /dev/null only on Linux and only when it is no character device at all (e.g. replaced by a file), so a
	 * healthy one is never removed.
	 *
	 * @param array|false $stat lstat() of /dev/null.
	 */
	private static function repairDevNull( $stat )
	{
		if ( 'Linux' !== PHP_OS_FAMILY || ( $stat && 0020000 === ( $stat['mode'] & 0170000 ) ) ) {
			return;
		}
		@unlink( '/dev/null' );
		exec( 'mknod -m 666 /dev/null c 1 3' );
	}

	/**
	 * @When /^I remove the (?:file|directory) "([^"]*)"$/
	 */
	public function iRemoveThePath( $path )
	{
		$path = $this->v( $path );
		$this->assert_writable_path( $path );
		EE::launch( 'rm -rf ' . escapeshellarg( $path ), false, true );
	}

	/* ----- /etc/hosts ----- */

	/**
	 * Adds a 127.0.0.1 entry that is removed again after the scenario.
	 *
	 * @Given /^the hosts file contains an entry for "([^"]*)"$/
	 */
	public function theHostsFileContainsAnEntryFor( $host )
	{
		$host = $this->v( $host );
		$this->assert_own_name( $host );
		if ( ! $this->hosts_has_entry( $host ) ) {
			$hosts = file_get_contents( '/etc/hosts' );
			$lead  = ( '' === $hosts || "\n" === substr( $hosts, -1 ) ) ? '' : "\n";
			file_put_contents( '/etc/hosts', "{$lead}127.0.0.1\t$host\n", FILE_APPEND );
			$this->added_hosts[] = $host;
		}
	}

	/**
	 * @When /^I remove the hosts entry for "([^"]*)"$/
	 */
	public function iRemoveTheHostsEntryFor( $host )
	{
		$host = $this->v( $host );
		$this->assert_own_name( $host );
		$this->remove_hosts_entry( $host );
		$this->added_hosts = array_values( array_diff( $this->added_hosts, [ $host ] ) );
	}

	/**
	 * @Then /^the hosts file should (not )?contain an entry for "([^"]*)"$/
	 */
	public function theHostsFileShouldContainAnEntryFor( $not, $host )
	{
		$host = $this->v( $host );
		if ( $this->hosts_has_entry( $host ) === (bool) $not ) {
			throw new Exception( "Expected /etc/hosts {$not}to contain an entry for $host:\n" . file_get_contents( '/etc/hosts' ) );
		}
	}

	/* ----- docker ----- */

	/**
	 * @Then /^the service "([^"]*)" of site "([^"]*)" should be running$/
	 */
	public function theServiceOfSiteShouldBeRunning( $service, $site )
	{
		$site = $this->v( $site );
		if ( empty( $this->site_container_ids( $site, $service ) ) ) {
			$all = EE::launch( 'docker ps -a --filter ' . escapeshellarg( 'label=io.easyengine.site=' . $site ) . ' --format "{{.Names}} {{.Status}}"', false, true );
			throw new Exception( "No running $service container for $site. Containers:\n" . $all->stdout );
		}
	}

	/**
	 * @Then /^the site "([^"]*)" should have no containers$/
	 */
	public function theSiteShouldHaveNoContainers( $site )
	{
		$site = $this->v( $site );
		$ids  = $this->site_container_ids( $site, null, true );
		if ( ! empty( $ids ) ) {
			throw new Exception( "Expected no containers for $site, found: " . implode( ', ', $ids ) );
		}
	}

	/**
	 * @Then /^the site "([^"]*)" should have (\d+) docker volumes?$/
	 */
	public function theSiteShouldHaveDockerVolumes( $site, $count )
	{
		$site    = $this->v( $site );
		$run     = EE::launch( 'docker volume ls -q --filter label=org.label-schema.vendor=EasyEngine --filter ' . escapeshellarg( 'label=io.easyengine.site=' . $site ), false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Could not list site volumes.\n" . $this->describe( $run ) );
		}
		$volumes = array_filter( explode( "\n", trim( $run->stdout ) ) );
		if ( (int) $count !== count( $volumes ) ) {
			throw new Exception( "Expected $count volumes for $site, found " . count( $volumes ) . ': ' . implode( ', ', $volumes ) );
		}
	}

	/**
	 * @Then /^the docker (network|volume|container) "([^"]*)" should (not )?exist$/
	 */
	public function theDockerObjectShouldExist( $type, $name, $not = '' )
	{
		$name = $this->v( $name );
		$format = 'container' === $type ? '{{.Names}}' : '{{.Name}}';
		$run    = EE::launch( 'docker ' . $type . ' ls' . ( 'container' === $type ? ' -a' : '' ) . ' --format ' . escapeshellarg( $format ), false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Could not list Docker $type objects.\n" . $this->describe( $run ) );
		}
		$exists = in_array( $name, explode( "\n", trim( $run->stdout ) ), true );
		if ( $exists === (bool) $not ) {
			throw new Exception( "Expected docker $type $name {$not}to exist.\n" . $this->describe( $run ) );
		}
	}

	/**
	 * @Then /^the docker container "([^"]*)" should be running$/
	 */
	public function theDockerContainerShouldBeRunning( $name )
	{
		$name = $this->v( $name );
		$run  = EE::launch( "docker inspect -f '{{.State.Running}}' " . escapeshellarg( $name ), false, true );
		if ( 'true' !== trim( $run->stdout ) ) {
			throw new Exception( "Container $name is not running.\n" . $this->describe( $run ) );
		}
	}

	/**
	 * @Then /^the service "([^"]*)" of site "([^"]*)" should (not )?have the environment variable "([^"]*)" containing "(.*)"$/
	 */
	public function theServiceShouldHaveEnv( $service, $site, $not, $variable, $text )
	{
		$site = $this->v( $site );
		$text = $this->v( $text );
		$ids  = $this->site_container_ids( $site, $service );
		if ( empty( $ids ) ) {
			throw new Exception( "No running $service container for $site." );
		}
		$run   = EE::launch( "docker inspect -f '{{json .Config.Env}}' " . $ids[0], false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Could not inspect the $service container of $site.\n" . $this->describe( $run ) );
		}
		$env   = json_decode( trim( $run->stdout ), true ) ?: [];
		$found = false;
		foreach ( $env as $line ) {
			if ( 0 === strpos( $line, $variable . '=' ) && false !== strpos( substr( $line, strlen( $variable ) + 1 ), $text ) ) {
				$found = true;
			}
		}
		if ( $found === (bool) $not ) {
			throw new Exception( "Expected $variable of $service ($site) {$not}to contain $text. Environment:\n" . implode( "\n", $env ) );
		}
	}

	/**
	 * @When /^I note the start time of service "([^"]*)" of site "([^"]*)"$/
	 */
	public function iNoteTheStartTimeOfService( $service, $site )
	{
		$site = $this->v( $site );
		$this->start_times[ "$site/$service" ] = $this->service_started_at( $site, $service );
	}

	/**
	 * @Then /^the service "([^"]*)" of site "([^"]*)" should (not )?have been restarted$/
	 */
	public function theServiceShouldHaveBeenRestarted( $service, $site, $not = '' )
	{
		$site = $this->v( $site );
		if ( ! isset( $this->start_times[ "$site/$service" ] ) ) {
			throw new Exception( "The start time of $service ($site) was not noted." );
		}
		$before = $this->start_times[ "$site/$service" ];
		$now    = $this->service_started_at( $site, $service );
		if ( ( $before !== $now ) === (bool) $not ) {
			throw new Exception( "Expected $service of $site {$not}to have been restarted (StartedAt before: $before, now: $now)." );
		}
	}

	/* ----- HTTP ----- */

	/**
	 * The host of the URL is resolved to 127.0.0.1 (whitelisted, no HTTP auth), TLS is not verified, redirects are not followed.
	 *
	 * @When /^I send a (GET|HEAD) request to "([^"]*)"$/
	 */
	public function iSendARequestTo( $method, $url )
	{
		$this->response = $this->http_request( $this->v( $url ), $method );
	}

	/**
	 * Resolves through the host's public IP instead, so the global whitelist does not apply.
	 *
	 * @When /^I send a GET request to "([^"]*)" through the public IP$/
	 */
	public function iSendARequestThroughThePublicIp( $url )
	{
		$this->response = $this->http_request( $this->v( $url ), 'GET', [ 'resolve_ip' => $this->public_ip() ] );
	}

	/**
	 * Real DNS and full TLS verification (Let's Encrypt sites).
	 *
	 * @When /^I send a verified GET request to "([^"]*)"$/
	 */
	public function iSendAVerifiedRequestTo( $url )
	{
		$this->response = $this->http_request( $this->v( $url ), 'GET', [ 'resolve_ip' => false, 'verify' => true ] );
	}

	/**
	 * @Then /^the response code should (not )?be (\d{3})$/
	 */
	public function theResponseCodeShouldBe( $not, $code )
	{
		$response = $this->last_response();
		if ( ( $not && $this->no_http_response( $response ) ) || ( (int) $code === $response['code'] ) === (bool) $not ) {
			throw new Exception( "Expected HTTP {$not}$code, got {$response['code']}.\n" . $this->describe_response( $response ) );
		}
	}

	/**
	 * @Then /^the response body should (not )?contain "(.*)"$/
	 */
	public function theResponseBodyShouldContain( $not, $text )
	{
		$response = $this->last_response();
		$text     = $this->v( $text );
		if ( $not && $this->no_http_response( $response ) ) {
			throw new Exception( "No HTTP response to check.\n" . $this->describe_response( $response ) );
		}
		if ( ( false !== strpos( $response['body'], $text ) ) === (bool) $not ) {
			throw new Exception( "Expected the response body {$not}to contain $text.\n" . $this->describe_response( $response ) );
		}
	}

	/**
	 * @Then /^the response header "([^"]*)" should (not )?(be|contain) "(.*)"$/
	 */
	public function theResponseHeaderShould( $header, $not, $mode, $value )
	{
		$response = $this->last_response();
		$value    = $this->v( $value );
		$name     = strtolower( $header );
		$actual   = isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : null;
		$matches  = null !== $actual && ( 'be' === $mode ? $actual === $value : false !== strpos( $actual, $value ) );
		if ( $not && $this->no_http_response( $response ) ) {
			throw new Exception( "No HTTP response to check.\n" . $this->describe_response( $response ) );
		}
		if ( $matches === (bool) $not ) {
			throw new Exception( "Expected header $header {$not}to $mode $value.\n" . $this->describe_response( $response ) );
		}
	}

	/**
	 * @Then /^the response should (not )?have the header "([^"]*)"$/
	 */
	public function theResponseShouldHaveTheHeader( $not, $header )
	{
		$response = $this->last_response();
		if ( $not && $this->no_http_response( $response ) ) {
			throw new Exception( "No HTTP response to check.\n" . $this->describe_response( $response ) );
		}
		if ( isset( $response['headers'][ strtolower( $header ) ] ) === (bool) $not ) {
			throw new Exception( "Expected the response {$not}to have the header $header.\n" . $this->describe_response( $response ) );
		}
	}

	/**
	 * @Then /^a GET request to "([^"]*)" with Host "([^"]*)" should return (\d{3})$/
	 */
	public function aGetRequestWithHostShouldReturn( $url, $host, $code )
	{
		$response = $this->http_request( $this->v( $url ), 'GET', [ 'resolve_ip' => false, 'host_header' => $this->v( $host ) ] );
		if ( (int) $code !== $response['code'] ) {
			throw new Exception( "Expected HTTP $code, got {$response['code']}.\n" . $this->describe_response( $response ) );
		}
	}

	/**
	 * Polls instead of sleeping (proxy reloads, container restarts).
	 *
	 * @When /^I wait up to (\d+) seconds for "([^"]*)" to (not )?return (\d{3})$/
	 */
	public function iWaitForUrlToReturn( $seconds, $url, $not, $code )
	{
		$url      = $this->v( $url );
		$deadline = time() + (int) $seconds;
		do {
			$this->response = $this->http_request( $url, 'GET' );
			// A transport error doesn't prove "not N"; keep polling.
			if ( ( ! $not || ! $this->no_http_response( $this->response ) ) && ( (int) $code === $this->response['code'] ) !== (bool) $not ) {
				return;
			}
			sleep( 1 );
		} while ( time() < $deadline );
		throw new Exception( "$url did not {$not}return $code within $seconds seconds.\n" . $this->describe_response( $this->response ) );
	}

	/**
	 * @When /^I wait up to (\d+) seconds until the command "(.*)" succeeds$/
	 */
	public function iWaitUntilTheCommandSucceeds( $seconds, $command )
	{
		$command  = $this->v( $command );
		$deadline = time() + (int) $seconds;
		do {
			$run = EE::launch( $command, false, true );
			if ( 0 === $run->return_code ) {
				return;
			}
			sleep( 1 );
		} while ( time() < $deadline );
		throw new Exception( "Command did not succeed within $seconds seconds.\n" . $this->describe( $run ) );
	}

	/* ----- TLS ----- */

	/**
	 * Connects to 127.0.0.1:443 with SNI set to the host.
	 *
	 * @Then /^the TLS certificate served for "([^"]*)" should (not )?cover "([^"]*)"$/
	 */
	public function theTlsCertificateShouldCover( $host, $not, $domain )
	{
		$cert    = $this->peer_certificate( $this->v( $host ) );
		$domain  = $this->v( $domain );
		$names   = $this->certificate_names( $cert );
		$covered = false;
		foreach ( $names as $name ) {
			if ( strtolower( $name ) === strtolower( $domain ) || ( 0 === strpos( $name, '*.' ) && strtolower( substr( $name, 1 ) ) === strtolower( strstr( $domain, '.' ) ) ) ) {
				$covered = true;
			}
		}
		if ( $covered === (bool) $not ) {
			throw new Exception( "Expected the certificate served for $host {$not}to cover $domain. Names: " . implode( ', ', $names ) );
		}
	}

	/**
	 * The proxy can serve its default certificate for a moment while it regenerates after an alias change.
	 *
	 * @Then /^the TLS certificate served for "([^"]*)" should cover "([^"]*)" within (\d+) seconds$/
	 */
	public function theTlsCertificateShouldCoverWithin( $host, $domain, $seconds )
	{
		$deadline = time() + (int) $seconds;
		while ( true ) {
			try {
				$this->theTlsCertificateShouldCover( $host, '', $domain );
				return;
			} catch ( Exception $e ) {
				if ( time() >= $deadline ) {
					throw $e;
				}
				sleep( 2 );
			}
		}
	}

	/**
	 * @Then /^the TLS certificate served for "([^"]*)" should be issued by "(.*)"$/
	 */
	public function theTlsCertificateShouldBeIssuedBy( $host, $issuer )
	{
		$cert   = $this->peer_certificate( $this->v( $host ) );
		$issuer = $this->v( $issuer );
		$parsed = openssl_x509_parse( $cert );
		$actual = isset( $parsed['issuer'] ) ? $parsed['issuer'] : [];
		if ( false === strpos( implode( ' / ', $actual ), $issuer ) ) {
			throw new Exception( "Expected the certificate for $host to be issued by $issuer, issuer is: " . json_encode( $actual ) );
		}
	}

	/* ----- configuration ----- */

	/**
	 * Fails fast instead of letting a command prompt for input (e.g. le-mail).
	 *
	 * @Given /^the EasyEngine config value "([^"]*)" is set$/
	 */
	public function theConfigValueIsSet( $key )
	{
		if ( '' === (string) $this->config_value( $key ) ) {
			throw new Exception( "$key is not set in " . EE_ROOT_DIR . '/config/config.yml; this scenario needs it.' );
		}
	}

	/**
	 * @Given /^the EasyEngine config value "([^"]*)" is (not set|"(.*)")$/
	 */
	public function theConfigValueIs( $key, $what, $value = null )
	{
		$actual = (string) $this->config_value( $key );
		if ( 'not set' === $what ? '' !== $actual : $actual !== $value ) {
			throw new Exception( "This scenario needs $key to be $what in " . EE_ROOT_DIR . "/config/config.yml, it is '$actual'." );
		}
	}

	/**
	 * Guard for host-wide commands (e.g. `ssl-renew --all`): refuses to run when other people's sites would be touched.
	 *
	 * @Given /^no enabled Let's Encrypt site other than this suite's exists$/
	 */
	public function noForeignLetsEncryptSiteExists()
	{
		$sites   = is_file( EE_ROOT_DIR . '/db/ee.sqlite' ) ? self::db()->query( "SELECT site_url FROM sites WHERE site_ssl = 'le' AND site_enabled = 1" )->fetchAll( PDO::FETCH_COLUMN ) : [];
		$foreign = array_filter( $sites, function ( $site ) {
			return ! $this->is_own_name( $site );
		} );
		if ( ! empty( $foreign ) ) {
			throw new Exception( 'Refusing to run: these Let\'s Encrypt sites would be renewed too: ' . implode( ', ', $foreign ) );
		}
	}

	/* ----- remote backups (rclone) ----- */

	/**
	 * @Given /^there are no remote backups for site "([^"]*)"$/
	 */
	public function thereAreNoRemoteBackupsForSite( $site )
	{
		$site = $this->v( $site );
		$this->assert_own_name( $site );
		// List first and purge only this exact site directory, never anything else on the real remote.
		if ( in_array( $site, self::remote_dirs( $this->rclone_path() ), true ) ) {
			EE::launch( 'rclone purge ' . escapeshellarg( $this->rclone_path() . '/' . $site ), false, true );
		}
		if ( ! empty( $this->remote_backups( $site ) ) ) {
			throw new Exception( "Could not remove the remote backups of $site." );
		}
	}

	/**
	 * @Then /^the remote backup storage should have (\d+) backups? for site "([^"]*)"$/
	 */
	public function theRemoteStorageShouldHaveBackups( $count, $site )
	{
		$backups = $this->remote_backups( $this->v( $site ) );
		if ( (int) $count !== count( $backups ) ) {
			throw new Exception( "Expected $count remote backups for $site, found " . count( $backups ) . ":\n" . implode( "\n", $backups ) );
		}
	}

	/**
	 * @Then /^the remote backup storage should have at most the configured "no-of-backups" backups for site "([^"]*)"$/
	 */
	public function theRemoteStorageShouldRespectRetention( $site )
	{
		$limit   = (int) ( $this->config_value( 'no-of-backups' ) ?: 7 );
		$backups = $this->remote_backups( $this->v( $site ) );
		if ( count( $backups ) > $limit ) {
			throw new Exception( "no-of-backups is $limit but " . count( $backups ) . " backups are kept for $site:\n" . implode( "\n", $backups ) );
		}
	}

	/**
	 * @Then /^the remote backup "([^"]*)" of site "([^"]*)" should contain the file "([^"]*)"$/
	 */
	public function theRemoteBackupShouldContainTheFile( $backup_id, $site, $file )
	{
		$site = $this->v( $site );
		$run  = EE::launch( 'rclone lsf ' . escapeshellarg( $this->remote_backup_path( $site, $this->v( $backup_id ) ) ), false, true );
		if ( ! in_array( $this->v( $file ), explode( "\n", trim( $run->stdout ) ), true ) ) {
			throw new Exception( "Remote backup does not contain $file.\n" . $this->describe( $run ) );
		}
	}

	/**
	 * Simulates a restore that died after downloading.
	 *
	 * @When /^I download the remote backup "([^"]*)" of site "([^"]*)" into the local backup directory$/
	 */
	public function iDownloadTheRemoteBackup( $backup_id, $site )
	{
		$site = $this->v( $site );
		$this->assert_own_name( $site );
		$run = EE::launch( sprintf( 'rclone copy %s %s', escapeshellarg( $this->remote_backup_path( $site, $this->v( $backup_id ) ) ), escapeshellarg( EE_ROOT_DIR . '/.backup/' . $site ) ), false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "rclone copy failed.\n" . $this->describe( $run ) );
		}
	}

	/* ----- background commands, logs and php-compat ----- */

	/**
	 * Runs a command like "I run", but without EE::launch(), which copies the command and its output into ee.log.
	 *
	 * @When /^I run (['"])(.*)\1 without logging it$/
	 */
	public function iRunWithoutLoggingIt( $quote, $command )
	{
		$this->commands[] = \EE\Process::create( $this->v( $command ), null, null )->run();
	}

	/**
	 * One command at a time; "I wait up to N seconds for the background command to finish" makes it the last command.
	 *
	 * @When /^I start (['"])(.*)\1 in the background$/
	 */
	public function iStartInTheBackground( $quote, $command )
	{
		if ( null !== $this->background ) {
			throw new Exception( 'A background command is already running in this scenario.' );
		}
		$command = $this->v( $command );
		$out     = $this->tmp_dir . '/background.stdout';
		$err     = $this->tmp_dir . '/background.stderr';
		// exec: the shell becomes the command, so stopping the process stops the command, not only its shell.
		$proc    = proc_open( 'exec ' . $command, [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $out, 'w' ], 2 => [ 'file', $err, 'w' ] ], $pipes );
		if ( ! is_resource( $proc ) ) {
			throw new Exception( "Could not start $command" );
		}
		$this->background = [
			'proc'     => $proc,
			'stdout'   => $out,
			'stderr'   => $err,
			'command'  => $command,
			'start'    => microtime( true ),
			'exitcode' => null,
		];
	}

	/**
	 * @Then the background command should still be running
	 */
	public function theBackgroundCommandShouldStillBeRunning()
	{
		if ( ! $this->background_running() ) {
			throw new Exception( "The background command has already finished.\n" . $this->describe( $this->background_result() ) );
		}
	}

	/**
	 * @When /^I wait up to (\d+) seconds for the background command to finish$/
	 */
	public function iWaitForTheBackgroundCommandToFinish( $seconds )
	{
		$deadline = time() + (int) $seconds;
		while ( $this->background_running() ) {
			if ( time() >= $deadline ) {
				throw new Exception( "The background command is still running after $seconds seconds: " . $this->background['command'] );
			}
			usleep( 200000 );
		}
		$this->commands[] = $this->background_result();
		proc_close( $this->background['proc'] );
		$this->background = null;
	}

	/**
	 * @When /^I note the size of the file "([^"]*)"$/
	 */
	public function iNoteTheSizeOfTheFile( $path )
	{
		$path = $this->v( $path );
		clearstatcache( true, $path );
		$this->noted_sizes[ $path ] = is_file( $path ) ? filesize( $path ) : 0;
	}

	/**
	 * @Then /^what was appended to the file "([^"]*)" since its size was noted should (not )?contain "(.*)"$/
	 */
	public function whatWasAppendedShouldContain( $path, $not, $text )
	{
		$path = $this->v( $path );
		$text = $this->v( $text );
		if ( ! isset( $this->noted_sizes[ $path ] ) ) {
			throw new Exception( "The size of $path was not noted." );
		}
		$added = (string) file_get_contents( $path, false, null, $this->noted_sizes[ $path ] );
		if ( ( false !== strpos( $added, $text ) ) === (bool) $not ) {
			throw new Exception( "Expected what was appended to $path {$not}to contain $text.\n--- appended ---\n$added" );
		}
	}

	/**
	 * @Then /^the file "([^"]*)" should be owned by uid (\d+) with mode ([0-7]{3,4})$/
	 */
	public function theFileShouldBeOwnedByWithMode( $path, $uid, $mode )
	{
		$path = $this->v( $path );
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || is_link( $path ) ) {
			throw new Exception( "$path is not a regular file." );
		}
		$actual_uid  = fileowner( $path );
		$actual_mode = fileperms( $path ) & 07777;
		if ( (int) $uid !== $actual_uid || octdec( $mode ) !== $actual_mode ) {
			throw new Exception( sprintf( 'Expected %s to be owned by uid %d with mode %s, it is uid %d with mode %04o.', $path, $uid, $mode, $actual_uid, $actual_mode ) );
		}
	}

	/**
	 * Every top-level field of the STDOUT JSON, except a comma-separated list, has the same value in the JSON file.
	 *
	 * @Then /^the JSON file "([^"]*)" should hold every field of the STDOUT JSON except "([^"]*)"$/
	 */
	public function theJsonFileShouldHoldEveryFieldOfStdout( $path, $except )
	{
		$path   = $this->v( $path );
		$stdout = $this->decode_stdout_json();
		$file   = is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
		if ( ! is_array( $stdout ) || ! is_array( $file ) ) {
			throw new Exception( "STDOUT and $path must both hold a JSON object.\n" . $this->describe( $this->last_command() ) );
		}
		$skip = array_map( 'trim', explode( ',', $except ) );
		foreach ( $stdout as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $file ) || $file[ $key ] !== $value ) {
				throw new Exception( sprintf( "Field %s is %s in STDOUT but %s in %s.", $key, json_encode( $value ), array_key_exists( $key, $file ) ? json_encode( $file[ $key ] ) : 'missing', $path ) );
			}
		}
	}

	/**
	 * @Then /^the JSON field "([^"]*)" in STDOUT should be the size of the file "([^"]*)"$/
	 */
	public function theJsonFieldShouldBeTheSizeOfTheFile( $field, $path )
	{
		$path   = $this->v( $path );
		$actual = $this->json_path( $this->decode_stdout_json(), $field );
		clearstatcache( true, $path );
		$size = is_file( $path ) ? filesize( $path ) : null;
		if ( $actual !== $size ) {
			throw new Exception( sprintf( 'Expected JSON field %s (%s) to be the size of %s (%s).', $field, var_export( $actual, true ), $path, var_export( $size, true ) ) );
		}
	}

	/**
	 * The scanner runs as uid 33, so fixtures written as root must be world-readable whatever the umask.
	 *
	 * @When /^I make everything under "([^"]*)" readable by everyone$/
	 */
	public function iMakeEverythingReadable( $path )
	{
		$path = $this->v( $path );
		$this->assert_writable_path( $path );
		$paths = [ $path ];
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $items as $item ) {
				$paths[] = $item->getPathname();
			}
		}
		foreach ( $paths as $item ) {
			if ( ! is_link( $item ) && ! chmod( $item, is_dir( $item ) ? 0755 : 0644 ) ) {
				throw new Exception( "Could not chmod $item" );
			}
		}
	}

	/**
	 * Clean PHP files of about 6 KB each, to make a php-compat scan last long enough to overlap with other commands.
	 *
	 * @Given /^the directory "([^"]*)" holds (\d+) generated PHP files$/
	 */
	public function theDirectoryHoldsGeneratedPhpFiles( $dir, $count )
	{
		$dir = $this->v( $dir );
		$this->assert_writable_path( $dir );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
			throw new Exception( "Could not create $dir" );
		}
		for ( $i = 1; $i <= (int) $count; $i++ ) {
			$file = sprintf( '%s/gen-%05d.php', $dir, $i );
			if ( is_file( $file ) ) {
				continue;
			}
			$body = "<?php\nfunction bh_compat_gen_$i() {\n\treturn array(\n";
			for ( $j = 0; $j < 200; $j++ ) {
				$body .= "\t\t'key_$j' => 'value $i $j',\n";
			}
			$body .= "\t);\n}\n";
			if ( false === file_put_contents( $file, $body ) ) {
				throw new Exception( "Could not write $file" );
			}
		}
		$this->iMakeEverythingReadable( $dir );
	}

	/**
	 * Pulls the image once when it is missing, as the command itself would.
	 *
	 * @Given the compat-scanner image is available
	 */
	public function theCompatScannerImageIsAvailable()
	{
		$this->compat_used = true;
		$image = $this->compat_image();
		if ( 0 === EE::launch( "docker image inspect --format '{{.Id}}' " . escapeshellarg( $image ), false, true )->return_code ) {
			return;
		}
		$pull = EE::launch( 'docker pull ' . escapeshellarg( $image ), false, true );
		if ( 0 !== $pull->return_code ) {
			throw new Exception( "$image is not present and could not be pulled.\n" . $this->describe( $pull ) );
		}
	}

	/**
	 * What a php-compat scan killed before its cleanup leaves: its temporary directory and a container with its name, stopped or (an orphaned scan) still running.
	 *
	 * @Given /^a killed php-compat scan of site "([^"]*)" left its temporary directory and (a running )?container behind$/
	 */
	public function aKilledPhpCompatScanLeftovers( $site, $running = '' )
	{
		$site = $this->v( $site );
		$this->assert_own_name( $site );
		$this->compat_used = true;
		$name = 'ee-php-compat-' . $site;
		// A run's /out: a random directory in the root-only per-site one.
		$dir = $this->variable( 'EE_ROOT_DIR' ) . '/tmp/php-compat/' . $site . '/0123456789abcdef';
		if ( ! is_dir( $dir ) && ! mkdir( $dir . '/.work', 0700, true ) ) {
			throw new Exception( "Could not create $dir" );
		}
		file_put_contents( $dir . '/report.json.tmp', '{"v":1,"status":"ok"' );
		EE::launch( 'chown -R 33:33 ' . escapeshellarg( $dir ), false, true );

		EE::launch( 'docker rm -f ' . escapeshellarg( $name ), false, true );
		if ( $running ) {
			$create = EE::launch( 'docker run -d --init --network none --name ' . escapeshellarg( $name ) . ' --entrypoint sleep ' . escapeshellarg( $this->compat_image() ) . ' 600', false, true );
		} else {
			$create = EE::launch( 'docker create --name ' . escapeshellarg( $name ) . ' --entrypoint true ' . escapeshellarg( $this->compat_image() ), false, true );
		}
		if ( 0 !== $create->return_code ) {
			throw new Exception( "Could not create the leftover container $name.\n" . $this->describe( $create ) );
		}
	}

	/* ----- helpers ----- */

	/**
	 * @return string The scanner image with the tag core's img-versions.json pins.
	 */
	private function compat_image()
	{
		$versions = json_decode( (string) file_get_contents( EE_ROOT . '/img-versions.json' ), true );
		if ( empty( $versions['easyengine/compat-scanner'] ) ) {
			throw new Exception( 'img-versions.json of this core has no easyengine/compat-scanner tag; php-compat scenarios need a core that pins the scanner image.' );
		}

		return 'easyengine/compat-scanner:' . $versions['easyengine/compat-scanner'];
	}

	private function background_running()
	{
		if ( null === $this->background ) {
			throw new Exception( 'No background command was started in this scenario.' );
		}
		if ( null !== $this->background['exitcode'] ) {
			return false;
		}
		$status = proc_get_status( $this->background['proc'] );
		if ( $status['running'] ) {
			return true;
		}
		// proc_get_status() reports the exit code only once.
		$this->background['exitcode'] = $status['exitcode'];

		return false;
	}

	private function background_result()
	{
		return new \EE\ProcessRun(
			[
				'stdout'      => (string) @file_get_contents( $this->background['stdout'] ),
				'stderr'      => (string) @file_get_contents( $this->background['stderr'] ),
				'return_code' => $this->background['exitcode'],
				'command'     => $this->background['command'],
				'cwd'         => getcwd(),
				'env'         => null,
				'run_time'    => microtime( true ) - $this->background['start'],
			]
		);
	}

	/**
	 * Ends a background command left by a failed scenario, so it can't hold a site's lock into the next one.
	 */
	private function stop_background()
	{
		if ( null === $this->background ) {
			return;
		}
		if ( $this->background_running() ) {
			// A running scan keeps bin/ee waiting; removing its container lets bin/ee clean up after itself.
			self::purge_compat_leftovers();
			$deadline = time() + 30;
			while ( $this->background_running() && time() < $deadline ) {
				usleep( 200000 );
			}
			if ( $this->background_running() ) {
				proc_terminate( $this->background['proc'], 9 );
			}
		}
		proc_close( $this->background['proc'] );
		$this->background = null;
	}

	/**
	 * Removes php-compat containers and temporary directories of this suite's sites, never others.
	 */
	private static function purge_compat_leftovers()
	{
		$prefix = 'ee-php-compat-' . self::SITE_PREFIX;
		$run    = EE::launch( "docker ps -a --format '{{.Names}}'", false, true );
		foreach ( array_filter( explode( "\n", trim( $run->stdout ) ) ) as $name ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				EE::launch( 'docker rm -f ' . escapeshellarg( $name ), false, true );
			}
		}
		// A directory whose container is still there may still be in use: keep it and say so.
		$ps = EE::launch( "docker ps -a --format '{{.Names}}'", false, true );
		if ( 0 !== $ps->return_code ) {
			fwrite( STDERR, "Could not list the containers; kept the php-compat temporary directories.\n" );

			return;
		}
		$left = array_filter( explode( "\n", trim( $ps->stdout ) ) );
		foreach ( glob( EE_ROOT_DIR . '/tmp/php-compat/' . self::SITE_PREFIX . '*' ) ?: [] as $path ) {
			if ( in_array( 'ee-php-compat-' . basename( $path ), $left, true ) ) {
				fwrite( STDERR, "Could not remove the container ee-php-compat-" . basename( $path ) . "; kept $path.\n" );
				continue;
			}
			EE::launch( 'rm -rf ' . escapeshellarg( $path ), false, true );
		}
	}

	/**
	 * Expands {NAME} variables.
	 */
	private function v( $value )
	{
		if ( ! is_string( $value ) || false === strpos( $value, '{' ) ) {
			return $value;
		}

		return preg_replace_callback(
			'/\{([A-Z][A-Z0-9_]*)\}/',
			function ( $m ) {
				$value = $this->variable( $m[1] );

				return null === $value ? $m[0] : $value;
			},
			$value
		);
	}

	private function variable( $name )
	{
		if ( array_key_exists( $name, $this->variables ) ) {
			return $this->variables[ $name ];
		}
		switch ( $name ) {
			case 'EE':
				return $this->ee_path . '/bin/ee';
			case 'EE_ROOT_DIR':
				return EE_ROOT_DIR;
			case 'SITES_DIR':
				return EE_SITE_ROOT;
			case 'PROXY_DIR':
				return EE_ROOT_DIR . '/services/nginx-proxy';
			case 'BACKUP_DIR':
				return EE_ROOT_DIR . '/.backup';
			case 'TMP':
				return $this->tmp_dir;
			case 'SYS_TMP':
				// What bin/ee's get_temp_dir() resolves to: it inherits this process's TMPDIR.
				return rtrim( \EE\Utils\get_temp_dir(), '/' );
			case 'RUN_ID':
				return self::$run_id;
			case 'PUBLIC_IP':
				return $this->public_ip();
			case 'RCLONE_PATH':
				return $this->rclone_path();
			case 'LE_ISSUER':
				// CI runs @le against Pebble, whose intermediate CN is random per start.
				return getenv( 'EE_BEHAT_LE_ISSUER' ) ?: "Let's Encrypt";
		}

		return null;
	}

	private function last_command()
	{
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private function strip_colors( $text )
	{
		return preg_replace( '/\x1b\[[0-9;]*[A-Za-z]/', '', (string) $text );
	}

	private function stream_of( $run, $stream )
	{
		return $this->strip_colors( 'STDOUT' === $stream ? $run->stdout : $run->stderr );
	}

	private function describe( $run )
	{
		return sprintf(
			"Command: %s\nExit code: %s\n--- STDOUT ---\n%s\n--- STDERR ---\n%s",
			$run->command,
			$run->return_code,
			rtrim( $this->strip_colors( $run->stdout ) ),
			rtrim( $this->strip_colors( $run->stderr ) )
		);
	}

	private function assert_output_contains( $stream, $not, $text )
	{
		$run   = $this->last_command();
		$found = false !== strpos( $this->stream_of( $run, $stream ), $text );
		if ( $found === (bool) $not ) {
			throw new Exception( sprintf( "Expected %s %sto contain:\n%s\n%s", $stream, $not, $text, $this->describe( $run ) ) );
		}
	}

	private function assert_file_contains( $path, $not, $text )
	{
		if ( ! is_file( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}
		$content = file_get_contents( $path );
		if ( ( false !== strpos( $content, $text ) ) === (bool) $not ) {
			throw new Exception( "Expected $path {$not}to contain:\n$text\n--- content ---\n$content" );
		}
	}

	private function decode_stdout_json()
	{
		$run  = $this->last_command();
		$data = json_decode( trim( $this->stream_of( $run, 'STDOUT' ) ), true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'STDOUT is not valid JSON (' . json_last_error_msg() . ").\n" . $this->describe( $run ) );
		}

		return $data;
	}

	private function json_path( $data, $path )
	{
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				throw new Exception( "JSON path $path not found in: " . json_encode( $data ) );
			}
			$data = $data[ $key ];
		}

		return $data;
	}

	private function is_own_name( $name )
	{
		return 1 === preg_match( '/(^|\.)' . preg_quote( self::SITE_PREFIX, '/' ) . '/', strtolower( $name ) );
	}

	private function assert_own_name( $name )
	{
		if ( ! $this->is_own_name( $name ) ) {
			throw new Exception( "Refusing to touch $name: it does not use the suite prefix " . self::SITE_PREFIX );
		}
	}

	private function assert_writable_path( $path )
	{
		if ( ( $this->tmp_dir && 0 === strpos( $path, $this->tmp_dir ) ) || false !== strpos( $path, '/' . self::SITE_PREFIX ) || false !== strpos( $path, '.' . self::SITE_PREFIX ) ) {
			return;
		}
		throw new Exception( "Refusing to modify $path: only {TMP} and paths of the suite's own sites may be changed." );
	}

	private static function db()
	{
		static $pdo = null;
		if ( null === $pdo ) {
			$pdo = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		}

		return $pdo;
	}

	private function get_site_row( $site )
	{
		if ( ! is_file( EE_ROOT_DIR . '/db/ee.sqlite' ) ) {
			return false;
		}
		$stmt = self::db()->prepare( 'SELECT * FROM sites WHERE site_url = ?' );
		$stmt->execute( [ $site ] );

		return $stmt->fetch( PDO::FETCH_ASSOC );
	}

	private static function own_sites()
	{
		// Opening a missing SQLite file would create it.
		if ( ! is_file( EE_ROOT_DIR . '/db/ee.sqlite' ) ) {
			return [];
		}
		$sites = self::db()->query( 'SELECT site_url FROM sites' )->fetchAll( PDO::FETCH_COLUMN );

		return array_values( array_filter( $sites, function ( $site ) {
			return 1 === preg_match( '/(^|\.)' . preg_quote( self::SITE_PREFIX, '/' ) . '/', $site );
		} ) );
	}

	private function ensure_site( $site, $flags )
	{
		$this->assert_own_name( $site );
		$row = $this->get_site_row( $site );
		if ( false === $row ) {
			$run = EE::launch( 'bin/ee site create ' . escapeshellarg( $site ) . ' ' . $flags, false, true );
			if ( 0 !== $run->return_code || false === $this->get_site_row( $site ) ) {
				throw new Exception( "Could not create $site.\n" . $this->describe( $run ) );
			}
		} elseif ( ! $row['site_enabled'] ) {
			$this->theSiteIs( $site, 'enabled' );
		}
	}

	private function site_container_ids( $site, $service = null, $all = false )
	{
		$command = 'docker ps -q' . ( $all ? ' -a' : '' ) . ' --filter ' . escapeshellarg( 'label=io.easyengine.site=' . $site );
		if ( $service ) {
			$command .= ' --filter ' . escapeshellarg( 'label=com.docker.compose.service=' . $service );
		}
		$run = EE::launch( $command, false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Could not list site containers.\n" . $this->describe( $run ) );
		}

		return array_values( array_filter( explode( "\n", trim( $run->stdout ) ) ) );
	}

	private function service_started_at( $site, $service )
	{
		$ids = $this->site_container_ids( $site, $service );
		if ( empty( $ids ) ) {
			throw new Exception( "No running $service container for $site." );
		}
		$run = EE::launch( "docker inspect -f '{{.State.StartedAt}}' " . $ids[0], false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Could not inspect the $service container of $site.\n" . $this->describe( $run ) );
		}

		return trim( $run->stdout );
	}

	private function hosts_has_entry( $host )
	{
		return 1 === preg_match( '/^\s*[0-9a-f.:]+\s+(.*\s)?' . preg_quote( $host, '/' ) . '(\s|$)/mi', file_get_contents( '/etc/hosts' ) );
	}

	private function remove_hosts_entry( $host )
	{
		$content = file_get_contents( '/etc/hosts' );
		$updated = preg_replace( '/^127\.0\.0\.1\t' . preg_quote( $host, '/' ) . '\n/m', '', $content, 1 );
		if ( null !== $updated && $updated !== $content ) {
			file_put_contents( '/etc/hosts', $updated );
		}
	}

	private function public_ip()
	{
		$run = EE::launch( "hostname -I | awk '{print $1}'", false, true );

		return trim( $run->stdout );
	}

	private function config_value( $key )
	{
		$file = EE_ROOT_DIR . '/config/config.yml';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$config = class_exists( 'Mustangostang\Spyc' ) ? Mustangostang\Spyc::YAMLLoad( $file ) : [];

		return isset( $config[ $key ] ) ? $config[ $key ] : null;
	}

	private function rclone_path()
	{
		return rtrim( (string) ( $this->config_value( 'rclone-path' ) ?: 'easyengine:easyengine' ), '/' );
	}

	/**
	 * Backup ids of a site: <rclone-path>/<site>/<id> (develop) and <rclone-path>/<site>/<host-id>/<id> (per-host layout).
	 */
	private function remote_backups( $site )
	{
		$ids = [];
		foreach ( $this->remote_backup_paths( $site ) as $path ) {
			$ids[] = basename( $path );
		}

		return $ids;
	}

	/**
	 * @return string[] Remote path of each backup of $site.
	 */
	private function remote_backup_paths( $site )
	{
		$paths = [];
		$root  = $this->rclone_path() . '/' . $site;
		foreach ( self::list_remote_dirs( $root ) as $dir ) {
			if ( self::is_backup_id( $dir ) ) {
				$paths[] = "$root/$dir";
				continue;
			}
			foreach ( self::list_remote_dirs( "$root/$dir" ) as $id ) {
				if ( self::is_backup_id( $id ) ) {
					$paths[] = "$root/$dir/$id";
				}
			}
		}

		return $paths;
	}

	private function remote_backup_path( $site, $backup_id )
	{
		foreach ( $this->remote_backup_paths( $site ) as $path ) {
			if ( basename( $path ) === basename( $backup_id ) ) {
				return $path;
			}
		}

		return $this->rclone_path() . '/' . $site . '/' . $backup_id;
	}

	private static function list_remote_dirs( $path )
	{
		$run = EE::launch( 'rclone lsf --dirs-only ' . escapeshellarg( $path ), false, true );

		return array_values( array_filter( array_map( function ( $line ) {
			return rtrim( trim( $line ), '/' );
		}, explode( "\n", $run->stdout ) ), 'strlen' ) );
	}

	private static function is_backup_id( $name )
	{
		return 1 === preg_match( '/^\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/', $name );
	}

	private static function purge_own_remote_backups()
	{
		$context = new self();
		$base    = $context->rclone_path();
		foreach ( self::remote_dirs( $base ) as $dir ) {
			if ( 0 === strpos( $dir, self::SITE_PREFIX ) ) {
				EE::launch( 'rclone purge ' . escapeshellarg( $base . '/' . $dir ), false, true );
			}
		}
	}

	/**
	 * Top-level directories under the rclone path that look like site names (no slashes or dot-segments).
	 */
	private static function remote_dirs( $base )
	{
		$run = EE::launch( 'rclone lsf --dirs-only ' . escapeshellarg( $base ), false, true );
		if ( 0 !== $run->return_code ) {
			return [];
		}
		$dirs = array_map( function ( $dir ) {
			return rtrim( trim( $dir ), '/' );
		}, explode( "\n", $run->stdout ) );

		return array_values( array_filter( $dirs, function ( $dir ) {
			return 1 === preg_match( '/^[a-z0-9][a-z0-9.-]*$/', $dir ) && false === strpos( $dir, '..' );
		} ) );
	}

	/**
	 * Removes proxy certs/ACME state, site dirs and volumes named after this suite once none of its sites is left
	 * (known bugs or a failed create can leave them behind), so runs do not pile up state.
	 */
	private static function purge_own_leftovers()
	{
		if ( ! empty( self::own_sites() ) ) {
			return;
		}
		$own   = function ( $name ) {
			return 1 === preg_match( '/(^|\.)' . preg_quote( self::SITE_PREFIX, '/' ) . '/', $name );
		};
		$proxy = EE_ROOT_DIR . '/services/nginx-proxy';
		foreach ( [ "$proxy/certs", "$proxy/acme-conf/certs", "$proxy/acme-conf/var", EE_SITE_ROOT ] as $dir ) {
			foreach ( glob( $dir . '/*' . self::SITE_PREFIX . '*' ) ?: [] as $path ) {
				if ( $own( basename( $path ) ) ) {
					EE::launch( 'rm -rf ' . escapeshellarg( $path ), false, true );
				}
			}
		}

		$run = EE::launch( 'docker volume ls -q --filter label=org.label-schema.vendor=EasyEngine', false, true );
		foreach ( array_filter( explode( "\n", trim( $run->stdout ) ) ) as $volume ) {
			$label = EE::launch( "docker volume inspect -f '{{index .Labels \"io.easyengine.site\"}}' " . escapeshellarg( $volume ), false, true );
			if ( $own( trim( $label->stdout ) ) ) {
				EE::launch( 'docker volume rm ' . escapeshellarg( $volume ), false, true );
			}
		}
	}

	private function http_request( $url, $method = 'GET', $options = [] )
	{
		$options = array_merge( [ 'resolve_ip' => '127.0.0.1', 'verify' => false, 'host_header' => null ], $options );
		$parts   = parse_url( $url );
		$ch      = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, (bool) $options['verify'] );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $options['verify'] ? 2 : 0 );
		if ( 'HEAD' === $method ) {
			curl_setopt( $ch, CURLOPT_NOBODY, true );
		}
		if ( $options['resolve_ip'] && ! empty( $parts['host'] ) ) {
			$port = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
			curl_setopt( $ch, CURLOPT_RESOLVE, [ $parts['host'] . ':' . $port . ':' . $options['resolve_ip'] ] );
		}
		if ( $options['host_header'] ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Host: ' . $options['host_header'] ] );
		}
		$raw         = curl_exec( $ch );
		$error       = curl_error( $ch );
		$code        = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );

		$raw_headers = false === $raw ? '' : substr( $raw, 0, $header_size );
		$headers     = [];
		foreach ( explode( "\n", $raw_headers ) as $line ) {
			if ( false !== strpos( $line, ':' ) ) {
				list( $name, $value ) = explode( ':', $line, 2 );
				$headers[ strtolower( trim( $name ) ) ] = trim( $value );
			}
		}

		return [
			'url'         => $url,
			'code'        => $code,
			'headers'     => $headers,
			'raw_headers' => $raw_headers,
			'body'        => false === $raw ? '' : substr( $raw, $header_size ),
			'error'       => $error,
		];
	}

	private function last_response()
	{
		if ( null === $this->response ) {
			throw new Exception( 'No HTTP request has been sent in this scenario.' );
		}

		return $this->response;
	}

	// Negative HTTP checks pass only on a real response.
	private function no_http_response( $response )
	{
		return 0 === $response['code'] || '' !== $response['error'];
	}

	private function describe_response( $response )
	{
		return sprintf( "URL: %s\nError: %s\n--- headers ---\n%s--- body (first 500 bytes) ---\n%s", $response['url'], $response['error'], $response['raw_headers'], substr( $response['body'], 0, 500 ) );
	}

	private function peer_certificate( $host )
	{
		$context = stream_context_create( [
			'ssl' => [
				'capture_peer_cert' => true,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'peer_name'         => $host,
				'SNI_enabled'       => true,
			],
		] );
		$socket  = @stream_socket_client( 'ssl://127.0.0.1:443', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context );
		if ( ! $socket ) {
			throw new Exception( "TLS connection for $host failed: $errstr ($errno)" );
		}
		$params = stream_context_get_params( $socket );
		fclose( $socket );

		return $params['options']['ssl']['peer_certificate'];
	}

	private function certificate_names( $cert )
	{
		$parsed = openssl_x509_parse( $cert );
		$names  = [];
		if ( ! empty( $parsed['extensions']['subjectAltName'] ) ) {
			foreach ( explode( ',', $parsed['extensions']['subjectAltName'] ) as $san ) {
				$san = trim( $san );
				if ( 0 === strpos( $san, 'DNS:' ) ) {
					$names[] = substr( $san, 4 );
				}
			}
		}
		// Clients ignore the Common Name when DNS SANs are present (RFC 6125).
		if ( ! $names && ! empty( $parsed['subject']['CN'] ) ) {
			$names[] = $parsed['subject']['CN'];
		}

		return array_values( array_unique( $names ) );
	}
}
