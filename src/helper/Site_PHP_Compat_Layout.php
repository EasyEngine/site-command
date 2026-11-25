<?php

namespace EE\Site\Type;

/**
 * The WordPress files php-compat must understand before it gives a verdict: the served index.php and the config WordPress
 * loads before wp-settings.php. Exact recognisers over PHP tokens (an allowlist): anything they don't recognise is refused
 * with a reason, never guessed at. Nothing here reads the filesystem; callers pass sources and paths.
 */
class Site_PHP_Compat_Layout {

	/** Directory constants WordPress loads code from, relative to the WordPress root: only their default value is accepted. */
	const DIR_DEFAULTS = [
		'WP_CONTENT_DIR'  => 'wp-content',
		'WP_PLUGIN_DIR'   => 'wp-content/plugins',
		'WPMU_PLUGIN_DIR' => 'wp-content/mu-plugins',
	];

	/** Constants a config may never set: each moves core or theme code WordPress loads. */
	const NEVER_DEFINED = [ 'WPINC', 'TEMPLATEPATH', 'STYLESHEETPATH', 'WP_LANG_DIR', 'PLUGINDIR', 'MUPLUGINDIR' ];

	/** The only variables a config may assign (table prefix, multisite, EE's --cache and memcached object caches). */
	const VARIABLES = [ 'table_prefix', 'base', 'redis_server', 'memcached_servers' ];

	/** The only $_SERVER keys a config may set: what proxy and HTTPS snippets set, never where the script is. */
	const SERVER_KEYS = [ 'HTTPS', 'SERVER_PORT', 'REMOTE_ADDR', 'HTTP_HOST', 'SERVER_NAME', 'REQUEST_SCHEME', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_SSL', 'HTTP_X_REAL_IP' ];

	/** The only settings ini_set() may change in a config. */
	const INI_KEYS = [ 'display_errors', 'display_startup_errors', 'log_errors', 'error_log', 'error_reporting', 'memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars', 'upload_max_filesize', 'post_max_size', 'default_socket_timeout', 'date.timezone', 'session.gc_maxlifetime' ];

	/** The only functions an expression may call: none has a side effect or loads code. */
	const PURE_FUNCTIONS = [ 'defined', 'dirname', 'getenv', 'strpos', 'stripos', 'strtolower', 'strtoupper', 'in_array', 'explode', 'trim', 'file_exists', 'isset', 'empty', 'array' ];

	/** Statements (besides define/assignment/include) a config may make, each with any allowed expressions as arguments. */
	const PURE_STATEMENTS = [ 'ini_set', 'error_reporting' ];

	/** Files below the WordPress root that EE's own --vip config loads, and how each is checked. */
	const VIP_FILES = [
		'wp-content/mu-plugins/000-pre-vip-config/requires.php' => 'requires',
		'wp-content/vip-config/vip-config.php'                  => 'vip-config',
	];

	/**
	 * The significant tokens of a PHP file: comments and whitespace dropped, `\name` read as `name`, a closing `?>` read as
	 * `;`. Refuses whatever the host's tokenizer and the site's PHP could read differently or that is not plain code.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array|string List of [ id, text, line ] (id is the T_* constant or the character), or the reason it is refused.
	 */
	public static function tokens( $source ) {
		$raw = @token_get_all( $source ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a broken file still tokenises.
		if ( ! $raw || ! is_array( $raw[0] ) || T_OPEN_TAG !== $raw[0][0] || 0 !== strncasecmp( $raw[0][1], '<?php', 5 ) ) {
			return 'it does not start with <?php';
		}
		$out  = [];
		$line = 1;
		$fq   = defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : -1;
		$skip = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
		$n    = count( $raw );
		for ( $i = 1; $i < $n; $i++ ) {
			$t = $raw[ $i ];
			if ( ! is_array( $t ) ) {
				$out[] = [ $t, $t, $line ];
				continue;
			}
			$line = $t[2];
			if ( T_COMMENT === $t[0] && 0 === strpos( $t[1], '#[' ) || defined( 'T_ATTRIBUTE' ) && T_ATTRIBUTE === $t[0] ) {
				return "line $line has an attribute, which PHP 7 reads as a comment";
			}
			if ( in_array( $t[0], $skip, true ) ) {
				continue;
			}
			if ( T_CLOSE_TAG === $t[0] ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					if ( ! is_array( $raw[ $j ] ) || T_INLINE_HTML !== $raw[ $j ][0] || '' !== trim( $raw[ $j ][1] ) ) {
						return "line $line has more after ?>";
					}
				}
				$last = $out ? end( $out )[0] : null;
				if ( ';' !== $last && '}' !== $last && null !== $last ) {
					$out[] = [ ';', '?>', $line ];
				}
				break;
			}
			if ( in_array( $t[0], [ T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_INLINE_HTML, T_START_HEREDOC, T_HALT_COMPILER ], true ) ) {
				return "line $line has " . ( T_START_HEREDOC === $t[0] ? 'a heredoc' : ( T_HALT_COMPILER === $t[0] ? '__halt_compiler' : 'another PHP tag or text outside PHP' ) );
			}
			if ( $fq === $t[0] ) {
				$name = substr( $t[1], 1 );
				if ( false !== strpos( $name, '\\' ) ) {
					return "line $line names $t[1], in a namespace";
				}
				$out[] = [ T_STRING, $name, $line ];
				continue;
			}
			if ( T_NS_SEPARATOR === $t[0] ) {
				// PHP 7: `\define` is a separator and a name; a separator after a name is a namespace path.
				$prev = $out ? end( $out )[0] : null;
				if ( T_STRING === $prev || ! isset( $raw[ $i + 1 ] ) || ! is_array( $raw[ $i + 1 ] ) || T_STRING !== $raw[ $i + 1 ][0] ) {
					return "line $line has a namespaced name";
				}
				continue;
			}
			$out[] = [ $t[0], $t[1], $line ];
		}

		return $out;
	}

	/**
	 * Recognises WordPress's own index.php: an optional `define( 'WP_USE_THEMES', true|false );` and one require/include of
	 * `__DIR__ . '<p>/wp-blog-header.php'` (or `dirname( __FILE__ ) . …`), as every WordPress version since 4.9 ships it, with
	 * <p> empty or a plain path below the file's directory.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array|string [ 'sub' => <p> without its leading slash ] or the reason it isn't recognised.
	 */
	public static function index_loader( $source ) {
		$t = self::tokens( $source );
		if ( is_string( $t ) ) {
			return $t;
		}
		$i = 0;
		if ( self::is_word( $t, 0, 'define' ) ) {
			$end  = self::statement_end( $t, 0 );
			$args = null === $end ? null : self::call_args( $t, 0, $end );
			if ( ! $args || 2 !== count( $args ) || 'WP_USE_THEMES' !== self::name_literal( $t, $args[0] )
				|| 1 !== count( $args[1] ) || ! self::is_word( $t, $args[1][0], [ 'true', 'false' ] ) ) {
				return 'line ' . $t[0][2] . ' is not define( \'WP_USE_THEMES\', true );';
			}
			$i = $end + 1;
		}
		$end = isset( $t[ $i ] ) ? self::statement_end( $t, $i ) : null;
		$inc = null === $end ? null : self::include_target( $t, $i, $end );
		if ( null === $inc || 'blog-header' !== $inc['kind'] ) {
			return isset( $t[ $i ] ) ? 'line ' . $t[ $i ][2] . ' is not require __DIR__ . \'/wp-blog-header.php\';' : 'it does not load wp-blog-header.php';
		}
		if ( count( $t ) !== $end + 1 ) {
			return 'line ' . $t[ $end + 1 ][2] . ' comes after WordPress is loaded';
		}

		return [ 'sub' => trim( dirname( $inc['path'] ), '/.' ) ];
	}

	/**
	 * WordPress's version from wp-includes/version.php: exactly one `$wp_version = '<literal>';` statement.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array|string [ 'version' => …, 'branch' => major.minor ], or why it can't be read.
	 */
	public static function wp_version( $source ) {
		$t = self::tokens( $source );
		if ( is_string( $t ) ) {
			return $t;
		}
		$found = null;
		foreach ( $t as $i => $token ) {
			if ( T_VARIABLE !== $token[0] || '$wp_version' !== $token[1] ) {
				continue;
			}
			$ok = null === $found && isset( $t[ $i + 3 ] ) && '=' === $t[ $i + 1 ][0] && T_CONSTANT_ENCAPSED_STRING === $t[ $i + 2 ][0] && ';' === $t[ $i + 3 ][0] && ( 0 === $i || ';' === $t[ $i - 1 ][0] );
			$value = $ok ? self::literal( $t[ $i + 2 ][1] ) : null;
			// Releases, RCs, betas, nightlies and dev builds: 7.2-RC3-65000, 7.3-alpha-65762-src.
			if ( null === $value || strlen( $value ) > 64 || ! preg_match( '/^(\d+)\.(\d+)(?:\.\d+)*(?:-[A-Za-z0-9.]+)*$/D', $value, $m ) ) {
				return 'line ' . $token[2] . ' is not a single $wp_version = \'<version>\'; statement';
			}
			$found = [ 'version' => $value, 'branch' => (int) $m[1] . '.' . (int) $m[2] ];
		}

		return null === $found ? 'it does not set $wp_version' : $found;
	}

	/**
	 * Checks a wp-config.php (or a file it loads) statement by statement against the config grammar.
	 *
	 * $env: 'root' (real WordPress root), 'dir' (real directory of this file: __DIR__), 'file' (for messages), 'boot'
	 * (whether this file must end with the wp-settings.php load), 'resolve' (callable( $abs_path ) returning
	 * [ 'state' => 'absent'|'bad'|'ok', 'real' => …, 'source' => …, 'scanned' => bool, 'detail' => … ] for a file this one
	 * loads; 'bad' is a file that is there but can't be read as the scanner would).
	 *
	 * @param string $source PHP source.
	 * @param array  $env    See above.
	 * @param int    $depth  Nesting of loaded files.
	 *
	 * @return string|null Why it is refused, or null.
	 */
	public static function check_config( $source, array $env, $depth = 0 ) {
		$t = self::tokens( $source );
		if ( is_string( $t ) ) {
			return "{$env['file']}: $t";
		}
		$state = [ 'booted' => false, 'content' => null, 'guard' => null ];
		$error = self::statements( $t, 0, count( $t ), $env, $depth, $state, true );
		if ( null !== $error ) {
			return $error;
		}
		if ( $env['boot'] && ! $state['booted'] ) {
			return "{$env['file']} does not end with require_once ABSPATH . 'wp-settings.php';";
		}

		return null;
	}

	/**
	 * @return string|null
	 */
	private static function statements( array $t, $i, $to, array $env, $depth, array &$state, $top ) {
		while ( $i < $to ) {
			if ( $state['booted'] ) {
				return self::unknown( $t, $i, $env, 'comes after wp-settings.php is loaded' );
			}
			if ( ';' === $t[ $i ][0] ) {
				$i++;
				continue;
			}
			if ( T_IF === $t[ $i ][0] ) {
				$next = self::if_block( $t, $i, $to, $env, $depth, $state );
				if ( is_string( $next ) ) {
					return $next;
				}
				$i = $next;
				continue;
			}
			$end = self::statement_end( $t, $i, $to );
			if ( null === $end ) {
				return self::unknown( $t, $i, $env );
			}
			$error = self::simple( $t, $i, $end, $env, $depth, $state, $top );
			if ( null !== $error ) {
				return $error;
			}
			$i = $end + 1;
		}

		return null;
	}

	/**
	 * `if ( expr ) { … } elseif ( expr ) { … } else { … }`, braced or one statement each; the alternative syntax is refused.
	 *
	 * @return int|string Index after the block, or the reason it is refused.
	 */
	private static function if_block( array $t, $i, $to, array $env, $depth, array &$state ) {
		while ( true ) {
			$kw = $t[ $i ][0];
			if ( T_ELSE !== $kw ) {
				$close = isset( $t[ $i + 1 ] ) && '(' === $t[ $i + 1 ][0] ? self::matching( $t, $i + 1, $to ) : null;
				if ( null === $close ) {
					return self::unknown( $t, $i, $env );
				}
				$error = self::expr( $t, $i + 2, $close, $env, 'condition' );
				if ( null !== $error ) {
					return $error;
				}
				$body  = $close + 1;
				$guard = $close === $i + 7 && '!' === $t[ $i + 2 ][0] && self::is_word( $t, $i + 3, 'defined' ) && '(' === $t[ $i + 4 ][0] && ')' === $t[ $i + 6 ][0] ? self::name_literal( $t, [ $i + 5 ] ) : null;
			} else {
				$body  = $i + 1;
				$guard = null;
			}
			$outer          = $state['guard'];
			$state['guard'] = T_IF === $kw ? $guard : null;
			if ( ! isset( $t[ $body ] ) ) {
				return self::unknown( $t, $i, $env );
			}
			if ( '{' === $t[ $body ][0] ) {
				$end = self::matching( $t, $body, $to );
				if ( null === $end ) {
					return self::unknown( $t, $i, $env );
				}
				$error = self::statements( $t, $body + 1, $end, $env, $depth, $state, false );
				$after = $end + 1;
			} elseif ( ':' === $t[ $body ][0] || T_IF === $t[ $body ][0] && T_ELSE !== $kw ) {
				return self::unknown( $t, $i, $env );
			} elseif ( T_IF === $t[ $body ][0] ) {
				// `else if`.
				$after = self::if_block( $t, $body, $to, $env, $depth, $state );
				return $after;
			} else {
				$end = self::statement_end( $t, $body, $to );
				if ( null === $end ) {
					return self::unknown( $t, $body, $env );
				}
				$error = self::simple( $t, $body, $end, $env, $depth, $state, false );
				$after = $end + 1;
			}
			$state['guard'] = $outer;
			if ( null !== $error ) {
				return $error;
			}
			if ( $after < $to && in_array( $t[ $after ][0], [ T_ELSEIF, T_ELSE ], true ) && T_ELSE !== $kw ) {
				$i = $after;
				continue;
			}

			return $after;
		}
	}

	/**
	 * One statement without a block: define, a `defined() || define()` guard, an assignment, ini_set/error_reporting, or a
	 * require/include this check can name.
	 *
	 * @return string|null
	 */
	private static function simple( array $t, $i, $end, array $env, $depth, array &$state, $top ) {
		$s = '@' === $t[ $i ][0] ? $i + 1 : $i;
		// define( 'NAME', expr );
		if ( self::is_word( $t, $s, 'define' ) ) {
			$args = self::call_args( $t, $s, $end );
			if ( null === $args || 2 !== count( $args ) ) {
				return self::unknown( $t, $i, $env );
			}
			return self::define( $t, $args, $env, $state, $i );
		}
		// defined( 'NAME' ) || define( 'NAME', expr );
		if ( self::is_word( $t, $s, 'defined' ) && isset( $t[ $s + 1 ] ) && '(' === $t[ $s + 1 ][0] ) {
			$close = self::matching( $t, $s + 1, $end );
			$or    = null === $close ? null : $close + 1;
			$args  = null === $or || ! in_array( $t[ $or ][0], [ T_BOOLEAN_OR, T_LOGICAL_OR ], true ) || ! self::is_word( $t, $or + 1, 'define' ) ? null : self::call_args( $t, $or + 1, $end );
			$name  = null === $close || $close !== $s + 3 ? null : self::name_literal( $t, [ $s + 2 ] );
			if ( null === $args || 2 !== count( $args ) || null === $name || self::name_literal( $t, $args[0] ) !== $name ) {
				return self::unknown( $t, $i, $env );
			}
			$outer          = $state['guard'];
			$state['guard'] = $name;
			$error          = self::define( $t, $args, $env, $state, $i );
			$state['guard'] = $outer;
			return $error;
		}
		// $var = expr;  $_SERVER['KEY'] = expr;
		if ( T_VARIABLE === $t[ $i ][0] ) {
			$name = substr( $t[ $i ][1], 1 );
			$eq   = $i + 1;
			if ( '_SERVER' === $name && isset( $t[ $i + 3 ] ) && '[' === $t[ $i + 1 ][0] && ']' === $t[ $i + 3 ][0] ) {
				$key = self::name_literal( $t, [ $i + 2 ] );
				if ( ! in_array( $key, self::SERVER_KEYS, true ) ) {
					return self::unknown( $t, $i, $env, 'sets a $_SERVER key this check doesn\'t accept' );
				}
				$eq = $i + 4;
			} elseif ( ! in_array( $name, self::VARIABLES, true ) ) {
				return self::unknown( $t, $i, $env, "assigns \${$name}, which this check doesn't accept" );
			}
			if ( ! isset( $t[ $eq ] ) || '=' !== $t[ $eq ][0] || $eq + 1 >= $end ) {
				return self::unknown( $t, $i, $env );
			}
			return self::expr( $t, $eq + 1, $end, $env, 'value' );
		}
		// ini_set( … ); error_reporting( … );
		if ( self::is_word( $t, $s, self::PURE_STATEMENTS ) ) {
			$args = self::call_args( $t, $s, $end );
			if ( null === $args || self::is_word( $t, $s, 'ini_set' ) && ( 2 !== count( $args ) || ! in_array( strtolower( (string) self::literal_arg( $t, $args[0] ) ), self::INI_KEYS, true ) ) ) {
				return self::unknown( $t, $i, $env );
			}
			foreach ( $args as $arg ) {
				$error = $arg ? self::expr( $t, $arg[0], end( $arg ) + 1, $env, 'argument' ) : null;
				if ( null !== $error ) {
					return $error;
				}
			}
			return null;
		}
		if ( in_array( $t[ $i ][0], [ T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ], true ) ) {
			return self::include_statement( $t, $i, $end, $env, $depth, $state, $top );
		}

		return self::unknown( $t, $i, $env );
	}

	/**
	 * @return string|null
	 */
	private static function define( array $t, array $args, array $env, array &$state, $at ) {
		$name = self::name_literal( $t, $args[0] );
		if ( null === $name ) {
			return self::unknown( $t, $at, $env, 'defines a constant whose name is not a plain string' );
		}
		$upper = strtoupper( $name );
		if ( in_array( $upper, self::NEVER_DEFINED, true ) ) {
			return "{$env['file']} line {$t[ $at ][2]} defines $name, which changes where WordPress loads code from";
		}
		if ( ! $args[1] ) {
			return self::unknown( $t, $at, $env );
		}
		if ( 'ABSPATH' === $upper ) {
			// Only WordPress's own guard: wp-load.php has set it already, but a direct request for wp-config.php hasn't.
			$value = self::signature( $t, $args[1] );
			if ( ! $env['boot'] || 'ABSPATH' !== $state['guard'] || ! in_array( $value, [ "T_DIR . '/'", 'T_DIR . "/"', "T_STRING:dirname ( T_FILE ) . '/'", 'T_STRING:dirname ( T_FILE ) . "/"' ], true ) ) {
				return self::unknown( $t, $at, $env, 'defines ABSPATH other than in WordPress\'s own guard' );
			}
			return null;
		}
		if ( isset( self::DIR_DEFAULTS[ $upper ] ) ) {
			$name = $upper;
			$path    = self::path_value( $t, $args[1][0], end( $args[1] ) + 1, $env, $state['content'] );
			$default = $env['root'] . '/' . self::DIR_DEFAULTS[ $name ];
			if ( false === $path ) {
				return "{$env['file']} sets $name with `..` (line {$t[ $at ][2]}); this check only accepts its default, $default, written without `..`";
			}
			if ( null === $path ) {
				return "can't resolve $name in {$env['file']} (line {$t[ $at ][2]}); this check only accepts its default, $default";
			}
			$canon = isset( $env['canon'] ) ? $env['canon'] : function ( $value ) {
				return $value;
			};
			if ( call_user_func( $canon, $path ) !== call_user_func( $canon, $default ) ) {
				return "{$env['file']} sets $name to $path (line {$t[ $at ][2]}); this check only accepts its default, $default";
			}
			if ( 'WP_CONTENT_DIR' === $name ) {
				$state['content'] = $path;
			}
			return null;
		}

		return self::expr( $t, $args[1][0], end( $args[1] ) + 1, $env, 'value' );
	}

	/**
	 * @return string|null
	 */
	private static function include_statement( array $t, $i, $end, array $env, $depth, array &$state, $top ) {
		$inc = self::include_target( $t, $i, $end );
		if ( null === $inc ) {
			return self::unknown( $t, $i, $env, 'loads a file this check can\'t name' );
		}
		if ( 'settings' === $inc['kind'] ) {
			if ( ! $env['boot'] || ! $top ) {
				return self::unknown( $t, $i, $env, 'loads wp-settings.php somewhere other than at the end of wp-config.php' );
			}
			$state['booted'] = true;
			return null;
		}
		if ( 'blog-header' === $inc['kind'] ) {
			return self::unknown( $t, $i, $env );
		}
		$base = [
			'ABSPATH'   => $env['root'],
			'__DIR__'   => $env['dir'],
			'__FILE__'  => $env['dir'],
			'dirname-2' => dirname( $env['dir'] ),
		][ $inc['base'] ];
		$path = self::normalise( $base . '/' . ltrim( $inc['path'], '/' ) );
		// PHP joins `__DIR__ . 'x.php'` without a slash: a sibling of the directory, never what's meant here.
		if ( null === $path || '' === $inc['path'] || '/' !== $inc['path'][0] && 'ABSPATH' !== $inc['base'] || '.php' !== substr( $path, -4 ) ) {
			return self::unknown( $t, $i, $env, 'loads a file this check can\'t name' );
		}
		$file = call_user_func( $env['resolve'], $path );
		if ( 'absent' === $file['state'] ) {
			return null;
		}
		if ( 'ok' !== $file['state'] ) {
			return "{$env['file']} line {$t[ $i ][2]} loads $path, which " . $file['detail'];
		}
		$rel = 0 === strpos( $file['real'], $env['root'] . '/' ) ? substr( $file['real'], strlen( $env['root'] ) + 1 ) : null;
		if ( null === $rel || ! $file['scanned'] ) {
			return "{$env['file']} line {$t[ $i ][2]} loads {$file['real']}, outside what the scan of {$env['root']} checks";
		}
		if ( isset( self::VIP_FILES[ $rel ] ) ) {
			$error = self::vip_file( $file['source'], self::VIP_FILES[ $rel ] );
			return null === $error ? null : "{$file['real']} (loaded by {$env['file']}): $error";
		}
		if ( $depth >= 2 ) {
			return "{$env['file']} line {$t[ $i ][2]} loads {$file['real']}, a config file nested too deep for this check";
		}

		return self::check_config( $file['source'], [ 'dir' => dirname( $file['real'] ), 'file' => $file['real'], 'boot' => false ] + $env, $depth + 1 );
	}

	/**
	 * EE's --vip files, which the scan checks as code: they may not touch the directory constants or hooks, run code from a
	 * string, or (requires.php) load anything but `$mu_plugins_base . $file` below its own mu-plugins/lib.
	 *
	 * @param string $source PHP source.
	 * @param string $kind   'requires' or 'vip-config'.
	 *
	 * @return string|null
	 */
	public static function vip_file( $source, $kind ) {
		$t = self::tokens( $source );
		if ( is_string( $t ) ) {
			return $t;
		}
		$names = array_merge( array_keys( self::DIR_DEFAULTS ), self::NEVER_DEFINED );
		$n     = count( $t );
		for ( $i = 0; $i < $n; $i++ ) {
			list( $id, $text, $line ) = $t[ $i ];
			$word = T_CONSTANT_ENCAPSED_STRING === $id ? substr( $text, 1, -1 ) : $text;
			if ( ( T_STRING === $id || T_CONSTANT_ENCAPSED_STRING === $id ) && in_array( strtoupper( $word ), $names, true ) ) {
				return "line $line names $word";
			}
			if ( T_VARIABLE === $id && in_array( substr( $text, 1 ), [ 'GLOBALS', 'wp_filter', 'wp_theme_directories' ], true ) ) {
				return "line $line uses $text";
			}
			if ( in_array( $id, [ T_EVAL, '`', '$' ], true ) || T_VARIABLE === $id && isset( $t[ $i + 1 ] ) && '(' === $t[ $i + 1 ][0] ) {
				return "line $line runs code this check can't read";
			}
			if ( in_array( $id, [ T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ], true ) ) {
				$ok = 'requires' === $kind && isset( $t[ $i + 4 ] ) && '$mu_plugins_base' === $t[ $i + 1 ][1] && '.' === $t[ $i + 2 ][0] && '$file' === $t[ $i + 3 ][1] && ';' === $t[ $i + 4 ][0];
				if ( ! $ok ) {
					return "line $line loads a file this check can't name";
				}
			}
			if ( 'requires' === $kind && T_VARIABLE === $id && '$mu_plugins_base' === $text && isset( $t[ $i + 1 ] ) && '=' === $t[ $i + 1 ][0] ) {
				$want = [ '=', 'dirname', '(', '__DIR__', ')', ';' ];
				$got  = array_map(
					function ( $tok ) {
						return $tok[1];
					},
					array_slice( $t, $i + 1, 6 )
				);
				if ( $want !== $got ) {
					return "line $line sets \$mu_plugins_base to something other than dirname( __DIR__ )";
				}
			}
			if ( 'requires' === $kind && T_CONSTANT_ENCAPSED_STRING === $id && '.php' === substr( $word, -4 ) && ! preg_match( '#^/lib/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.php$#D', $word ) ) {
				return "line $line names $word, outside mu-plugins/lib";
			}
		}

		return null;
	}

	/**
	 * Checks an expression: literals, constants, $variables and $array[…] reads, the operators, casts, array literals and the
	 * pure functions; no other call, no `new`, eval, include, backticks, `$$`, `::`, `->`, closures or assignment.
	 *
	 * @return string|null
	 */
	private static function expr( array $t, $from, $to, array $env, $what ) {
		static $ops = null;
		if ( null === $ops ) {
			$ops = [ '.', '?', ':', '!', ',', '-', '+', '*', '/', '%', '|', '&', '^', '~', '<', '>', '@', ')', ']', '(', '[',
				T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL,
				T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE, T_DOUBLE_ARROW, T_SL, T_SR,
				T_INT_CAST, T_BOOL_CAST, T_STRING_CAST, T_DOUBLE_CAST, T_SPACESHIP, T_POW ];
			// PHP 8.1 tokenises `&` by what follows it.
			foreach ( [ 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG', 'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG' ] as $name ) {
				if ( defined( $name ) ) {
					$ops[] = constant( $name );
				}
			}
		}
		if ( $from >= $to ) {
			return self::unknown( $t, max( 0, $from - 1 ), $env );
		}
		$after_value = [ T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_VARIABLE, T_DIR, T_FILE, T_LINE, ')', ']', T_STRING ];
		for ( $i = $from; $i < $to; $i++ ) {
			list( $id, $text, $line ) = $t[ $i ];
			$call = isset( $t[ $i + 1 ] ) && $i + 1 < $to && '(' === $t[ $i + 1 ][0];
			if ( in_array( $id, [ T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DIR, T_FILE, T_LINE ], true ) ) {
				if ( $call ) {
					return "{$env['file']} line $line calls a string as a function";
				}
				continue;
			}
			if ( T_VARIABLE === $id ) {
				if ( $call ) {
					return "{$env['file']} line $line calls a variable as a function";
				}
				continue;
			}
			if ( in_array( $id, [ T_STRING, T_ISSET, T_EMPTY, T_ARRAY ], true ) ) {
				$word = strtolower( $text );
				if ( $call && ! in_array( $word, self::PURE_FUNCTIONS, true ) ) {
					return "{$env['file']} line $line calls $text(), which this check doesn't accept in a config";
				}
				if ( ! $call && T_STRING !== $id ) {
					return self::unknown( $t, $i, $env );
				}
				continue;
			}
			if ( '(' === $id && $i > $from && in_array( $t[ $i - 1 ][0], $after_value, true ) && ! in_array( $t[ $i - 1 ][0], [ T_STRING ], true ) ) {
				return "{$env['file']} line $line calls the result of an expression";
			}
			if ( ! in_array( $id, $ops, true ) ) {
				return "{$env['file']} line $line has " . self::clean( $text ) . " in a $what, which this check doesn't accept in a config";
			}
		}

		return null;
	}

	/**
	 * The host path an expression of ABSPATH, __DIR__, dirname( __FILE__ ), WP_CONTENT_DIR and string literals joined by `.`
	 * gives, null when it is anything else, or false when a literal has a `..` segment.
	 *
	 * @return string|null
	 */
	private static function path_value( array $t, $from, $to, array $env, $content ) {
		$path = '';
		$i    = $from;
		while ( $i < $to ) {
			list( $id, $text ) = $t[ $i ];
			if ( '(' === $id && ')' === $t[ $to - 1 ][0] && $i === $from && self::matching( $t, $i, $to ) === $to - 1 ) {
				$to--;
				$i++;
				continue;
			}
			$part = null;
			$next = $i + 1;
			if ( T_DIR === $id ) {
				$part = $env['dir'];
			} elseif ( self::is_word( $t, $i, 'abspath' ) && 'ABSPATH' === $text ) {
				$part = $env['root'] . '/';
			} elseif ( 'WP_CONTENT_DIR' === $text && T_STRING === $id ) {
				$part = null === $content ? $env['root'] . '/wp-content' : $content;
			} elseif ( T_CONSTANT_ENCAPSED_STRING === $id ) {
				$part = self::literal( $text );
			} elseif ( self::is_word( $t, $i, 'dirname' ) && isset( $t[ $i + 3 ] ) && '(' === $t[ $i + 1 ][0] && ')' === $t[ $i + 3 ][0] && in_array( $t[ $i + 2 ][0], [ T_FILE, T_DIR ], true ) ) {
				$part = T_FILE === $t[ $i + 2 ][0] ? $env['dir'] : dirname( $env['dir'] );
				$next = $i + 4;
			}
			if ( null === $part ) {
				return null;
			}
			$path .= $part;
			$i     = $next;
			if ( $i < $to ) {
				if ( '.' !== $t[ $i ][0] ) {
					return null;
				}
				$i++;
			}
		}

		// PHP follows each link before `..`; a textual collapse could call another directory the default. The joined
		// string is tested, so `'/L/.' . './x'` counts too.
		if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return false;
		}

		return self::normalise( $path );
	}

	/**
	 * What a require/include statement loads: wp-settings.php from ABSPATH, wp-blog-header.php from the file's directory, or a
	 * file named by `ABSPATH|__DIR__|dirname( __FILE__ )|dirname( __DIR__ ) . 'literal'`.
	 *
	 * @return array|null [ 'kind' => settings|blog-header|file, 'base' => ABSPATH|__DIR__|__FILE__|dirname-2, 'path' => literal ].
	 */
	private static function include_target( array $t, $i, $end ) {
		if ( ! in_array( $t[ $i ][0], [ T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ], true ) ) {
			return null;
		}
		$from = $i + 1;
		$to   = $end;
		if ( $from < $to && '(' === $t[ $from ][0] && self::matching( $t, $from, $to ) === $to - 1 ) {
			$from++;
			$to--;
		}
		$ops = array_slice( $t, $from, $to - $from );
		$sig = implode(
			' ',
			array_map(
				function ( $tok ) {
					return is_int( $tok[0] ) ? token_name( $tok[0] ) . ':' . strtolower( $tok[1] ) : $tok[0];
				},
				$ops
			)
		);
		$bases = [
			'T_STRING:abspath .'                         => 'ABSPATH',
			'T_DIR:__dir__ .'                            => '__DIR__',
			'T_STRING:dirname ( T_FILE:__file__ ) .'     => '__FILE__',
			'T_STRING:dirname ( T_DIR:__dir__ ) .'       => 'dirname-2',
		];
		foreach ( $bases as $prefix => $base ) {
			if ( 0 !== strpos( $sig, $prefix . ' ' ) || 'ABSPATH' === $base && 'ABSPATH' !== $ops[0][1] ) {
				continue;
			}
			$last = end( $ops );
			if ( substr_count( $sig, ' ' ) !== substr_count( $prefix, ' ' ) + 1 || T_CONSTANT_ENCAPSED_STRING !== $last[0] ) {
				return null;
			}
			$path = self::literal( $last[1] );
			if ( null === $path || preg_match( '#(^|/)\.\.?(/|$)#', $path ) || preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
				return null;
			}
			if ( 'ABSPATH' === $base && in_array( $path, [ 'wp-settings.php', '/wp-settings.php' ], true ) ) {
				return [ 'kind' => 'settings', 'base' => $base, 'path' => $path ];
			}
			if ( in_array( $base, [ '__DIR__', '__FILE__' ], true ) && preg_match( '#^(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*/wp-blog-header\.php$#D', $path ) ) {
				return [ 'kind' => 'blog-header', 'base' => $base, 'path' => $path ];
			}
			return [ 'kind' => 'file', 'base' => $base, 'path' => $path ];
		}

		return null;
	}

	/**
	 * Index of the `;` ending the statement at $i (brackets balanced, no braces), or null.
	 */
	private static function statement_end( array $t, $i, $to = null ) {
		$to    = null === $to ? count( $t ) : $to;
		$depth = 0;
		for ( ; $i < $to; $i++ ) {
			$id = $t[ $i ][0];
			if ( '(' === $id || '[' === $id ) {
				$depth++;
			} elseif ( ')' === $id || ']' === $id ) {
				if ( --$depth < 0 ) {
					return null;
				}
			} elseif ( '{' === $id || '}' === $id || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				return null;
			} elseif ( ';' === $id && 0 === $depth ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Index of the bracket closing the one at $i, or null.
	 */
	private static function matching( array $t, $i, $to ) {
		$pairs = [ '(' => ')', '[' => ']', '{' => '}' ];
		$stack = [];
		for ( ; $i < $to; $i++ ) {
			$id = $t[ $i ][0];
			if ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				return null;
			}
			if ( is_string( $id ) && isset( $pairs[ $id ] ) ) {
				$stack[] = $pairs[ $id ];
			} elseif ( is_string( $id ) && in_array( $id, $pairs, true ) ) {
				if ( array_pop( $stack ) !== $id ) {
					return null;
				}
				if ( ! $stack ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Arguments of `name( … )` spanning exactly $s..$end-1 ($end is the `;`): lists of token indexes, a trailing comma dropped.
	 *
	 * @return array[]|null
	 */
	private static function call_args( array $t, $s, $end ) {
		if ( ! isset( $t[ $s + 1 ] ) || '(' !== $t[ $s + 1 ][0] || self::matching( $t, $s + 1, $end ) !== $end - 1 ) {
			return null;
		}
		$args  = [ [] ];
		$depth = 0;
		for ( $i = $s + 2; $i < $end - 1; $i++ ) {
			$id = $t[ $i ][0];
			if ( ',' === $id && 0 === $depth ) {
				$args[] = [];
				continue;
			}
			$depth           += in_array( $id, [ '(', '[' ], true ) ? 1 : ( in_array( $id, [ ')', ']' ], true ) ? -1 : 0 );
			$args[ count( $args ) - 1 ][] = $i;
		}
		if ( count( $args ) > 1 && ! end( $args ) ) {
			array_pop( $args );
		}

		return in_array( [], $args, true ) && [ [] ] !== $args ? null : ( [ [] ] === $args ? [] : $args );
	}

	/**
	 * The constant name in a single string-literal argument, when it has no escapes or interpolation.
	 *
	 * @param array $t   Tokens.
	 * @param int[] $arg Token indexes of the argument.
	 *
	 * @return string|null
	 */
	private static function name_literal( array $t, array $arg ) {
		if ( 1 !== count( $arg ) || T_CONSTANT_ENCAPSED_STRING !== $t[ $arg[0] ][0] ) {
			return null;
		}
		$name = substr( $t[ $arg[0] ][1], 1, -1 );

		return preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $name ) ? $name : null;
	}

	/**
	 * A string literal's value, when it has no escape PHP would read differently in single and double quotes.
	 *
	 * @return string|null
	 */
	private static function literal( $text ) {
		$body = substr( $text, 1, -1 );
		if ( false !== strpos( $body, '\\' ) || '"' === $text[0] && false !== strpos( $body, '$' ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Collapses `//`, `/./` and `x/../` in an absolute path; null when `..` would go above /.
	 */
	public static function normalise( $path ) {
		$out = [];
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! $out ) {
					return null;
				}
				array_pop( $out );
				continue;
			}
			$out[] = $part;
		}

		return '/' . implode( '/', $out );
	}

	/**
	 * Tokens of an argument as `T_NAME:text` (names lower-cased) or the character, joined by spaces.
	 */
	private static function signature( array $t, array $arg ) {
		return implode(
			' ',
			array_map(
				function ( $i ) use ( $t ) {
					return is_int( $t[ $i ][0] ) ? ( T_CONSTANT_ENCAPSED_STRING === $t[ $i ][0] ? $t[ $i ][1] : token_name( $t[ $i ][0] ) . ( T_STRING === $t[ $i ][0] ? ':' . strtolower( $t[ $i ][1] ) : '' ) ) : $t[ $i ][0];
				},
				$arg
			)
		);
	}

	/**
	 * A single string-literal argument's value.
	 */
	private static function literal_arg( array $t, array $arg ) {
		return 1 === count( $arg ) && T_CONSTANT_ENCAPSED_STRING === $t[ $arg[0] ][0] ? self::literal( $t[ $arg[0] ][1] ) : null;
	}

	private static function is_word( array $t, $i, $words ) {
		return isset( $t[ $i ] ) && T_STRING === $t[ $i ][0] && in_array( strtolower( $t[ $i ][1] ), (array) $words, true );
	}

	private static function unknown( array $t, $i, array $env, $why = null ) {
		$text = '';
		for ( $j = $i, $n = count( $t ); $j < $n && strlen( $text ) < 60 && ';' !== $t[ $j ][0]; $j++ ) {
			$text .= ( '' !== $text && preg_match( '/^\w/', $t[ $j ][1] ) && preg_match( '/\w$/', $text ) ? ' ' : '' ) . $t[ $j ][1];
		}
		$text = self::clean( trim( substr( $text, 0, 60 ) ) );

		return isset( $t[ $i ] )
			? "{$env['file']} line {$t[ $i ][2]} " . ( null === $why ? "isn't a statement this check recognises" : $why ) . ": $text"
			: "{$env['file']} ends early";
	}

	private static function clean( $text ) {
		return class_exists( __NAMESPACE__ . '\\Site_PHP_Compat', false ) ? Site_PHP_Compat::clean( $text ) : preg_replace( '/[\x00-\x1F\x7F]/', '?', $text );
	}
}
