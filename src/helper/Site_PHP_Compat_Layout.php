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

	/**
	 * Constants a config may never set: each moves core or theme code WordPress loads. Core (4.9–7.1) requires from
	 * BLOCKS_PATH and GETID3_INCLUDEPATH with a guard or after its own define, so an earlier define wins; Akismet ships
	 * with core.
	 */
	const NEVER_DEFINED = [ 'WPINC', 'TEMPLATEPATH', 'STYLESHEETPATH', 'WP_LANG_DIR', 'PLUGINDIR', 'MUPLUGINDIR', 'LANGDIR', 'BLOCKS_PATH', 'GETID3_INCLUDEPATH', 'AKISMET__PLUGIN_DIR' ];

	/** Constants code is loaded from that a config may set only to a path inside the WordPress root (WP Super Cache's drop-in). */
	const INSIDE_ROOT = [ 'WPCACHEHOME' ];

	/** Constants core puts into the name of a file it loads (locale file, filesystem class, theme): a plain name only. */
	const NAME_VALUES = [ 'WPLANG', 'FS_METHOD', 'WP_DEFAULT_THEME' ];

	/**
	 * The only variables a config may assign (table prefix, multisite, EE's --cache and memcached object caches, the locals of
	 * reverse-proxy snippets), besides WP Engine's `$wpe_*` leftovers, which only its own plugin reads.
	 */
	const VARIABLES = [ 'table_prefix', 'base', 'redis_server', 'memcached_servers', 'forwarded_ips', 'http_x_headers', 'xffaddrs', 'batcache' ];

	/** The only $_SERVER keys a config may set: what proxy and HTTPS snippets set, never where the script is. */
	const SERVER_KEYS = [ 'HTTPS', 'SERVER_PORT', 'REMOTE_ADDR', 'HTTP_HOST', 'SERVER_NAME', 'REQUEST_SCHEME', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_SSL', 'HTTP_X_REAL_IP' ];

	/**
	 * The only settings ini_set() may change in a config: none names a file, path, handler or callback PHP loads or runs
	 * (so never include_path, auto_prepend_file, opcache.*, session.save_handler or unserialize_callback_func).
	 * upload_max_size isn't a PHP setting, but a widely copied snippet sets it (ini_set() then just fails).
	 */
	const INI_KEYS = [ 'display_errors', 'display_startup_errors', 'log_errors', 'error_log', 'error_reporting', 'memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars', 'max_input_nesting_level', 'upload_max_filesize', 'upload_max_size', 'post_max_size', 'default_socket_timeout', 'date.timezone', 'html_errors', 'log_errors_max_len', 'ignore_repeated_errors', 'ignore_repeated_source', 'default_charset', 'output_buffering', 'zlib.output_compression', 'zlib.output_compression_level', 'pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.jit', 'session.gc_maxlifetime', 'session.gc_probability', 'session.gc_divisor', 'session.cookie_httponly', 'session.cookie_secure', 'session.cookie_samesite', 'session.cookie_lifetime', 'session.cookie_domain', 'session.cookie_path', 'session.use_only_cookies', 'session.use_strict_mode', 'session.use_trans_sid', 'session.name' ];

	/**
	 * The only functions an expression may call: none loads code, takes a callback or can autoload a class (so never
	 * constant(), filter_var() with FILTER_CALLBACK, preg_replace(), unserialize() or class_exists()), and each exists with
	 * the same signature on every PHP version php-compat checks. umask() and filter_var() are checked further in expr().
	 */
	const PURE_FUNCTIONS = [ 'defined', 'dirname', 'basename', 'getenv', 'strpos', 'stripos', 'strrpos', 'strstr', 'stristr', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'ucwords', 'in_array', 'explode', 'implode', 'join', 'trim', 'rtrim', 'ltrim', 'substr', 'strlen', 'str_replace', 'str_ireplace', 'str_pad', 'sprintf', 'md5', 'sha1', 'crc32', 'base64_encode', 'serialize', 'json_encode', 'urlencode', 'rawurlencode', 'parse_url', 'intval', 'floatval', 'strval', 'boolval', 'abs', 'round', 'floor', 'ceil', 'is_numeric', 'is_string', 'is_array', 'is_bool', 'array_merge', 'array_keys', 'array_values', 'array_key_exists', 'array_search', 'array_unique', 'array_slice', 'file_exists', 'is_file', 'is_dir', 'is_readable', 'php_sapi_name', 'phpversion', 'php_uname', 'gethostname', 'version_compare', 'extension_loaded', 'function_exists', 'umask', 'filter_var', 'isset', 'empty', 'array' ];

	/**
	 * Statements (besides define/assignment/include) a config may make, each with any allowed expressions as arguments:
	 * they change settings of this request, never what PHP loads.
	 */
	const PURE_STATEMENTS = [ 'ini_set', 'error_reporting', 'set_time_limit', 'date_default_timezone_set', 'umask', 'header', 'mb_internal_encoding' ];

	/** Significant tokens read per file; real configs have a few thousand. */
	const MAX_TOKENS = 50000;

	/** How deep `if` blocks may nest: deeper ones cost quadratic time to match. */
	const MAX_NEST = 16;

	/** Files one config check may follow, counting each once. */
	const MAX_FILES = 32;

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
		if ( ! function_exists( 'token_get_all' ) ) {
			return 'this host\'s PHP has no tokenizer extension, which this check needs';
		}
		// A UTF-8 BOM before <?php is output, not code.
		if ( 0 === strncmp( $source, "\xEF\xBB\xBF", 3 ) ) {
			$source = substr( $source, 3 );
		}
		$raw = @token_get_all( $source ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a broken file still tokenises.
		if ( ! $raw || ! is_array( $raw[0] ) || T_OPEN_TAG !== $raw[0][0] || 0 !== strncasecmp( $raw[0][1], '<?php', 5 ) ) {
			return 'it does not start with <?php';
		}
		$out  = [];
		$line = 1;
		$fq   = defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : -1;
		$pipe = defined( 'T_PIPE' ) ? T_PIPE : -1;
		$skip = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
		$n    = count( $raw );
		for ( $i = 1; $i < $n; $i++ ) {
			$t = $raw[ $i ];
			if ( count( $out ) > self::MAX_TOKENS ) {
				return 'it has more than ' . self::MAX_TOKENS . ' tokens';
			}
			if ( ! is_array( $t ) ) {
				// Hosts before PHP 8.5 read the pipe `|>` as `|` `>`; `%>` ends PHP code on a 5.6 site with asp_tags on.
				if ( ( '|' === $t || '%' === $t ) && isset( $raw[ $i + 1 ] ) && '>' === $raw[ $i + 1 ] ) {
					return "line $line has $t>, which some PHP versions read differently";
				}
				$out[] = [ $t, $t, $line ];
				continue;
			}
			$line = $t[2];
			if ( T_COMMENT === $t[0] && 0 === strpos( $t[1], '#[' ) || defined( 'T_ATTRIBUTE' ) && T_ATTRIBUTE === $t[0] ) {
				return "line $line has an attribute, which PHP 7 reads as a comment";
			}
			// PHP 7 runs a file whose last comment is never closed; PHP 8 refuses to parse it.
			if ( in_array( $t[0], [ T_COMMENT, T_DOC_COMMENT ], true ) && 0 === strncmp( $t[1], '/*', 2 ) && ( strlen( $t[1] ) < 4 || '*/' !== substr( $t[1], -2 ) ) ) {
				return "line $line has a comment that is never closed";
			}
			if ( $pipe === $t[0] ) {
				return "line $line has |>, which some PHP versions read differently";
			}
			// `b'…'` is one token: the prefix would shift every literal read below.
			if ( T_CONSTANT_ENCAPSED_STRING === $t[0] && '\'' !== $t[1][0] && '"' !== $t[1][0] ) {
				return "line $line has a string with a b prefix";
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

		return [ 'sub' => trim( dirname( $inc['path'] ), '/' ) ];
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
		if ( ! isset( $env['followed'] ) ) {
			$env['followed'] = new \ArrayObject();
		}
		$state = [ 'booted' => false, 'content' => null, 'guard' => null, 'nest' => 0 ];
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
		if ( $state['nest'] >= self::MAX_NEST ) {
			return self::unknown( $t, $i, $env, 'nests `if` blocks deeper than this check reads' );
		}
		$state['nest']++;
		$after = self::if_chain( $t, $i, $to, $env, $depth, $state );
		$state['nest']--;

		return $after;
	}

	/**
	 * @return int|string
	 */
	private static function if_chain( array $t, $i, $to, array $env, $depth, array &$state ) {
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
			if ( null !== $args && 3 === count( $args ) && 1 === count( $args[2] ) && self::is_word( $t, $args[2][0], 'true' ) ) {
				return self::unknown( $t, $i, $env, 'defines a case-insensitive constant, which PHP 8 no longer has' );
			}
			// A third argument of false is the default, ignored on every version.
			if ( null !== $args && 3 === count( $args ) && 1 === count( $args[2] ) && self::is_word( $t, $args[2][0], 'false' ) ) {
				array_pop( $args );
			}
			if ( null === $args || 2 !== count( $args ) ) {
				return self::unknown( $t, $i, $env );
			}
			return self::define( $t, $args, $env, $state, $i );
		}
		// declare( strict_types = 0|1 );
		if ( T_DECLARE === $t[ $i ][0] && $end === $i + 6 && self::is_word( $t, $i + 2, 'strict_types' ) && '(' === $t[ $i + 1 ][0] && '=' === $t[ $i + 3 ][0] && in_array( $t[ $i + 4 ][1], [ '0', '1' ], true ) && ')' === $t[ $i + 5 ][0] ) {
			return null;
		}
		// defined( 'NAME' ) || define( 'NAME', expr );  ! defined( 'NAME' ) && define( 'NAME', expr );
		$not = '!' === $t[ $s ][0] ? 1 : 0;
		if ( self::is_word( $t, $s + $not, 'defined' ) && isset( $t[ $s + $not + 1 ] ) && '(' === $t[ $s + $not + 1 ][0] ) {
			$s    += $not;
			$close = self::matching( $t, $s + 1, $end );
			$or    = null === $close ? null : $close + 1;
			$ops   = $not ? [ T_BOOLEAN_AND, T_LOGICAL_AND ] : [ T_BOOLEAN_OR, T_LOGICAL_OR ];
			$args  = null === $or || ! in_array( $t[ $or ][0], $ops, true ) || ! self::is_word( $t, $or + 1, 'define' ) ? null : self::call_args( $t, $or + 1, $end );
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
			} elseif ( ! in_array( $name, self::VARIABLES, true ) && 0 !== strpos( $name, 'wpe_' ) ) {
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
			if ( null === $args || self::is_word( $t, $s, 'ini_set' ) && ( 2 !== count( $args ) || ! in_array( self::lower( (string) self::literal_arg( $t, $args[0] ) ), self::INI_KEYS, true ) ) ) {
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
		if ( in_array( $t[ $s ][0], [ T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ], true ) ) {
			return self::include_statement( $t, $s, $end, $env, $depth, $state, $top );
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
		$upper = self::upper( $name );
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
		if ( in_array( $upper, self::NAME_VALUES, true ) ) {
			$value = self::literal_arg( $t, $args[1] );
			if ( null === $value || ! preg_match( '/^[A-Za-z0-9_.@-]*$/D', $value ) ) {
				return "{$env['file']} line {$t[ $at ][2]} sets $name to something other than a plain name, which WordPress puts into the name of a file it loads";
			}
			return null;
		}
		if ( in_array( $upper, self::INSIDE_ROOT, true ) ) {
			$raw  = self::path_value( $t, $args[1][0], end( $args[1] ) + 1, $env, $state['content'] );
			$path = is_string( $raw ) && isset( $env['canon'] ) ? call_user_func( $env['canon'], $raw ) : $raw;
			$site = false;
			foreach ( [ '/var/www', $env['dir'], $env['root'] ] as $prefix ) {
				$site = $site || is_string( $raw ) && 0 === strpos( $raw . '/', $prefix . '/' );
			}
			// Inside the WordPress root the scan reads it; a path outside the site (left from another host) isn't there.
			if ( ! is_string( $path ) || $site && 0 !== strpos( $path . '/', $env['root'] . '/' ) ) {
				return "{$env['file']} line {$t[ $at ][2]} sets $name, which code is loaded from, to a path outside {$env['root']}";
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

		return self::follow( $t, $i, $inc, $env, $depth );
	}

	/**
	 * A file a config (or a file it loads) names: absent is fine; inside the scanned WordPress root it must pass the config
	 * grammar, or else be code the scan reads that touches nothing WordPress loads code from (screen()).
	 *
	 * @return string|null
	 */
	private static function follow( array $t, $i, array $inc, array $env, $depth ) {
		$base = [
			'ABSPATH'   => $env['root'],
			'__DIR__'   => $env['dir'],
			'__FILE__'  => $env['dir'],
			'dirname-2' => dirname( $env['dir'] ),
			'/'         => '',
		][ $inc['base'] ];
		$path = self::normalise( $base . '/' . ltrim( $inc['path'], '/' ) );
		// PHP joins `__DIR__ . 'x.php'` without a slash: a sibling of the directory, never what's meant here.
		if ( null === $path || '' === $inc['path'] || '/' !== $inc['path'][0] && 'ABSPATH' !== $inc['base'] || '.php' !== substr( $path, -4 ) ) {
			return self::unknown( $t, $i, $env, 'loads a file this check can\'t name' );
		}
		// An absolute path is the container's: /var/www is the site's app/.
		if ( '/' === $inc['base'] && isset( $env['canon'] ) ) {
			$path = call_user_func( $env['canon'], $path );
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
		$key = $depth . ':' . $file['real'];
		if ( $env['followed']->offsetExists( $key ) ) {
			return $env['followed'][ $key ];
		}
		if ( count( $env['followed'] ) >= self::MAX_FILES ) {
			return "{$env['file']} line {$t[ $i ][2]} loads {$file['real']}, more files than this check follows";
		}
		$sub   = [ 'dir' => dirname( $file['real'] ), 'file' => $file['real'], 'boot' => false ] + $env;
		$error = self::check_config( $file['source'], $sub, $depth + 1 );
		if ( null !== $error ) {
			$error = self::screen( $file['source'], $sub, $depth + 1 );
		}
		$env['followed'][ $key ] = $error;

		return $error;
	}

	/**
	 * A file below the WordPress root that the scan reads as code but that doesn't fit the config grammar (a plugin's
	 * generated headers file, say). PHP loads it before wp-settings.php, so it may not name the constants or globals
	 * WordPress loads code from, run code from a string, or load a file other than one follow() accepts. Like the --vip
	 * files, it is a token screen, not a proof: deliberate evasion is left to the coverage of app/.
	 *
	 * @return string|null
	 */
	private static function screen( $source, array $env, $depth ) {
		$t = self::tokens( $source );
		if ( is_string( $t ) ) {
			return "{$env['file']}: $t";
		}
		$names = array_merge( array_keys( self::DIR_DEFAULTS ), self::NEVER_DEFINED, self::INSIDE_ROOT, self::NAME_VALUES );
		$deny  = [ 'extract', 'parse_str', 'call_user_func', 'call_user_func_array', 'create_function', 'set_include_path', 'spl_autoload', 'ini_alter', 'assert', 'preg_replace', 'unserialize', 'constant' ];
		$n     = count( $t );
		for ( $i = 0; $i < $n; $i++ ) {
			list( $id, $text, $line ) = $t[ $i ];
			$at   = "{$env['file']} line $line";
			$word = T_CONSTANT_ENCAPSED_STRING === $id ? substr( $text, 1, -1 ) : $text;
			$call = isset( $t[ $i + 1 ] ) && '(' === $t[ $i + 1 ][0];
			if ( ( T_STRING === $id || T_CONSTANT_ENCAPSED_STRING === $id ) && in_array( self::upper( $word ), $names, true ) ) {
				return "$at names $word, which changes where WordPress loads code from";
			}
			if ( T_VARIABLE === $id && in_array( substr( $text, 1 ), [ 'GLOBALS', 'wp_filter', 'wp_theme_directories', 'wp_plugin_paths', 'wp_local_package', 'locale' ], true ) ) {
				return "$at uses $text, which WordPress reads to find code";
			}
			if ( in_array( $id, [ T_EVAL, '`', '$' ], true ) || $call && in_array( $id, [ T_VARIABLE, T_CONSTANT_ENCAPSED_STRING ], true ) ) {
				return "$at runs code this check can't read";
			}
			if ( T_STRING === $id && $call && in_array( self::lower( $text ), $deny, true ) ) {
				return "$at calls $text(), which can load code or set what this check can't see";
			}
			if ( T_STRING === $id && $call && 'ini_set' === self::lower( $text ) ) {
				$close = self::matching( $t, $i + 1, $n );
				$args  = null === $close ? null : self::call_args( $t, $i, $close + 1 );
				if ( ! $args || ! in_array( self::lower( (string) self::literal_arg( $t, $args[0] ) ), self::INI_KEYS, true ) ) {
					return "$at calls ini_set() for a setting this check doesn't accept";
				}
			}
			if ( ( T_STRING === $id && $call && 'define' === self::lower( $text ) || T_CONST === $id ) ) {
				$close = T_CONST === $id ? null : self::matching( $t, $i + 1, $n );
				$args  = null === $close ? null : self::call_args( $t, $i, $close + 1 );
				$name  = T_CONST === $id ? ( isset( $t[ $i + 1 ] ) && T_STRING === $t[ $i + 1 ][0] ? $t[ $i + 1 ][1] : null ) : ( $args ? self::name_literal( $t, $args[0] ) : null );
				if ( null === $name ) {
					return "$at defines a constant whose name is not a plain string";
				}
				if ( 'ABSPATH' === self::upper( $name ) ) {
					return "$at defines ABSPATH, which only WordPress's own guard in wp-config.php may";
				}
			}
			if ( in_array( $id, [ T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ], true ) ) {
				$end = self::statement_end( $t, $i );
				$inc = null === $end ? null : self::include_target( $t, $i, $end );
				if ( null === $inc || 'blog-header' === $inc['kind'] ) {
					return "$at loads a file this check can't name";
				}
				if ( 'settings' === $inc['kind'] ) {
					return "$at loads wp-settings.php somewhere other than at the end of wp-config.php";
				}
				$error = self::follow( $t, $i, $inc, $env, $depth );
				if ( null !== $error ) {
					return $error;
				}
			}
		}

		return null;
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
			if ( ( T_STRING === $id || T_CONSTANT_ENCAPSED_STRING === $id ) && in_array( self::upper( $word ), $names, true ) ) {
				return "line $line names $word";
			}
			if ( T_VARIABLE === $id && in_array( substr( $text, 1 ), [ 'GLOBALS', 'wp_filter', 'wp_theme_directories' ], true ) ) {
				return "line $line uses $text";
			}
			// A name built at run time (`'WP_' . 'CONTENT_DIR'`) would get past the name check above.
			if ( T_STRING === $id && 'define' === self::lower( $text ) && isset( $t[ $i + 1 ] ) && '(' === $t[ $i + 1 ][0] ) {
				$close = self::matching( $t, $i + 1, $n );
				$args  = null === $close ? null : self::call_args( $t, $i, $close + 1 );
				if ( ! $args || null === self::name_literal( $t, $args[0] ) ) {
					return "line $line defines a constant whose name is not a plain string";
				}
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
				T_INT_CAST, T_BOOL_CAST, T_STRING_CAST, T_DOUBLE_CAST, T_ARRAY_CAST, T_SPACESHIP, T_POW ];
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
			// `&` only as bitwise and: after `=`, `(`, `,` or `=>` it takes a reference, which writes through to any global.
			if ( '&' === $text && ( $i === $from || ! in_array( $t[ $i - 1 ][0], $after_value, true ) ) ) {
				return "{$env['file']} line $line takes a reference with &, which this check doesn't accept in a config";
			}
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
				$word = self::lower( $text );
				if ( $call && ! in_array( $word, self::PURE_FUNCTIONS, true ) ) {
					return "{$env['file']} line $line calls $text(), which this check doesn't accept in a config";
				}
				$close = $call && in_array( $word, [ 'umask', 'filter_var' ], true ) ? self::matching( $t, $i + 1, $to ) : null;
				$args  = null === $close ? null : self::call_args( $t, $i, $close + 1 );
				// umask() only reads the mask; filter_var() only validates or sanitises (FILTER_CALLBACK runs code).
				if ( 'umask' === $word && $call && [] !== $args || 'filter_var' === $word && ( null === $args || count( $args ) < 2 || count( $args ) > 3
					|| 1 !== count( $args[1] ) || T_STRING !== $t[ $args[1][0] ][0] || ! preg_match( '/^FILTER_(?:VALIDATE|SANITIZE|DEFAULT|UNSAFE_RAW)/', $t[ $args[1][0] ][1] )
					|| isset( $args[2] ) && ( 1 !== count( $args[2] ) || T_STRING !== $t[ $args[2][0] ][0] || ! preg_match( '/^FILTER_(?:FLAG_|NULL_ON_FAILURE$)/', $t[ $args[2][0] ][1] ) ) ) ) {
					return "{$env['file']} line $line calls $text() in a form this check doesn't accept in a config";
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
					return is_int( $tok[0] ) ? token_name( $tok[0] ) . ':' . self::lower( $tok[1] ) : $tok[0];
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
		// An absolute literal: `require '/var/www/htdocs/wp-content/x.php';`.
		if ( 1 === count( $ops ) && T_CONSTANT_ENCAPSED_STRING === $ops[0][0] ) {
			$path = self::literal( $ops[0][1] );

			return null === $path || '/' !== substr( $path, 0, 1 ) || preg_match( '#(^|/)\.\.?(/|$)#', $path ) || preg_match( '/[\x00-\x1F\x7F]/', $path ) ? null : [ 'kind' => 'file', 'base' => '/', 'path' => $path ];
		}
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
			if ( in_array( $base, [ '__DIR__', '__FILE__' ], true ) && preg_match( '#^(?:/[A-Za-z0-9_-](?:[A-Za-z0-9_.-]*[A-Za-z0-9_-])?)*/wp-blog-header\.php$#D', $path ) ) {
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

		// Trailing spaces: the handbook's own `'FTP_PLUGIN_DIR '` (a different constant, so harmless).
		return preg_match( '/^[A-Za-z_][A-Za-z0-9_]* *$/D', $name ) ? $name : null;
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
					return is_int( $t[ $i ][0] ) ? ( T_CONSTANT_ENCAPSED_STRING === $t[ $i ][0] ? $t[ $i ][1] : token_name( $t[ $i ][0] ) . ( T_STRING === $t[ $i ][0] ? ':' . self::lower( $t[ $i ][1] ) : '' ) ) : $t[ $i ][0];
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

	/**
	 * ASCII case mapping: before PHP 8.2 strtolower() follows the locale, and a Turkish one keeps `I`.
	 */
	public static function lower( $text ) {
		return strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	public static function upper( $text ) {
		return strtr( $text, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' );
	}

	private static function is_word( array $t, $i, $words ) {
		return isset( $t[ $i ] ) && T_STRING === $t[ $i ][0] && in_array( self::lower( $t[ $i ][1] ), (array) $words, true );
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
