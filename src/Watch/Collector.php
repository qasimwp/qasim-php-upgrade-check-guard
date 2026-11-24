<?php
/**
 * Upgrade watch: collects PHP errors per plugin or theme (DECISIONS D59).
 *
 * Loaded only while Upgrade watch is on. It must never break a site: the handler only adds to an array (no database, no
 * output, nothing thrown by this code), and all writes happen once, at the end of the request, inside try/catch.
 *
 * A handler set without an error mask is called for every error type, whatever the site's error display level says, so it sees
 * deprecations even when the site's error level hides them from the log. That is intended: a site that hides deprecations still
 * learns about them here. PHP's own handling is unchanged, because the handler returns the previous handler's result, or
 * false when there is none, which hands the error back to PHP's standard handler. Errors silenced with @ are skipped.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Watch;

use Qpucg\Paths;
use Qpucg\Settings;
use Qpucg\Usage\Recorder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom table (spec §6); the object cache does not apply to it.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Error handler, in-memory buffer and the single write per request.
 */
class Collector {

	const TABLE             = 'qpucg_errors';
	const DEFAULT_RATE      = 0.25; // Used when Activity watch is off.
	const MAX_DISTINCT      = 200;  // Different errors kept in memory per request.
	const MAX_RAW           = 2000; // Bytes of one message kept until it is cleaned.
	const SAMPLE_CHARS      = 300;
	const DAILY_CAP         = 1000; // Rows per day; after that only crashes are written that day.
	const PAUSE_SECONDS     = 600;
	const RESERVE_BYTES     = 262144; // Freed first after a crash, so a memory-limit fatal leaves room to write the row.
	const RETENTION         = 30;
	const PURGE_CHUNK       = 100;
	const PURGE_SECONDS     = 5;
	const SILENCE_MAX_BYTES = 2097152; // 2 MB cap for shutdown-time @-operator source inspection.
	const SILENCE_LOOKBACK  = 5;       // Max lines ending at $errline inspected for @-suppression.

	const CRASH       = 'crash';
	const ERROR       = 'error';
	const WARNING     = 'warning';
	const DEPRECATION = 'deprecation';

	const CORE = 'other:core'; // Files outside plugins, themes and wp-content.

	// Fatal types as error_get_last() reports them (the same list as WordPress, plus E_CORE_ERROR).
	const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	// Error number => level. 2048 is E_STRICT, whose constant is deprecated from PHP 8.4.
	const LEVELS = array(
		E_WARNING           => self::WARNING,
		E_NOTICE            => self::WARNING,
		E_CORE_WARNING      => self::WARNING,
		E_COMPILE_WARNING   => self::WARNING,
		E_USER_WARNING      => self::WARNING,
		E_USER_NOTICE       => self::WARNING,
		2048                => self::WARNING,
		E_DEPRECATED        => self::DEPRECATION,
		E_USER_DEPRECATED   => self::DEPRECATION,
		E_USER_ERROR        => self::ERROR,
		E_RECOVERABLE_ERROR => self::ERROR,
	);

	/**
	 * Errors of this request, by location and message. Each: array( errno, message, file, line, count ).
	 *
	 * @var array
	 */
	private static $buffer = array();

	/**
	 * Whether the handler is collecting.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * The handler that was set before ours.
	 *
	 * @var callable|null
	 */
	private static $prev = null;

	/**
	 * Memory held back for the crash path.
	 *
	 * @var string|null
	 */
	private static $reserve = null;

	/**
	 * Which path recorded this request's crash: '', 'filter' or 'shutdown'.
	 *
	 * @var string
	 */
	private static $caught_by = '';

	/**
	 * Path patterns => replacement, built once when a message is cleaned.
	 *
	 * @var array|null
	 */
	private static $roots = null;

	/**
	 * Per-shutdown cache of source file lines for @-suppression checks (path => string[]|false).
	 *
	 * @var array
	 */
	private static $file_lines = array();

	/**
	 * Starts collecting for this request. Crashes are always captured; other errors only on watched page views.
	 *
	 * @param array $settings Settings.
	 */
	public static function start( array $settings ) {
		self::$reserve = str_repeat( 'x', self::RESERVE_BYTES );
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		// WordPress's own fatal handler runs first. A custom wp_die handler can end the request there, so also record here.
		add_filter( 'wp_php_error_args', array( __CLASS__, 'on_wp_fatal' ), 10, 2 );

		if ( self::watched( $settings ) ) {
			self::$active = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the point of this feature; it chains the previous handler.
			self::$prev = set_error_handler( array( __CLASS__, 'on_error' ) );
		}
	}

	/**
	 * The per-request decision: Activity watch's share of page views, or 25% when Activity watch is off.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function watched( array $settings ) {
		$rate = ! empty( $settings['usage_enabled'] ) ? (float) $settings['sample_rate'] : self::DEFAULT_RATE;
		if ( $rate >= 1.0 ) {
			return true;
		}
		try {
			return random_int( 0, PHP_INT_MAX ) / PHP_INT_MAX < $rate;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The error handler. An exception from another plugin's previous handler is that plugin's behaviour, so it isn't caught.
	 *
	 * @param int    $errno   Error number.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return mixed The previous handler's result, or false for PHP's standard handler.
	 */
	public static function on_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
		// These two end the request unless a handler takes them, so they are noted only if the request goes on.
		$may_end = E_USER_ERROR === $errno || E_RECOVERABLE_ERROR === $errno;
		if ( ! $may_end ) {
			self::note( $errno, $errstr, $errfile, $errline );
		}

		// phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection -- the parameters are never changed; PHP below 8 also passes a fifth one.
		$result = null === self::$prev ? false : call_user_func_array( self::$prev, func_get_args() );

		if ( $may_end && false !== $result ) {
			self::note( $errno, $errstr, $errfile, $errline );
		}
		return $result;
	}

	/**
	 * Adds one error to the buffer. Array work only.
	 *
	 * @param int    $errno   Error number.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 */
	private static function note( $errno, $errstr, $errfile, $errline ) {
		try {
			if ( ! self::$active || ( defined( 'QPUCG_SCANNING' ) && QPUCG_SCANNING ) ) {
				return;
			}
			$key = $errno . '|' . $errline . '|' . $errfile . '|' . crc32( (string) $errstr );
			if ( isset( self::$buffer[ $key ] ) ) {
				++self::$buffer[ $key ][4];
			} elseif ( count( self::$buffer ) < self::MAX_DISTINCT ) {
				self::$buffer[ $key ] = array( $errno, substr( (string) $errstr, 0, self::MAX_RAW ), (string) $errfile, (int) $errline, 1 );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Filter callback in WordPress's fatal handler: records the crash and returns the arguments unchanged.
	 *
	 * @param array $args  Arguments for wp_die().
	 * @param array $error error_get_last().
	 * @return array
	 */
	public static function on_wp_fatal( $args, $error = null ) {
		self::record_crash( $error, 'filter' );
		return $args;
	}

	/**
	 * Shutdown function: the crash (if WordPress's handler didn't report it), then the buffer.
	 */
	public static function on_shutdown() {
		self::$reserve = null;
		self::$active  = false;
		try {
			if ( ( defined( 'QPUCG_SCANNING' ) && QPUCG_SCANNING ) || ! ( $GLOBALS['wpdb'] instanceof \wpdb ) ) {
				return;
			}
			$last = error_get_last();
			self::record_crash( $last, 'shutdown' );
			self::flush_buffer();
			State::on_request();
		} catch ( \Throwable $e ) {
			self::failed( get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Records one crash, once per request.
	 *
	 * @param array|null $error error_get_last().
	 * @param string     $path  'filter' or 'shutdown'.
	 */
	private static function record_crash( $error, $path ) {
		if ( '' !== self::$caught_by || ! is_array( $error ) || ! isset( $error['type'], $error['message'], $error['file'] )
			|| 0 === ( (int) $error['type'] & self::FATAL ) || ( defined( 'QPUCG_SCANNING' ) && QPUCG_SCANNING ) ) {
			return;
		}
		self::$reserve   = null;
		self::$caught_by = $path;
		try {
			// The plugin comes from the raw stack text, so this has to happen before the message is cleaned.
			$component = self::crash_component( (string) $error['message'], (string) $error['file'] );
			$row       = self::row( $component, self::CRASH, (string) $error['file'], (int) ( isset( $error['line'] ) ? $error['line'] : 0 ), (string) $error['message'], 1 );
			$context   = self::crash_context( $component );
			self::store( array( $row ), $context );
			Notify::on_crash( $row, $context );
		} catch ( \Throwable $e ) {
			self::failed( get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Writes the watched errors of this request.
	 */
	private static function flush_buffer() {
		if ( empty( self::$buffer ) ) {
			return;
		}
		$rows = array();
		foreach ( self::$buffer as $item ) {
			if ( ! isset( self::LEVELS[ $item[0] ] ) ) {
				continue;
			}
			// Skip non-fatal warnings/notices/deprecations silenced with PHP's @ operator at the source line.
			if ( self::ERROR !== self::LEVELS[ $item[0] ] && self::is_silenced( $item[2], $item[3] ) ) {
				continue;
			}
			$key       = Paths::key( $item[2] );
			$component = null === $key ? self::CORE : Paths::component( $key );
			$row       = self::row( $component, self::LEVELS[ $item[0] ], $item[2], $item[3], $item[1], $item[4] );
			if ( isset( $rows[ $row['hash'] ] ) ) {
				$rows[ $row['hash'] ]['hits'] += $row['hits'];
			} else {
				$rows[ $row['hash'] ] = $row;
			}
		}
		self::$buffer     = array();
		self::$file_lines = array();
		if ( empty( $rows ) ) {
			return;
		}
		self::store( array_values( $rows ), '' !== self::$caught_by ? self::base_context() : Recorder::context() );
	}

	/**
	 * Checks at shutdown whether a buffered error originated from an @-silenced expression.
	 *
	 * Inspects up to SILENCE_LOOKBACK lines ending at $line, stopping at the previous statement boundary (; { }),
	 * using token_get_all() without altering runtime error state, stack inspection, or @ suppression.
	 *
	 * @param string $file Source file path.
	 * @param int    $line 1-based line number.
	 * @return bool
	 */
	private static function is_silenced( $file, $line ) {
		try {
			if ( ! is_string( $file ) || '' === $file || $line <= 0 || false !== strpos( $file, "eval()'d code" ) ) {
				return false;
			}
			if ( ! array_key_exists( $file, self::$file_lines ) ) {
				if ( ! is_file( $file ) || ! is_readable( $file ) ) {
					self::$file_lines[ $file ] = false;
				} else {
					$size = filesize( $file );
					if ( false === $size || $size > self::SILENCE_MAX_BYTES ) {
						self::$file_lines[ $file ] = false;
					} else {
						$lines                     = file( $file );
						self::$file_lines[ $file ] = is_array( $lines ) ? $lines : false;
					}
				}
			}
			$lines = self::$file_lines[ $file ];
			if ( ! is_array( $lines ) ) {
				return false;
			}
			$idx = (int) $line - 1;
			if ( ! isset( $lines[ $idx ] ) ) {
				return false;
			}
			$start   = max( 0, $idx - self::SILENCE_LOOKBACK + 1 );
			$snippet = implode( '', array_slice( $lines, $start, $idx - $start + 1 ) );
			if ( false === strpos( $snippet, '@' ) ) {
				return false;
			}
			$tokens = token_get_all( "<?php\n" . $snippet );
			$skip   = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
			$i      = count( $tokens ) - 1;
			// Skip trailing whitespace/comments and a single statement terminator on the error line.
			while ( $i >= 0 && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $skip, true ) ) {
				--$i;
			}
			if ( $i >= 0 && ( ';' === $tokens[ $i ] || '{' === $tokens[ $i ] ) ) {
				--$i;
			}
			for ( ; $i >= 0; --$i ) {
				$tok = $tokens[ $i ];
				if ( '@' === $tok ) {
					return true;
				}
				if ( ';' === $tok || '{' === $tok || '}' === $tok ) {
					return false;
				}
				if ( is_array( $tok ) && T_OPEN_TAG === $tok[0] ) {
					return false;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}

	/**
	 * Component for a crash: its file, or the first plugin or theme in the stack text when the file is a core file.
	 *
	 * @param string $message Raw message of error_get_last().
	 * @param string $file    File of error_get_last().
	 * @return string
	 */
	public static function crash_component( $message, $file ) {
		$key       = Paths::key( $file );
		$component = null === $key ? self::CORE : Paths::component( $key );
		if ( self::CORE !== $component ) {
			return $component;
		}
		if ( preg_match_all( '/^#\d+\s+(.+?)\(\d+\):/m', $message, $frames ) ) {
			foreach ( $frames[1] as $frame ) {
				$key = Paths::key( $frame );
				if ( null !== $key ) {
					return Paths::component( $key );
				}
			}
		}
		return self::CORE;
	}

	/**
	 * Builds one row.
	 *
	 * @param string $component Component id.
	 * @param string $level     Level.
	 * @param string $file      File.
	 * @param int    $line      Line.
	 * @param string $message   Raw message.
	 * @param int    $hits      Count.
	 * @return array
	 */
	private static function row( $component, $level, $file, $line, $message, $hits ) {
		$clean = self::clean( $message );
		$key   = Paths::key( $file );
		$where = str_replace( '\\', '/', self::clean( $file ) );
		$text  = self::CRASH === $level ? $clean : $clean . ' in ' . $where . ':' . $line;
		// No line number and no digits in the hash: a plugin update that moves code must not look like a new problem.
		$same = implode( '|', array( $component, $level, null === $key ? $where : $key, (string) preg_replace( '/\d+/', 'N', $clean ) ) );
		return array(
			'component' => $component,
			'level'     => $level,
			'hash'      => md5( $same ),
			'hits'      => (int) $hits,
			'sample'    => mb_substr( $text, 0, self::SAMPLE_CHARS, 'UTF-8' ),
		);
	}

	/**
	 * A message made safe to store: first line only, relative paths, no URLs, query strings, SQL, emails or IP addresses.
	 * Best effort: a plugin can put anything in its own message.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	public static function clean( $message ) {
		$text = substr( (string) $message, 0, strcspn( (string) $message, "\r\n" ) );
		$text = (string) preg_replace( '/\b(for query|query was:?)\s.*$/i', '[query]', $text );
		$text = (string) preg_replace( '#\b[a-z][a-z0-9+.\-]*://[^\s\'"<>)]+#i', '[url]', $text );
		$text = (string) preg_replace( '/\?[\w%.\-\[\]]+=[^\s\'"]*/', '[url]', $text );
		$text = (string) preg_replace( '/[\w.+\-]+@[\w\-]+\.[\w.\-]+/', '[email]', $text );
		foreach ( self::roots() as $pattern => $replacement ) {
			$text = (string) preg_replace_callback(
				$pattern,
				function ( $found ) use ( $replacement ) {
					return $replacement . str_replace( '\\', '/', $found[1] );
				},
				$text
			);
		}
		$text = (string) preg_replace( '/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip]', $text );
		$text = (string) preg_replace( '/\b(?:[0-9a-f]{1,4}:){4,7}[0-9a-f]{1,4}\b/i', '[ip]', $text );
		$text = (string) preg_replace( '#\b[a-z]:[\\\\/][^\s\'"():,]*#i', '[path]', $text );
		$text = (string) preg_replace( '#(?<![\w.\]>\-])/(?:[\w.\-]+/)+[\w.\-]*#', '[path]', $text );
		if ( ! preg_match( '//u', $text ) ) {
			$text = (string) preg_replace( '/[^\x00-\x7F]/', '?', $text );
		}
		// wpdb refuses a query with characters the column can't store; 4-byte characters don't fit a utf8 (3-byte) table.
		$text = (string) preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '?', $text );
		return (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
	}

	/**
	 * Site paths as patterns that accept either slash, wp-content first (it can be outside the WordPress folder).
	 *
	 * @return array pattern => replacement.
	 */
	private static function roots() {
		if ( null !== self::$roots ) {
			return self::$roots;
		}
		$dirs        = Paths::scrub_roots();
		self::$roots = array();
		foreach ( $dirs as $dir ) {
			if ( '' === $dir[0] ) {
				continue;
			}
			$parts   = preg_split( '#[\\\\/]+#', rtrim( (string) $dir[0], '\\/' ) );
			$quoted  = array_map(
				function ( $part ) {
					return preg_quote( $part, '#' );
				},
				$parts
			);
			$pattern = '#' . implode( '[\\\\/]+', $quoted ) . '[\\\\/]+([^\s\'"():,]*)#i';

			self::$roots[ $pattern ] = $dir[1];
		}
		return self::$roots;
	}

	/**
	 * Page type after a crash. WooCommerce's own conditional tags aren't called when WooCommerce is what crashed.
	 *
	 * @param string $component Component that crashed.
	 * @return int
	 */
	private static function crash_context( $component ) {
		$context = 0 === strpos( $component, 'plugin:woocommerce' ) ? self::base_context() : Recorder::context();
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
			$context |= Recorder::WC_CHECKOUT;
		}
		return $context;
	}

	/**
	 * The page type from constants and core only, for use after a crash.
	 *
	 * @return int
	 */
	private static function base_context() {
		if ( wp_doing_cron() ) {
			return Recorder::CRON;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return Recorder::REST;
		}
		if ( wp_doing_ajax() ) {
			return Recorder::AJAX;
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return Recorder::LOGIN;
		}
		return is_admin() ? Recorder::ADMIN : Recorder::FRONT;
	}

	/**
	 * Writes rows in one statement. Callers are at the end of the request; errors are suppressed and only pause the watch.
	 *
	 * @param array $rows    Rows from row().
	 * @param int   $context Page type bits.
	 */
	private static function store( array $rows, $context ) {
		global $wpdb;

		$day = current_time( 'Y-m-d' );
		if ( State::get()['capped_day'] === $day ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) {
						return self::CRASH === $row['level'];
					}
				)
			);
		}
		if ( empty( $rows ) ) {
			return;
		}

		$suppress = $wpdb->suppress_errors( true );
		$limited  = false;
		try {
			$limited  = Recorder::limit_lock_wait();
			$affected = self::upsert( $rows, $context, $day );
			if ( false === $affected ) {
				self::failed( 'errors: ' . ( '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'unknown database error' ) );
				return;
			}
			// An inserted row counts 1 and an updated row 2, so anything under 2 per row means new rows arrived.
			if ( $affected < 2 * count( $rows ) ) {
				self::check_cap( $day );
			}
		} catch ( \Throwable $e ) {
			self::failed( get_class( $e ) . ': ' . $e->getMessage() );
		} finally {
			if ( $limited ) {
				Recorder::restore_lock_wait();
			}
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * The upsert (single transaction, one prepared statement per buffered row).
	 * Preserves cumulative rows_affected semantics (1 per insert, 2 per update) for check_cap().
	 *
	 * @param array  $rows    Rows.
	 * @param int    $context Page type bits.
	 * @param string $day     Y-m-d.
	 * @return int|false Total rows affected, false on error.
	 */
	private static function upsert( array $rows, $context, $day ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$php   = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$total = 0;

		$use_tx = count( $rows ) > 1;
		if ( $use_tx ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		foreach ( $rows as $row ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (day, php_version, component, level, message_hash, hits, context, sample) VALUES (%s, %s, %s, %s, %s, %d, %d, %s) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), context = context | VALUES(context)',
					$table,
					$day,
					$php,
					$row['component'],
					$row['level'],
					$row['hash'],
					$row['hits'],
					$context,
					$row['sample']
				)
			);
			if ( false === $result ) {
				if ( $use_tx ) {
					$wpdb->query( 'ROLLBACK' );
				}
				return false;
			}
			$total += (int) $wpdb->rows_affected;
		}
		if ( $use_tx ) {
			$wpdb->query( 'COMMIT' );
		}
		return $total;
	}

	/**
	 * Marks the day as full once it has DAILY_CAP rows. Costs one indexed count, only when a request added new rows.
	 *
	 * @param string $day Y-m-d.
	 */
	private static function check_cap( $day ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$rows  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE day = %s', $table, $day ) );
		if ( $rows >= self::DAILY_CAP ) {
			$state               = State::get();
			$state['capped_day'] = $day;
			State::save( $state );
		}
	}

	/**
	 * Stores the error text and pauses the watch for 10 minutes. Never throws.
	 *
	 * @param string $message Error text.
	 */
	private static function failed( $message ) {
		try {
			Recorder::set_error( 'upgrade watch: ' . $message );
			$settings = Settings::stored();
			if ( null === $settings ) {
				return;
			}
			$settings['watch_paused_until'] = time() + self::PAUSE_SECONDS;
			update_option( Settings::OPTION, $settings );
			Settings::flush();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Daily retention: deletes rows older than 30 days in small chunks, inside a time limit (as Rollup::phase_purge does).
	 * With the watch off, the state goes too once the table is empty, so the cron callback stops being added.
	 */
	public static function purge() {
		global $wpdb;

		if ( QPUCG_DB_VERSION !== get_option( 'qpucg_db_version' ) ) {
			return;
		}
		$table    = $wpdb->prefix . self::TABLE;
		$cutoff   = wp_date( 'Y-m-d', time() - self::RETENTION * DAY_IN_SECONDS );
		$deadline = microtime( true ) + self::PURGE_SECONDS;
		do {
			$count = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s LIMIT %d', $table, $cutoff, self::PURGE_CHUNK ) );
			if ( false === $count ) {
				Recorder::set_error( 'upgrade watch purge: ' . $wpdb->last_error );
				return;
			}
		} while ( self::PURGE_CHUNK === (int) $count && microtime( true ) < $deadline );

		if ( empty( Settings::get( 'upgrade_watch' ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ) {
			delete_option( State::OPTION );
		}
	}
}
