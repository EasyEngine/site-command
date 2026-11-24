<?php

namespace EE\Site\Type;

use EE;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use function EE\Utils\delem_log;
use function EE\Utils\get_flag_value;

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
	const SUMMARY_KEYS  = [ 'v', 'status', 'site', 'target', 'safe_to_upgrade', 'errors', 'warnings', 'files_checked', 'files_not_checked', 'php_bytes', 'components_checked', 'components_with_errors', 'duration_s', 'tool' ];
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
	// Constants that move the code WordPress loads away from <root>/wp-content.
	const DIR_CONSTANTS = [ 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ];

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

	/** @var string|null Host directory the scan looked in, for the no_code_found text; null when app/ is missing. */
	protected $looked_in;

	/** @var string|null The site's nginx config path, for the no_code_found text; null when app/ is missing. */
	protected $nginx_conf;

	/** @var bool Whether the scan looks at WordPress code (wp-content) rather than all of htdocs. */
	protected $wp_layout = false;

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

		$this->site     = $site_data->site_url;
		$this->target   = (string) get_flag_value( $assoc_args, 'php', '' );
		$this->estimate = (bool) get_flag_value( $assoc_args, 'estimate', false );
		$this->cancel   = (bool) get_flag_value( $assoc_args, 'cancel', false );
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
			}
		} finally {
			$this->cleanup();
		}

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

		$this->log_result( $result['doc'], microtime( true ) - $started );
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
			$this->looked_in = $site_data->site_fs_path . '/app';
			return $this->doc( 'no_code_found' );
		}
		$layout = $this->resolve_layout( $site_data, $code );
		if ( null !== $layout ) {
			return $layout;
		}
		if ( false !== strpbrk( $code, ',"' ) ) {
			return $this->doc( 'tool_error', 'the site code path has a comma or a quote' );
		}

		// Two EE runs can't both pass the container check below and then clear each other's directory as a leftover.
		if ( ! $this->lock_site( $site_data->site_fs_path ) ) {
			return $this->doc( 'busy' );
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
		if ( 'exited' === $status ) {
			EE::debug( "Removing the leftover container $name." );
			// Known limit: a container whose --rm is still in progress can't be removed again, which reads as this tool_error.
			if ( 0 !== $this->docker( [ 'docker', 'rm', '-f', $name ], 60 )['code'] ) {
				return $this->doc( 'tool_error', "could not remove the leftover container $name" );
			}
		}

		if ( ! $this->ensure_image( $image ) ) {
			return $this->doc( 'tool_error', 'image pull failed' );
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
			if ( self::decode_cost( $raw ) > $this->memory_available() ) {
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

		// Last point a cancel wins: after it, the result stands and --output is written.
		if ( $this->cancel_requested() ) {
			return $this->cancelled();
		}

		$summary = $this->summary( $doc );
		if ( null !== $output ) {
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
			$this->looked_in = $site_data->site_fs_path . '/app';
			return $this->doc( 'no_code_found' );
		}
		$layout = $this->resolve_layout( $site_data, $code );
		if ( null !== $layout ) {
			return $layout;
		}
		if ( false !== strpbrk( $code, ',"' ) ) {
			return $this->doc( 'tool_error', 'the site code path has a comma or a quote' );
		}
		// One estimate per site at a time; it never waits for a scan.
		$locked = $this->lock_estimate();
		if ( null === $locked ) {
			return $this->doc( 'tool_error', 'could not create the estimate lock' );
		}
		if ( ! $locked ) {
			return $this->doc( 'busy' );
		}
		if ( ! $this->ensure_image( $image ) ) {
			return $this->doc( 'tool_error', 'image pull failed' );
		}

		$run = $this->docker( $this->docker_run_args( 'estimate', $image, $site_data, $code ), self::ESTIMATE_TIMEOUT + self::PROCESS_MARGIN, true );
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
		$this->hold_cancel_guard( $marker );

		if ( ! $this->lock_site( $site_data->site_fs_path ) ) {
			// A scan holds the lock: ask it to stop, then wait until it has removed its container, cleaned up and let go.
			$token = $this->request_cancel( $marker );
			if ( null === $token ) {
				return $this->doc( 'tool_error', 'could not write the cancel request' );
			}
			$grace  = min( self::CANCEL_GRACE, $this->cancel_wait() );
			$locked = $this->wait_for_site_lock( $site_data->site_fs_path, $grace );
			if ( ! $locked ) {
				// Untaken: a killed scan's docker client (it inherits the lock) or a frozen scan holds the lock. The id is read
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
	 * @return string
	 */
	protected function cancel_path( $parent ) {
		return "$parent/.cancel-{$this->site}";
	}

	/**
	 * Held from before --cancel writes its request until it ends, so no scan clears that request as stale. Exclusive: a second
	 * --cancel waits for the first, so it never replaces a request the first still waits on.
	 *
	 * @param string $marker cancel_path().
	 */
	protected function hold_cancel_guard( $marker ) {
		$guard = @fopen( "$marker.lock", 'c' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
		if ( false === $guard ) {
			return;
		}
		// Polled, so signals still reach the handlers; the holder ends within cancel_wait() and its sweep.
		while ( ! flock( $guard, LOCK_EX | LOCK_NB, $would_block ) ) {
			if ( ! $would_block ) {
				fclose( $guard );
				return;
			}
			usleep( 100000 );
			if ( function_exists( 'pcntl_signal_dispatch' ) ) {
				null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
			}
		}
		$this->cancel_guard = $guard;
	}

	/**
	 * With the site lock: remove a request no --cancel waits on any more (its scan was killed, or the cancel gave up or was killed).
	 * A request of a waiting --cancel is for this scan, even one written for a scan that ended just before this one started.
	 */
	protected function clear_stale_cancel() {
		// Not created here: a --cancel creates it before its request, so while it is missing any request is a live one.
		$guard = @fopen( $this->cancel_marker . '.lock', 'r' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing is handled.
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
		$tmp   = "$marker.$token";
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
		$args = [ 'docker', 'run', '--rm', '--init' ];
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
	 * @return string|null Real path, or null when it isn't a directory.
	 */
	protected function code_dir( $site_fs_path ) {
		$real = realpath( $site_fs_path . '/app' );

		return ( false !== $real && is_dir( $real ) ) ? $real : null;
	}

	/**
	 * Picks the scanner's path argument from the root nginx serves, and refuses a WordPress layout the scanner can't check.
	 *
	 * @param object $site_data Site record.
	 * @param string $code      Real path of the site's app/.
	 *
	 * @return array|null tool_error result, or null with $this->scan_path set.
	 */
	protected function resolve_layout( $site_data, $code ) {
		$this->nginx_conf = $site_data->site_fs_path . self::NGINX_CONF;
		$served           = $this->served_root( $site_data );
		$rel              = ltrim( substr( $served, strlen( '/var/www' ) ), '/' );
		$app              = $site_data->site_fs_path . '/app/';

		$this->scan_path = $served;
		if ( 'wp' !== $site_data->site_type ) {
			// The scanner checks all of htdocs for php sites, whatever the root.
			$this->looked_in = $app . 'htdocs';
			return null;
		}

		$this->wp_layout = true;
		$root            = $this->wp_root( $code, $rel );
		if ( null === $root ) {
			// No WordPress found here; a wp-config that moves its code elsewhere still rules out no_code_found.
			$root = $rel;
		}
		$this->scan_path = '/var/www/' . $root;
		$this->looked_in = $app . $root . '/wp-content';
		EE::debug( "php-compat: WordPress root $root (nginx root $served)" );

		$detail = $this->check_wp_dirs( $code, $root, $app );
		return null === $detail ? null : $this->doc( 'tool_error', $detail );
	}

	/**
	 * The root nginx serves (main.conf), or the public directory in EE's record when that can't be read.
	 *
	 * @param object $site_data Site record.
	 *
	 * @return string Path under /var/www/htdocs.
	 */
	protected function served_root( $site_data ) {
		$db   = rtrim( (string) $site_data->site_container_fs_path, '/' );
		$db   = '' === $db ? '/var/www/htdocs' : $db;
		$conf = $this->nginx_conf;
		$text = is_file( $conf ) && filesize( $conf ) <= self::CONFIG_MAX ? @file_get_contents( $conf ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.
		// Exactly one root, as EE writes it; anything else is a config this check doesn't guess at.
		$root = ( false !== $text && 1 === preg_match_all( '/^\s*root\s+([^\s;]+)\s*;/m', $text, $m ) ) ? rtrim( $m[1][0], '/' ) : null;
		if ( null === $root || ! preg_match( self::ROOT_PATTERN, $root ) ) {
			EE::debug( "php-compat: no usable root in $conf; using the public directory $db" );
			return $db;
		}
		if ( $root !== $db ) {
			EE::warning( sprintf( "EE's record of %s has %s as its public directory, but nginx serves %s (root in %s). Checking what nginx serves.", $this->site, $db, $root, $conf ) );
		}

		return $root;
	}

	/**
	 * WordPress root relative to app/: the served root or its current/ (as backup finds it), else wp-cli.yml's path.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Served root relative to app/.
	 *
	 * @return string|null
	 */
	protected function wp_root( $code, $rel ) {
		$candidates = \EE\Site\Utils\get_wp_root_candidates( $rel );
		foreach ( array_unique( [ $rel, 'htdocs' ] ) as $dir ) {
			$path = $this->wp_cli_path( $code, $dir );
			if ( null !== $path ) {
				$candidates[] = $path;
			}
		}
		$candidates = array_values( array_unique( $candidates ) );
		// A WordPress directory without wp-content may still load code from elsewhere (WP_CONTENT_DIR).
		foreach ( [ 'wp-content', 'wp-settings.php' ] as $marker ) {
			foreach ( $candidates as $candidate ) {
				if ( null !== self::resolve_in( $code, $candidate . '/' . $marker ) ) {
					return $candidate;
				}
			}
		}

		return null;
	}

	/**
	 * `path:` of <dir>/wp-cli.yml, relative to app/.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $dir  Directory relative to app/.
	 *
	 * @return string|null
	 */
	protected function wp_cli_path( $code, $dir ) {
		$text = $this->read_site_file( $code, $dir . '/wp-cli.yml' );
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
		$path = trim( preg_replace( '#/+#', '/', preg_replace( '#(^|/)\.(?=/|$)#', '$1', $path ) ), '/' );

		return preg_match( '#^htdocs(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#D', $path ) ? $path : null;
	}

	/**
	 * Refuses a verdict when wp-config moves the code WordPress loads where the scanner doesn't look.
	 *
	 * The scanner checks <path>/wp-content with plugins/ and mu-plugins/ in it, so only a content directory named
	 * wp-content can be followed (by scanning its parent).
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $root WordPress root relative to app/.
	 * @param string $app  The site's app/ path with a trailing slash, for messages.
	 *
	 * @return string|null tool_error detail, or null when the scan covers what WordPress loads.
	 */
	protected function check_wp_dirs( $code, $root, $app ) {
		$abspath = self::resolve_in( $code, $root );
		$values  = [];
		foreach ( $this->wp_config_files( $code, $root, $abspath ) as $file ) {
			$found = self::dir_defines( (string) $this->read_site_file( $code, $file ) );
			if ( is_string( $found ) ) {
				return "can't resolve $found in $app$file, so this check can't tell which plugins and themes WordPress loads. It follows only define( '$found', <__DIR__, dirname( __FILE__ ), dirname( __DIR__ ), ABSPATH or WP_CONTENT_DIR> . '<path>' ) or a /var/www path";
			}
			foreach ( $found as $name => $expr ) {
				if ( isset( $values[ $name ] ) ) {
					return "can't resolve $name in $app$file: it is defined more than once";
				}
				$values[ $name ] = [ $expr, $file ];
			}
		}

		$content = $root . '/wp-content';
		foreach ( self::DIR_CONSTANTS as $name ) {
			if ( ! isset( $values[ $name ] ) ) {
				continue;
			}
			list( $expr, $file ) = $values[ $name ];
			$dir = $this->dir_value( $code, $expr, $file, $abspath, $content );
			if ( null === $dir ) {
				return "can't resolve $name in $app$file";
			}
			$real = self::resolve_in( $code, $dir );
			if ( null === $real ) {
				return "$name in $app$file points to $app$dir, which doesn't exist";
			}
			$default = 'WP_CONTENT_DIR' === $name ? $content : $content . ( 'WP_PLUGIN_DIR' === $name ? '/plugins' : '/mu-plugins' );
			if ( self::resolve_in( $code, $default ) === $real ) {
				continue;
			}
			if ( 'WP_CONTENT_DIR' === $name && 'wp-content' === basename( $real ) ) {
				// The scanner checks <path>/wp-content: point it at the parent of this one.
				$content         = substr( $real, strlen( $code ) + 1 );
				$this->scan_path = '/var/www/' . dirname( $content );
				$this->looked_in = $app . $content;
				continue;
			}
			$what = 'WP_CONTENT_DIR' === $name ? 'plugins and themes' : ( 'WP_PLUGIN_DIR' === $name ? 'plugins' : 'must-use plugins' );
			return "WordPress loads $what from $app$dir ($name in $app$file); this check can't scan that layout yet";
		}

		return null;
	}

	/**
	 * The wp-config.php WordPress loads (in its directory, else the parent without wp-settings.php), and Bedrock's
	 * config/application.php beside that parent when there is one. Paths relative to app/.
	 *
	 * @param string      $code    Real path of the site's app/.
	 * @param string      $root    WordPress root relative to app/.
	 * @param string|null $abspath Real WordPress root.
	 *
	 * @return string[]
	 */
	protected function wp_config_files( $code, $root, $abspath ) {
		if ( null === $abspath ) {
			return [];
		}
		$real_root = substr( $abspath, strlen( $code ) + 1 );
		$config    = null;
		if ( null !== self::resolve_in( $code, $real_root . '/wp-config.php' ) ) {
			$config = $real_root . '/wp-config.php';
		} else {
			// EE's own sites keep it in app/, above htdocs.
			foreach ( array_unique( [ dirname( $real_root ), dirname( $root ) ] ) as $parent ) {
				$parent = '.' === $parent ? '' : "$parent/";
				if ( null !== self::resolve_in( $code, "{$parent}wp-config.php" ) && null === self::resolve_in( $code, "{$parent}wp-settings.php" ) ) {
					$config = "{$parent}wp-config.php";
					break;
				}
			}
		}
		if ( null === $config ) {
			return [];
		}
		$real  = self::resolve_in( $code, $config );
		$files = [ substr( $real, strlen( $code ) + 1 ) ];
		$dir   = dirname( dirname( $files[0] ) );
		if ( '.' !== $dir && null !== self::resolve_in( $code, "$dir/config/application.php" ) ) {
			$files[] = "$dir/config/application.php";
		}

		return $files;
	}

	/**
	 * Plain defines of the directory constants in a PHP file, read without running it.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array|string Constant => value expression (whitespace and comments removed), or the name of a constant
	 *                      the file sets or uses in any other way.
	 */
	public static function dir_defines( $source ) {
		$code   = '';
		$depths = [];
		$level  = 0;
		$skip   = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML ];
		foreach ( @token_get_all( $source ) as $token ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a broken file still tokenises.
			if ( is_array( $token ) && in_array( $token[0], $skip, true ) ) {
				continue;
			}
			$text                    = is_array( $token ) ? $token[1] : $token;
			$depths[ strlen( $code ) ] = $level;
			$code                   .= $text;
			if ( '{' === $text || ( is_array( $token ) && in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
				$level++;
			} elseif ( '}' === $text ) {
				$level--;
			}
		}

		$names = implode( '|', self::DIR_CONSTANTS );
		$spans = [];
		$found = [];
		// A top-level define, also inside its own `if ( ! defined( 'X' ) )` guard.
		preg_match_all( "/(?:\\bif\\(!defined\\((['\"])($names)\\1\\)\\)(\\{)?)?\\bdefine\\((['\"])($names)\\4,(.*?)\\);(?(3)\\})/i", $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $matches as $match ) {
			$name = $match[5][0];
			$at   = $match[0][1];
			if ( ( '' !== $match[2][0] && $match[2][0] !== $name ) || ! isset( $depths[ $at ] ) || 0 !== $depths[ $at ] || isset( $found[ $name ] ) ) {
				return $name;
			}
			$found[ $name ] = $match[6][0];
			$spans[]        = [ $at, $at + strlen( $match[0][0] ) ];
		}
		preg_match_all( "/\\bdefined\\((['\"])(?:$names)\\1\\)/i", $code, $checks, PREG_OFFSET_CAPTURE );
		foreach ( $checks[0] as $check ) {
			$spans[] = [ $check[1], $check[1] + strlen( $check[0] ) ];
		}
		preg_match_all( "/\\b(?:$names)\\b/", $code, $uses, PREG_OFFSET_CAPTURE );
		foreach ( $uses[0] as $use ) {
			$covered = false;
			foreach ( $spans as $span ) {
				$covered = $covered || ( $use[1] >= $span[0] && $use[1] < $span[1] );
			}
			if ( ! $covered ) {
				return $use[0];
			}
		}

		return $found;
	}

	/**
	 * A directory constant's value relative to app/, from the plain forms dir_defines() returns.
	 *
	 * @param string      $code    Real path of the site's app/.
	 * @param string      $expr    Value expression.
	 * @param string      $file    File it is defined in, relative to app/ (real).
	 * @param string|null $abspath Real WordPress root.
	 * @param string      $content WP_CONTENT_DIR relative to app/.
	 *
	 * @return string|null
	 */
	protected function dir_value( $code, $expr, $file, $abspath, $content ) {
		$string = '\'[^\'\\\\]*\'|"[^"\\\\$]*"';
		if ( preg_match( "/^($string)$/", $expr, $m ) ) {
			$path = substr( $m[1], 1, -1 );
			if ( 0 !== strpos( $path . '/', '/var/www/' ) ) {
				return null;
			}
			$base = '';
			$path = substr( $path, strlen( '/var/www/' ) );
		} elseif ( preg_match( "/^(__DIR__|dirname\\(__FILE__\\)|dirname\\(__DIR__\\)|dirname\\(dirname\\(__FILE__\\)\\)|ABSPATH|WP_CONTENT_DIR)\\.($string)$/i", $expr, $m ) ) {
			$dir = dirname( $file );
			if ( '.' === $dir && 0 === stripos( $m[1], 'dirname(d' ) ) {
				return null;
			}
			$base = [
				'__dir__'                     => $dir,
				'dirname(__file__)'           => $dir,
				'dirname(__dir__)'            => dirname( $dir ),
				'dirname(dirname(__file__))'  => dirname( $dir ),
				'abspath'                     => null === $abspath ? null : substr( $abspath, strlen( $code ) + 1 ),
				'wp_content_dir'              => $content,
			][ strtolower( $m[1] ) ];
			if ( null === $base ) {
				return null;
			}
			$base = '.' === $base ? '' : $base;
			$path = substr( $m[2], 1, -1 );
		} else {
			return null;
		}
		$parts = [];
		foreach ( explode( '/', $base . '/' . $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! $parts ) {
					return null;
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}

		return $parts ? implode( '/', $parts ) : null;
	}

	/**
	 * A file under app/, read the way the scanner container sees it, never from outside app/.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Path relative to app/.
	 *
	 * @return string|null
	 */
	protected function read_site_file( $code, $rel ) {
		$path = self::resolve_in( $code, $rel );
		if ( null === $path || ! is_file( $path ) || filesize( $path ) > self::CONFIG_MAX ) {
			return null;
		}
		$text = @file_get_contents( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- false is handled.

		return false === $text ? null : $text;
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
				if ( false === $target || ++$hops > 40 ) {
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
			if ( ! file_exists( $path ) ) {
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
		$handle = @fopen( $site_fs_path, 'r' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
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
	 * @return bool|null False when another estimate of the site holds the lock; null when the lock file can't be made.
	 */
	protected function lock_estimate() {
		$parent = $this->temp_parent();
		// Not in the per-site directory, whose leftovers a scan removes; a site name never starts with a dot.
		$handle = null === $parent ? false : @fopen( "$parent/.estimate-{$this->site}.lock", 'c' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
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
	 * @return string|false running, exited, or false when there is none.
	 */
	protected function container_status( $name ) {
		return \EE_DOCKER::container_status( $name );
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
	 * Pull on first use; most servers never run a check, so a fresh install doesn't pull it.
	 *
	 * @param string $image Image with tag.
	 *
	 * @return bool Whether it is present.
	 */
	protected function ensure_image( $image ) {
		if ( 0 === $this->docker( [ 'docker', 'image', 'inspect', '--format', '{{.Id}}', $image ], 60 )['code'] ) {
			return true;
		}
		EE::debug( "Pulling $image." );
		if ( 0 !== $this->docker( [ 'docker', 'pull', $image ], self::PULL_TIMEOUT )['code'] ) {
			return false;
		}
		$this->remove_other_tags( $image );

		return true;
	}

	/**
	 * After pulling a new tag, remove the scanner's other tags that no container uses; failures only cost disk.
	 *
	 * @param string $image The pinned image with its tag, never removed.
	 */
	protected function remove_other_tags( $image ) {
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
		if ( is_link( $dir ) || ! is_dir( $dir ) || ! chmod( $dir, 0700 ) || ( 0 === self::euid() && ! ( chown( $dir, 33 ) && chgrp( $dir, 33 ) ) ) ) {
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
	 * @return int Bytes the report may still take: up to MEMORY_FLOOR when there is no limit.
	 */
	protected function memory_available() {
		$limit = self::bytes( ini_get( 'memory_limit' ) );

		return ( $limit > 0 ? $limit : self::MEMORY_FLOOR ) - memory_get_usage();
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
		$ok = $ok && ( 0 !== self::euid() || ( @lchown( $tmp, 0 ) && @lchgrp( $tmp, 0 ) ) ) && $this->output_dir_unchanged( $path ) && @rename( $tmp, $target ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
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
		if ( false === $doc['safe_to_upgrade'] ) {
			yield sprintf( 'Not safe to upgrade %s to PHP %s: %s in %s. %s', $site, $target, self::count_of( $doc['errors'], 'error' ), self::count_of( $doc['components_with_errors'], 'component' ), $tail );
		} elseif ( true === $doc['safe_to_upgrade'] ) {
			yield sprintf( 'Safe to upgrade %s to PHP %s: 0 errors. %s', $site, $target, $tail );
		} else {
			$could = 1 === $doc['files_not_checked'] ? 'file couldn\'t' : 'files couldn\'t';
			yield sprintf( 'Can\'t confirm %s is safe for PHP %s: %d %s be checked. %s', $site, $target, $doc['files_not_checked'], $could, $tail );
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
			yield '';
		}

		if ( isset( $doc['report'] ) ) {
			yield 'Full report: ' . $doc['report'];
			yield '';
		}

		$tool = isset( $doc['tool']['phpcompatibility'] ) && is_string( $doc['tool']['phpcompatibility'] ) ? ' ' . self::clean( $doc['tool']['phpcompatibility'] ) : '';
		yield "This is static analysis with PHPCompatibility$tool. Its coverage isn't complete and it can't see breakage that only shows at runtime.";
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

		return "$text Looked for plugins, themes and other WordPress code in $dir. The check follows the directory nginx serves (root in {$this->nginx_conf}), its current/ and wp-cli.yml's path:.";
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
		if ( null !== $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
		if ( null !== $this->cancel_guard ) {
			fclose( $this->cancel_guard );
			$this->cancel_guard = null;
		}
		$this->cleaning = false;
	}
}
