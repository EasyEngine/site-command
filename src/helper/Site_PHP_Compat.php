<?php

namespace EE\Site\Type;

use EE;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use function EE\Utils\delem_log;
use function EE\Utils\get_flag_value;

require_once __DIR__ . '/Site_PHP_Compat_Layout.php';

/**
 * `ee site php-compat`: runs the compat-scanner image against a site's code and prints its verdict or estimate.
 *
 * Output goes through `echo` only: EE::exec(), EE::launch() and EE::log() would put findings into ee.log.
 */
class Site_PHP_Compat {

	const IMAGE            = 'easyengine/compat-scanner';
	const SITE_TYPES       = [ 'wp', 'php' ];
	const DEFAULT_TIMEOUT  = 7200;
	const ESTIMATE_TIMEOUT = 120;
	// Docker's own start and teardown on top of the container's deadline, which normally fires first.
	const PROCESS_MARGIN = 60;
	const PULL_TIMEOUT   = 1800;
	// Estimate tuning passed to the scanner (its defaults: 0.1 and 1.5), from runner and host measurements on 2026-11-24.
	const ESTIMATE_SECONDS_PER_BATCH = '0.5';
	const ESTIMATE_HIGH_FACTOR       = '2.5';
	// Refreshing a tag that is already local; past it the local copy serves.
	const REPULL_TIMEOUT = 300;
	// How long --cancel waits for the stopped scan to clean up and release the site lock.
	const CANCEL_WAIT = 60;
	// A live scan takes the request within about 0.2 s; one that hasn't after this can't act on it.
	const CANCEL_GRACE = 3;
	const STDOUT_MAX     = 1048576;
	const STDERR_MAX     = 65536;
	const REPORT_MAX     = 134217728;
	// Memory the report may take once decoded; the limit is raised to this when lower.
	const MEMORY_FLOOR = 1073741824;
	// Under EE_ROOT_DIR/tmp/, root-only, so no other user or host uid 33 can reach a scan's /out.
	const TEMP_DIR      = 'php-compat';
	const SCAN_STATUSES = [ 'ok', 'no_code_found', 'tool_error' ];
	const SUMMARY_INTS  = [ 'errors', 'warnings', 'files_checked', 'files_not_checked', 'php_bytes', 'components_checked', 'components_with_errors', 'duration_s' ];
	const ESTIMATE_INTS = [ 'php_files', 'php_bytes', 'components', 'estimated_seconds', 'estimated_seconds_low', 'estimated_seconds_high' ];
	// --format=json prints only the documented keys (INTERFACE.md), in this order.
	const SUMMARY_KEYS  = [ 'v', 'status', 'site', 'target', 'safe_to_upgrade', 'errors', 'warnings', 'files_checked', 'files_not_checked', 'php_bytes', 'components_checked', 'components_with_errors', 'checked', 'not_checked', 'not_checked_more', 'wordpress', 'cross_check', 'duration_s', 'tool' ];

	// stdout stays fixed-size: at most this many not_checked entries, each string at most NOT_CHECKED_CHARS characters.
	const NOT_CHECKED_STDOUT = 10;
	const NOT_CHECKED_CHARS  = 256;
	const TOOL_KEYS     = [ 'phpcs', 'phpcompatibility', 'phpcompatibility_wp', 'image' ];
	const ESTIMATE_KEYS = [ 'v', 'status', 'estimate', 'site', 'target', 'php_files', 'php_bytes', 'components', 'estimated_seconds', 'estimated_seconds_low', 'estimated_seconds_high', 'inputs' ];
	const INPUT_KEYS    = [ 'calibration_bytes', 'calibration_seconds', 'startup_seconds', 'bytes_per_second', 'batches', 'seconds_per_batch', 'cpus', 'load_average_1m', 'low_factor', 'high_factor' ];
	// File names and messages from the scanned code can hold invalid UTF-8.
	const JSON_UTF8 = JSON_INVALID_UTF8_SUBSTITUTE; // phpcs:ignore PHPCompatibility.PHP.NewConstants.json_invalid_utf8_substituteFound -- PHP 7.2+; EE needs 7.4.
	// C1 controls, bidi and zero-width characters: a terminal acts on them or they disguise text.
	const UNSAFE_CHARS = '\x{80}-\x{9F}\x{061C}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}';
	// The site's nginx config, whose root is what the site serves.
	const NGINX_CONF = '/config/nginx/conf.d/main.conf';
	// A public directory as EE stores it (the pattern Cloner checks site_container_fs_path with).
	const ROOT_PATTERN = '#^/var/www/htdocs(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#D';
	// Size cap for the site files read on the host (main.conf, wp-cli.yml, wp-config.php).
	const CONFIG_MAX = 1048576;
	// Size cap for PHP files read on the host (wp-config.php and what it includes, index.php): tokens take ~500x the bytes.
	const PHP_SOURCE_MAX = 262144;
	/**
	 * PHP versions each WordPress branch supports, [ lowest, highest ], from WordPress's own "PHP Compatibility and WordPress
	 * Versions" page (https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/, last
	 * updated 2026-11-04). A target newer than every row is one WordPress hasn't declared support for yet.
	 */
	const WP_PHP_SUPPORT = [
		'7.1' => [ '7.4', '8.5' ],
		'7.0' => [ '7.4', '8.5' ],
		'6.9' => [ '7.2', '8.5' ],
		'6.8' => [ '7.2', '8.4' ],
		'6.7' => [ '7.2', '8.4' ],
		'6.6' => [ '7.2', '8.3' ],
		'6.5' => [ '7.0', '8.3' ],
		'6.4' => [ '7.0', '8.3' ],
		'6.3' => [ '7.0', '8.2' ],
		'6.2' => [ '5.6', '8.2' ],
		'6.1' => [ '5.6', '8.2' ],
		'6.0' => [ '5.6', '8.1' ],
		'5.9' => [ '5.6', '8.1' ],
		'5.8' => [ '5.6', '8.0' ],
		'5.7' => [ '5.6', '8.0' ],
		'5.6' => [ '5.6', '8.0' ],
		'5.5' => [ '5.6', '7.4' ],
		'5.4' => [ '5.6', '7.4' ],
		'5.3' => [ '5.6', '7.4' ],
		'5.2' => [ '5.6', '7.3' ],
		'5.1' => [ '5.2', '7.3' ],
		'5.0' => [ '5.2', '7.3' ],
		'4.9' => [ '5.2', '7.2' ],
		'4.8' => [ '5.2', '7.1' ],
		'4.7' => [ '5.2', '7.1' ],
	];

	/** Dot-directories of the www-data home in /var/www (app/): tool caches, never site code. */
	const HOME_DOTDIRS = [ '.wp-cli', '.composer', '.cache', '.config', '.local', '.npm', '.ssh' ];

	/** The include targets of EE's nginx.conf, main.conf and admin-tools.conf, current and historical (dockerfiles nginx, site-type templates). */
	const NGINX_INCLUDES = [ 'mime.types', 'fastcgi_params', 'fastcgi.conf', '/usr/local/openresty/nginx/conf/conf.d/main.conf', '/usr/local/openresty/nginx/conf/custom/*.conf', 'custom/*.conf', 'user/*.conf', '/etc/nginx/custom/*.conf', '/etc/nginx/conf.d/*.conf', '/etc/nginx/main.conf', '/etc/nginx/default.conf', '/etc/nginx/sites-enabled/*', '/version.conf' ];

	/** Longest php-compat lists the site's files for (PHP outside WordPress, links, .user.ini files). */
	const WALK_SECONDS = 120;

	// tool_error detail when the site's directory or app/ is missing or its lock can't be opened (a delete in progress).
	const SITE_GONE = 'the site is being deleted or its files are missing';

	// Directory names the scanner searches by name only (its manifest's sniff.skipped_dirs); the sniff doesn't apply below them.
	const SCANNER_SKIP_NAMES = [ 'uploads', 'cache', 'upgrade', 'upgrade-temp-backup', 'node_modules', '.git' ];

	/** EE's own config/nginx/custom/admin-tools.conf (site-type-wp templates/config/nginx/admin-tools.conf.mustache). */
	const ADMIN_TOOLS_CONF = "# ee-admin pages\nlocation ^~ /ee-admin/ {\n\troot /var/www/htdocs ;\n\tlocation ~ \\.php$ {\n\t\ttry_files \$uri =404;\n\t\tinclude fastcgi_params;\n\t\tfastcgi_pass php;\n\t}\n}\n";

	/** WordPress's own files beside wp-admin/ and wp-includes/, which the scanner never reads (compat-scanner CORE_FILES). */
	const CORE_FILES = [ 'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' ];

	/** @var string Site name. */
	protected $site = '';

	/** @var string Target PHP version. */
	protected $target = '';

	/** @var bool Whether this run is an estimate. */
	protected $estimate = false;

	/** @var bool Whether this run is --cancel. */
	protected $cancel = false;

	/** @var string|null Path argument for the scanner (under /var/www), set by resolve_layout(). */
	protected $scan_path;

	/** @var string[] --mount arguments for code the site's php container gets from mounts below /var/www. */
	protected $extra_mounts = [];

	/** @var string|null Status of this scan's result, so cleanup() knows whether a late --cancel can still be honoured. */
	protected $result_status;

	/** @var resource|null The site lock `ee site delete` keeps until it ends. */
	protected $delete_lock;

	/** @var resource|null An estimate lock held while `ee site delete` removes it. */
	protected $estimate_lock;

	/** @var bool Whether cleanup() took a --cancel request that arrived after the scan's last check. */
	protected $late_cancel = false;

	/** @var string|null Host directory the scan looked in, for the no_code_found text; null when app/ is missing. */
	protected $looked_in;

	/** @var string|null The site's nginx config path, for the no_code_found text; null when app/ is missing. */
	protected $nginx_conf;

	/** @var bool Whether this is a WordPress scan (its WordPress directory, core left out) rather than all of htdocs. */
	protected $wp_layout = false;

	/** @var array|null WordPress's version ( version, branch ) read from the scanned root's wp-includes/version.php. */
	protected $wp_version;

	/** @var array|null What resolve_layout() found, for the cross-check: wp, and as real host paths scan (the scanner's root), top, abspath, a2 index. */
	protected $cross_layout;

	/** @var string|null Real path of the wp-config.php WordPress loads when that makes EE's app/wp-config.php unused (shape (c)). */
	protected $ee_config_unused_by;

	/** @var bool Whether the last lock_site() couldn't open the site directory (gone during a delete). */
	protected $lock_unopenable = false;

	/** @var array|null The cross-check of the scanner's manifest (cross_check()). */
	protected $cross;

	/** @var bool Whether the record/nginx root mismatch was already reported. */
	protected $warned_root = false;

	/** @var string|null The PHP version the site's record says, major.minor; null when unknown. */
	protected $current_php;

	/** @var string|null The PHP version of the running php container's image (easyengine/phpX.Y), null when unknown. */
	protected $container_php;

	/** @var string|null Cancel request file a scan watches once it holds the site lock. */
	protected $cancel_marker;

	/** @var resource|null Shared lock --cancel holds while its request is live. */
	protected $cancel_guard;

	/** @var string|null This run's directory mounted at /out, while it exists. */
	protected $temp_dir;

	/** @var string|null Name of a scan container that may still exist. */
	protected $active_container;

	/** @var resource|null Lock on the site directory while a scan runs, or on the site's estimate lock file while an estimate runs. */
	protected $lock;

	/** @var string|null Label value of this estimate's container while cleanup() may have to remove it. */
	protected $estimate_label;

	/** @var resource|null Shared host-wide lock while this check uses the scanner image, so no other check removes its tag. */
	protected $image_lock;

	/** @var array|null --output directory as validated: path, dev, ino. */
	protected $output_dir;

	/** @var string|null Temporary file of write_report() until it is renamed. */
	protected $output_tmp;

	/** @var callable|null Runs pcntl_signal_dispatch() in the site command's scope, where its protected rollback() handlers can be called. */
	protected $dispatch;

	/** @var bool Set during cleanup(), so a second signal can't interrupt it. */
	protected $cleaning = false;

	/** @var bool Whether the shutdown cleanup is registered. */
	private $cleanup_registered = false;

	/**
	 * Validate the options, run the scan or the estimate and print the result.
	 *
	 * @param object        $site_data  Site record from get_site_info( ..., false ).
	 * @param array         $assoc_args Command options.
	 * @param callable|null $dispatch   Signal dispatcher from EE_Site_Command; null dispatches here.
	 */
	public function run( $site_data, array $assoc_args, $dispatch = null ) {

		$this->site = $site_data->site_url;
		// `--estimate=false` is the string "false": only a bare flag (or --no-<flag>) is a switch.
		foreach ( [ 'estimate', 'cancel' ] as $key ) {
			if ( ! is_bool( get_flag_value( $assoc_args, $key, false ) ) ) {
				EE::error( "--$key doesn't take a value." );
			}
		}
		$this->target   = (string) get_flag_value( $assoc_args, 'php', '' );
		$this->estimate = true === get_flag_value( $assoc_args, 'estimate', false );
		$this->cancel   = true === get_flag_value( $assoc_args, 'cancel', false );
		$format         = get_flag_value( $assoc_args, 'format', 'table' );
		$output         = get_flag_value( $assoc_args, 'output', null );
		$timeout        = get_flag_value( $assoc_args, 'timeout', null );

		// The dispatcher drops a valueless optional flag with only a warning; the runner still has it.
		$raw = (array) EE::get_runner()->assoc_args;
		if ( $this->cancel ) {
			// A cancel stops whichever check of the site is running, whatever its target.
			foreach ( [ 'php', 'estimate', 'output', 'timeout' ] as $key ) {
				if ( array_key_exists( $key, $assoc_args ) || array_key_exists( $key, $raw ) ) {
					EE::error( "--$key doesn't apply to --cancel." );
				}
			}
		}
		foreach ( [ 'php', 'format', 'output', 'timeout' ] as $key ) {
			if ( ! array_key_exists( $key, $assoc_args ) && isset( $raw[ $key ] ) && true === $raw[ $key ] ) {
				EE::error( "--$key needs a value." );
			}
		}
		if ( ! in_array( $site_data->site_type, self::SITE_TYPES, true ) ) {
			EE::error( "ee site php-compat supports wp and php sites; {$this->site} is a {$site_data->site_type} site." );
		}
		if ( ! $this->cancel && ! array_key_exists( 'php', $assoc_args ) ) {
			// Optional in the synopsis only because --cancel doesn't take it; same message as the dispatcher's.
			EE::error( "Parameter errors:\n missing --php parameter (PHP version to check the code against.)" );
		}
		// The dispatcher compares loosely, so `--php=8` or `--php=8.10` (== 8.1) would pass its check.
		if ( ! $this->cancel && ! in_array( $this->target, self::php_versions(), true ) ) {
			EE::error( 'Invalid --php value. Use one of: ' . implode( ', ', self::php_versions() ) . '.' );
		}
		if ( ! in_array( $format, [ 'table', 'json' ], true ) ) {
			EE::error( 'Invalid --format value. Use table or json.' );
		}
		if ( $this->estimate && ( null !== $output || null !== $timeout ) ) {
			EE::error( '--output and --timeout don\'t apply to --estimate.' );
		}
		if ( null === $timeout ) {
			$timeout = self::DEFAULT_TIMEOUT;
		} elseif ( ! is_string( $timeout ) || ! preg_match( '/^[1-9][0-9]{0,8}$/', $timeout ) ) {
			EE::error( '--timeout must be a positive whole number of seconds.' );
		}
		if ( null !== $output ) {
			$output = $this->validate_output_path( $output );
		}

		$this->dispatch = $dispatch;
		if ( null !== $dispatch && 'json' === $format ) {
			self::keep_stdout_for_json();
		}

		delem_log( 'site php-compat start' );
		if ( $this->cancel ) {
			EE::debug( "php-compat: site {$this->site}, cancel" );
		} else {
			EE::debug( sprintf( 'php-compat: site %s, PHP %s, %s', $this->site, $this->target, $this->estimate ? 'estimate' : "scan, timeout $timeout s" ) );
		}
		$started = microtime( true );

		try {
			if ( $this->cancel ) {
				$result = $this->run_cancel( $site_data );
			} elseif ( $this->estimate ) {
				$result = $this->run_estimate( $site_data );
			} else {
				$result = $this->run_scan( $site_data, (int) $timeout, $output );
				// A scan stopped by --cancel fails in whichever step it was in; report the cancel instead.
				if ( 'ok' !== $result['doc']['status'] && $this->cancel_requested() ) {
					$result = $this->cancelled();
				}
				$this->result_status = $result['doc']['status'];
			}
		} finally {
			$this->cleanup();
		}
		if ( $this->late_cancel ) {
			// The request came while this scan cleaned up; --cancel reads its removal as stopped, so say the same.
			$result = $this->doc( 'cancelled' );
		}

		// Before printing: PHP stops at an echo to a terminal that is gone.
		$this->log_result( $result['doc'], microtime( true ) - $started );
		if ( 'json' === $format ) {
			echo self::encode( $result['doc'] ) . "\n";
		} elseif ( $this->cancel ) {
			echo $this->cancel_text( $result['doc'] );
		} elseif ( $this->estimate ) {
			echo $this->estimate_text( $result['doc'] );
		} else {
			// Line by line: a large report isn't copied into one more string.
			foreach ( $this->table_lines( $result['doc'], $result['report'] ) as $line ) {
				echo $line, "\n";
			}
		}

		delem_log( 'site php-compat end' );
	}

	/**
	 * The --php values of this command's docblock, the same list as `ee site update --php`.
	 *
	 * @return string[]
	 */
	public static function php_versions() {
		$doc     = ( new \ReflectionMethod( EE_Site_Command::class, 'php_compat' ) )->getDocComment();
		$options = ( new \EE\DocParser( $doc ) )->get_param_args( 'php' );

		// YAML reads 8.0 as a float.
		return array_map(
			function ( $version ) {
				return is_float( $version ) ? number_format( $version, 1, '.', '' ) : (string) $version;
			},
			isset( $options['options'] ) ? $options['options'] : []
		);
	}

	/**
	 * Refuse an --output the report couldn't be written to, before the scan runs.
	 *
	 * @param string $output --output value.
	 *
	 * @return string The path.
	 */
	protected function validate_output_path( $output ) {
		$output = (string) $output;
		if ( '' === $output || '/' !== $output[0] ) {
			EE::error( '--output must be an absolute path.' );
		}
		if ( ! preg_match( '//u', $output ) || preg_match( '/[\x00-\x1F\x7F' . self::UNSAFE_CHARS . ']/u', $output ) ) {
			EE::error( '--output must not contain control characters.' );
		}
		$name = basename( $output );
		if ( '/' === substr( $output, -1 ) || '' === $name || '.' === $name || '..' === $name ) {
			EE::error( '--output must name a file.' );
		}
		$dir = realpath( dirname( $output ) );
		if ( false === $dir || ! is_dir( $dir ) ) {
			EE::error( 'The directory of --output does not exist: ' . dirname( $output ) );
		}
		// Another user who can rename entries there could redirect the root-owned write.
		if ( ! $this->trusted_dir( $dir ) ) {
			EE::error( "--output must be in a directory only root can change: $dir or one of its parents is owned by another user or writable by others." );
		}
		if ( is_dir( $output ) && ! is_link( $output ) ) {
			EE::error( "--output is a directory: $output" );
		}
		// The rename would replace a device node such as /dev/null with the report.
		foreach ( [ '/dev', '/proc', '/sys', '/run' ] as $system ) {
			if ( $dir === $system || 0 === strpos( $dir, "$system/" ) ) {
				EE::error( "--output can't be in $system: $output" );
			}
		}
		if ( ! self::replaceable( $output ) ) {
			EE::error( "--output is not a regular file: $output" );
		}
		// tempnam() falls back to the system temp dir when $dir isn't writable (read-only mount, not root).
		$probe    = @tempnam( $dir, '.ee-php-compat-' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		$writable = false !== $probe && realpath( dirname( $probe ) ) === $dir;
		if ( false !== $probe ) {
			@unlink( $probe ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! $writable ) {
			EE::error( 'The directory of --output is not writable: ' . dirname( $output ) );
		}
		$stat             = stat( $dir );
		$this->output_dir = [
			'path' => $dir,
			'dev'  => $stat['dev'],
			'ino'  => $stat['ino'],
		];

		return $output;
	}

	/**
	 * @param object $site_data Site record.
	 * @param int    $timeout   --timeout.
	 * @param string $output    --output path, or null.
	 *
	 * @return array 'doc' (summary to print) and 'report' (decoded report, ok only).
	 */
	protected function run_scan( $site_data, $timeout, $output ) {

		$image = $this->image();
		if ( null === $image ) {
			return $this->doc( 'tool_error', 'no ' . self::IMAGE . ' tag in img-versions.json' );
		}
		$code = $this->code_dir( $site_data->site_fs_path );
		if ( null === $code ) {
			// R48: delete removes the site's files before its record, so no app/ is never "no code found".
			return $this->doc( 'tool_error', self::SITE_GONE );
		}
		if ( false === $code ) {
			return $this->doc( 'tool_error', "can't resolve {$site_data->site_fs_path}/app, the link to the site's code" );
		}
		// Read first so a layout this check refuses is answered at once, without a lock or a pull; read again under the lock.
		$layout = $this->resolve_layout( $site_data, $code );
		if ( null !== $layout ) {
			return $layout;
		}
		if ( false !== strpbrk( $code, ',"' ) ) {
			return $this->doc( 'tool_error', 'the site code path has a comma or a quote' );
		}

		// Two EE runs can't both pass the container check below and then clear each other's directory as a leftover.
		if ( ! $this->lock_site( $site_data->site_fs_path ) ) {
			return $this->restore_running( $site_data->site_fs_path ) ? $this->doc( 'tool_error', 'a restore of the site is running' ) : $this->doc( 'busy' );
		}
		// R48: a site directory that can't be opened is being deleted (delete removes it before the record); never scan unlocked.
		if ( $this->lock_unopenable ) {
			return $this->doc( 'tool_error', self::SITE_GONE );
		}
		$parent = $this->temp_parent();
		if ( null !== $parent ) {
			$this->cancel_marker = $this->cancel_path( $parent );
			$this->clear_stale_cancel();
		}
		$name   = 'ee-php-compat-' . $this->site;
		$status = $this->container_status( $name );
		if ( 'running' === $status ) {
			return $this->doc( 'busy' );
		}
		if ( 'unknown' === $status ) {
			return $this->doc( 'tool_error', "could not check the container $name" );
		}
		if ( 'exited' === $status ) {
			EE::debug( "Removing the leftover container $name." );
			// Known limit: a container whose --rm is still in progress can't be removed again, which reads as this tool_error.
			if ( 0 !== $this->docker( [ 'docker', 'rm', '-f', $name ], 60 )['code'] ) {
				return $this->doc( 'tool_error', "could not remove the leftover container $name" );
			}
		}

		$this->hold_image();
		$pull = $this->ensure_image( $image );
		if ( null !== $pull ) {
			return $this->doc( 'tool_error', $pull );
		}
		// Read under the site lock and after the pull, so the layout checked is the one scanned.
		$layout = $this->resolve_layout( $site_data, $code );
		if ( null !== $layout ) {
			return $layout;
		}
		$mounts = $this->extra_mounts( $site_data, $code );
		if ( is_string( $mounts ) ) {
			return $this->doc( 'tool_error', $mounts );
		}

		$out = $this->make_temp_dir();
		if ( null === $out ) {
			return $this->doc( 'tool_error', 'could not create the temporary directory' );
		}
		if ( false !== strpbrk( $out, ',"' ) ) {
			return $this->doc( 'tool_error', 'the temporary directory path has a comma or a quote' );
		}

		if ( $this->cancel_requested() ) {
			return $this->cancelled();
		}

		// From here until cleanup() confirms it is gone, /out may be in use by this container.
		$this->active_container = $name;
		$run                    = $this->docker( $this->docker_run_args( 'scan', $image, $site_data, $code, $out, $timeout ), $timeout + self::PROCESS_MARGIN, true );
		if ( $run['timed_out'] ) {
			return $this->doc( 'tool_error', "timed out after $timeout s" );
		}

		// Docker refuses a taken name before creating anything, so nothing is printed, and the other container exists.
		if ( 125 === $run['code'] && '' === trim( $run['stdout'] ) && false !== stripos( $run['stderr'], 'Conflict' ) && false !== strpos( $run['stderr'], $name ) && false !== $this->container_status( $name ) ) {
			// That container is another run's: leave it alone.
			$this->active_container = null;

			return $this->doc( 'busy' );
		}

		$doc = empty( $run['overflow'] ) ? $this->parse_document( $run['stdout'] ) : null;
		if ( null === $doc || ! $this->valid_scan_doc( $doc ) ) {
			return $this->doc( 'tool_error', self::exit_detail( $run, 'without a valid result' ) );
		}
		if ( 'ok' !== $doc['status'] ) {
			return $this->doc( $doc['status'], 'tool_error' === $doc['status'] ? $doc['detail'] : null );
		}

		// The container ended (docker run --rm returns once it is removed), so nothing changes /out any more.
		$file = $out . '/report.json';
		clearstatcache( true, $file );
		$size = @filesize( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a missing file is refused below.
		if ( false !== $size && $size > self::REPORT_MAX ) {
			return $this->doc( 'tool_error', 'the report is larger than ' . ( self::REPORT_MAX >> 20 ) . ' MiB' );
		}

		$raw    = null;
		$report = null;
		try {
			self::raise_memory_limit();
			$raw = \EE\Site\Utils\get_container_file_contents( $file, $out, self::REPORT_MAX );
			if ( ! $this->make_room( self::decode_cost( $raw ) ) ) {
				return $this->doc( 'tool_error', sprintf( 'the report (%s) needs more memory to read than is available', self::size( strlen( $raw ) ) ) );
			}
			$report = json_decode( $raw, true, 512, self::JSON_UTF8 );
		} catch ( \Exception $e ) {
			EE::debug( 'php-compat report: ' . self::clean( $e->getMessage() ) );
		}
		// The scanner never prints invalid UTF-8; the decode above only substitutes it, and --output publishes these bytes as they are.
		if ( ! is_array( $report ) || 1 !== preg_match( '//u', $raw ) || ! $this->valid_report( $report, $doc ) ) {
			return $this->doc( 'tool_error', self::exit_detail( $run, 'without a valid report' ) );
		}

		// The final gate: every code file in app/ is one the scanner read or reported, or an exact exempt shape.
		$this->cross = $this->cross_check( $code, $out );

		// Last point a cancel wins: after it, the result stands and --output is written.
		if ( $this->cancel_requested() ) {
			return $this->cancelled();
		}

		$full    = $this->summary( $this->scoped( $doc, $report ) );
		$summary = self::capped( $full );
		if ( null !== $output ) {
			// The report says what stdout says (gated verdict, scoped fields), with the full not_checked list and the components.
			$raw = self::encode( $full + [ 'components' => $report['components'] ] );
			// A value json_encode() refuses (INF in a key EE doesn't know): no report rather than an empty one.
			if ( ! is_string( $raw ) || '' === $raw ) {
				return $this->doc( 'tool_error', 'could not encode the report' );
			}
			if ( ! $this->write_report( $output, $raw ) ) {
				return $this->doc( 'tool_error', "could not write the report to $output" );
			}
			$summary['report']       = $output;
			$summary['report_bytes'] = strlen( $raw );
		}

		return [
			'doc'    => $summary,
			'report' => $report,
		];
	}

	/**
	 * @param object $site_data Site record.
	 *
	 * @return array 'doc' (estimate to print) and 'report' (always null).
	 */
	protected function run_estimate( $site_data ) {

		$image = $this->image();
		if ( null === $image ) {
			return $this->doc( 'tool_error', 'no ' . self::IMAGE . ' tag in img-versions.json' );
		}
		$code = $this->code_dir( $site_data->site_fs_path );
		if ( null === $code ) {
			// R48: delete removes the site's files before its record, so no app/ is never "no code found".
			return $this->doc( 'tool_error', self::SITE_GONE );
		}
		if ( false === $code ) {
			return $this->doc( 'tool_error', "can't resolve {$site_data->site_fs_path}/app, the link to the site's code" );
		}
		// Read first so a layout this check refuses is answered at once, without a lock or a pull; read again under the lock.
		$layout = $this->resolve_layout( $site_data, $code );
		if ( null !== $layout ) {
			return $layout;
		}
		if ( false !== strpbrk( $code, ',"' ) ) {
			return $this->doc( 'tool_error', 'the site code path has a comma or a quote' );
		}
		// Before the lock: `ee site delete` waits only for the estimate's own time limit, not for a slow pull.
		$this->hold_image();
		$pull = $this->ensure_image( $image );
		if ( null !== $pull ) {
			return $this->doc( 'tool_error', $pull );
		}
		// One estimate per site at a time; it never waits for a scan.
		$locked = $this->lock_estimate();
		if ( null === $locked ) {
			return $this->doc( 'tool_error', 'could not create the estimate lock' );
		}
		if ( ! $locked ) {
			return $this->doc( 'busy' );
		}
		// Taken before this check, so a restore starting now sees the estimate lock (Site_Backup_Restore::hold_scan_lock()).
		if ( $this->restore_running( $site_data->site_fs_path ) ) {
			return $this->doc( 'tool_error', 'a restore of the site is running' );
		}
		// The pull ran unlocked: a delete may have gone through meanwhile, and the layout is read only now.
		if ( ! is_dir( $site_data->site_fs_path ) || $this->code_dir( $site_data->site_fs_path ) !== $code ) {
			return $this->doc( 'tool_error', 'the site was deleted or changed while the scanner image was pulled' );
		}
		$layout = $this->resolve_layout( $site_data, $code );
		if ( null !== $layout ) {
			return $layout;
		}
		$mounts = $this->extra_mounts( $site_data, $code );
		if ( is_string( $mounts ) ) {
			return $this->doc( 'tool_error', $mounts );
		}

		// The client ignores signals (no --sig-proxy) and a timeout stops only the client: cleanup() removes the container by label.
		$this->estimate_label = bin2hex( random_bytes( 8 ) );
		$run                  = $this->docker( $this->docker_run_args( 'estimate', $image, $site_data, $code ), self::ESTIMATE_TIMEOUT + self::PROCESS_MARGIN, true );
		if ( null !== $run['code'] ) {
			// Ended on its own: --rm removed it.
			$this->estimate_label = null;
		}
		if ( $run['timed_out'] ) {
			return $this->doc( 'tool_error', 'timed out after ' . self::ESTIMATE_TIMEOUT . ' s' );
		}

		$doc = empty( $run['overflow'] ) ? $this->parse_document( $run['stdout'] ) : null;
		if ( null === $doc || ! $this->valid_estimate_doc( $doc ) ) {
			return $this->doc( 'tool_error', self::exit_detail( $run, 'without a valid result' ) );
		}
		if ( 'ok' !== $doc['status'] ) {
			return $this->doc( $doc['status'], 'tool_error' === $doc['status'] ? $doc['detail'] : null );
		}

		return [
			'doc'    => $this->with_site( self::pick( $doc, self::ESTIMATE_KEYS ) ),
			'report' => null,
		];
	}

	/**
	 * --cancel: stop the site's running scan and remove what it left. Estimates have no named container and are never stopped.
	 *
	 * @param object $site_data Site record.
	 *
	 * @return array 'doc' (cancelled, not_running or tool_error) and 'report' (always null).
	 */
	protected function run_cancel( $site_data ) {

		$parent = $this->temp_parent();
		if ( null === $parent ) {
			return $this->doc( 'tool_error', 'could not create the temporary directory' );
		}
		$name    = 'ee-php-compat-' . $this->site;
		$marker  = $this->cancel_path( $parent );
		$stopped = false;
		if ( null === $marker ) {
			return $this->doc( 'tool_error', 'could not create the temporary directory' );
		}
		if ( ! $this->hold_cancel_guard( $marker ) ) {
			return $this->doc( 'tool_error', 'another cancel of the site is still running' );
		}

		$locked = $this->lock_site( $site_data->site_fs_path );
		if ( ! $locked && $this->restore_running( $site_data->site_fs_path ) ) {
			// No check runs during a restore: nothing to stop, and no request to leave.
			return $this->doc( 'tool_error', 'a restore of the site is running' );
		}
		if ( ! $locked ) {
			// A scan holds the lock: ask it to stop, then wait until it has removed its container, cleaned up and let go.
			$token = $this->request_cancel( $marker );
			if ( null === $token ) {
				return $this->doc( 'tool_error', 'could not write the cancel request' );
			}
			$grace  = min( self::CANCEL_GRACE, $this->cancel_wait() );
			$locked = $this->wait_for_site_lock( $site_data->site_fs_path, $grace );
			if ( ! $locked ) {
				// Untaken: a frozen scan holds the lock. The id is read
				// first: no scan starts a container while the request stays, so a later scan's container is never this id.
				$id = $this->running_container_id( $name );
				if ( null !== $id && $token === $this->read_cancel( $marker ) ) {
					$stopped = 0 === $this->docker( [ 'docker', 'rm', '-f', $id ], 60 )['code'];
				}
			}
			if ( ! $locked && ! $this->wait_for_site_lock( $site_data->site_fs_path, $this->cancel_wait() - $grace ) ) {
				// The request stays: the scan still reports cancelled when it gets to it.
				return $this->doc( 'tool_error', 'the running check did not stop within ' . $this->cancel_wait() . ' s' );
			}
			if ( $token === $this->read_cancel( $marker ) ) {
				// The scan finished without seeing the request.
				@unlink( $marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			} else {
				// The scan removed it when it stopped.
				$stopped = true;
			}
		} elseif ( null !== $this->lock ) {
			// No scan runs: a request no scan picked up is stale.
			@unlink( $marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}

		// With the lock, a container or directory left now belongs to a killed run, the same leftovers a scan clears first.
		$status = $this->container_status( $name );
		if ( false !== $status ) {
			if ( null === $this->lock && 'running' === $status ) {
				// Without a lock a scan may still be live: let it report cancelled.
				$this->request_cancel( $marker );
			}
			if ( ! $this->container_gone( $name ) ) {
				return $this->doc( 'tool_error', "could not remove the container $name" );
			}
			$stopped = $stopped || 'running' === $status;
		}
		$site_dir = "$parent/{$this->site}";
		if ( null !== $this->lock && ( file_exists( $site_dir ) || is_link( $site_dir ) ) ) {
			if ( $this->own_dir( $site_dir ) ) {
				$this->remove_leftovers( $site_dir );
				@rmdir( $site_dir ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- an entry that couldn't be removed keeps it.
			} else {
				EE::debug( "Leaving $site_dir alone: not a directory of this user with mode 0700." );
			}
		}

		return $this->doc( $stopped ? 'cancelled' : 'not_running' );
	}

	/**
	 * Beside the estimate locks, outside the per-site directory a scan clears; a site name never starts with a dot.
	 *
	 * @param string $parent temp_parent().
	 *
	 * @return string|null
	 */
	protected function cancel_path( $parent ) {
		return $this->control_path( $parent, 'cancel' );
	}

	/**
	 * A per-site control file, one directory per kind, so no site name can collide with another site's file of another kind.
	 *
	 * @param string $parent temp_parent().
	 * @param string $kind   cancel, cancel-lock or estimate-lock.
	 *
	 * @return string|null
	 */
	protected function control_path( $parent, $kind ) {
		return $this->own_dir( "$parent/.$kind" ) ? "$parent/.$kind/{$this->site}" : null;
	}

	/**
	 * @param string $marker cancel_path().
	 *
	 * @return string The guard file of that request.
	 */
	protected static function guard_path( $marker ) {
		return dirname( $marker, 2 ) . '/.cancel-lock/' . basename( $marker );
	}

	/**
	 * Held from before --cancel writes its request until it ends, so no scan clears that request as stale. Exclusive: a second
	 * --cancel waits for the first, so it never replaces a request the first still waits on.
	 *
	 * @param string $marker cancel_path().
	 *
	 * @return bool False when another --cancel still holds it after twice cancel_wait() (e.g. stuck on a hung docker).
	 */
	protected function hold_cancel_guard( $marker ) {
		$guard = $this->own_dir( dirname( self::guard_path( $marker ) ) ) ? @fopen( self::guard_path( $marker ), 'ce' ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
		if ( false === $guard ) {
			return true;
		}
		// Polled, so signals still reach the handlers.
		$until = microtime( true ) + 2 * $this->cancel_wait();
		while ( ! flock( $guard, LOCK_EX | LOCK_NB, $would_block ) ) {
			if ( ! $would_block ) {
				fclose( $guard );
				return true;
			}
			if ( microtime( true ) >= $until ) {
				fclose( $guard );
				return false;
			}
			usleep( 100000 );
			if ( function_exists( 'pcntl_signal_dispatch' ) ) {
				null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
			}
		}
		$this->cancel_guard = $guard;

		return true;
	}

	/**
	 * With the site lock: remove a request no --cancel waits on any more (its scan was killed, or the cancel gave up or was killed).
	 * A request of a waiting --cancel is for this scan, even one written for a scan that ended just before this one started.
	 */
	protected function clear_stale_cancel() {
		// Not created here: a --cancel creates it before its request, so while it is missing any request is a live one.
		$guard = @fopen( self::guard_path( $this->cancel_marker ), 're' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing is handled.
		if ( false === $guard ) {
			return;
		}
		if ( flock( $guard, LOCK_EX | LOCK_NB, $would_block ) || ! $would_block ) {
			// Removed under the exclusive lock, so a --cancel starting now writes its request after this.
			@unlink( $this->cancel_marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- usually missing.
		}
		fclose( $guard );
	}

	/**
	 * @param string $marker cancel_path().
	 *
	 * @return string|null This request's token, or null when it couldn't be written.
	 */
	protected function request_cancel( $marker ) {
		$token = bin2hex( random_bytes( 8 ) );
		// A leading dot: never a site name.
		$tmp = dirname( $marker ) . '/.' . basename( $marker ) . ".$token";
		if ( false === @file_put_contents( $tmp, $token ) || ! @rename( $tmp, $marker ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
			@unlink( $tmp ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			return null;
		}

		return $token;
	}

	/**
	 * @param string $marker cancel_path().
	 *
	 * @return string|null The pending request's token.
	 */
	protected function read_cancel( $marker ) {
		clearstatcache( true, $marker );
		$token = @file_get_contents( $marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing means none.

		return false === $token ? null : $token;
	}

	/**
	 * @return bool Whether --cancel asked this scan to stop.
	 */
	protected function cancel_requested() {
		if ( null === $this->cancel_marker ) {
			return false;
		}
		clearstatcache( true, $this->cancel_marker );

		return file_exists( $this->cancel_marker );
	}

	/**
	 * The scan's result once cancelled; removing the request tells the waiting --cancel that this scan stopped for it.
	 *
	 * @return array 'doc' and 'report'.
	 */
	protected function cancelled() {
		@unlink( $this->cancel_marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$this->cancel_marker = null;

		return $this->doc( 'cancelled' );
	}

	/**
	 * @param string $site_fs_path Site directory.
	 * @param int    $seconds      How long to try.
	 *
	 * @return bool Whether this run got the site lock in time.
	 */
	protected function wait_for_site_lock( $site_fs_path, $seconds ) {
		$until = microtime( true ) + $seconds;
		do {
			usleep( 100000 );
			if ( function_exists( 'pcntl_signal_dispatch' ) ) {
				null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
			}
			if ( $this->lock_site( $site_fs_path ) ) {
				return true;
			}
		} while ( microtime( true ) < $until );

		return false;
	}

	/**
	 * @return int Seconds.
	 */
	protected function cancel_wait() {
		return self::CANCEL_WAIT;
	}

	/**
	 * The `docker run` command line. Estimates have no name, so they never conflict, and no /out.
	 *
	 * @param string      $mode      scan or estimate.
	 * @param string      $image     Image with tag.
	 * @param object      $site_data Site record.
	 * @param string      $code      Real path of the site's code.
	 * @param string|null $out       Directory mounted at /out (scan only).
	 * @param int|null    $timeout   --timeout (scan only).
	 *
	 * @return string[]
	 */
	public function docker_run_args( $mode, $image, $site_data, $code, $out = null, $timeout = null ) {
		// No --sig-proxy: the client would pass a terminal's SIGHUP on to the scanner.
		$args = [ 'docker', 'run', '--rm', '--init', '--sig-proxy=false' ];
		if ( 'scan' === $mode ) {
			array_push( $args, '--name', 'ee-php-compat-' . $this->site );
		}
		// No log driver: the daemon would otherwise keep its own unbounded copy of the container's stdout.
		array_push(
			$args,
			'--network', 'none', '--log-driver', 'none', '--read-only', '--tmpfs', '/tmp:size=64m',
			'--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--pids-limit', '64',
			'--memory', '1g', '--memory-swap', '1g', '--cpus', '1', '--cpu-shares', '256',
			'--ulimit', 'core=0',
			'--user', '33:33',
			// The scanner stops at the same report size EE accepts.
			'-e', 'COMPAT_SITE=' . $this->site, '-e', 'COMPAT_IMAGE=' . $image, '-e', 'COMPAT_REPORT_MAX=' . self::REPORT_MAX,
			'--mount', "type=bind,src=$code,dst=/var/www,readonly"
		);
		if ( 'estimate' === $mode && null !== $this->estimate_label ) {
			// The site label lets `ee site delete` stop a running estimate.
			array_push( $args, '--label', 'ee-php-compat-estimate=' . $this->estimate_label, '--label', 'ee-php-compat-estimate-site=' . $this->site );
		}
		if ( 'estimate' === $mode ) {
			// The byte model misses PHPCS's per-file cost on dense many-file trees (2x short seen): more per batch, wider top end.
			array_push( $args, '-e', 'COMPAT_SECONDS_PER_BATCH=' . self::ESTIMATE_SECONDS_PER_BATCH, '-e', 'COMPAT_HIGH_FACTOR=' . self::ESTIMATE_HIGH_FACTOR );
		}
		$args = array_merge( $args, $this->extra_mounts );
		if ( 'scan' === $mode ) {
			array_push( $args, '--mount', "type=bind,src=$out,dst=/out" );
		}
		array_push( $args, $image, $mode, $site_data->site_type, null !== $this->scan_path ? $this->scan_path : $site_data->site_container_fs_path, $this->target );
		if ( 'scan' === $mode ) {
			$args[] = (string) $timeout;
		}

		return $args;
	}

	/**
	 * @return string|null Image with its tag from img-versions.json.
	 */
	protected function image() {
		$versions = \EE\Utils\get_image_versions();

		return empty( $versions[ self::IMAGE ] ) ? null : self::IMAGE . ':' . $versions[ self::IMAGE ];
	}

	/**
	 * `app` is a symlink to the htdocs volume; Docker needs its real path.
	 *
	 * @param string $site_fs_path Site directory.
	 *
	 * @return string|null|false Real path; null when there is no app/ at all; false when app/ is there but can't be resolved.
	 */
	protected function code_dir( $site_fs_path ) {
		$app = $site_fs_path . '/app';
		if ( ! is_link( $app ) && ! file_exists( $app ) ) {
			return null;
		}
		$real = realpath( $app );
		if ( false === $real && is_link( $app ) ) {
			if ( ! preg_match( '#/volumes/([A-Za-z0-9][A-Za-z0-9_.-]*)/_data/?$#D', (string) readlink( $app ), $m ) ) {
				return false;
			}
			// The link holds Docker's root dir of when the volume was made; ask Docker where the volume is now.
			$run  = $this->docker( [ 'docker', 'volume', 'inspect', '--format', '{{.Mountpoint}}', $m[1] ], 60 );
			$real = 0 === $run['code'] && '' !== trim( $run['stdout'] ) ? realpath( trim( $run['stdout'] ) ) : false;
		}

		return ( false !== $real && is_dir( $real ) ) ? $real : false;
	}

	/**
	 * Picks the scanner's path argument from the root nginx serves, and gives no verdict unless this check recognises the
	 * whole layout (INTERFACE.md, "Allowlist (v4.16.0 r3)" and its revision): anything else is tool_error with the reason.
	 *
	 * @param object $site_data Site record.
	 * @param string $code      Real path of the site's app/.
	 *
	 * @return array|null tool_error result, or null with $this->scan_path set.
	 */
	protected function resolve_layout( $site_data, $code ) {
		$this->nginx_conf = $site_data->site_fs_path . self::NGINX_CONF;
		$this->wp_layout  = false;
		$served           = $this->served_root( $site_data );
		if ( '!' === $served[0] ) {
			return $this->doc( 'tool_error', substr( $served, 1 ) );
		}
		$rel = ltrim( substr( $served, strlen( '/var/www' ) ), '/' );
		$app = $site_data->site_fs_path . '/app/';

		$this->scan_path = $served;
		$detail          = $this->server_config_check( $site_data );
		if ( null !== $detail ) {
			return $this->doc( 'tool_error', $detail );
		}
		if ( 'wp' !== $site_data->site_type ) {
			// The scanner checks all of htdocs for php sites, whatever the root nginx serves.
			$this->looked_in = $app . 'htdocs';
			$htdocs          = self::resolve_in( $code, 'htdocs' );
			$user_ini        = [];
			$error           = null === $htdocs ? null : $this->walk_tree(
				$htdocs,
				function ( $path, $name, $type ) use ( &$user_ini ) {
					if ( '.user.ini' === $name ) {
						$user_ini[] = [ $path, $type ];
					}
					return 'dir' === $type;
				}
			);
			$this->cross_layout = [ 'wp' => false, 'scan' => $code, 'top' => self::resolve_in( $code, $rel ), 'abspath' => null, 'index' => null ];
			$detail             = null !== $error ? "can't look through {$app}htdocs for .user.ini files: $error" : $this->prepend_check( $site_data, $code, $user_ini, null );
			return null === $detail ? null : $this->doc( 'tool_error', $detail );
		}

		$this->wp_layout = true;
		$layout          = $this->wp_layout_of( $code, $rel, $app );
		if ( is_string( $layout ) ) {
			return $this->doc( 'tool_error', $layout );
		}
		$root            = $layout['root'];
		$this->scan_path = '/var/www/' . $root;
		$this->looked_in = $app . $root;
		EE::debug( "php-compat: WordPress root $root (nginx root $served)" );

		$allowed = [];
		$index   = self::resolve_in( $code, "$rel/index.php" );
		if ( null !== $index ) {
			$allowed[] = $index;
		}
		$this->wp_version          = null;
		$this->ee_config_unused_by = null;
		$this->current_php         = isset( $site_data->php_version ) && preg_match( '/^\d+\.\d+$/D', (string) $site_data->php_version ) ? (string) $site_data->php_version : null;
		if ( null !== $layout['abspath'] ) {
			// Core isn't scanned: its version decides whether it supports the target PHP.
			$file    = $this->site_file( $code, substr( $layout['abspath'], strlen( $code ) + 1 ) . '/wp-includes/version.php', self::PHP_SOURCE_MAX );
			$version = 'ok' === $file['state'] ? Site_PHP_Compat_Layout::wp_version( $file['source'] ) : 'it ' . ( 'bad' === $file['state'] ? $file['detail'] : 'is missing' );
			if ( is_string( $version ) ) {
				return $this->doc( 'tool_error', "can't read WordPress's version from $app$root/wp-includes/version.php: " . self::clean( $version ) );
			}
			$this->wp_version = $version;
			$config           = $this->check_wp_config( $code, $root, $layout['abspath'], $app );
			if ( is_string( $config ) ) {
				return $this->doc( 'tool_error', $config );
			}
			$allowed = array_merge( $allowed, $config );
		}
		$this->cross_layout = [
			'wp'      => true,
			'scan'    => self::resolve_in( $code, $root ),
			'top'     => self::resolve_in( $code, $rel ),
			'abspath' => $layout['abspath'],
			// (d): the served index.php of layout a2, which passed WordPress's own loader recogniser above.
			'index'   => $root !== $rel ? $index : null,
			// (c): the config WordPress loads instead of EE's app/wp-config.php.
			'config'  => $this->ee_config_unused_by,
		];
		$walk = $this->app_walk( $code, $rel, $root, $layout['abspath'], $allowed );
		if ( is_string( $walk ) ) {
			return $this->doc( 'tool_error', str_replace( "$code/", $app, $walk ) );
		}
		$detail = $this->prepend_check( $site_data, $code, $walk, $layout );

		return null === $detail ? null : $this->doc( 'tool_error', $detail );
	}

	/**
	 * Where WordPress is, in one of the accepted shapes: at the served root or below it, loaded by WordPress's own index.php;
	 * or, with no served index.php, at its current/ or wp-cli.yml's path: (as backup finds it). The WordPress root's own
	 * index.php must be WordPress's too.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Served root relative to app/.
	 * @param string $app  The site's app/ with a trailing slash, for messages.
	 *
	 * @return array|string [ root (relative to app/, as served), abspath (real WordPress root, or null when there is none) ],
	 *                      or a tool_error detail.
	 */
	protected function wp_layout_of( $code, $rel, $app ) {
		$index = $this->site_file( $code, "$rel/index.php", self::PHP_SOURCE_MAX );
		if ( 'bad' === $index['state'] ) {
			return "can't read $app$rel/index.php: it {$index['detail']}";
		}
		$root = null;
		if ( 'ok' === $index['state'] ) {
			// __DIR__ is the real directory: a linked index.php elsewhere would load another WordPress.
			if ( dirname( $index['real'] ) !== self::resolve_in( $code, $rel ) ) {
				return "$app$rel/index.php is a link to " . self::clean( str_replace( "$code/", $app, $index['real'] ) ) . ', so it loads WordPress from another directory than nginx serves';
			}
			$loader = Site_PHP_Compat_Layout::index_loader( $index['source'] );
			if ( is_string( $loader ) ) {
				return "$app$rel/index.php isn't WordPress's own index.php (" . self::clean( $loader ) . '); this check only gives a verdict when the served index.php just loads WordPress';
			}
			$root = '' === $loader['sub'] ? $rel : "$rel/{$loader['sub']}";
			if ( null === $this->wp_abspath( $code, $root ) ) {
				return "$app$rel/index.php loads WordPress from $app$root, which holds no WordPress";
			}
		} elseif ( null !== $this->wp_abspath( $code, $rel ) ) {
			return "$app$rel holds WordPress but no index.php";
		} else {
			$candidates = array_slice( \EE\Site\Utils\get_wp_root_candidates( $rel ), 1 );
			foreach ( array_unique( [ $rel, 'htdocs' ] ) as $dir ) {
				$path = $this->wp_cli_path( $code, $dir );
				if ( null !== $path && 0 === strpos( $path, "$rel/" ) ) {
					$candidates[] = $path;
				}
			}
			foreach ( array_unique( $candidates ) as $candidate ) {
				if ( null !== $this->wp_abspath( $code, $candidate ) ) {
					$root = $candidate;
					break;
				}
			}
			if ( null === $root ) {
				// No WordPress: fine when there is no PHP at all (the scanner then finds nothing to check).
				return [ 'root' => $rel, 'abspath' => null ];
			}
		}
		$abspath = $this->wp_abspath( $code, $root );
		// The resolved WordPress root must be inside the resolved served root.
		$top = self::resolve_in( $code, $rel );
		if ( null === $top || 0 !== strpos( $abspath . '/', $top . '/' ) ) {
			return "WordPress in $app$root resolves to " . self::clean( str_replace( "$code/", $app, $abspath ) ) . ", outside the directory nginx serves ($app$rel)";
		}
		if ( $root !== $rel ) {
			// Rule (b) for the WordPress root's own index.php too (a2, a3); in a1 it is the served one, checked above.
			$own = $this->site_file( $code, "$root/index.php", self::PHP_SOURCE_MAX );
			if ( 'ok' === $own['state'] && dirname( $own['real'] ) !== $abspath ) {
				return "$app$root/index.php is a link to " . self::clean( str_replace( "$code/", $app, $own['real'] ) ) . ', so it loads WordPress from another directory';
			}
			$own = 'ok' === $own['state'] ? Site_PHP_Compat_Layout::index_loader( $own['source'] ) : 'it ' . ( 'bad' === $own['state'] ? $own['detail'] : 'is missing' );
			if ( is_string( $own ) || '' !== $own['sub'] ) {
				return "$app$root/index.php isn't WordPress's own index.php (" . self::clean( is_string( $own ) ? $own : 'it loads WordPress from elsewhere' ) . ')';
			}
		}

		return [ 'root' => $root, 'abspath' => $abspath ];
	}

	/**
	 * @param string $code Real path of the site's app/.
	 * @param string $root Directory relative to app/.
	 *
	 * @return string|null Its real path when it holds WordPress (a regular wp-settings.php).
	 */
	protected function wp_abspath( $code, $root ) {
		$settings = self::resolve_in( $code, "$root/wp-settings.php" );

		return null !== $settings && is_file( $settings ) && ! is_link( $settings ) ? dirname( $settings ) : null;
	}

	/**
	 * Reads the wp-config.php WordPress loads, and the files it loads, against the config grammar.
	 *
	 * @param string $code    Real path of the site's app/.
	 * @param string $root    WordPress root relative to app/.
	 * @param string $abspath Real WordPress root.
	 * @param string $app     The site's app/ with a trailing slash, for messages.
	 *
	 * @return string[]|string Real paths of the config files read, or a tool_error detail.
	 */
	protected function check_wp_config( $code, $root, $abspath, $app ) {
		$config = $this->wp_config_files( $code, $root, $abspath );
		if ( ! $config ) {
			return [];
		}
		$file = $this->site_file( $code, $config[0], self::PHP_SOURCE_MAX );
		if ( 'ok' !== $file['state'] ) {
			return "can't read $app{$config[0]}: " . ( 'bad' === $file['state'] ? $file['detail'] : 'it is gone' );
		}
		$read    = [ $file['real'] ];
		$resolve = function ( $path ) use ( $code, $abspath, &$read ) {
			if ( 0 !== strpos( $path, "$code/" ) ) {
				return [ 'state' => 'bad', 'detail' => 'is outside the site\'s files' ];
			}
			$found = $this->site_file( $code, substr( $path, strlen( $code ) + 1 ), self::PHP_SOURCE_MAX );
			if ( 'ok' === $found['state'] ) {
				$read[]           = $found['real'];
				$found['scanned'] = self::scanned_by_wp_scan( $found['real'], $abspath );
			}
			return $found;
		};
		$error   = Site_PHP_Compat_Layout::check_config(
			$file['source'],
			[
				'root'    => $abspath,
				'dir'     => dirname( $file['real'] ),
				'file'    => $file['real'],
				'boot'    => true,
				'resolve' => $resolve,
				// Container (/var/www/…) and host paths, links resolved, so a default written either way compares equal.
				'canon'   => function ( $path ) use ( $code ) {
					if ( 0 === strpos( $path . '/', '/var/www/' ) ) {
						$path = $code . substr( $path, strlen( '/var/www' ) );
					}
					if ( 0 !== strpos( $path . '/', $code . '/' ) ) {
						return $path;
					}
					// The longest prefix that exists, resolved, then the rest (mu-plugins may not exist yet).
					$rest = '';
					for ( $prefix = $path; strlen( $prefix ) > strlen( $code ); $prefix = dirname( $prefix ) ) {
						$real = self::resolve_in( $code, ltrim( substr( $prefix, strlen( $code ) ), '/' ) );
						if ( null !== $real ) {
							return $real . $rest;
						}
						$rest = '/' . basename( $prefix ) . $rest;
					}
					return $path;
				},
			]
		);
		if ( null !== $error ) {
			// Messages name the site's app/ path, not the volume's.
			return self::clean( str_replace( "$code/", $app, $error ) );
		}

		// WordPress takes ABSPATH's wp-config.php first: one in the parent (EE's app/wp-config.php beside an imported tree)
		// is never loaded, so it is no code outside WordPress either.
		if ( dirname( $file['real'] ) === $abspath ) {
			$parent = $this->site_file( $code, ltrim( substr( dirname( $abspath ), strlen( $code ) ), '/' ) . '/wp-config.php', self::PHP_SOURCE_MAX );
			if ( 'ok' === $parent['state'] ) {
				$read[] = $parent['real'];
			}
		}
		// (c) EE's app/wp-config.php is unused when WordPress's own lookup (its root, else the real parent) loads another file.
		$real_root = substr( $abspath, strlen( $code ) + 1 );
		$parent    = dirname( $real_root );
		$ee_config = "$code/wp-config.php";
		$stat      = @lstat( $ee_config ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
		if ( in_array( $config[0], [ "$real_root/wp-config.php", ( '.' === $parent ? '' : "$parent/" ) . 'wp-config.php' ], true ) && $file['real'] !== $ee_config && false !== $stat && 0100000 === ( $stat['mode'] & 0170000 ) ) {
			$read[]                    = $ee_config;
			$this->ee_config_unused_by = $file['real'];
		}

		return $read;
	}

	/**
	 * Walks all of app/ (no links followed, time-bounded) for what the scan of the WordPress root would miss: any code file
	 * or link outside it (besides the served index.php, the config files read, its own `current` link, dot-directories and
	 * old releases nginx doesn't serve), and inside it a link to one of its own ancestors or a nested WordPress install.
	 *
	 * @param string      $code    Real path of the site's app/.
	 * @param string      $rel     Served root relative to app/.
	 * @param string      $root    WordPress root relative to app/ (as served).
	 * @param string|null $abspath Real WordPress root, or null when there is none.
	 * @param string[]    $allowed Real paths of PHP files already checked.
	 *
	 * @return array|string The .user.ini files found ([ path, type ] each), or a tool_error detail (host paths).
	 */
	protected function app_walk( $code, $rel, $root, $abspath, array $allowed ) {
		$top      = (string) self::resolve_in( $code, $rel );
		// The live release: the WordPress root or its ancestor directly in a releases/ directory.
		$releases = null;
		$live     = null;
		for ( $dir = $abspath; null !== $dir && strlen( $dir ) > strlen( $code ); $dir = dirname( $dir ) ) {
			if ( 'releases' === basename( dirname( $dir ) ) ) {
				$releases = dirname( $dir );
				$live     = $dir;
				break;
			}
		}
		$user_ini = [];
		$found    = null;
		$outside  = [];
		$linked   = [];
		$error    = $this->walk_tree(
			$code,
			function ( $path, $name, $type ) use ( $code, $top, $root, $abspath, $allowed, $releases, $live, &$user_ini, &$found, &$outside, &$linked ) {
				$inside = null !== $abspath && 0 === strpos( $path . '/', $abspath . '/' );
				if ( '.user.ini' === $name ) {
					$user_ini[] = [ $path, $type ];
				}
				if ( 'link' === $type ) {
					// The served root or the WordPress root may be a link (current -> releases/<n>).
					if ( 0 === strpos( "$code/$root/", $path . '/' ) ) {
						return false;
					}
					$real = self::resolve_in( $code, substr( $path, strlen( $code ) + 1 ) );
					// A link to a file already read and validated (releases/wp-config.php -> ../wp-config.php): no other code.
					if ( null !== $real && in_array( $real, $allowed, true ) ) {
						return false;
					}
					if ( $inside ) {
						if ( null !== $real && 0 === strpos( dirname( $path ) . '/', $real . '/' ) ) {
							$found = "$path links to its own ancestor " . $real;
							return 'stop';
						}
						if ( null !== $real && self::reaches_install( $code, $real, $abspath ) ) {
							$found = "$path links into another WordPress install ($real)";
							return 'stop';
						}
						// The scanner follows it: what lies there counts as inside (e.g. shared uploads of a Deployer release).
						if ( null !== $real && is_dir( $real ) && 0 !== strpos( $real . '/', $abspath . '/' ) ) {
							$linked[] = $real;
						}
						return false;
					}
					$found = "$path is a link outside the WordPress directory";
					return 'stop';
				}
				if ( 'dir' === $type ) {
					if ( $inside ) {
						if ( $path !== $abspath && ( file_exists( "$path/wp-settings.php" ) || is_dir( "$path/wp-includes" ) ) ) {
							$found = "$path holds another WordPress install";
							return 'stop';
						}
						return true;
					}
					// The www-data home in /var/www (wp-cli packages, composer cache) is not site code; other dot-directories are
					// walked like any other (nginx serves e.g. .well-known).
					if ( dirname( $path ) === $code && in_array( $name, self::HOME_DOTDIRS, true ) ) {
						return false;
					}
					// Old releases beside the live one are out of scope only when nginx doesn't serve them (root at current/ or deeper).
					if ( null !== $releases && dirname( $path ) === $releases && $path !== $live ) {
						if ( 0 === strpos( $path . '/', $top . '/' ) ) {
							$found = "!nginx serves $top, which also exposes old releases (" . substr( $path, strlen( $top ) + 1 ) . "); recreate (or clone) the site with `--public-dir=current`, or remove old releases";
							return 'stop';
						}
						return false;
					}
					return true;
				}
				if ( ! $inside && self::php_name( $name ) && ! in_array( $path, $allowed, true ) ) {
					// Decided after the walk: a link from inside WordPress to its directory may still turn up.
					$outside[] = $path;
					return count( $outside ) >= 1000 ? 'stop' : false;
				}
				return false;
			}
		);
		if ( null !== $error ) {
			return "can't look through the site's files: $error";
		}
		if ( null === $found ) {
			foreach ( $outside as $path ) {
				$covered = false;
				foreach ( $linked as $target ) {
					$covered = $covered || 0 === strpos( $path, $target . '/' );
				}
				if ( ! $covered ) {
					$found = "$path is PHP outside the WordPress directory" . ( null === $abspath ? ' (there is no WordPress this check recognises)' : '' );
					break;
				}
			}
		}

		if ( null === $found ) {
			return $user_ini;
		}

		return '!' === $found[0] ? self::clean( substr( $found, 1 ) ) : self::clean( $found ) . '; this check can\'t scan that layout';
	}

	/**
	 * Compares every code file in app/ with the scanner's manifest (/out/manifest.json, INTERFACE.md "File manifest"):
	 * each must be in `checked` or `not_checked` (by inode, or under a not_checked directory), or one of the exact exempt
	 * shapes of "Cross-check". Code is what the manifest says it is; the manifest's own exemptions count only where EE's
	 * shape check agrees.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $out  The scan's /out directory on the host.
	 *
	 * @return array manifest (bool), unlisted (up to 10 container paths), unlisted_more (int), walk (ok|failed, null when
	 *               no walk ran), walk_error (string|null), error (string|null: why there is no positive verdict besides unlisted).
	 */
	protected function cross_check( $code, $out ) {
		$result = [ 'manifest' => false, 'unlisted' => [], 'unlisted_more' => 0, 'walk' => null, 'walk_error' => null, 'error' => null ];
		$m      = $this->read_manifest( $out );
		if ( ! is_array( $m ) ) {
			$result['error'] = $m;
			return $result;
		}
		$rules = $this->manifest_rules( $code, $m );
		if ( is_string( $rules ) ) {
			$result['error'] = $rules;
			return $result;
		}
		$result['manifest'] = true;
		$unlisted           = [];
		$count              = 0;
		$seen               = [];
		$searchable         = [ $code => self::uid33_may( @lstat( $code ), 0100 ) ]; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
		$covered            = function ( $path, array $stat, $phar ) use ( $code, $rules ) {
			$ino = $stat['dev'] . ':' . $stat['ino'];
			if ( ( ! $phar && isset( $rules['checked'][ $ino ] ) ) || isset( $rules['nc_files'][ $ino ] ) || null !== self::under_any( $path, $code, $rules['nc_dirs'] ) ) {
				return true;
			}
			return call_user_func( $rules['exempt'], $path );
		};
		$walk               = $this->walk_tree(
			$code,
			function ( $path, $name, $type, $stat = false ) use ( $code, $rules, $covered, &$searchable, &$seen, &$unlisted, &$count ) {
				if ( 'dir' === $type ) {
					$searchable[ $path ] = $searchable[ dirname( $path ) ] && self::uid33_may( $stat, 0100 );
					return true;
				}
				if ( 'link' === $type ) {
					// A code-named link runs its target as code, whatever the target is called.
					if ( ! preg_match( $rules['code_name'], $name ) && ! preg_match( $rules['phar'], $name ) ) {
						return false;
					}
					$target = self::resolve_in( $code, substr( $path, strlen( $code ) + 1 ) );
					$stat   = null === $target ? false : @lstat( $target ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
					if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
						return false;
					}
					$phar = 1 === preg_match( $rules['phar'], $name ) || 1 === preg_match( $rules['phar'], basename( $target ) );
					$path = $target;
				} elseif ( 'file' !== $type ) {
					return false;
				} else {
					$phar = 1 === preg_match( $rules['phar'], $name );
					if ( ! $phar && ! preg_match( $rules['code_name'], $name ) && ! $this->manifest_sniffs( $path, $name, $stat, $searchable[ dirname( $path ) ], $rules ) ) {
						return false;
					}
				}
				// A file reached again (through a link, or a hard link) counts once.
				$ino = $stat['dev'] . ':' . $stat['ino'];
				if ( isset( $seen[ $ino ] ) || $covered( $path, $stat, $phar ) ) {
					return false;
				}
				$seen[ $ino ] = true;
				if ( ++$count <= self::NOT_CHECKED_STDOUT ) {
					$unlisted[] = self::cut( self::clean( '/var/www' . substr( $path, strlen( $code ) ) ) );
				}
				return false;
			}
		);
		$result['walk'] = null === $walk ? 'ok' : 'failed';
		if ( null !== $walk ) {
			$result['walk_error'] = self::cut( $walk );
			$result['error']      = "can't list the site's files to compare with what the scanner read: $walk";
		}
		$result['unlisted']      = $unlisted;
		$result['unlisted_more'] = max( 0, $count - self::NOT_CHECKED_STDOUT );

		return $result;
	}

	/**
	 * Reads and validates /out/manifest.json; paths written `b64:` are decoded.
	 *
	 * @param string $out The scan's /out directory on the host.
	 *
	 * @return array|string|null The manifest; null when there is none; why it can't be used.
	 */
	protected function read_manifest( $out ) {
		$file = $out . '/manifest.json';
		clearstatcache( true, $file );
		if ( false === @lstat( $file ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- none is an older scanner.
			return null;
		}
		$bad = "the scanner's list of the files it read can't be used";
		try {
			self::raise_memory_limit();
			$raw = \EE\Site\Utils\get_container_file_contents( $file, $out, self::REPORT_MAX );
			if ( ! $this->make_room( self::decode_cost( $raw ) ) ) {
				return "$bad: it needs more memory to read than is available";
			}
			$m = json_decode( $raw, true );
		} catch ( \Exception $e ) {
			return "$bad: " . self::clean( $e->getMessage() );
		}
		if ( ! is_array( $m ) || ! array_key_exists( 'v', $m ) ) {
			return "$bad: it isn't valid";
		}
		if ( 1 !== $m['v'] ) {
			return "$bad: its version isn't one this check knows";
		}
		$strings = function ( $list ) {
			if ( ! is_array( $list ) || array_values( $list ) !== $list ) {
				return false;
			}
			foreach ( $list as $item ) {
				if ( ! is_string( $item ) || '' === $item ) {
					return false;
				}
			}
			return true;
		};
		$regex   = function ( $value ) {
			return is_string( $value ) && false !== @preg_match( $value, '' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is false.
		};
		$sniff   = isset( $m['sniff'] ) ? $m['sniff'] : null;
		$core    = isset( $m['core'] ) ? $m['core'] : null;
		$valid   = isset( $m['code_extensions'], $m['phar_regex'], $m['roots'], $m['excluded'], $m['old_releases'], $m['checked'], $m['not_checked'] )
			&& $strings( $m['code_extensions'] ) && $m['code_extensions'] && isset( $m['extensions_case_insensitive'] ) && is_bool( $m['extensions_case_insensitive'] ) && $regex( $m['phar_regex'] )
			// No sniff (null) would switch content sniffing off: the scanner always writes one.
			&& is_array( $sniff ) && isset( $sniff['bytes'], $sniff['regex'], $sniff['exclude_extensions'], $sniff['scope'] ) && is_int( $sniff['bytes'] ) && $sniff['bytes'] > 0
			&& $regex( $sniff['regex'] ) && is_array( $sniff['exclude_extensions'] ) && ( ! $sniff['exclude_extensions'] || $strings( $sniff['exclude_extensions'] ) )
			&& is_array( $core ) && array_key_exists( 'root', $core ) && ( null === $core['root'] || ( is_string( $core['root'] ) && '' !== $core['root'] ) ) && isset( $core['dirs'], $core['files'] ) && $strings( $core['dirs'] ) && $strings( $core['files'] );
		foreach ( [ 'roots', 'excluded', 'old_releases', 'checked', 'not_checked' ] as $key ) {
			$valid = $valid && ( [] === $m[ $key ] || $strings( $m[ $key ] ) );
		}
		if ( ! $valid ) {
			return "$bad: it isn't valid";
		}
		// Only scopes this check reproduces exactly; any other fails closed.
		if ( 'except_skipped_dirs' === $sniff['scope'] ) {
			if ( ! isset( $sniff['skipped_dirs'] ) || ! ( [] === $sniff['skipped_dirs'] || $strings( $sniff['skipped_dirs'] ) ) ) {
				return "$bad: it isn't valid";
			}
		} elseif ( 'all' === $sniff['scope'] ) {
			$m['sniff']['skipped_dirs'] = [];
		} else {
			return "$bad: its content sniff scope isn't one this check knows";
		}
		$m['sniff']['exclude_extensions'] = array_map( 'strtolower', $sniff['exclude_extensions'] );
		// Every path is a container path under /var/www; one that isn't valid UTF-8 comes as `b64:` + base64 of its bytes.
		$decode = function ( $path ) {
			if ( 0 === strpos( $path, 'b64:' ) ) {
				$path = base64_decode( substr( $path, 4 ), true );
			}
			return is_string( $path ) && 0 === strpos( $path, '/var/www/' ) && false === strpos( $path, "\0" ) ? $path : null;
		};
		$lists  = [ [ 'roots' ], [ 'excluded' ], [ 'old_releases' ], [ 'checked' ], [ 'not_checked' ], [ 'sniff', 'skipped_dirs' ] ];
		foreach ( $lists as $keys ) {
			$list = 1 === count( $keys ) ? $m[ $keys[0] ] : $m['sniff']['skipped_dirs'];
			foreach ( $list as $i => $path ) {
				$list[ $i ] = $decode( $path );
				if ( null === $list[ $i ] ) {
					return "$bad: it names a path outside the site";
				}
			}
			if ( 1 === count( $keys ) ) {
				$m[ $keys[0] ] = $list;
			} else {
				$m['sniff']['skipped_dirs'] = $list;
			}
		}
		if ( null !== $core['root'] ) {
			$m['core']['root'] = $decode( $core['root'] );
			if ( null === $m['core']['root'] ) {
				return "$bad: it names a path outside the site";
			}
		}

		return $m;
	}

	/**
	 * The manifest turned into host lookups, after checking its scope against the layout EE resolved. A manifest exemption
	 * (old_releases, core) counts only where EE's exact shape check agrees; when they disagree, nothing is positive.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param array  $m    read_manifest().
	 *
	 * @return array|string Lookups, or why the manifest and this check disagree.
	 */
	protected function manifest_rules( $code, array $m ) {
		$layout   = null !== $this->cross_layout ? $this->cross_layout : [
			'wp'      => false,
			'scan'    => null,
			'top'     => null,
			'abspath' => null,
			'index'   => null,
		];
		$disagree = "the scanner's list of the files it read disagrees with this check about the site's %s, so this check can't tell that it read all the site's code";
		$checked  = [];
		$nc_files = [];
		$nc_dirs  = [];
		foreach ( $m['checked'] as $path ) {
			$stat = self::manifest_stat( $code, $path );
			if ( false !== $stat && 0100000 === ( $stat['mode'] & 0170000 ) ) {
				$checked[ $stat['dev'] . ':' . $stat['ino'] ] = true;
			}
		}
		foreach ( $m['not_checked'] as $path ) {
			$host = self::manifest_dir( $code, $path );
			if ( null !== $host ) {
				$nc_dirs[ $host ] = true;
				continue;
			}
			$stat = '/' === substr( $path, -1 ) ? false : self::manifest_stat( $code, $path );
			if ( false !== $stat && 0100000 === ( $stat['mode'] & 0170000 ) ) {
				$nc_files[ $stat['dev'] . ':' . $stat['ino'] ] = true;
			}
		}
		// The sniff isn't applied below the directories the scanner searched by name only (accepted residual R-SNIFF).
		$skipped = [];
		foreach ( $m['sniff']['skipped_dirs'] as $path ) {
			$host = self::manifest_dir( $code, $path );
			if ( null !== $host && in_array( basename( $host ), self::SCANNER_SKIP_NAMES, true ) ) {
				$skipped[ $host ] = true;
			}
		}

		// roots: the WordPress root EE passed (wp), or all of /var/www (php).
		$roots = [];
		foreach ( $m['roots'] as $path ) {
			$roots[] = self::manifest_dir( $code, $path );
		}
		$expected = $layout['wp'] ? $layout['scan'] : $code;
		// No root on either side covers nothing, so any code file in app/ is still unlisted.
		if ( ( null === $expected ? [] : [ $expected ] ) !== $roots ) {
			return sprintf( $disagree, 'scanned directory' );
		}

		// (b) core: only the live WordPress root EE resolved, and only what both lists call core (index.php is scanned).
		$abspath = $layout['abspath'];
		$core    = null === $m['core']['root'] ? null : self::manifest_dir( $code, $m['core']['root'] );
		if ( ( null === $m['core']['root'] ) !== ( null === $abspath ) || ( null !== $abspath && $core !== $abspath ) ) {
			return sprintf( $disagree, 'WordPress core' );
		}
		$core_dirs  = array_values( array_intersect( $m['core']['dirs'], [ 'wp-admin', 'wp-includes' ] ) );
		$core_files = array_values( array_intersect( $m['core']['files'], self::CORE_FILES ) );

		$old = $this->old_release_dirs( $code );
		// The scanner names old releases only for php sites, which it walks whole; a wp scan never leaves the live root.
		$listed = [];
		foreach ( $m['old_releases'] as $path ) {
			$host = self::manifest_dir( $code, $path );
			if ( $layout['wp'] || null === $host || ! isset( $old[ $host ] ) ) {
				return sprintf( $disagree, 'old releases' );
			}
			$listed[ $host ] = true;
		}
		if ( ! $layout['wp'] ) {
			$old = $listed;
		}

		// (c) EE's app/wp-config.php when WordPress loads another config, validated before the scan and read by the scanner.
		$loaded        = isset( $layout['config'] ) ? $layout['config'] : null;
		$loaded_stat   = null === $loaded ? false : @lstat( $loaded ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
		$unused_config = null !== $abspath && $abspath !== $code && false !== $loaded_stat && isset( $checked[ $loaded_stat['dev'] . ':' . $loaded_stat['ino'] ] ) ? "$code/wp-config.php" : null;
		$index         = $layout['index'];
		$exempt        = function ( $path ) use ( $code, $old, $abspath, $core_dirs, $core_files, $unused_config, $index ) {
			if ( $old && null !== self::under_any( $path, $code, $old ) ) {
				return true;
			}
			if ( null !== $abspath && 0 === strpos( $path, $abspath . '/' ) ) {
				$rel = substr( $path, strlen( $abspath ) + 1 );
				$top = strstr( $rel, '/', true );
				if ( false === $top ? in_array( $rel, $core_files, true ) : in_array( $top, $core_dirs, true ) ) {
					return true;
				}
			}

			return $path === $unused_config || $path === $index;
		};
		$names         = array_map(
			function ( $ext ) {
				return preg_quote( $ext, '/' );
			},
			$m['code_extensions']
		);

		return [
			'code'       => $code,
			'checked'    => $checked,
			'nc_files'   => $nc_files,
			'nc_dirs'    => $nc_dirs,
			'skipped'    => $skipped,
			'code_name'  => '/\.(?:' . implode( '|', $names ) . ')\z/' . ( $m['extensions_case_insensitive'] ? 'i' : '' ),
			'phar'       => $m['phar_regex'],
			'sniff'      => $m['sniff'],
			'exempt'     => $exempt,
		];
	}

	/**
	 * Exempt shape (a): the old releases beside the live release in releases/, only when nginx's root is the live release
	 * or below it (--public-dir=current).
	 *
	 * @param string $code Real path of the site's app/.
	 *
	 * @return array Real host directories as keys.
	 */
	protected function old_release_dirs( $code ) {
		$top     = null === $this->cross_layout ? null : $this->cross_layout['top'];
		$abspath = null === $this->cross_layout ? null : $this->cross_layout['abspath'];
		$live    = null;
		for ( $dir = null !== $abspath ? $abspath : $top; null !== $dir && strlen( $dir ) > strlen( $code ); $dir = dirname( $dir ) ) {
			if ( 'releases' === basename( dirname( $dir ) ) ) {
				$live = $dir;
				break;
			}
		}
		$old = [];
		if ( null !== $live && null !== $top && 0 === strpos( $top . '/', $live . '/' ) ) {
			foreach ( (array) @scandir( dirname( $live ) ) as $name ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- unreadable lists nothing.
				$dir = dirname( $live ) . '/' . $name;
				if ( '.' !== $name && '..' !== $name && $dir !== $live && ! is_link( $dir ) && is_dir( $dir ) ) {
					$old[ $dir ] = true;
				}
			}
		}

		return $old;
	}

	/**
	 * lstat() of a manifest path on the host, only when it names the same file there: the container path maps to app/ with
	 * no link on the way (a host lookup would resolve an absolute link against the host).
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $path Container path under /var/www/.
	 *
	 * @return array|false
	 */
	protected static function manifest_stat( $code, $path ) {
		$host = $code . substr( rtrim( $path, '/' ), strlen( '/var/www' ) );
		$real = @realpath( $host ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.

		return $real === $host ? @lstat( $host ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
	}

	/**
	 * @param string $code Real path of the site's app/.
	 * @param string $path Container path; a directory ends with `/`.
	 *
	 * @return string|null Real host directory it names, or null when it isn't one (then it covers nothing).
	 */
	protected static function manifest_dir( $code, $path ) {
		if ( '/' !== substr( $path, -1 ) ) {
			return null;
		}
		$stat = self::manifest_stat( $code, $path );

		return false !== $stat && 0040000 === ( $stat['mode'] & 0170000 ) ? rtrim( $code . substr( $path, strlen( '/var/www' ) ), '/' ) : null;
	}

	/**
	 * @param string $path Real host path.
	 * @param string $code Real path of the site's app/.
	 * @param array  $dirs Real host directories as keys.
	 *
	 * @return string|null The directory of $dirs that $path is in (any depth), or null.
	 */
	protected static function under_any( $path, $code, array $dirs ) {
		for ( $dir = dirname( $path ); strlen( $dir ) >= strlen( $code ); $dir = dirname( $dir ) ) {
			if ( isset( $dirs[ $dir ] ) ) {
				return $dir;
			}
			if ( $dir === $code ) {
				break;
			}
		}

		return null;
	}

	/**
	 * Whether uid 33 (PHP-FPM's www-data, the scanner's user) may have a permission. Only the bits are read, and only "no"
	 * is certain: the owner class counts when uid 33 owns it, and group or other bits (which bound every ACL entry) say maybe.
	 *
	 * @param array|false $stat lstat().
	 * @param int         $bit  0400 read or 0100 search, in owner position.
	 *
	 * @return bool
	 */
	protected static function uid33_may( $stat, $bit ) {
		if ( false === $stat ) {
			return true;
		}
		$mode = $stat['mode'];

		return 0 !== ( $mode & ( ( $bit >> 3 ) | ( $bit >> 6 ) ) ) || ( 33 === $stat['uid'] && 0 !== ( $mode & $bit ) );
	}

	/**
	 * Whether a regular file without a code name is code by the manifest's content sniff (rule 3 of "File manifest").
	 *
	 * @param string      $path       Real host path.
	 * @param string      $name       File name.
	 * @param array|false $stat       Its lstat().
	 * @param bool        $searchable Whether uid 33 may reach its directory.
	 * @param array       $rules      manifest_rules().
	 *
	 * @return bool
	 */
	protected function manifest_sniffs( $path, $name, $stat, $searchable, array $rules ) {
		$sniff = $rules['sniff'];
		if ( in_array( strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ), $sniff['exclude_extensions'], true ) || null !== self::under_any( $path, $rules['code'], $rules['skipped'] ) ) {
			return false;
		}
		// A file uid 33 can't read can't run under PHP-FPM, and the scanner doesn't sniff it either.
		if ( ! $searchable || ! self::uid33_may( $stat, 0400 ) ) {
			return false;
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- unreadable fails closed.
		if ( false === $handle ) {
			return true;
		}
		$head = (string) fread( $handle, min( $sniff['bytes'], 1048576 ) );
		fclose( $handle );

		return 1 === preg_match( $sniff['regex'], $head );
	}

	/**
	 * @param array|null $cross cross_check() result.
	 *
	 * @return bool Whether the cross-check allows a positive verdict.
	 */
	protected static function cross_ok( $cross ) {
		return null !== $cross && $cross['manifest'] && null === $cross['error'] && ! $cross['unlisted'] && 0 === $cross['unlisted_more'];
	}

	/**
	 * @param array|null $cross cross_check() result.
	 *
	 * @return string Why the cross-check refuses a positive verdict.
	 */
	protected static function cross_text( $cross ) {
		if ( null === $cross || ( ! $cross['manifest'] && null === $cross['error'] ) ) {
			return "the scanner gave no list of the files it read (an older scanner image), so this check can't tell that it read all the site's code";
		}
		if ( null !== $cross['error'] ) {
			return $cross['error'];
		}
		$count = count( $cross['unlisted'] ) + $cross['unlisted_more'];

		return sprintf( '%s the scanner didn\'t read: %s%s', self::count_of( $count, 'code file' ), implode( ', ', $cross['unlisted'] ), $cross['unlisted_more'] > 0 ? ", and {$cross['unlisted_more']} more" : '' );
	}

	/**
	 * Whether a link target is (in) another WordPress install: the target, any of its ancestors up to app/, or (for a
	 * directory) any direct child holds wp-settings.php or wp-includes/. The live WordPress root doesn't count.
	 *
	 * @param string $code    Real path of the site's app/.
	 * @param string $real    Real link target.
	 * @param string $abspath Real WordPress root.
	 *
	 * @return bool
	 */
	protected static function reaches_install( $code, $real, $abspath ) {
		$install = function ( $dir ) use ( $abspath ) {
			return $dir !== $abspath && is_dir( $dir ) && ( is_file( "$dir/wp-settings.php" ) || is_dir( "$dir/wp-includes" ) );
		};
		for ( $dir = $real; strlen( $dir ) >= strlen( $code ); $dir = dirname( $dir ) ) {
			if ( $install( $dir ) ) {
				return true;
			}
			if ( $dir === $code ) {
				break;
			}
		}
		if ( is_dir( $real ) && ! is_link( $real ) ) {
			foreach ( (array) @scandir( $real ) as $name ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- unreadable lists nothing.
				if ( '.' !== $name && '..' !== $name && ! is_link( "$real/$name" ) && $install( "$real/$name" ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Walks a directory tree without following links, bounded in time: $visit( $path, $name, file|dir|link|other, lstat ) returns
	 * 'stop' to stop (true also stops for a non-directory), and for a directory whether to go into it.
	 *
	 * @param string   $top   Real directory.
	 * @param callable $visit See above.
	 *
	 * @return string|null Why the walk could not finish, or null.
	 */
	protected function walk_tree( $top, callable $visit ) {
		$todo     = [ $top ];
		$seconds  = $this->walk_seconds();
		$deadline = microtime( true ) + $seconds;
		while ( $todo ) {
			$dir = array_pop( $todo );
			if ( microtime( true ) > $deadline ) {
				return 'it takes more than ' . $seconds . ' s to list';
			}
			// A long walk still answers a signal or a --cancel (run() reports cancelled then).
			if ( function_exists( 'pcntl_signal_dispatch' ) ) {
				null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
			}
			if ( $this->cancel_requested() ) {
				return 'a cancel was requested';
			}
			$entries = @scandir( $dir ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
			if ( false === $entries ) {
				return self::clean( $dir ) . ' can\'t be listed';
			}
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = "$dir/$name";
				$stat = @lstat( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
				$mode = false === $stat ? 0 : $stat['mode'] & 0170000;
				$type = 0120000 === $mode ? 'link' : ( 0040000 === $mode ? 'dir' : ( 0100000 === $mode ? 'file' : 'other' ) );
				$go   = $visit( $path, $name, $type, $stat );
				if ( 'stop' === $go || ( 'dir' !== $type && true === $go ) ) {
					return null;
				}
				if ( 'dir' === $type && $go ) {
					$todo[] = $path;
				}
			}
		}

		return null;
	}

	/**
	 * @return int The time bound of a walk of the site's files.
	 */
	protected function walk_seconds() {
		return self::WALK_SECONDS;
	}

	/**
	 * @param string $name File name.
	 *
	 * @return bool Whether the scanner reads it as PHP code (its CODE_EXTENSIONS, any case).
	 */
	protected static function php_name( $name ) {
		return 1 === preg_match( '/\.(?:php|inc|phtml|module|install|theme|engine|php5|php7)$/iD', $name );
	}

	/**
	 * The PHP settings that load or change code outside the scan, wherever the site's PHP takes them from: the .user.ini
	 * files found, the site's php.ini, conf.d/*.ini, php-fpm.conf and pool files. Every assignment counts.
	 *
	 * @param object     $site_data Site record.
	 * @param string     $code      Real path of the site's app/.
	 * @param array      $user_ini  .user.ini files found ([ path, type ] each).
	 * @param array|null $layout    wp_layout_of() result, or null for a php site.
	 *
	 * @return string|null tool_error detail.
	 */
	protected function prepend_check( $site_data, $code, array $user_ini, $layout ) {
		$conf  = $site_data->site_fs_path . '/config/php';
		$files = [];
		// php-fpm-fcgi.ini replaces php.ini for FPM when it exists; PHP's conf.d scan loads dot-files too.
		$scanned = array_map(
			function ( $name ) use ( $conf ) {
				return "$conf/php/conf.d/$name";
			},
			preg_grep( '/\.ini$/', (array) @scandir( "$conf/php/conf.d" ) ) // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing is fine.
		);
		foreach ( array_merge( [ "$conf/php/php.ini", "$conf/php/php-fpm-fcgi.ini" ], $scanned ) as $file ) {
			$files[ $file ] = 'ini';
		}
		foreach ( array_merge( [ "$conf/php-fpm.conf" ], (array) glob( "$conf/php-fpm.d/*.conf" ) ) as $file ) {
			$files[ $file ] = 'fpm';
		}
		foreach ( $user_ini as list( $path, $type ) ) {
			$files[ $path ] = 'file' === $type ? 'ini' : 'bad';
		}
		$app = $site_data->site_fs_path . '/app/';
		foreach ( $files as $file => $kind ) {
			if ( ! file_exists( $file ) && ! is_link( $file ) ) {
				continue;
			}
			$shown = self::clean( str_replace( "$code/", $app, $file ) );
			$text  = 'bad' === $kind || ! is_file( $file ) || filesize( $file ) > self::CONFIG_MAX ? false : @file_get_contents( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
			if ( false === $text ) {
				return "can't read $shown, which may set a file PHP runs before WordPress";
			}
			foreach ( self::php_settings( $text, $kind ) as list( $key, $value, $quoted ) ) {
				if ( ! $this->php_setting_ok( $code, $key, $value, $layout, $quoted ) ) {
					return "$shown sets $key to " . self::clean( '' === $value ? '""' : $value ) . ', which this check can\'t confirm';
				}
			}
		}

		return null;
	}

	/**
	 * Every assignment of the settings that load or change code (auto_prepend_file, auto_append_file, opcache.preload,
	 * include_path, user_ini.filename, zend.multibyte, zend.script_encoding) and FPM's include= and
	 * security.limit_extensions, in file order.
	 *
	 * @param string $text Its text.
	 * @param string $kind ini or fpm.
	 *
	 * @return array[] [ key, value (unquoted), whether it was quoted ] each.
	 */
	public static function php_settings( $text, $kind ) {
		// PHP's ini scanner ends a line at \r\n, \n or a bare \r; a UTF-8 BOM before the first key is skipped.
		$text    = str_replace( [ "\r\n", "\r" ], "\n", preg_replace( '/^\xEF\xBB\xBF/', '', $text ) );
		$keys    = '(auto_prepend_file|auto_append_file|opcache\.preload|include_path|user_ini\.filename|zend\.multibyte|zend\.script_encoding)';
		$pattern = 'fpm' === $kind
			? '/^[ \t]*(?:php(?:_admin)?_(?:value|flag)[ \t]*\[[ \t]*["\']?' . $keys . '["\']?[ \t]*\]|(include|security\.limit_extensions))[ \t]*=[ \t]*([^\n]*)$/mi'
			: '/^[ \t]*' . $keys . '()[ \t]*=[ \t]*([^\n]*)$/mi';
		$out     = [];
		preg_match_all( $pattern, $text, $all, PREG_SET_ORDER );
		foreach ( $all as $m ) {
			$value  = trim( $m[3] );
			$quoted = false;
			if ( preg_match( '/^(["\'])(.*?)\1(.*)$/', $value, $q ) ) {
				$rest = trim( $q[3] );
				// PHP joins adjacent parts ("/a".php): anything but a comment after the quote keeps the raw text, never matched.
				$value  = '' === $rest || ';' === $rest[0] ? $q[2] : $value;
				$quoted = true;
			} else {
				$value = trim( preg_replace( '/[ \t]*;.*$/', '', $value ) );
			}
			$out[] = [ strtolower( '' !== $m[1] ? $m[1] : $m[2] ), $value, $quoted ];
		}

		return $out;
	}


	/**
	 * @param string     $code   Real path of the site's app/.
	 * @param string     $key    Setting.
	 * @param string     $value  Its value.
	 * @param array|null $layout wp_layout_of() result, or null for a php site.
	 * @param bool       $quoted Whether the value was quoted.
	 *
	 * @return bool
	 */
	protected function php_setting_ok( $code, $key, $value, $layout, $quoted = false ) {
		// Only the bare keywords mean "nothing" to PHP; a quoted "none", or 0, is a file name.
		$off = '' === $value || ! $quoted && in_array( strtolower( $value ), [ 'none', 'off', 'no', 'false', 'null' ], true );
		switch ( $key ) {
			case 'include':
				// php-fpm.conf of EE's images: relative before PHP 8.6, absolute from 8.6.
				return in_array( $value, [ 'etc/php-fpm.d/*.conf', '/usr/local/etc/php-fpm.d/*.conf' ], true );
			case 'security.limit_extensions':
				return '.php' === $value;
			case 'user_ini.filename':
				return '' === $value || '.user.ini' === $value;
			case 'zend.multibyte':
				return $off;
			case 'auto_prepend_file':
				break;
			default:
				return $off;
		}
		if ( $off ) {
			return true;
		}
		// Resolved like the container resolves it (links first, then `..`), never collapsed as text.
		if ( 0 !== strpos( $value, '/var/www/' ) ) {
			return false;
		}
		$file = $this->site_file( $code, substr( $value, strlen( '/var/www/' ) ), self::PHP_SOURCE_MAX );
		if ( 'ok' !== $file['state'] ) {
			return false;
		}
		if ( null === $layout ) {
			return $this->scanned_by_php_scan( $code, $file['real'] );
		}

		// Any loader the scan reads (Wordfence's wordfence-waf.php, NinjaFirewall's, …).
		return self::scanned_by_wp_scan( $file['real'], $layout['abspath'] );
	}

	/**
	 * nginx and PHP-FPM settings that could run other code than the scan reads: nginx's own files (config/nginx/, conf.d/,
	 * custom/), and php-fpm.conf's and the pools' include= (in prepend_check()).
	 *
	 * @param object $site_data Site record.
	 *
	 * @return string|null tool_error detail.
	 */
	protected function server_config_check( $site_data ) {
		$dir   = $site_data->site_fs_path . '/config/nginx';
		$files = array_merge( (array) glob( "$dir/*" ), (array) glob( "$dir/conf.d/*.conf" ), (array) glob( "$dir/custom/*.conf" ) );
		foreach ( $files as $file ) {
			if ( is_dir( $file ) && ! is_link( $file ) || dirname( $file ) === $dir && preg_match( '/\.(?:default|types)$|^(?:koi-|win-|uwsgi_params|scgi_params)/', basename( $file ) ) ) {
				continue;
			}
			$text = is_file( $file ) && filesize( $file ) <= self::CONFIG_MAX ? @file_get_contents( $file ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
			if ( false === $text ) {
				return "can't read $file, which may change what nginx runs";
			}
			$custom = 'custom' === basename( dirname( $file ) );
			if ( $custom && 'admin-tools.conf' === basename( $file ) && self::ADMIN_TOOLS_CONF === str_replace( "\r\n", "\n", $text ) ) {
				continue;
			}
			$why = self::nginx_refusal( $text, $file === $dir . '/conf.d/main.conf', $custom );
			if ( null !== $why ) {
				return "$file $why, which this check can't confirm";
			}
		}

		return null;
	}

	/**
	 * What in an nginx file could make it run other code than the scan reads.
	 *
	 * @param string $text   File text.
	 * @param bool   $main   Whether it is conf.d/main.conf (its single root is read by served_root()).
	 * @param bool   $custom Whether it is in custom/.
	 *
	 * @return string|null
	 */
	public static function nginx_refusal( $text, $main, $custom ) {
		$text = preg_replace( '/#[^\r\n]*/', '', $text );
		if ( preg_match( '/\bPHP_(?:ADMIN_)?VALUE\b/i', $text ) ) {
			return 'sets PHP_VALUE';
		}
		preg_match_all( '/(?:^|[;{}])\s*fastcgi_param\s+(SCRIPT_FILENAME|DOCUMENT_ROOT)\s+([^;]*);/i', $text, $all, PREG_SET_ORDER );
		foreach ( $all as $m ) {
			$value = trim( $m[2] );
			if ( ! in_array( $value, 'DOCUMENT_ROOT' === strtoupper( $m[1] ) ? [ '$document_root' ] : [ '$request_filename', '$document_root$fastcgi_script_name' ], true ) ) {
				return "sets {$m[1]} to $value";
			}
		}
		// Only what EE's own nginx files include, in every version EE has shipped.
		preg_match_all( '/(?:^|[;{}])\s*include\s+([^;]+?)\s*;/', $text, $all, PREG_SET_ORDER );
		foreach ( $all as $m ) {
			if ( ! in_array( $m[1], self::NGINX_INCLUDES, true ) ) {
				return "includes {$m[1]}";
			}
		}
		if ( ! $main && preg_match( '/(?:^|[;{}])\s*(root|alias)\s/', $text, $m ) ) {
			return "sets $m[1]";
		}
		if ( $custom && preg_match( '/(?:^|[;{}])\s*fastcgi_pass\s/', $text ) ) {
			return 'passes requests to PHP';
		}

		return null;
	}

	/**
	 * Code the site's php container gets from mounts below /var/www (docker-compose-custom.yml, user-docker-compose/), mounted
	 * read-only at the same place so the scanner sees what PHP runs.
	 *
	 * @param object $site_data Site record.
	 * @param string $code      Real path of the site's app/.
	 *
	 * @return array|string --mount arguments, or a tool_error detail.
	 */
	protected function extra_mounts( $site_data, $code ) {
		$this->extra_mounts = [];
		$ps                 = $this->docker( [ 'docker', 'ps', '-q', '--no-trunc', '--filter', 'label=io.easyengine.site=' . $this->site, '--filter', 'label=com.docker.compose.service=php' ], 60 );
		if ( 0 !== $ps['code'] ) {
			return 'could not list the site\'s php container: ' . self::first_line( $ps['stderr'] );
		}
		$ids = preg_split( '/\s+/', trim( $ps['stdout'] ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $ids ) {
			// Not running (a disabled site): only its compose files say what it mounts.
			foreach ( array_merge( [ $site_data->site_fs_path . '/docker-compose-custom.yml' ], (array) glob( $site_data->site_fs_path . '/user-docker-compose/*.yml' ), (array) glob( $site_data->site_fs_path . '/user-docker-compose/*.yaml' ) ) as $file ) {
				$mounts = is_file( $file ) ? self::compose_mounts_code( $file ) : false;
				if ( null === $mounts ) {
					return "could not read $file; enable the site to check mounted code";
				}
				if ( $mounts ) {
					return "$file may change the site's php service, which this check can only see while the site runs; enable the site and run it again";
				}
			}

			return [];
		}
		$inspect = $this->docker( [ 'docker', 'inspect', '--format', '{{json .}}', $ids[0] ], 60 );
		$info    = 0 === $inspect['code'] ? json_decode( trim( $inspect['stdout'] ), true ) : null;
		$mounts  = isset( $info['Mounts'] ) && is_array( $info['Mounts'] ) ? $info['Mounts'] : null;
		if ( null === $mounts ) {
			return 'could not read the mounts of the site\'s php container';
		}
		$detail = $this->container_config_check( $info );
		if ( null !== $detail ) {
			return $detail;
		}
		$admin = realpath( EE_ROOT_DIR . '/admin-tools' );
		// EE's own mounts outside /var/www: PHP's config, its logs and admin-tools' data.
		$known = [
			'/usr/local/etc'              => realpath( $site_data->site_fs_path . '/config/php' ),
			'/var/log/php'                => realpath( $site_data->site_fs_path . '/logs/php' ),
			'/etc/easyengine/admin-tools' => realpath( $site_data->site_fs_path . '/config/admin-tools' ),
			// macOS mounts only custom.ini instead of all of config/php.
			'/usr/local/etc/php/php/conf.d/custom.ini' => realpath( $site_data->site_fs_path . '/config/php/php/conf.d/custom.ini' ),
		];
		foreach ( $mounts as $mount ) {
			$dst = isset( $mount['Destination'] ) ? rtrim( (string) $mount['Destination'], '/' ) : '';
			$src = isset( $mount['Source'] ) ? (string) $mount['Source'] : '';
			if ( '/var/www' === $dst ) {
				// The code itself: it must be the volume app/ points at, or the scan would read other files than PHP runs.
				if ( realpath( $src ) !== $code ) {
					return 'the site\'s php container gets /var/www from ' . self::clean( $src ) . ', not the site\'s app/ (' . $code . ')';
				}
				continue;
			}
			// The New Relic daemon's socket: EE's global volume, never code.
			if ( '/run/newrelic' === $dst && isset( $mount['Type'], $mount['Name'] ) && 'volume' === $mount['Type'] && ( defined( 'GLOBAL_NEWRELIC_DAEMON' ) ? GLOBAL_NEWRELIC_DAEMON : 'global-newrelic-daemon' ) . '_newrelic_sock' === $mount['Name'] && $this->ee_plain_volume( $mount['Name'] ) ) {
				continue;
			}
			if ( isset( $known[ $dst ] ) ) {
				if ( false === $known[ $dst ] || realpath( $src ) !== $known[ $dst ] ) {
					return 'the site\'s php container gets ' . self::clean( $dst ) . ' from ' . self::clean( $src ) . ', not from EE\'s own directory for it';
				}
				continue;
			}
			if ( 0 !== strpos( $dst, '/var/www/' ) ) {
				return 'the site\'s php container mounts ' . self::clean( $dst ) . ' from ' . self::clean( $src ) . ', code this check doesn\'t scan';
			}
			// EE's own admin tools (ee site admin-tools): not the site's code.
			if ( '/var/www/htdocs/ee-admin' === $dst && false !== $admin && realpath( $src ) === $admin ) {
				continue;
			}
			// The mount point is in the htdocs volume (Docker made it there), so the read-only bind below can use it.
			if ( '' === $src || false !== strpbrk( $src . $dst, ",\"\n\r" ) || ! file_exists( $src ) || null === self::resolve_in( $code, substr( $dst, strlen( '/var/www/' ) ) ) ) {
				return 'the site\'s php container mounts ' . self::clean( $dst ) . ' from ' . self::clean( $src ) . ', which this check can\'t mount';
			}
			// The WordPress root and its config are read on the host before the scan: only a mount inside wp-content leaves them as read.
			if ( $this->wp_layout && 0 !== strpos( $dst . '/', $this->scan_path . '/wp-content/' ) ) {
				return 'the site\'s php container mounts ' . self::clean( $dst ) . ' over the WordPress files, which this check reads before it scans';
			}
			// Mounted code is seen only by the scanner, never by EE's app/ walk: it must land, after the kernel follows the
			// volume's links, where the scanner walks (wp: the root's wp-content; php: not a /var/www dot entry or an old release).
			$at = self::resolve_in( $code, substr( $dst, strlen( '/var/www/' ) ) );
			if ( $this->wp_layout ) {
				$content = self::resolve_in( $code, substr( $this->scan_path, strlen( '/var/www/' ) ) . '/wp-content' );
				$outside = null === $content || 0 !== strpos( $at . '/', $content . '/' );
			} else {
				$top     = (string) substr( $at, strlen( $code ) + 1 );
				$outside = '' === $top || '.' === $top[0] || null !== self::under_any( $at . '/x', $code, $this->old_release_dirs( $code ) );
			}
			if ( $outside ) {
				return 'the site\'s php container mounts ' . self::clean( $dst ) . ' at ' . self::clean( '/var/www' . substr( $at, strlen( $code ) ) ) . ', where the scanner doesn\'t look';
			}
			EE::debug( "php-compat: also mounting $src at $dst" );
			array_push( $this->extra_mounts, '--mount', "type=bind,src=$src,dst=$dst,readonly" );
		}

		return $this->extra_mounts;
	}

	/**
	 * Whether a named volume is a plain local volume EE made: its EasyEngine label, and no options (a bind or a remote
	 * filesystem under the same name would have them).
	 *
	 * @param string $name Volume name.
	 *
	 * @return bool
	 */
	protected function ee_plain_volume( $name ) {
		$run  = $this->docker( [ 'docker', 'volume', 'inspect', '--format', '{{json .}}', $name ], 60 );
		$info = 0 === $run['code'] ? json_decode( trim( $run['stdout'] ), true ) : null;

		return is_array( $info ) && isset( $info['Labels']['org.label-schema.vendor'] ) && 'EasyEngine' === $info['Labels']['org.label-schema.vendor']
			&& ( ! isset( $info['Driver'] ) || 'local' === $info['Driver'] ) && empty( $info['Options'] );
	}

	/**
	 * The php container must run as EE starts it: the image's command and entrypoint, and the image's environment plus
	 * only EE's own variables (so no PHPRC or PHP_INI_SCAN_DIR loads other PHP settings).
	 *
	 * @param array $info docker inspect of the container.
	 *
	 * @return string|null tool_error detail.
	 */
	protected function container_config_check( array $info ) {
		$image = isset( $info['Image'] ) ? (string) $info['Image'] : '';
		$run   = '' === $image ? null : $this->docker( [ 'docker', 'image', 'inspect', '--format', '{{json .Config}}', $image ], 60 );
		$own   = null !== $run && 0 === $run['code'] ? json_decode( trim( $run['stdout'] ), true ) : null;
		if ( ! is_array( $own ) || ! isset( $info['Config'] ) || ! is_array( $info['Config'] ) ) {
			return 'could not read how the site\'s php container is configured';
		}
		$config = $info['Config'];
		// The PHP it runs: easyengine/php8.4:<tag>; easyengine/php (latest) or any other image tells nothing.
		$this->container_php = isset( $config['Image'] ) && preg_match( '#^easyengine/php(\d+\.\d+):#', (string) $config['Image'], $m ) ? $m[1] : null;
		foreach ( [ 'Cmd', 'Entrypoint' ] as $key ) {
			if ( ( isset( $config[ $key ] ) ? $config[ $key ] : null ) !== ( isset( $own[ $key ] ) ? $own[ $key ] : null ) ) {
				return "the site's php container runs another " . strtolower( $key ) . ' than its image, which this check can\'t confirm';
			}
		}
		$image_env = [];
		foreach ( isset( $own['Env'] ) ? (array) $own['Env'] : [] as $pair ) {
			$image_env[ strtok( $pair, '=' ) ] = $pair;
		}
		foreach ( isset( $config['Env'] ) ? (array) $config['Env'] : [] as $pair ) {
			$name = (string) strtok( $pair, '=' );
			if ( isset( $image_env[ $name ] ) ? $image_env[ $name ] !== $pair : ! preg_match( '/^(?:WORDPRESS_DB_[A-Z_]+|DB_[A-Z_]+|MYSQL_[A-Z_]+|REDIS_HOST|USER_ID|GROUP_ID|VIRTUAL_HOST|NEWRELIC_[A-Z_]+)$/D', $name ) ) {
				return 'the site\'s php container sets ' . self::clean( $name ) . ', which this check can\'t confirm';
			}
		}

		return null;
	}

	/**
	 * Whether a compose file may change the php service of a stopped site, read without a YAML parser (the release phar has
	 * none): any `php` key (block or flow style, quoted or not), any merge key or anchor use, any `extends` or `include`, and
	 * any interpolation count. Only a running container shows what PHP really gets, so this never tries to tell harmless
	 * php settings from others.
	 *
	 * @param string $file Compose file.
	 *
	 * @return bool|null True when it may, false when it doesn't, null when it can't be read.
	 */
	protected static function compose_mounts_code( $file ) {
		$text = @file_get_contents( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- null when unreadable.
		if ( false === $text ) {
			return null;
		}
		$text = preg_replace( '/(^|\s)#[^\r\n]*/', '$1', $text );

		return 1 === preg_match( '/(?:^|[\s{,])["\']?php["\']?\s*:|<<\s*:|\*[A-Za-z0-9_-]|\b(?:extends|include)\s*:|\$/m', $text );
	}


	/**
	 * The root nginx serves (main.conf), or the public directory in EE's record when there is no main.conf.
	 *
	 * @param object $site_data Site record.
	 *
	 * @return string Path under /var/www/htdocs, or `!<tool_error detail>`.
	 */
	protected function served_root( $site_data ) {
		// `--public-dir=a//b` is stored as given; the scanner refuses `//`.
		$db   = rtrim( preg_replace( '#/+#', '/', (string) $site_data->site_container_fs_path ), '/' );
		$db   = '' === $db ? '/var/www/htdocs' : $db;
		$conf = $this->nginx_conf;
		if ( ! file_exists( $conf ) && ! is_link( $conf ) ) {
			EE::debug( "php-compat: there is no $conf; using the public directory $db" );
			return $db;
		}
		$text = is_file( $conf ) && filesize( $conf ) <= self::CONFIG_MAX ? @file_get_contents( $conf ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
		if ( false === $text ) {
			return "!can't read $conf, so can't tell which directory nginx serves";
		}
		$text = preg_replace( '/#[^\r\n]*/', '', $text );
		// Exactly one root, as EE writes it, and no alias; anything else is a config this check doesn't guess at.
		$root = 1 === preg_match_all( '/(?:^|[;{}])\s*root\s+([^\s;{}]+)\s*;/', $text, $m ) ? rtrim( preg_replace( '#/+#', '/', $m[1][0] ), '/' ) : null;
		if ( null === $root || ! preg_match( self::ROOT_PATTERN, $root ) || preg_match( '/(?:^|[;{}])\s*alias\s/', $text ) ) {
			return "!can't tell which directory nginx serves: $conf has no single plain root";
		}
		if ( $root !== $db && ! $this->warned_root ) {
			// resolve_layout() runs twice (early, then under the lock): say it once.
			$this->warned_root = true;
			EE::warning( sprintf( "EE's record of %s has %s as its public directory, but nginx serves %s (root in %s). Checking what nginx serves.", $this->site, $db, $root, $conf ) );
		}

		return $root;
	}

	protected function wp_cli_path( $code, $dir ) {
		$text = $this->read_site_file( $code, $dir . '/wp-cli.yml' );
		$text = null === $text ? null : str_replace( [ "\r\n", "\r" ], "\n", $text );
		if ( null === $text || ! preg_match( '/^path:[ \t]*([\'"]?)([^\'"\r\n]*?)\1[ \t]*$/m', $text, $m ) || '' === $m[2] ) {
			return null;
		}
		$path = $m[2];
		if ( '/' === $path[0] ) {
			if ( 0 !== strpos( $path . '/', '/var/www/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( '/var/www/' ) );
		} else {
			$path = $dir . '/' . $path;
		}
		// Never collapsed as text: a `.` or `..` segment, or `//`, isn't a path this check names.
		if ( preg_match( '#(^|/)\.\.?(/|$)|//#', $path ) ) {
			return null;
		}
		$path = trim( $path, '/' );

		return preg_match( '#^htdocs(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#D', $path ) ? $path : null;
	}

	/**
	 * Whether a wp scan of the WordPress directory reads this file as code: inside it, a code extension, outside core and
	 * the directories the scanner skips (uploads, cache, upgrade, languages, upgrade-temp-backup at a content root's top;
	 * node_modules, .git anywhere), as compat-scanner's listFiles() does.
	 *
	 * @param string      $real    Real host path of the file.
	 * @param string|null $abspath Real WordPress root.
	 *
	 * @return bool
	 */
	protected static function scanned_by_wp_scan( $real, $abspath ) {
		if ( null === $abspath || 0 !== strpos( $real, $abspath . '/' ) || ! self::php_name( basename( $real ) ) ) {
			return false;
		}
		$parts = explode( '/', substr( $real, strlen( $abspath ) + 1 ) );
		if ( in_array( $parts[0], [ 'wp-admin', 'wp-includes' ], true ) || array_intersect( $parts, [ 'node_modules', '.git' ] ) ) {
			return false;
		}
		if ( 1 === count( $parts ) && in_array( $parts[0], self::CORE_FILES, true ) ) {
			return false;
		}

		return ! ( count( $parts ) > 2 && in_array( $parts[1], [ 'uploads', 'cache', 'upgrade', 'languages', 'upgrade-temp-backup' ], true ) );
	}

	/**
	 * The wp-config.php WordPress loads: in its directory, else the parent without wp-settings.php (the real parent, as
	 * WordPress looks; the served parent too, as the scanner looks). A file there that can't be read counts.
	 *
	 * @param string $code    Real path of the site's app/.
	 * @param string $root    WordPress root relative to app/ (as served).
	 * @param string $abspath Real WordPress root.
	 *
	 * @return string[] Its path relative to app/, or nothing.
	 */
	protected function wp_config_files( $code, $root, $abspath ) {
		$real_root  = substr( $abspath, strlen( $code ) + 1 );
		$candidates = [ "$real_root/wp-config.php" ];
		foreach ( array_unique( [ dirname( $real_root ), dirname( $root ) ] ) as $parent ) {
			$parent = '.' === $parent ? '' : "$parent/";
			if ( null === self::resolve_in( $code, "{$parent}wp-settings.php" ) ) {
				$candidates[] = "{$parent}wp-config.php";
			}
		}
		foreach ( $candidates as $candidate ) {
			if ( 'absent' !== $this->site_file( $code, $candidate, self::PHP_SOURCE_MAX )['state'] ) {
				return [ $candidate ];
			}
		}

		return [];
	}

	/**
	 * Whether a php site's scan reads this file as code: in htdocs, a code extension, outside node_modules and .git, and
	 * not in an old release beside the live one behind a `current` link (the scanner's oldReleases()).
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $real Real host path of the file.
	 *
	 * @return bool
	 */
	protected function scanned_by_php_scan( $code, $real ) {
		$htdocs = self::resolve_in( $code, 'htdocs' );
		if ( null === $htdocs || 0 !== strpos( $real, "$htdocs/" ) || ! self::php_name( basename( $real ) ) ) {
			return false;
		}
		$parts = explode( '/', substr( $real, strlen( $htdocs ) + 1 ) );
		if ( array_intersect( $parts, [ 'node_modules', '.git' ] ) ) {
			return false;
		}
		$served = null === $this->scan_path ? null : self::resolve_in( $code, ltrim( substr( $this->scan_path, strlen( '/var/www' ) ), '/' ) );
		if ( null !== $served && 'releases' === basename( dirname( $served ) ) ) {
			$releases = dirname( $served );
			if ( 0 === strpos( $real, "$releases/" ) && 0 !== strpos( $real, "$served/" ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A file under app/ as the scanner container sees it, never from outside app/, never more than $max bytes, and only a
	 * regular file (a FIFO is never opened).
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Path relative to app/.
	 * @param int    $max  Size cap.
	 *
	 * @return array [ state => absent ] when nothing is there; [ state => bad, detail ] when something is there that can't
	 *               be read as a regular file inside app/; [ state => ok, real, source ].
	 */
	protected function site_file( $code, $rel, $max = self::CONFIG_MAX ) {
		$real = self::resolve_in( $code, $rel );
		if ( null === $real ) {
			// Nothing at all, or something (a link on the way) that leaves app/ or can't be resolved.
			if ( preg_match( '/[\x00-\x1F\x7F]/', $rel ) ) {
				return [ 'state' => 'bad', 'detail' => 'its path has control characters' ];
			}
			for ( $path = rtrim( "$code/$rel", '/' ); strlen( $path ) > strlen( $code ); $path = dirname( $path ) ) {
				if ( is_link( $path ) ) {
					return [ 'state' => 'bad', 'detail' => 'is reached through a link that leaves the site\'s files or goes nowhere' ];
				}
			}
			return file_exists( "$code/$rel" ) ? [ 'state' => 'bad', 'detail' => 'can\'t be resolved' ] : [ 'state' => 'absent' ];
		}
		$stat = @lstat( $real ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			return [ 'state' => 'bad', 'detail' => 'is not a regular file' ];
		}
		$handle = @fopen( $real, 'rb' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
		if ( false === $handle ) {
			return [ 'state' => 'bad', 'detail' => 'can\'t be opened' ];
		}
		$fstat = fstat( $handle );
		$text  = $fstat && 0100000 === ( $fstat['mode'] & 0170000 ) && $fstat['ino'] === $stat['ino'] ? stream_get_contents( $handle, $max + 1 ) : false;
		fclose( $handle );
		if ( false === $text ) {
			return [ 'state' => 'bad', 'detail' => 'changed while it was read' ];
		}
		if ( strlen( $text ) > $max ) {
			return [ 'state' => 'bad', 'detail' => 'is larger than ' . self::size( $max ) . ', more than this check reads' ];
		}

		return [ 'state' => 'ok', 'real' => $real, 'source' => $text ];
	}

	/**
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Path relative to app/.
	 * @param int    $max  Size cap.
	 *
	 * @return string|null The file's text; null when it isn't there or can't be read.
	 */
	protected function read_site_file( $code, $rel, $max = self::CONFIG_MAX ) {
		$file = $this->site_file( $code, $rel, $max );

		return 'ok' === $file['state'] ? $file['source'] : null;
	}

	/**
	 * Resolves a path under app/ as the container sees it: a link to /var/www/... points into app/.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Path relative to app/.
	 *
	 * @return string|null Real host path inside app/, or null when it doesn't exist or leaves app/.
	 */
	public static function resolve_in( $code, $rel ) {
		// Path text from the site's files: a NUL or another control character is never a real path here.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $rel ) ) {
			return null;
		}
		$todo = array_values( array_filter( explode( '/', $rel ), 'strlen' ) );
		$done = [];
		$hops = 0;
		while ( $todo ) {
			$part = array_shift( $todo );
			if ( '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! $done ) {
					return null;
				}
				array_pop( $done );
				continue;
			}
			$path = $code . '/' . implode( '/', array_merge( $done, [ $part ] ) );
			if ( is_link( $path ) ) {
				$target = readlink( $path );
				if ( false === $target || ++$hops > 40 || preg_match( '/[\x00-\x1F\x7F]/', $target ) ) {
					return null;
				}
				if ( '/' === substr( $target, 0, 1 ) ) {
					if ( 0 !== strpos( $target . '/', '/var/www/' ) ) {
						return null;
					}
					$done   = [];
					$target = substr( $target, strlen( '/var/www' ) );
				}
				$todo = array_merge( array_values( array_filter( explode( '/', $target ), 'strlen' ) ), $todo );
				continue;
			}
			// Only a directory can be passed through, as in the kernel.
			if ( ! file_exists( $path ) || ( $todo && ! is_dir( $path ) ) ) {
				return null;
			}
			$done[] = $part;
		}

		return rtrim( $code . '/' . implode( '/', $done ), '/' );
	}

	/**
	 * @param string $site_fs_path Site directory.
	 *
	 * @return bool False when another php-compat run holds it.
	 */
	protected function lock_site( $site_fs_path ) {
		// Close-on-exec: a child (docker pull, rm) must not keep the lock after this process is killed.
		$handle                = @fopen( $site_fs_path, 're' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
		$this->lock_unopenable = false === $handle;
		if ( false === $handle ) {
			EE::debug( "Could not open $site_fs_path to lock it; relying on the container name." );

			return true;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			fclose( $handle );
			if ( ! $would_block ) {
				EE::debug( "Could not lock $site_fs_path; relying on the container name." );

				return true;
			}

			return false;
		}
		$this->lock = $handle;

		return true;
	}

	/**
	 * A restore holds the site directory shared and every php-compat run exclusive, so only shared holders mean a restore.
	 * Briefly locks it when no one holds it.
	 *
	 * @param string $site_fs_path Site directory.
	 *
	 * @return bool
	 */
	protected function restore_running( $site_fs_path ) {
		$handle = @fopen( $site_fs_path, 're' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- none then.
		if ( false === $handle ) {
			return false;
		}
		$restore = flock( $handle, LOCK_SH | LOCK_NB ) && ! flock( $handle, LOCK_EX | LOCK_NB );
		fclose( $handle );

		return $restore;
	}

	/**
	 * For `ee site restore`: whether an estimate of the site runs now (it holds its lock file exclusive).
	 *
	 * @param string $site Site name.
	 *
	 * @return bool
	 */
	public static function estimate_running( $site ) {
		$handle = @fopen( EE_ROOT_DIR . '/tmp/' . self::TEMP_DIR . "/.estimate-lock/$site", 're' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing: none ran.
		if ( false === $handle ) {
			return false;
		}
		$running = ! flock( $handle, LOCK_SH | LOCK_NB );
		fclose( $handle );

		return $running;
	}

	/**
	 * @return bool|null False when another estimate of the site holds the lock; null when the lock file can't be made.
	 */
	protected function lock_estimate() {
		$parent = $this->temp_parent();
		$path   = null === $parent ? null : $this->control_path( $parent, 'estimate-lock' );
		// Not in the per-site directory, whose leftovers a scan removes.
		$handle = null === $path ? false : @fopen( $path, 'ce' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
		if ( false === $handle ) {
			return null;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			fclose( $handle );
			if ( ! $would_block ) {
				EE::debug( "Could not lock the estimate of {$this->site}; running without the lock." );

				return true;
			}

			return false;
		}
		$this->lock = $handle;

		return true;
	}

	/**
	 * @param string $name Container name.
	 *
	 * @return string|false running, exited, unknown (docker failed or timed out), or false when there is none.
	 */
	protected function container_status( $name ) {
		$run = $this->docker( [ 'docker', 'inspect', '--format', '{{.State.Running}}', $name ], 60 );
		if ( 0 === $run['code'] ) {
			return 'true' === trim( $run['stdout'] ) ? 'running' : 'exited';
		}
		// Only Docker's own answer means none: a daemon that fails or hangs may still run it.
		return 1 === $run['code'] && preg_match( '/no such (object|container)/i', $run['stderr'] ) ? false : 'unknown';
	}

	/**
	 * @param string $name Container name.
	 *
	 * @return string|null Id of the container when it is running.
	 */
	protected function running_container_id( $name ) {
		$run = $this->docker( [ 'docker', 'inspect', '--format', '{{.State.Running}} {{.Id}}', $name ], 60 );

		return 0 === $run['code'] && 1 === preg_match( '/^true ([0-9a-f]{64})$/', trim( $run['stdout'] ), $m ) ? $m[1] : null;
	}

	/**
	 * Remove a container if it still exists and confirm it is gone.
	 *
	 * @param string $name Container name.
	 *
	 * @return bool
	 */
	protected function container_gone( $name ) {
		$this->docker( [ 'docker', 'rm', '-f', $name ], 60 );
		for ( $try = 1; ; $try++ ) {
			if ( false === $this->container_status( $name ) ) {
				return true;
			}
			if ( 3 === $try ) {
				return false;
			}
			sleep( 1 );
		}
	}

	/**
	 * Pull the pinned tag before each check, so a host never keeps an outdated image under it (a tag can be rebuilt); a local
	 * copy serves when the pull fails. Most servers never run a check, so a fresh install doesn't pull it.
	 *
	 * @param string $image Image with tag.
	 *
	 * @return string|null tool_error detail, or null when the image is there.
	 */
	protected function ensure_image( $image ) {
		$inspect = $this->docker( [ 'docker', 'image', 'inspect', '--format', '{{.Id}} {{len .RepoDigests}}', $image ], 60 );
		$present = 0 === $inspect['code'];
		// Built here, never pulled (no registry digest): a local test build, which a pull would replace.
		if ( $present && preg_match( '/^\S+ 0$/', trim( $inspect['stdout'] ) ) ) {
			EE::debug( "Using the local build of $image." );

			return null;
		}
		EE::debug( "Pulling $image." );
		$pull = $this->docker( [ 'docker', 'pull', '--quiet', $image ], $present ? self::REPULL_TIMEOUT : self::PULL_TIMEOUT, true );
		if ( 0 === $pull['code'] && ! $pull['timed_out'] ) {
			$this->remove_other_tags( $image );

			return null;
		}
		$why = $pull['timed_out'] ? 'timed out after ' . ( $present ? self::REPULL_TIMEOUT : self::PULL_TIMEOUT ) . ' s' : self::first_line( $pull['stderr'] );
		if ( $present ) {
			EE::debug( "Could not pull $image ($why); using the local copy." );

			return null;
		}

		return 'image pull failed' . ( '' === $why ? '' : ": $why" );
	}

	/**
	 * @param string $text Command output.
	 *
	 * @return string Its first non-empty line, cleaned and capped, for a one-line detail.
	 */
	protected static function first_line( $text ) {
		foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				return self::clean( strlen( $line ) > 200 ? substr( $line, 0, 200 ) . '...' : $line );
			}
		}

		return '';
	}

	/**
	 * After pulling a new tag, remove the scanner's other tags that no container uses; failures only cost disk.
	 *
	 * @param string $image The pinned image with its tag, never removed.
	 */
	protected function remove_other_tags( $image ) {
		// Only with no other check running: one between its pull and its `docker run` has no container that keeps its tag.
		if ( null !== $this->image_lock ) {
			flock( $this->image_lock, LOCK_UN );
			$alone = flock( $this->image_lock, LOCK_EX | LOCK_NB );
			if ( ! $alone ) {
				flock( $this->image_lock, LOCK_SH );
				EE::debug( 'Keeping the other ' . self::IMAGE . ' tags: another check is running.' );

				return;
			}
		}
		try {
			$this->remove_unused_tags( $image );
		} finally {
			if ( null !== $this->image_lock ) {
				flock( $this->image_lock, LOCK_SH );
			}
		}
	}

	/**
	 * @param string $image The pinned image with its tag, never removed.
	 */
	protected function remove_unused_tags( $image ) {
		$list = $this->docker( [ 'docker', 'image', 'ls', '--format', '{{.Tag}}', self::IMAGE ], 60 );
		if ( 0 !== $list['code'] ) {
			EE::debug( 'Could not list the ' . self::IMAGE . ' tags.' );

			return;
		}
		foreach ( array_unique( preg_split( '/\s+/', trim( $list['stdout'] ), -1, PREG_SPLIT_NO_EMPTY ) ) as $tag ) {
			$old = self::IMAGE . ':' . $tag;
			if ( $old === $image || ! preg_match( '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $tag ) ) {
				continue;
			}
			$users = $this->docker( [ 'docker', 'ps', '-a', '-q', '--filter', "ancestor=$old" ], 60 );
			if ( 0 !== $users['code'] || '' !== trim( $users['stdout'] ) ) {
				EE::debug( "Keeping $old: a container uses it." );
				continue;
			}
			// Without -f, Docker also refuses an image a container started in the meantime.
			if ( 0 !== $this->docker( [ 'docker', 'image', 'rm', $old ], 60 )['code'] ) {
				EE::debug( "Could not remove $old." );
			}
		}
	}

	/**
	 * Hold the host-wide image lock shared until cleanup(); without it (no usable temp directory) old tags are removed as before.
	 */
	protected function hold_image() {
		$parent = $this->temp_parent();
		$handle = null === $parent ? false : @fopen( "$parent/.image-lock", 'ce' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
		if ( false === $handle ) {
			return;
		}
		// Held exclusive only while another check removes old tags; polled, so signals still reach the handlers.
		$until = microtime( true ) + 60;
		while ( ! flock( $handle, LOCK_SH | LOCK_NB, $would_block ) ) {
			if ( ! $would_block || microtime( true ) >= $until ) {
				fclose( $handle );
				EE::debug( 'Going on without the scanner image lock.' );

				return;
			}
			usleep( 100000 );
			if ( function_exists( 'pcntl_signal_dispatch' ) ) {
				null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
			}
		}
		$this->image_lock = $handle;
	}

	/**
	 * This run's /out: a new random directory in a root-only per-site directory, after removing what a killed run left there.
	 *
	 * Only called with the site lock held and no container of the site left, so nothing else uses that directory.
	 *
	 * @return string|null Its real path.
	 */
	protected function make_temp_dir() {
		$parent = $this->temp_parent();
		if ( null === $parent ) {
			return null;
		}
		$site_dir = $parent . '/' . $this->site;
		if ( ! $this->own_dir( $site_dir ) ) {
			EE::debug( "Refusing $site_dir as a temporary directory: not a directory of this user with mode 0700." );

			return null;
		}

		// One that can't be removed doesn't block this run: it gets its own directory.
		$this->remove_leftovers( $site_dir );

		$dir = $site_dir . '/' . bin2hex( random_bytes( 8 ) );
		if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
			return null;
		}
		$this->temp_dir = $dir;
		$this->register_cleanup();
		clearstatcache( true, $dir );
		// Without root (macOS), Docker Desktop's file sharing maps the owner itself.
		if ( is_link( $dir ) || ! is_dir( $dir ) || ! chmod( $dir, 0700 ) || ( 0 === self::euid() && ! ( chown( $dir, 33 ) && chgrp( $dir, 33 ) ) ) || ( null === self::euid() && ! ( @chown( $dir, 33 ) && @chgrp( $dir, 33 ) ) && 0 === fileowner( $dir ) ) ) {
			return null;
		}
		$real = realpath( $dir );
		if ( false === $real ) {
			return null;
		}
		$this->temp_dir = $real;

		return $real;
	}

	/**
	 * Remove what killed runs left in the per-site directory. Only with the site lock held and no container of the site left.
	 *
	 * @param string $site_dir Per-site directory, checked with own_dir().
	 */
	protected function remove_leftovers( $site_dir ) {
		foreach ( array_diff( (array) @scandir( $site_dir ), [ '.', '..', false ] ) as $entry ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- an unreadable dir has nothing to remove.
			EE::debug( "Removing the leftover temporary directory $site_dir/" . self::clean( $entry ) . '.' );
			$this->remove_tree( "$site_dir/$entry" );
		}
	}

	/**
	 * @return string|null EE_ROOT_DIR/tmp/php-compat, checked to be root-only; null when it isn't.
	 */
	protected function temp_parent() {
		$base = rtrim( $this->temp_base(), '/' );
		foreach ( [ $base, $base . '/' . self::TEMP_DIR ] as $dir ) {
			if ( ! $this->own_dir( $dir ) ) {
				EE::debug( "Refusing $dir as a temporary directory: not a directory of this user with mode 0700." );

				return null;
			}
		}

		return $base . '/' . self::TEMP_DIR;
	}

	/**
	 * Create a directory 0700 when missing, and accept it only as a real directory of this user that no one else can enter.
	 *
	 * @param string $dir Path.
	 *
	 * @return bool
	 */
	protected function own_dir( $dir ) {
		@mkdir( $dir, 0700 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- it may exist; checked below.
		clearstatcache( true, $dir );
		$stat = @lstat( $dir ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		$euid = self::euid();
		if ( ! $stat || 0040000 !== ( $stat['mode'] & 0170000 ) || ( null !== $euid && $stat['uid'] !== $euid ) ) {
			return false;
		}

		return 0 === ( $stat['mode'] & 0077 ) || chmod( $dir, 0700 );
	}

	/**
	 * Remove a tree without following symlinks: rm's *at() calls also survive a directory swapped mid-way and any depth.
	 *
	 * @param string $path Path.
	 *
	 * @return bool Whether it is gone.
	 */
	protected function remove_tree( $path ) {
		$argv = ( defined( 'IS_DARWIN' ) && IS_DARWIN ) ? [ 'rm', '-rf', '--', $path ] : [ 'rm', '-rf', '--one-file-system', '--', $path ];
		$run  = $this->run_process( $argv, 600 );
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			// stderr can hold names chosen inside the container.
			EE::debug( 'rm: ' . self::clean( trim( $run['stderr'] ) ) );

			return false;
		}

		return true;
	}

	/**
	 * @return string Base directory with a trailing slash.
	 */
	protected function temp_base() {
		return EE_ROOT_DIR . '/tmp/';
	}

	/**
	 * Run a docker command without EE::exec()/EE::launch(), which would copy its output into ee.log.
	 *
	 * @param string[] $argv     Command and arguments.
	 * @param int      $timeout  Seconds.
	 * @param bool     $bounded  Stop it once stdout or stderr passes its cap.
	 *
	 * @return array code (int|null), stdout, stderr, timed_out, overflow, signal (int|null), cancelled.
	 */
	protected function docker( array $argv, $timeout, $bounded = false ) {
		EE::debug( 'COMMAND: ' . implode( ' ', array_map( 'escapeshellarg', $argv ) ) );
		$run = $this->run_process( $argv, $timeout, $bounded );
		EE::debug( 'RETURN CODE: ' . var_export( $run['code'], true ) );

		return $run;
	}

	/**
	 * @param string[] $argv    Command and arguments.
	 * @param int      $timeout Seconds.
	 * @param bool     $bounded Stop it once stdout or stderr passes its cap.
	 *
	 * @return array See docker().
	 */
	protected function run_process( array $argv, $timeout, $bounded = false ) {
		$result  = [
			'code'      => null,
			'stdout'    => '',
			'stderr'    => '',
			'timed_out' => false,
			'overflow'  => false,
			'signal'    => null,
			'cancelled' => false,
		];
		$process = new Process( $argv );
		$process->setTimeout( $timeout );
		// Process keeps its own copy of all output unless disabled; the callback keeps a capped one.
		$process->disableOutput();
		try {
			$process->start(
				function ( $type, $buffer ) use ( &$result ) {
					$key = Process::OUT === $type ? 'stdout' : 'stderr';
					$max = 'stdout' === $key ? self::STDOUT_MAX : self::STDERR_MAX;
					// One callback can carry everything a fast writer produced since the last read.
					$room = $max + 1 - strlen( $result[ $key ] );
					if ( $room > 0 ) {
						$result[ $key ] .= substr( $buffer, 0, $room );
					}
					if ( strlen( $result[ $key ] ) > $max ) {
						$result['overflow'] = true;
					}
				}
			);
			// wait() never dispatches signals, so poll: SIGTERM/SIGINT then reach the command's handlers, whose exit runs cleanup().
			for ( $tick = 0; $process->isRunning(); $tick++ ) {
				if ( ! $this->cleaning && function_exists( 'pcntl_signal_dispatch' ) ) {
					null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
				}
				// A cancelled scan stops whatever it runs (a pull, rm, the scan itself) within about 0.2 s; cleanup() always finishes.
				if ( ! $this->cleaning && 0 === $tick % 10 && $this->cancel_requested() ) {
					$process->stop( 0 );
					$result['cancelled'] = true;

					return $result;
				}
				$process->checkTimeout();
				if ( $bounded && $result['overflow'] ) {
					$process->stop( 0 );

					return $result;
				}
				usleep( 20000 );
			}
			$process->wait();
			$result['code'] = $process->getExitCode();
		} catch ( ProcessTimedOutException $e ) {
			$result['timed_out'] = true;
		} catch ( ProcessSignaledException $e ) {
			$result['signal'] = $e->getSignal();
		} catch ( \Exception $e ) {
			EE::debug( 'Could not run ' . $argv[0] . ': ' . self::clean( $e->getMessage() ) );
		}

		return $result;
	}

	/**
	 * @param string $stdout Container stdout.
	 *
	 * @return array|null One JSON object with v and status.
	 */
	protected function parse_document( $stdout ) {
		if ( strlen( $stdout ) > self::STDOUT_MAX ) {
			return null;
		}
		$doc = json_decode( trim( $stdout ), true, 32, self::JSON_UTF8 );
		if ( ! is_array( $doc ) || array_values( $doc ) === $doc || ! array_key_exists( 'v', $doc ) || ! array_key_exists( 'status', $doc ) ) {
			return null;
		}

		return $doc;
	}

	/**
	 * @param array $doc Container stdout document.
	 *
	 * @return bool
	 */
	protected function valid_scan_doc( array $doc ) {
		if ( 1 !== $doc['v'] || ! in_array( $doc['status'], self::SCAN_STATUSES, true ) ) {
			return false;
		}
		if ( 'tool_error' === $doc['status'] ) {
			return isset( $doc['detail'] ) && is_string( $doc['detail'] );
		}
		if ( 'ok' !== $doc['status'] ) {
			return true;
		}
		if ( ! isset( $doc['target'] ) || $this->target !== $doc['target'] || ! array_key_exists( 'safe_to_upgrade', $doc ) || ! isset( $doc['tool'] ) || ! is_array( $doc['tool'] ) ) {
			return false;
		}
		foreach ( self::SUMMARY_INTS as $key ) {
			if ( ! isset( $doc[ $key ] ) || ! is_int( $doc[ $key ] ) || $doc[ $key ] < 0 ) {
				return false;
			}
		}
		if ( $doc['errors'] > 0 ) {
			$verdict = false;
		} else {
			$verdict = $doc['files_not_checked'] > 0 ? null : true;
		}

		return $verdict === $doc['safe_to_upgrade'];
	}

	/**
	 * @param array $doc Container stdout document.
	 *
	 * @return bool
	 */
	protected function valid_estimate_doc( array $doc ) {
		if ( 1 !== $doc['v'] || ! in_array( $doc['status'], self::SCAN_STATUSES, true ) ) {
			return false;
		}
		if ( 'tool_error' === $doc['status'] ) {
			return isset( $doc['detail'] ) && is_string( $doc['detail'] );
		}
		if ( 'ok' !== $doc['status'] ) {
			return true;
		}
		if ( ! isset( $doc['estimate'], $doc['target'], $doc['inputs'] ) || true !== $doc['estimate'] || $this->target !== $doc['target'] || ! is_array( $doc['inputs'] ) ) {
			return false;
		}
		foreach ( self::ESTIMATE_INTS as $key ) {
			if ( ! isset( $doc[ $key ] ) || ! is_int( $doc[ $key ] ) || $doc[ $key ] < 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The report must carry the stdout summary, and its lists must add up to it.
	 *
	 * @param array $report Decoded /out/report.json.
	 * @param array $doc    Validated stdout summary.
	 *
	 * @return bool
	 */
	protected function valid_report( array $report, array $doc ) {
		foreach ( $doc as $key => $value ) {
			if ( ! array_key_exists( $key, $report ) || $report[ $key ] !== $value ) {
				return false;
			}
		}
		if ( ! isset( $report['components'], $report['not_checked'] ) || ! is_array( $report['components'] ) || ! is_array( $report['not_checked'] ) ) {
			return false;
		}
		if ( count( $report['components'] ) !== $doc['components_checked'] || count( $report['not_checked'] ) !== $doc['files_not_checked'] ) {
			return false;
		}
		$errors      = 0;
		$warnings    = 0;
		$with_errors = 0;
		foreach ( $report['components'] as $component ) {
			if ( ! is_array( $component ) || ! self::valid_place( $component ) || ! isset( $component['errors'], $component['warnings'], $component['findings'] ) || ! is_int( $component['errors'] ) || ! is_int( $component['warnings'] ) || ! is_array( $component['findings'] ) ) {
				return false;
			}
			foreach ( [ 'name', 'version' ] as $key ) {
				if ( ! array_key_exists( $key, $component ) || ! ( null === $component[ $key ] || is_string( $component[ $key ] ) ) ) {
					return false;
				}
			}
			$counts = [
				'ERROR'   => 0,
				'WARNING' => 0,
			];
			foreach ( $component['findings'] as $finding ) {
				if ( ! is_array( $finding ) || ! isset( $finding['file'], $finding['line'], $finding['type'], $finding['source'], $finding['message'] ) || ! is_string( $finding['file'] ) || ! is_int( $finding['line'] ) || ! in_array( $finding['type'], [ 'ERROR', 'WARNING' ], true ) || ! is_string( $finding['source'] ) || ! is_string( $finding['message'] ) ) {
					return false;
				}
				++$counts[ $finding['type'] ];
			}
			// Every error and warning is listed, so the component's counts are its findings'.
			if ( $counts['ERROR'] !== $component['errors'] || $counts['WARNING'] !== $component['warnings'] ) {
				return false;
			}
			$errors      += $component['errors'];
			$warnings    += $component['warnings'];
			$with_errors += $component['errors'] > 0 ? 1 : 0;
		}
		foreach ( $report['not_checked'] as $entry ) {
			if ( ! is_array( $entry ) || ! self::valid_place( $entry ) || ! isset( $entry['file'], $entry['reason'] ) || ! is_string( $entry['file'] ) || ! is_string( $entry['reason'] ) ) {
				return false;
			}
		}

		return $errors === $doc['errors'] && $warnings === $doc['warnings'] && $with_errors === $doc['components_with_errors'];
	}

	/**
	 * @param array $item Component or not_checked entry.
	 *
	 * @return bool Whether type, slug and branch have the right types.
	 */
	private static function valid_place( array $item ) {
		return isset( $item['type'], $item['slug'] ) && is_string( $item['type'] ) && is_string( $item['slug'] ) && array_key_exists( 'branch', $item ) && ( null === $item['branch'] || is_string( $item['branch'] ) );
	}

	/**
	 * Upper bound of what json_decode() allocates: a non-empty array or object costs far more than its bytes.
	 *
	 * @param string $raw JSON.
	 *
	 * @return int Bytes.
	 */
	protected static function decode_cost( $raw ) {
		$nodes = substr_count( $raw, '{' ) + substr_count( $raw, '[' );

		// Measured on 7.4 and 8.5: up to ~450 per array or object, ~80 per element (hash growth), ~40 per string, plus the bytes.
		return strlen( $raw ) + $nodes * 450 + ( substr_count( $raw, ',' ) + $nodes ) * 80 + (int) ( substr_count( $raw, '"' ) / 2 ) * 40;
	}

	/**
	 * @return int Bytes the report may still take: what the limit leaves, or with no limit what the host has free.
	 */
	protected function memory_available() {
		$limit = self::bytes( ini_get( 'memory_limit' ) );
		if ( $limit > 0 ) {
			return $limit - memory_get_usage();
		}
		$free = $this->host_memory_available();

		return null === $free ? self::MEMORY_FLOOR - memory_get_usage() : $free;
	}

	/**
	 * Raise the limit so a report that needs more than MEMORY_FLOOR can be read when the host has the memory.
	 *
	 * @param int $cost decode_cost() of the report.
	 *
	 * @return bool Whether the report now fits.
	 */
	protected function make_room( $cost ) {
		$free = $this->host_memory_available();
		// Never past what the host has free, whatever PHP's limit allows.
		if ( null !== $free && $cost > $free ) {
			return false;
		}
		if ( $cost <= $this->memory_available() ) {
			return true;
		}
		if ( self::bytes( ini_get( 'memory_limit' ) ) <= 0 || null === $free ) {
			return false;
		}
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + $cost + 67108864 ) );

		return $cost <= $this->memory_available();
	}

	/**
	 * @return int|null MemAvailable of the host, or null when it can't be read (not Linux).
	 */
	protected function host_memory_available() {
		$info = @file_get_contents( '/proc/meminfo' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- null when missing.

		return false !== $info && preg_match( '/^MemAvailable:\s+(\d+) kB$/m', $info, $m ) ? (int) $m[1] * 1024 : null;
	}

	/**
	 * Write the report as root, 0600, through a temporary file and a rename, into the directory validated at the start.
	 *
	 * @param string $path    --output.
	 * @param string $content Report, byte for byte.
	 *
	 * @return bool
	 */
	protected function write_report( $path, $content ) {
		$dir = $this->output_dir['path'];
		if ( ! $this->output_dir_unchanged( $path ) ) {
			EE::debug( "The directory of $path changed during the check." );

			return false;
		}
		$tmp = $dir . '/.ee-php-compat-' . bin2hex( random_bytes( 8 ) );
		// 'x' (O_EXCL) never follows or reuses an entry, and the file is written through this handle only, never reopened by name.
		$mask   = umask( 0077 );
		$handle = @fopen( $tmp, 'x' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		umask( $mask );
		if ( false === $handle ) {
			return false;
		}
		$this->output_tmp = $tmp;
		// A default ACL on the directory replaces the umask, so set the mode, and check it on the handle before writing anything.
		@chmod( $tmp, 0600 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		$created = fstat( $handle );
		if ( ! $created || 0600 !== ( $created['mode'] & 07777 ) ) {
			fclose( $handle );
			@unlink( $tmp ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$this->output_tmp = null;

			return false;
		}
		$written          = 0;
		while ( $written < strlen( $content ) ) {
			$chunk = fwrite( $handle, substr( $content, $written, 1048576 ) );
			if ( ! $chunk ) {
				break;
			}
			$written += $chunk;
		}
		$ok     = strlen( $content ) === $written && fflush( $handle );
		$file   = fstat( $handle );
		$target = $dir . '/' . basename( $path );
		// lchown/lchgrp never follow a link (a setgid directory gives the file its group); the directory is checked again right before the rename.
		$ok = $ok && ( 0 !== self::euid() || ( @lchown( $tmp, 0 ) && @lchgrp( $tmp, 0 ) ) ) && $this->output_dir_unchanged( $path ) && self::replaceable( $target ) && @rename( $tmp, $target ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
		if ( ! $ok ) {
			@unlink( $tmp ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
		$this->output_tmp = null;
		if ( $ok ) {
			clearstatcache( true, $target );
			$published = @lstat( $target ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
			// What was renamed must be the file written above; the open handle keeps its inode number from being reused.
			$ok = $published && $file && 0100000 === ( $published['mode'] & 0170000 ) && $published['dev'] === $file['dev'] && $published['ino'] === $file['ino'];
		}
		fclose( $handle );

		return $ok;
	}

	/**
	 * @param string $path --output.
	 *
	 * @return bool Whether the report may replace what is there: nothing, a regular file, or a link to one or to nothing.
	 */
	protected static function replaceable( $path ) {
		clearstatcache( true, $path );
		$entry = @lstat( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		if ( ! $entry ) {
			return true;
		}
		$type = $entry['mode'] & 0170000;
		if ( 0120000 === $type ) {
			$target = @stat( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a dangling link is fine.
			return ! $target || 0100000 === ( $target['mode'] & 0170000 );
		}

		return 0100000 === $type;
	}

	/**
	 * @param string $dir Real path of a directory.
	 *
	 * @return bool Whether no other user can change its entries or those of its parents: each is owned by root or this user, and not group- or world-writable unless sticky.
	 */
	protected function trusted_dir( $dir ) {
		$euid = self::euid();
		for ( $path = $dir; ; $path = dirname( $path ) ) {
			clearstatcache( true, $path );
			$stat = @lstat( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
			if ( ! $stat || 0040000 !== ( $stat['mode'] & 0170000 ) || ( 0 !== $stat['uid'] && $euid !== $stat['uid'] ) ) {
				return false;
			}
			if ( ( $stat['mode'] & 0022 ) && ! ( $stat['mode'] & 01000 ) ) {
				return false;
			}
			if ( '/' === $path || '.' === $path ) {
				return '/' === $path;
			}
		}
	}

	/**
	 * @param string $path --output.
	 *
	 * @return bool Whether its directory still resolves to the one validated at the start.
	 */
	protected function output_dir_unchanged( $path ) {
		clearstatcache();
		$real = realpath( dirname( $path ) );
		$stat = false === $real ? false : @stat( $real ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.

		return $real === $this->output_dir['path'] && $stat && $stat['dev'] === $this->output_dir['dev'] && $stat['ino'] === $this->output_dir['ino'] && $this->trusted_dir( $real );
	}

	/**
	 * The verdict this check gives from the scanner's result: what was checked, what wasn't, and for WordPress whether its
	 * core supports the target. Safe only with no error, nothing the scanner left unchecked, and core supporting the target.
	 *
	 * @param array $doc    Validated scanner summary.
	 * @param array $report Validated report.
	 *
	 * @return array $doc with safe_to_upgrade, checked, not_checked (the scanner's entries) and (WordPress) wordpress set.
	 */
	protected function scoped( array $doc, array $report ) {
		// Only EE sets these; the scanner can't.
		unset( $doc['checked'], $doc['not_checked'], $doc['wordpress'], $doc['cross_check'] );
		$doc['checked']     = [
			'dir'        => self::clean( (string) $this->looked_in ),
			'components' => $doc['components_checked'],
			'files'      => $doc['files_checked'],
		];
		$doc['not_checked'] = array_map(
			function ( $entry ) {
				return [
					'type'   => self::clean( $entry['type'] ),
					'slug'   => self::clean( $entry['slug'] ),
					'branch' => null === $entry['branch'] ? null : self::clean( $entry['branch'] ),
					'file'   => self::clean( $entry['file'] ),
					'reason' => self::clean( $entry['reason'] ),
				];
			},
			$report['not_checked']
		);
		$doc['not_checked_more'] = 0;
		$core                    = null;
		if ( $this->wp_layout && null !== $this->wp_version ) {
			// Core's files are never scanned; not_checked stays the scanner's own list (its type and reason values are a fixed set).
			$core             = self::wp_supports( $this->wp_version['branch'], $this->target, $this->current_php() );
			$doc['wordpress'] = [
				'version'            => self::clean( $this->wp_version['version'] ),
				'supports'           => $core['supports'],
				'minimum'            => $core['minimum'],
				'table_newest'       => $core['table_newest'],
				'core_files_checked' => false,
			];
		}
		$doc['cross_check'] = null === $this->cross ? null : [
			'manifest'      => $this->cross['manifest'],
			'unlisted'      => $this->cross['unlisted'],
			'unlisted_more' => $this->cross['unlisted_more'],
			'walk'          => $this->cross['walk'],
			'walk_error'    => $this->cross['walk_error'],
		];
		if ( $doc['errors'] > 0 || ( null !== $core && false === $core['supports'] ) ) {
			$doc['safe_to_upgrade'] = false;
		} elseif ( $doc['files_not_checked'] > 0 || ( null !== $core && null === $core['supports'] ) || ! self::cross_ok( $this->cross ) ) {
			$doc['safe_to_upgrade'] = null;
		} else {
			$doc['safe_to_upgrade'] = true;
		}

		return $doc;
	}

	/**
	 * @return string|null The PHP version the site runs now: only when its record and its running container agree.
	 */
	protected function current_php() {
		return null !== $this->current_php && $this->current_php === $this->container_php ? $this->current_php : null;
	}

	/**
	 * @param string $text Text.
	 *
	 * @return string At most NOT_CHECKED_CHARS characters of it, so stdout stays fixed-size.
	 */
	protected static function cut( $text ) {
		return preg_match( '/^.{' . self::NOT_CHECKED_CHARS . '}/us', $text, $m ) ? $m[0] : $text;
	}

	/**
	 * The summary as stdout prints it: fixed-size, so not_checked keeps its first NOT_CHECKED_STDOUT entries, each string
	 * cut to NOT_CHECKED_CHARS characters, and not_checked_more counts the rest (the --output report has them all).
	 *
	 * @param array $summary Full summary.
	 *
	 * @return array
	 */
	public static function capped( array $summary ) {
		if ( ! isset( $summary['not_checked'] ) ) {
			return $summary;
		}
		$all                         = $summary['not_checked'];
		$summary['not_checked']      = array_map(
			function ( $entry ) {
				foreach ( $entry as $key => $value ) {
					if ( is_string( $value ) && preg_match( '/^.{' . self::NOT_CHECKED_CHARS . '}/us', $value, $m ) && $m[0] !== $value ) {
						$entry[ $key ] = $m[0];
					}
				}
				return $entry;
			},
			array_slice( $all, 0, self::NOT_CHECKED_STDOUT )
		);
		$summary['not_checked_more'] = max( 0, count( $all ) - self::NOT_CHECKED_STDOUT );

		return $summary;
	}

	/**
	 * Whether a WordPress branch supports a PHP version, by WP_PHP_SUPPORT.
	 *
	 * @param string $branch WordPress major.minor.
	 * @param string      $target  PHP major.minor.
	 * @param string|null $current PHP the site runs now, major.minor (only used for a branch newer than the table).
	 *
	 * @return array supports (true, false, or null when WordPress hasn't said), minimum (lowest WordPress branch supporting
	 *               the target, or null), table_newest (the newest row, when the branch is newer than the table) and why
	 *               (the reason to print, or null).
	 */
	public static function wp_supports( $branch, $target, $current = null ) {
		$current = null !== $current && preg_match( '/^\d+\.\d+$/D', (string) $current ) ? (string) $current : null;
		$minimum = null;
		$highest = '0';
		foreach ( self::WP_PHP_SUPPORT as $wp => list( $low, $high ) ) {
			if ( version_compare( $target, $low, '>=' ) && version_compare( $target, $high, '<=' ) ) {
				$minimum = (string) $wp;
			}
			$highest = version_compare( $high, $highest, '>' ) ? $high : $highest;
		}
		$result = function ( $supports, $why, $min = null, $newest = null ) {
			return [ 'supports' => $supports, 'minimum' => $min, 'table_newest' => $newest, 'why' => $why ];
		};
		if ( version_compare( $target, $highest, '>' ) ) {
			return $result( null, "WordPress hasn't declared support for PHP $target yet" );
		}
		$branches = array_map( 'strval', array_keys( self::WP_PHP_SUPPORT ) );
		$newest   = $branches[0];
		$oldest   = end( $branches );
		if ( version_compare( $branch, $newest, '>' ) ) {
			// WordPress doesn't drop a newer PHP within its support window, but may raise its floor: a newer branch supports
			// what the newest row does only when moving up from the PHP the site runs now.
			list( $low, $high ) = self::WP_PHP_SUPPORT[ $newest ];
			if ( version_compare( $target, $low, '>=' ) && version_compare( $target, $high, '<=' ) ) {
				if ( null !== $current && version_compare( $target, $current, '>=' ) ) {
					return $result( true, "WordPress $branch is newer than this check's table; WordPress $newest supports PHP $target", $minimum, $newest );
				}
				return $result( null, "WordPress $branch is newer than this check's table, which can't tell whether it still supports PHP $target" . ( null === $current ? '' : ", below the PHP $current the site runs now" ), $minimum, $newest );
			}
			// Below the newest row's floor nothing says a newer WordPress still runs it: no minimum to name.
			return $result( null, "WordPress $branch is newer than this check's table, and WordPress $newest doesn't support PHP $target", version_compare( $target, $low, '<' ) ? null : $minimum, $newest );
		}
		if ( ! isset( self::WP_PHP_SUPPORT[ $branch ] ) ) {
			// Older than every row: it can't support a PHP newer than the oldest row does.
			if ( version_compare( $branch, $oldest, '<' ) && version_compare( $target, self::WP_PHP_SUPPORT[ $oldest ][1], '>' ) ) {
				$why = "WordPress $branch doesn't support PHP $target";
				return $result( false, null === $minimum ? $why : "$why; update WordPress to at least $minimum first", $minimum );
			}
			return $result( null, "this check doesn't know which PHP versions WordPress $branch supports", $minimum );
		}
		list( $low, $high ) = self::WP_PHP_SUPPORT[ $branch ];
		if ( version_compare( $target, $low, '<' ) ) {
			return $result( false, "WordPress $branch needs PHP $low or newer, so it doesn't support PHP $target" );
		}
		if ( version_compare( $target, $high, '<=' ) ) {
			return $result( true, null, $minimum );
		}
		$why = "WordPress $branch doesn't support PHP $target";

		return $result( false, null === $minimum ? $why : "$why; update WordPress to at least $minimum first", $minimum );
	}


	/**
	 * Container scan summary to print: findings never, `site` always.
	 *
	 * @param array $doc Validated stdout summary.
	 *
	 * @return array
	 */
	protected function summary( array $doc ) {
		return $this->with_site( self::pick( $doc, self::SUMMARY_KEYS ) );
	}

	/**
	 * Only the documented keys, in their order; tool strings and estimate input numbers only.
	 *
	 * @param array    $doc  Validated document.
	 * @param string[] $keys Keys to keep.
	 *
	 * @return array
	 */
	protected static function pick( array $doc, array $keys ) {
		$picked = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $doc ) ) {
				$picked[ $key ] = $doc[ $key ];
			}
		}
		if ( isset( $picked['tool'] ) ) {
			$picked['tool'] = array_filter( array_intersect_key( $picked['tool'], array_flip( self::TOOL_KEYS ) ), 'is_string' );
		}
		if ( isset( $picked['inputs'] ) ) {
			$picked['inputs'] = array_filter(
				array_intersect_key( $picked['inputs'], array_flip( self::INPUT_KEYS ) ),
				function ( $value ) {
					// json_decode() reads 1e999 as INF, which json_encode() refuses.
					return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
				}
			);
		}

		return $picked;
	}

	/**
	 * Put `site` after v, status and estimate, whatever the container sent.
	 *
	 * @param array $doc Document.
	 *
	 * @return array
	 */
	protected function with_site( array $doc ) {
		$head = [
			'v'      => $doc['v'],
			'status' => $doc['status'],
		];
		if ( array_key_exists( 'estimate', $doc ) ) {
			$head['estimate'] = $doc['estimate'];
		}
		$head['site'] = $this->site;

		return $head + $doc;
	}

	/**
	 * A document other than `ok`: only v, status, site, target, safe_to_upgrade and, for tool_error, detail.
	 *
	 * @param string      $status busy, no_code_found, cancelled or tool_error; with --cancel: cancelled, not_running or tool_error.
	 * @param string|null $detail tool_error only.
	 *
	 * @return array 'doc' and 'report'.
	 */
	protected function doc( $status, $detail = null ) {
		$doc = [
			'v'      => 1,
			'status' => $status,
		];
		if ( $this->cancel ) {
			// No target: a cancel stops whichever check runs.
			$doc += [
				'cancel' => true,
				'site'   => $this->site,
			];
			if ( 'tool_error' === $status ) {
				$doc['detail'] = (string) $detail;
			}

			return [
				'doc'    => $doc,
				'report' => null,
			];
		}
		if ( $this->estimate ) {
			$doc['estimate'] = true;
		}
		$doc += [
			'site'            => $this->site,
			'target'          => $this->target,
			'safe_to_upgrade' => null,
		];
		if ( 'tool_error' === $status ) {
			$doc['detail'] = (string) $detail;
		}

		return [
			'doc'    => $doc,
			'report' => null,
		];
	}

	/**
	 * Detail of a tool_error EE reports for the container: its exit code and, for Docker's own errors, Docker's message.
	 *
	 * @param array  $run  docker() result.
	 * @param string $what What was missing.
	 *
	 * @return string
	 */
	protected static function exit_detail( array $run, $what ) {
		if ( ! empty( $run['overflow'] ) ) {
			return 'the scanner printed more output than a result can have';
		}
		if ( ! empty( $run['signal'] ) ) {
			return "the scanner was interrupted by signal {$run['signal']}";
		}
		if ( null === $run['code'] ) {
			return 'the scanner could not be started';
		}
		$detail = "the scanner exited with code {$run['code']} $what";
		// 125-127 come from Docker itself, not from the scanned code.
		if ( in_array( $run['code'], [ 125, 126, 127 ], true ) && '' !== trim( $run['stderr'] ) ) {
			$line    = strtok( trim( $run['stderr'] ), "\n" );
			$detail .= ': ' . self::clean( strlen( $line ) > 300 ? substr( $line, 0, 300 ) . '...' : $line );
		}

		return $detail;
	}

	/**
	 * The table, for tests and callers that want one string.
	 *
	 * @param array      $doc    Summary.
	 * @param array|null $report Decoded report (ok only).
	 *
	 * @return string
	 */
	protected function table( array $doc, $report ) {
		return implode( "\n", iterator_to_array( $this->table_lines( $doc, $report ), false ) ) . "\n";
	}

	/**
	 * @param array      $doc    Summary.
	 * @param array|null $report Decoded report (ok only).
	 *
	 * @return \Generator Lines without newlines.
	 */
	protected function table_lines( array $doc, $report ) {
		$site   = $this->site;
		$target = $this->target;
		switch ( $doc['status'] ) {
			case 'busy':
				yield "A check of $site is already running.";
				return;
			case 'no_code_found':
				yield $this->no_code_text();
				return;
			case 'cancelled':
				yield "The check of $site for PHP $target was cancelled.";
				return;
			case 'tool_error':
				yield "The check of $site for PHP $target didn't finish: " . self::clean( $doc['detail'] );
				return;
		}

		$tail = sprintf( '%s. %s checked.', self::count_of( $doc['warnings'], 'warning' ), self::count_of( $doc['files_checked'], 'file' ) );
		$core = isset( $doc['wordpress'] ) && null !== $this->wp_version ? self::wp_supports( $this->wp_version['branch'], $target, $this->current_php() ) : null;
		$why  = null === $core ? null : $core['why'];
		if ( false === $doc['safe_to_upgrade'] ) {
			if ( $doc['errors'] > 0 ) {
				yield sprintf( 'Not safe to upgrade %s to PHP %s: %s in %s. %s', $site, $target, self::count_of( $doc['errors'], 'error' ), self::count_of( $doc['components_with_errors'], 'component' ), $tail );
				if ( null !== $why ) {
					yield ucfirst( $why ) . '.';
				}
			} else {
				yield sprintf( 'Not safe to upgrade %s to PHP %s: %s. %s', $site, $target, $why, $tail );
			}
		} elseif ( true === $doc['safe_to_upgrade'] ) {
			// Only what was checked: never "safe" for the whole site.
			$what  = $this->wp_layout ? 'plugins, themes, mu-plugins, drop-ins and config' : 'all PHP';
			$found = 0 === $doc['warnings'] ? 'problems' : 'errors';
			$line  = sprintf( 'No PHP %s %s found in the %s checked in %s (%s in %s).', $target, $found, self::count_of( $doc['files_checked'], 'file' ), $site, $what, self::clean( (string) $this->looked_in ) );
			if ( null !== $core ) {
				$line .= ' ' . ( null === $why ? sprintf( 'WordPress %s supports PHP %s', $this->wp_version['branch'], $target ) : ucfirst( $why ) ) . '.';
			}
			yield $line . ( $doc['warnings'] > 0 ? ' ' . self::count_of( $doc['warnings'], 'warning' ) . '.' : '' );
		} else {
			if ( $doc['files_not_checked'] > 0 ) {
				$could = 1 === $doc['files_not_checked'] ? 'file couldn\'t' : 'files couldn\'t';
				yield sprintf( 'Can\'t confirm %s is safe for PHP %s: %d %s be checked. %s', $site, $target, $doc['files_not_checked'], $could, $tail );
				if ( null !== $why ) {
					yield ucfirst( $why ) . '.';
				}
			} elseif ( null !== $why ) {
				yield sprintf( 'Can\'t confirm %s is safe for PHP %s: %s. %s', $site, $target, $why, $tail );
			} else {
				yield sprintf( 'Can\'t confirm %s is safe for PHP %s: %s. %s', $site, $target, self::cross_text( $this->cross ), $tail );
			}
			if ( ( $doc['files_not_checked'] > 0 || null !== $why ) && ! self::cross_ok( $this->cross ) ) {
				yield ucfirst( self::cross_text( $this->cross ) ) . '.';
			}
		}
		yield '';

		$warnings_only = [];
		foreach ( $report['components'] as $component ) {
			if ( $component['errors'] > 0 ) {
				yield sprintf( '%s: %s, %s', self::component_heading( $component ), self::count_of( $component['errors'], 'error' ), self::count_of( $component['warnings'], 'warning' ) );
				foreach ( $component['findings'] as $finding ) {
					if ( 'ERROR' !== $finding['type'] ) {
						continue;
					}
					yield '  ' . self::clean( $finding['file'] ) . ':' . $finding['line'];
					yield '    ' . self::clean( $finding['message'] );
					yield '    ' . self::clean( $finding['source'] );
				}
				yield '';
			} elseif ( $component['warnings'] > 0 ) {
				$warnings_only[] = self::component_name( $component ) . ' (' . $component['warnings'] . ')';
			}
		}
		if ( $warnings_only ) {
			yield 'Warnings only: ' . implode( ', ', $warnings_only );
			yield '';
		}

		if ( $report['not_checked'] ) {
			yield 'Not checked:';
			foreach ( $report['not_checked'] as $entry ) {
				yield '  ' . self::place( $entry ) . ': ' . self::clean( $entry['file'] ) . ' (' . self::clean( $entry['reason'] ) . ')';
			}
			if ( null !== $this->wp_version ) {
				yield '  WordPress core files (only its version is checked)';
			}
			yield '';
		} elseif ( null !== $this->wp_version ) {
			yield 'Not checked: WordPress core files (only its version is checked).';
			yield '';
		}

		if ( isset( $doc['report'] ) ) {
			yield 'Full report: ' . $doc['report'];
			yield '';
		}

		$tool = isset( $doc['tool']['phpcompatibility'] ) && is_string( $doc['tool']['phpcompatibility'] ) ? ' ' . self::clean( $doc['tool']['phpcompatibility'] ) : '';
		// Core's files are never scanned; its version is compared with the PHP versions WordPress says it supports.
		$scope = $this->wp_layout ? ' WordPress core is checked only by its version, against the PHP versions WordPress lists as supported.' : '';
		yield "This is static analysis with PHPCompatibility$tool.$scope Its coverage isn't complete and it can't see breakage that only shows at runtime.";
	}

	/**
	 * @param array $doc --cancel document.
	 *
	 * @return string
	 */
	protected function cancel_text( array $doc ) {
		switch ( $doc['status'] ) {
			case 'cancelled':
				return "Cancelled the check of {$this->site}.\n";
			case 'not_running':
				return "No check of {$this->site} is running.\n";
		}

		return "Couldn't cancel the check of {$this->site}: " . self::clean( $doc['detail'] ) . "\n";
	}

	/**
	 * Where the check looked, so a wrong root doesn't read as an empty site.
	 *
	 * @return string
	 */
	protected function no_code_text() {
		$text = "No PHP code found to check in {$this->site}.";
		if ( null === $this->looked_in ) {
			return $text;
		}
		$dir = self::clean( $this->looked_in );
		if ( null === $this->nginx_conf ) {
			return "$text $dir doesn't exist.";
		}
		if ( ! $this->wp_layout ) {
			return "$text Looked for PHP files in $dir.";
		}

		return "$text Looked in $dir: its PHP code except WordPress core, uploads, caches and other skipped directories. The check follows the directory nginx serves (root in {$this->nginx_conf}), its current/ and wp-cli.yml's path:.";
	}

	/**
	 * @param array $doc Estimate document.
	 *
	 * @return string
	 */
	protected function estimate_text( array $doc ) {
		$site   = $this->site;
		$target = $this->target;
		switch ( $doc['status'] ) {
			case 'busy':
				return "An estimate for $site is already running.\n";
			case 'no_code_found':
				return $this->no_code_text() . "\n";
			case 'tool_error':
				return "Couldn't estimate a check of $site for PHP $target: " . self::clean( $doc['detail'] ) . "\n";
		}

		return sprintf(
			"A check of %s for PHP %s should take %s: %s, %s, %s. This is an estimate.\n",
			$site,
			$target,
			self::duration( $doc['estimated_seconds'], $doc['estimated_seconds_low'], $doc['estimated_seconds_high'] ),
			self::count_of( $doc['php_files'], 'PHP file', true ),
			self::size( $doc['php_bytes'] ),
			self::count_of( $doc['components'], 'component', true )
		);
	}

	/**
	 * "about 3 minutes (2 to 4)", in one unit for all three.
	 *
	 * @return string
	 */
	public static function duration( $seconds, $low, $high ) {
		if ( $high < 120 ) {
			list( $unit, $name, $precision ) = [ 1, 'second', 0 ];
		} elseif ( $seconds < 5400 ) {
			list( $unit, $name, $precision ) = [ 60, 'minute', 0 ];
		} else {
			list( $unit, $name, $precision ) = [ 3600, 'hour', 1 ];
		}
		$value = function ( $s ) use ( $unit, $precision ) {
			return max( $precision ? 0.1 : 1, round( $s / $unit, $precision ) );
		};
		$main = $value( $seconds );

		return sprintf( 'about %s %s%s (%s to %s)', $main, $name, 1 == $main ? '' : 's', $value( $low ), $value( $high ) );
	}

	/**
	 * @return string "87 MB", "6.9 MB", "512 KB".
	 */
	public static function size( $bytes ) {
		$mb = $bytes / 1048576;
		if ( $mb >= 10 ) {
			return round( $mb ) . ' MB';
		}
		if ( $mb >= 1 ) {
			return round( $mb, 1 ) . ' MB';
		}

		return max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
	}

	/**
	 * @return string "1 error", "3 errors", "14,614 PHP files".
	 */
	protected static function count_of( $n, $noun, $separators = false ) {
		return ( $separators ? number_format( $n ) : $n ) . " $noun" . ( 1 === $n ? '' : 's' );
	}

	/**
	 * @return string "WPForms Lite 1.9.2 (plugin wpforms-lite)", "mu-plugins (mu-plugin)".
	 */
	protected static function component_heading( array $component ) {
		$detail = $component['type'];
		if ( null !== $component['name'] && '' !== $component['slug'] ) {
			$detail .= ' ' . $component['slug'];
		}
		if ( null !== $component['branch'] ) {
			$detail .= ', branch ' . $component['branch'];
		}

		return self::component_name( $component ) . ' (' . self::clean( $detail ) . ')';
	}

	/**
	 * @return string Name and version, or the slug, or "other files".
	 */
	protected static function component_name( array $component ) {
		if ( null !== $component['name'] && '' !== $component['name'] ) {
			$name = $component['name'];
		} else {
			$name = '' !== $component['slug'] ? $component['slug'] : 'other files';
		}
		if ( null !== $component['version'] && '' !== $component['version'] ) {
			$name .= ' ' . $component['version'];
		}

		return self::clean( $name );
	}

	/**
	 * @return string "plugin huge-plugin", "other, branch main".
	 */
	protected static function place( array $entry ) {
		$place = $entry['type'] . ( '' !== $entry['slug'] ? ' ' . $entry['slug'] : '' ) . ( null !== $entry['branch'] ? ', branch ' . $entry['branch'] : '' );

		return self::clean( $place );
	}

	/**
	 * Text from the scanned code must not drive the terminal: control, bidi and zero-width characters become '?'.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		// Invalid UTF-8 would make the /u pattern fail, and a raw 0x9B byte is a C1 CSI.
		if ( ! preg_match( '//u', $text ) ) {
			$text = preg_replace( '/[\x80-\xFF]/', '?', $text );
		}

		return preg_replace( '/[\x00-\x1F\x7F' . self::UNSAFE_CHARS . ']/u', '?', $text );
	}

	/**
	 * One line of JSON, with characters a terminal would act on escaped (the data stays the same).
	 *
	 * @return string
	 */
	public static function encode( array $doc ) {
		$json = json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | self::JSON_UTF8 );
		if ( false === $json ) {
			return '';
		}

		return preg_replace_callback(
			'/[' . self::UNSAFE_CHARS . ']/u',
			function ( $char ) {
				return substr( json_encode( $char[0] ), 1, -1 );
			},
			$json
		);
	}

	/**
	 * Command, target, duration, verdict and counts only; findings and details never reach ee.log.
	 *
	 * @param array $doc     Printed document.
	 * @param float $elapsed Seconds.
	 */
	protected function log_result( array $doc, $elapsed ) {
		if ( $this->cancel ) {
			EE::debug( sprintf( 'php-compat cancel of %s: status %s, %d s.', $this->site, $doc['status'], (int) round( $elapsed ) ) );

			return;
		}
		$counts = '';
		$keys   = $this->estimate ? [ 'php_files', 'php_bytes', 'components', 'estimated_seconds' ] : [ 'errors', 'warnings', 'files_checked', 'files_not_checked', 'components_with_errors' ];
		foreach ( $keys as $key ) {
			if ( isset( $doc[ $key ] ) && is_int( $doc[ $key ] ) ) {
				$counts .= ", $key {$doc[ $key ]}";
			}
		}
		EE::debug(
			sprintf(
				'php-compat %s of %s for PHP %s: status %s, safe_to_upgrade %s%s, %d s.',
				$this->estimate ? 'estimate' : 'scan',
				$this->site,
				$this->target,
				$doc['status'],
				var_export( isset( $doc['safe_to_upgrade'] ) ? $doc['safe_to_upgrade'] : null, true ),
				$counts,
				(int) round( $elapsed )
			)
		);
	}

	/**
	 * @param string|false $value php.ini size such as 128M.
	 *
	 * @return int Bytes; -1 for no limit.
	 */
	protected static function bytes( $value ) {
		$value = trim( (string) $value );
		if ( '-1' === $value || '' === $value ) {
			return -1;
		}
		$bytes = (int) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}

		return $bytes;
	}

	/**
	 * Raise PHP's memory limit to at least MEMORY_FLOOR before reading a report.
	 */
	protected static function raise_memory_limit() {
		$limit = self::bytes( ini_get( 'memory_limit' ) );
		if ( $limit > 0 && $limit < self::MEMORY_FLOOR ) {
			ini_set( 'memory_limit', (string) self::MEMORY_FLOOR );
		}
	}

	/**
	 * With --format=json stdout holds the document or nothing: a signal ends the run in rollback(), whose "Success:" line goes to stderr instead.
	 */
	protected static function keep_stdout_for_json() {
		$runner = EE::get_runner();
		if ( ! empty( $runner->config['quiet'] ) ) {
			return;
		}
		EE::set_logger(
			new class( $runner->in_color() ) extends \EE\Loggers\Regular {
				public function info( $message ) {
					$this->write( STDERR, $message . "\n" );
				}

				public function success( $message ) {
					$this->_line( $message, 'Success', '%G', STDERR );
				}
			}
		);
	}

	/**
	 * @return int|null Effective uid, null without the posix extension.
	 */
	protected static function euid() {
		return function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
	}

	protected function register_cleanup() {
		if ( ! $this->cleanup_registered ) {
			$this->cleanup_registered = true;
			// EE::error() exits without running finally blocks. EE_Site_Command::shut_down_function() also calls cleanup(), before its rollback() exits.
			register_shutdown_function( [ $this, 'cleanup' ] );
		}
	}

	/**
	 * For `ee site delete`: stop a running check of the site, then remove everything php-compat keeps for it.
	 *
	 * @param object        $site_data Site record.
	 * @param callable|null $dispatch  Signal dispatcher, as for run().
	 *
	 * @return string|null Why the check couldn't be stopped, or null.
	 */
	public function stop_for_delete( $site_data, $dispatch = null ) {
		$this->site     = $site_data->site_url;
		$this->dispatch = $dispatch;
		$parent         = rtrim( $this->temp_base(), '/' ) . '/' . self::TEMP_DIR;
		$name           = 'ee-php-compat-' . $this->site;
		// Never checked on this host: no check runs without the temporary directory, and docker isn't asked.
		if ( ! file_exists( $parent ) && ! is_link( $parent ) ) {
			return $this->hold_for_delete( $site_data );
		}
		$usable = $this->temp_parent();
		if ( null === $usable || null === $this->cancel_path( $usable ) ) {
			// No check can run with these directories (a link, another owner): only a container can be left, and is stopped by name.
			EE::warning( "Leaving $parent as it is: not a directory of this user with mode 0700." );
			if ( false !== $this->container_status( $name ) && ! $this->container_gone( $name ) ) {
				return "could not remove the container $name";
			}
			return $this->hold_for_delete( $site_data );
		}
		$kept = null;
		try {
			$result = $this->run_cancel( $site_data );
			if ( 'tool_error' !== $result['doc']['status'] ) {
				// Kept until this process ends, so no new check of the site starts while it is deleted.
				$kept       = $this->lock;
				$this->lock = null;
			}
		} finally {
			$this->cleanup();
		}
		if ( 'tool_error' === $result['doc']['status'] ) {
			// Once its container is gone the check reads nothing more: a process that doesn't let go of the lock doesn't stop the delete.
			if ( $this->restore_running( $site_data->site_fs_path ) || false !== $this->container_status( $name ) && ! $this->container_gone( $name ) ) {
				return $result['doc']['detail'];
			}
			EE::warning( "The php-compat check of {$this->site} didn't stop cleanly ({$result['doc']['detail']}); its container is gone, so the delete goes on." );
		}
		$this->delete_lock = $kept;
		$parent            = $this->temp_parent();
		if ( null !== $parent && ! $this->wait_for_estimate( $parent ) ) {
			// An estimate only reads the site: stop its container and go on, never refuse the delete.
			$ps = $this->docker( [ 'docker', 'ps', '-aq', '--no-trunc', '--filter', 'label=ee-php-compat-estimate-site=' . $this->site ], 60 );
			foreach ( 0 === $ps['code'] ? preg_split( '/\s+/', trim( $ps['stdout'] ), -1, PREG_SPLIT_NO_EMPTY ) : [] as $id ) {
				$this->docker( [ 'docker', 'rm', '-f', $id ], 60 );
			}
			EE::warning( "Stopped the php-compat estimate of {$this->site}; the delete goes on." );
		}
		if ( null !== $parent ) {
			$files = [ "$parent/.cancel/{$this->site}", "$parent/.cancel-lock/{$this->site}", "$parent/.estimate-lock/{$this->site}" ];
			// Names site-command v3.11.0 used.
			array_push( $files, "$parent/.cancel-{$this->site}", "$parent/.cancel-{$this->site}.lock", "$parent/.estimate-{$this->site}.lock" );
			foreach ( $files as $file ) {
				@unlink( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- usually missing.
			}
			if ( is_dir( "$parent/{$this->site}" ) && ! is_link( "$parent/{$this->site}" ) ) {
				$this->remove_tree( "$parent/{$this->site}" );
			}
		}

		return null;
	}

	/**
	 * Takes the site lock for the rest of a delete, so no check starts meanwhile. Refused only while a restore runs; a lock
	 * another php-compat process keeps past CANCEL_WAIT gives a warning, not a refusal.
	 *
	 * @param object $site_data Site record.
	 *
	 * @return string|null Why the delete can't go on, or null.
	 */
	protected function hold_for_delete( $site_data ) {
		if ( ! $this->lock_site( $site_data->site_fs_path ) ) {
			if ( $this->restore_running( $site_data->site_fs_path ) ) {
				return 'a restore of the site is running';
			}
			if ( ! $this->wait_for_site_lock( $site_data->site_fs_path, $this->cancel_wait() ) ) {
				EE::warning( "Another php-compat process of {$this->site} still holds the site's lock after " . $this->cancel_wait() . ' s; the delete goes on.' );
				return null;
			}
		}
		$this->delete_lock = $this->lock;
		$this->lock        = null;

		return null;
	}

	/**
	 * Wait for a running estimate of the site to end (it holds its lock file), so its file isn't removed under it.
	 *
	 * @param string $parent temp_parent().
	 *
	 * @return bool Whether none runs now.
	 */
	protected function wait_for_estimate( $parent ) {
		$handle = @fopen( "$parent/.estimate-lock/{$this->site}", 're' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing: none ran.
		if ( false === $handle ) {
			return true;
		}
		$until  = microtime( true ) + $this->estimate_wait();
		$logged = false;
		while ( ! flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			if ( $would_block && ! $logged ) {
				EE::log( "Waiting for the running php-compat estimate of {$this->site} to finish." );
				$logged = true;
			}
			if ( ! $would_block || microtime( true ) >= $until ) {
				fclose( $handle );

				return ! $would_block;
			}
			usleep( 100000 );
			if ( function_exists( 'pcntl_signal_dispatch' ) ) {
				null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
			}
		}
		// Unlinked by the caller while still locked, so a new estimate gets a new file.
		$this->estimate_lock = $handle;

		return true;
	}

	/**
	 * @return int Seconds `ee site delete` waits for a running estimate to end on its own before it stops it.
	 */
	protected function estimate_wait() {
		return 10;
	}

	/**
	 * Remove a scan container that may still exist, then, once it is gone, the temporary directory; release the lock. Safe to call twice.
	 */
	public function cleanup() {
		$this->cleaning = true;
		if ( null !== $this->active_container ) {
			$name                   = $this->active_container;
			$this->active_container = null;
			// /out is the container's until it is gone: never remove it under a running one.
			if ( ! $this->container_gone( $name ) && null !== $this->temp_dir ) {
				EE::warning( "Left {$this->temp_dir} in place: the container $name could not be removed." );
				$this->temp_dir = null;
			}
		}
		if ( null !== $this->estimate_label ) {
			$label                = 'label=ee-php-compat-estimate=' . $this->estimate_label;
			$this->estimate_label = null;
			$ids                  = $this->docker( [ 'docker', 'ps', '-aq', '--filter', $label ], 60 );
			foreach ( preg_split( '/\s+/', trim( $ids['stdout'] ), -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
				$this->docker( [ 'docker', 'rm', '-f', $id ], 60 );
			}
		}
		if ( null !== $this->temp_dir ) {
			$dir            = $this->temp_dir;
			$this->temp_dir = null;
			if ( $this->remove_tree( $dir ) ) {
				// The per-site directory, once empty.
				@rmdir( dirname( $dir ) ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a leftover in it keeps it.
			} else {
				EE::warning( "Could not remove $dir." );
			}
		}
		if ( null !== $this->output_tmp ) {
			@unlink( $this->output_tmp ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$this->output_tmp = null;
		}
		// Still holding the lock: a --cancel that came during cleanup gets the same answer as this scan prints. An ok result
		// stands; its request is then left for --cancel to read as not_running.
		if ( null !== $this->result_status && ! in_array( $this->result_status, [ 'ok', 'cancelled' ], true ) && $this->cancel_requested() ) {
			@unlink( $this->cancel_marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$this->cancel_marker = null;
			$this->late_cancel   = true;
		}
		if ( null !== $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
		if ( null !== $this->cancel_guard ) {
			fclose( $this->cancel_guard );
			$this->cancel_guard = null;
		}
		if ( null !== $this->image_lock ) {
			fclose( $this->image_lock );
			$this->image_lock = null;
		}
		// Without the lock, a request that comes now is for another scan: a second cleanup() leaves it.
		$this->cancel_marker = null;
		$this->cleaning      = false;
	}
}
