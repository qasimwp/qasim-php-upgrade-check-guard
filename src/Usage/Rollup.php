<?php
/**
 * Rollup: filesets + hit counters → per-file usage table (spec §7.2), in time-budgeted steps (DECISIONS D48).
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Usage;

use Qpucg\Install;
use Qpucg\Paths;
use Qpucg\Scan\Runner;
use Qpucg\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Builds `qpucg_usage` and the rollup meta as a job of phases: init → sets → write → swap → purge.
 *
 * The job row (option `qpucg_rollup_job`, read and written with SQL only) holds the phase and the cursor.
 * The per-file totals built so far live in `qpucg_rollup_state`; the new usage rows are built in
 * `qpucg_usage_next` (tagged with the job id) and copied into `qpucg_usage` in one transaction together with
 * the meta. One step runs at a time (atomic lock option row, like the scan lock, DECISIONS D47), and every step
 * commits with a compare-and-swap on the job row, so a step that dies leaves the committed job unchanged and the
 * next step redoes its work. Admin pages only read the last swapped result.
 */
class Rollup {

	const META           = 'qpucg_rollup_meta';
	const LOCK           = 'qpucg_rollup_lock';
	const JOB            = 'qpucg_rollup_job';
	const STEP_HOOK      = 'qpucg_rollup_step';
	const MAX_AGE        = 3600;
	const RETENTION_DAYS = 30; // DECISIONS D55 (was 60).
	const MIN_REQUESTS   = 30;
	const PAGE_SIZE      = 50;
	const INSERT_CHUNK   = 200;
	const PURGE_CHUNK    = 100;
	const STEP_SECONDS   = 5;
	const LOCK_TTL       = 60;
	const STATE_CHUNK    = 262144;
	const RETRY_SECONDS  = 600;
	const MEMORY_ROOM    = 33554432;
	const MAX_RESTARTS   = 3;
	const STOPPED        = 'qpucg_rollup_stopped';
	const RECENT_DAYS    = 3; // Days the "no list of files" notice looks at (DECISIONS D66).

	const COMPACT_STEP_HOOK = 'qpucg_compact_step';
	const COMPACT_RETRY     = 60;
	const DICTIONARY_CHUNK  = 200;
	const ORPHAN_PAGE       = 200;

	/**
	 * Lock token of the step running in this process ('' = none).
	 *
	 * @var string
	 */
	private static $token = '';

	/**
	 * Seconds the lock held by this process lasts after each hold() (Storage takes it for longer, DECISIONS D66).
	 *
	 * @var int
	 */
	private static $ttl = self::LOCK_TTL;

	/**
	 * Dictionary while the lock is held: path key => file id. Null = not loaded.
	 *
	 * @var array|null
	 */
	private static $file_ids = null;

	/**
	 * Dictionary while the lock is held: file id => component id.
	 *
	 * @var array
	 */
	private static $file_components = array();

	/**
	 * Raw path => path key|null, while the lock is held.
	 *
	 * @var array
	 */
	private static $path_keys = array();

	/**
	 * Hourly cron and its follow-up: converts new file lists to dictionary ids, so raw lists never pile up until the
	 * daily rollup (DECISIONS D55). While a backlog remains it runs again a minute later.
	 */
	public static function compact() {
		if ( self::stale_db() ) {
			return;
		}
		update_option( Health::HEARTBEAT, time(), false );
		$more = self::guarded(
			function () {
				return self::compact_step();
			}
		);
		if ( true === $more && ! wp_next_scheduled( self::COMPACT_STEP_HOOK ) ) {
			wp_schedule_single_event( time() + self::COMPACT_RETRY, self::COMPACT_STEP_HOOK );
		}
		Storage::hourly();
	}

	/**
	 * One time-budgeted conversion run. Nothing else: no totals, no job.
	 *
	 * @return bool Whether lists are left to convert (also when another step holds the lock).
	 * @throws \RuntimeException On a DB error, or when memory is too close to the limit.
	 */
	public static function compact_step() {
		global $wpdb;

		$table = Install::table( 'filesets' );
		// Most runs end here: the cheap check comes before the memory raise and the lock.
		if ( null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE format < %d LIMIT 1', $table, Recorder::FORMAT_IDS ) ) ) {
			self::check_db( 'filesets' );
			return false;
		}
		$t0 = microtime( true );
		self::make_room();
		$token = self::lock();
		if ( false === $token ) {
			return true;
		}
		self::begin( $token );
		try {
			$deadline = $t0 + self::budget() * 0.8;
			while ( true ) {
				$page = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, files, is_compressed, format FROM %i WHERE format < %d ORDER BY id LIMIT %d', $table, Recorder::FORMAT_IDS, self::PAGE_SIZE ), ARRAY_A );
				self::check_db( 'filesets' );
				if ( empty( $page ) ) {
					return false;
				}
				if ( ! self::hold() ) {
					return true;
				}
				foreach ( $page as $i => $set ) {
					self::convert( $set );
					unset( $page[ $i ] );
					if ( microtime( true ) >= $deadline ) {
						return true;
					}
				}
			}
		} finally {
			self::end( $token );
		}
	}

	/**
	 * Runs a callback under the step lock, for other lock-holding work (DECISIONS D63).
	 *
	 * @param callable $callback Callback.
	 * @param int      $ttl      Seconds the lock lasts: at least as long as the callback may run (DECISIONS D66).
	 * @return mixed Callback result, or null when another step holds the lock.
	 */
	public static function with_lock( $callback, $ttl = self::LOCK_TTL ) {
		$token = self::lock( $ttl );
		if ( false === $token ) {
			return null;
		}
		self::begin( $token );
		self::$ttl = max( self::LOCK_TTL, (int) $ttl );
		try {
			return call_user_func( $callback );
		} finally {
			self::end( $token );
		}
	}

	/**
	 * Starts lock-holding work: fresh dictionary caches (another process may have reset the data).
	 *
	 * @param string $token Lock token.
	 */
	private static function begin( $token ) {
		self::$token           = $token;
		self::$file_ids        = null;
		self::$file_components = array();
		self::$path_keys       = array();
	}

	/**
	 * Ends lock-holding work.
	 *
	 * @param string $token Lock token.
	 */
	private static function end( $token ) {
		self::unlock( $token );
		self::$token = '';
		self::$ttl   = self::LOCK_TTL;
	}

	/**
	 * Raises the memory limit like a scan batch and refuses when a step wouldn't fit (DECISIONS D51).
	 *
	 * @return int Memory in use, bytes.
	 * @throws \RuntimeException When memory is too close to the limit.
	 */
	private static function make_room() {
		wp_raise_memory_limit( 'admin' );
		$in_use = memory_get_usage();
		$limit  = Runner::memory_limit();
		if ( $in_use + self::MEMORY_ROOM > $limit ) {
			throw new \RuntimeException(
				sprintf(
					'not enough memory for a step (%s MB in use, limit %s MB). Raise WP_MAX_MEMORY_LIMIT or run wp qpucg usage --rollup',
					number_format( $in_use / 1048576, 1 ),
					number_format( $limit / 1048576, 1 )
				)
			);
		}
		return $in_use;
	}

	/**
	 * Loads the dictionary once per lock.
	 *
	 * @throws \RuntimeException On a DB error.
	 */
	private static function load_dictionary() {
		global $wpdb;

		if ( null !== self::$file_ids ) {
			return;
		}
		$table          = Install::table( 'files' );
		$rows           = $wpdb->get_results( $wpdb->prepare( 'SELECT id, path_key, component FROM %i', $table ), ARRAY_N );
		self::$file_ids = array();
		self::check_db( 'files' );
		foreach ( (array) $rows as $row ) {
			self::$file_ids[ $row[1] ]              = (int) $row[0];
			self::$file_components[ (int) $row[0] ] = $row[2];
		}
	}

	/**
	 * Dictionary ids of path keys; keys not seen before are added. Only called while the lock is held, so the rollup is
	 * the only writer. Invalid UTF-8 is stripped first: the column couldn't store it (DECISIONS D51).
	 *
	 * @param string[] $keys Path keys.
	 * @return int[] Sorted, unique ids.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function ids_for( array $keys ) {
		global $wpdb;

		self::load_dictionary();
		$ids     = array();
		$missing = array();
		foreach ( $keys as $key ) {
			if ( isset( self::$file_ids[ $key ] ) ) {
				$ids[ self::$file_ids[ $key ] ] = true;
			} else {
				$missing[ $key ] = true;
			}
		}

		$table = Install::table( 'files' );
		foreach ( array_chunk( array_keys( $missing ), self::DICTIONARY_CHUNK ) as $chunk ) {
			$by_hash = array();
			$values  = array();
			foreach ( $chunk as $key ) {
				$key   = (string) $key;
				$clean = wp_check_invalid_utf8( $key, true );
				if ( '' === $clean ) {
					continue;
				}
				$hash                     = Paths::hash( $clean );
				$by_hash[ $hash ][ $key ] = $clean;
				$values[ $hash ]          = array( $hash, $clean, Paths::component( $clean ) );
			}
			if ( empty( $values ) ) {
				continue;
			}
			$wpdb->query( 'START TRANSACTION' );
			try {
				foreach ( array_chunk( array_values( $values ), 5 ) as $ins_chunk ) {
					if ( 5 === count( $ins_chunk ) ) {
						$wpdb->query(
							$wpdb->prepare(
								'INSERT IGNORE INTO %i (path_hash, path_key, component) VALUES (%s, %s, %s), (%s, %s, %s), (%s, %s, %s), (%s, %s, %s), (%s, %s, %s)',
								$table,
								$ins_chunk[0][0],
								$ins_chunk[0][1],
								$ins_chunk[0][2],
								$ins_chunk[1][0],
								$ins_chunk[1][1],
								$ins_chunk[1][2],
								$ins_chunk[2][0],
								$ins_chunk[2][1],
								$ins_chunk[2][2],
								$ins_chunk[3][0],
								$ins_chunk[3][1],
								$ins_chunk[3][2],
								$ins_chunk[4][0],
								$ins_chunk[4][1],
								$ins_chunk[4][2]
							)
						);
						self::check_db( 'files' );
					} else {
						foreach ( $ins_chunk as $item ) {
							$wpdb->query(
								$wpdb->prepare(
									'INSERT IGNORE INTO %i (path_hash, path_key, component) VALUES (%s, %s, %s)',
									$table,
									$item[0],
									$item[1],
									$item[2]
								)
							);
							self::check_db( 'files' );
						}
					}
				}
				$wpdb->query( 'COMMIT' );
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}

			$rows = array();
			foreach ( array_chunk( array_keys( $values ), 20 ) as $hash_chunk ) {
				$padded = array_pad( $hash_chunk, 20, end( $hash_chunk ) );
				$part   = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, path_hash, component FROM %i WHERE path_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
						$table,
						$padded[0],
						$padded[1],
						$padded[2],
						$padded[3],
						$padded[4],
						$padded[5],
						$padded[6],
						$padded[7],
						$padded[8],
						$padded[9],
						$padded[10],
						$padded[11],
						$padded[12],
						$padded[13],
						$padded[14],
						$padded[15],
						$padded[16],
						$padded[17],
						$padded[18],
						$padded[19]
					),
					ARRAY_A
				);
				self::check_db( 'files' );
				if ( is_array( $part ) ) {
					$rows = array_merge( $rows, $part );
				}
			}
			foreach ( (array) $rows as $row ) {
				$id                           = (int) $row['id'];
				self::$file_components[ $id ] = $row['component'];
				foreach ( $by_hash[ $row['path_hash'] ] as $key => $clean ) {
					self::$file_ids[ $key ]   = $id;
					self::$file_ids[ $clean ] = $id;
					$ids[ $id ]               = true;
				}
			}
		}

		$ids = array_keys( $ids );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Path keys of raw paths stored by the recorder, cached while the lock is held.
	 *
	 * @param string[] $paths Raw paths.
	 * @return string[] Path keys.
	 */
	private static function keys_of( array $paths ) {
		$keys = array();
		foreach ( $paths as $path ) {
			if ( ! array_key_exists( $path, self::$path_keys ) ) {
				self::$path_keys[ $path ] = Paths::key( $path );
			}
			if ( null !== self::$path_keys[ $path ] ) {
				$keys[] = self::$path_keys[ $path ];
			}
		}
		return $keys;
	}

	/**
	 * Converts one stored list to packed dictionary ids and rewrites its row. Idempotent: a step that dies later just
	 * finds the row converted.
	 *
	 * @param array $set Row: id, files, is_compressed, format.
	 * @return int[] Runs: start id, length - 1, start id, length - 1, ...
	 * @throws \RuntimeException On a DB error.
	 */
	private static function convert( array $set ) {
		global $wpdb;

		$format = (int) $set['format'];
		$list   = self::unpack( $set['files'], (int) $set['is_compressed'] );
		$ids    = array();
		if ( '' !== $list ) {
			$lines = explode( "\n", $list );
			$ids   = self::ids_for( Recorder::FORMAT_PATHS === $format ? self::keys_of( $lines ) : $lines );
		}
		$runs = self::runs( $ids );

		$data       = empty( $runs ) ? '' : pack( 'N*', ...$runs );
		$compressed = 0;
		if ( '' !== $data && function_exists( 'gzcompress' ) ) {
			$data       = gzcompress( $data, 6 );
			$compressed = 1;
		}
		$table = Install::table( 'filesets' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET files = UNHEX(%s), is_compressed = %d, format = %d WHERE id = %d AND format = %d', $table, bin2hex( $data ), $compressed, Recorder::FORMAT_IDS, (int) $set['id'], $format ) );
		self::check_db( 'filesets' );
		return $runs;
	}

	/**
	 * Sorted ids as runs of consecutive ids.
	 *
	 * @param int[] $ids Sorted, unique ids.
	 * @return int[] start id, length - 1, start id, length - 1, ...
	 */
	private static function runs( array $ids ) {
		$runs = array();
		$last = -2;
		$open = -1;
		foreach ( $ids as $id ) {
			if ( $id === $last + 1 ) {
				++$runs[ $open ];
			} else {
				$runs[] = $id;
				$runs[] = 0;
				$open   = count( $runs ) - 1;
			}
			$last = $id;
		}
		return $runs;
	}

	/**
	 * Runs of a converted row.
	 *
	 * @param string $data       Stored data.
	 * @param int    $compressed Whether gzcompress was used.
	 * @return int[] start id, length - 1, ...
	 */
	private static function stored_runs( $data, $compressed ) {
		$binary = self::unpack( $data, $compressed );
		if ( '' === $binary ) {
			return array();
		}
		$runs = unpack( 'N*', $binary );
		return false === $runs ? array() : array_values( $runs );
	}

	/**
	 * Daily cron: starts a rollup (which ends with the retention purge), or continues the running one, by one step.
	 */
	public static function daily() {
		if ( self::stale_db() ) {
			return;
		}
		$ok = self::guarded(
			function () {
				return self::step( true );
			}
		);
		self::schedule_next( false !== $ok );
	}

	/**
	 * Single cron event: one step of the running rollup, or a new one if the data is overdue (or `$force`: the storage
	 * cap deleted days, so "not seen in N days" must follow now, DECISIONS D63). Re-schedules itself until the job is done.
	 *
	 * @param bool $force Start a new rollup even if the last one is recent.
	 */
	public static function cron_step( $force = false ) {
		if ( self::stale_db() ) {
			return;
		}
		$start = ( true === $force || self::overdue() ) && ! self::stopped();
		$ok    = self::guarded(
			function () use ( $start ) {
				return self::step( $start );
			}
		);
		self::schedule_next( false !== $ok );
	}

	/**
	 * Files updated but tables not yet: cron runs the upgrade itself, so a site nobody logs in to doesn't stop recording
	 * (DECISIONS D63, amends D51). Install::maybe_upgrade() has its own lock and reads the version with SQL, so a stale
	 * cached option is cleared here too. Only when the upgrade failed does this cron run do nothing.
	 *
	 * @return bool
	 */
	private static function stale_db() {
		if ( QPUCG_DB_VERSION === get_option( 'qpucg_db_version' ) ) {
			return false;
		}
		return true !== self::guarded(
			function () {
				return Install::maybe_upgrade();
			}
		);
	}

	/**
	 * Whether a job gave up after MAX_RESTARTS in the last day: only the daily cron, the button or WP-CLI start a new
	 * one then, not an overdue check (DECISIONS D51).
	 *
	 * @return bool
	 */
	private static function stopped() {
		return time() - (int) get_option( self::STOPPED, 0 ) < DAY_IN_SECONDS;
	}

	/**
	 * Admin page render: never rolls up, only schedules a cron step when a job is running or the data is overdue.
	 */
	public static function maybe_schedule() {
		$ok = self::guarded(
			function () {
				return null !== self::job_raw() || ( self::overdue() && ! self::stopped() );
			}
		);
		if ( true === $ok && ! wp_next_scheduled( self::STEP_HOOK ) ) {
			wp_schedule_single_event( time(), self::STEP_HOOK );
		}
	}

	/**
	 * Schedules the next cron step while a job exists.
	 *
	 * @param bool $ok Whether the last step ran without an error (else retry later).
	 */
	private static function schedule_next( $ok ) {
		$running = self::guarded(
			function () {
				return null !== self::job_raw();
			}
		);
		if ( true === $running && ! wp_next_scheduled( self::STEP_HOOK ) ) {
			wp_schedule_single_event( time() + ( $ok ? 0 : self::RETRY_SECONDS ), self::STEP_HOOK );
		}
	}

	/**
	 * Whether the last rollup is missing or older than 1 hour.
	 *
	 * @return bool
	 */
	public static function overdue() {
		$meta = self::meta();
		return false === $meta || time() - (int) $meta['time'] > self::MAX_AGE;
	}

	/**
	 * Runs a callback with DB errors suppressed; a Throwable is stored in the recorder flags
	 * (`last_error` = "rollup: ...") instead of reaching cron or the admin page.
	 *
	 * @param callable $callback Callback.
	 * @return mixed Callback result, or false on error.
	 */
	public static function guarded( $callback ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		try {
			return call_user_func( $callback );
		} catch ( \Throwable $e ) {
			Recorder::set_error( 'rollup: ' . $e->getMessage() );
			return false;
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Throws when the last query failed.
	 *
	 * @param string $what Label for the message.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function check_db( $what ) {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored in the recorder flags, escaped when displayed.
			throw new \RuntimeException( $what . ': ' . $wpdb->last_error );
		}
	}

	/**
	 * Last swapped rollup meta: option `qpucg_rollup_meta` (JSON, SQL only, written in the swap transaction).
	 * Builds before T7.3 kept it in a transient of the same name, which is still read until the first new rollup.
	 *
	 * @return array|false
	 */
	public static function meta() {
		global $wpdb;
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::META ) );
		$meta = is_string( $raw ) ? json_decode( $raw, true ) : get_transient( self::META );
		return is_array( $meta ) && isset( $meta['time'] ) ? $meta : false;
	}

	/**
	 * What the admin pages show: the last meta, the running job's progress, whether the data is overdue and whether
	 * a cron step is scheduled. Reads only.
	 *
	 * @return array meta (array|false), progress (array), overdue (bool), stopped (bool), scheduled (bool),
	 *               cron_disabled (bool).
	 */
	public static function status() {
		$job = self::guarded(
			function () {
				$raw = self::job_raw();
				return null === $raw ? null : self::decode( $raw );
			}
		);
		return array(
			'meta'          => self::meta(),
			'progress'      => self::progress( is_array( $job ) ? $job : null ),
			'overdue'       => self::overdue(),
			'stopped'       => self::stopped(),
			'scheduled'     => false !== wp_next_scheduled( self::STEP_HOOK ),
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	/**
	 * Days the Check tab may name in "not seen running in the last N days": the days of the last rollup, but never more than
	 * the days still kept, so a day the storage cap deleted is not counted until the next rollup (DECISIONS D66).
	 *
	 * @param array|false $meta meta().
	 * @return int
	 */
	public static function days_shown( $meta ) {
		global $wpdb;

		if ( false === $meta ) {
			return 0;
		}
		$days     = (int) $meta['days_observed'];
		$hits     = Install::table( 'fileset_hits' );
		$folded   = Install::table( 'usage_days' );
		$suppress = $wpdb->suppress_errors( true );
		$kept     = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT DISTINCT day FROM %i UNION SELECT DISTINCT day FROM %i WHERE day >= %s) d', $hits, $folded, wp_date( 'Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
		$wpdb->suppress_errors( $suppress );
		return null === $kept ? $days : min( $days, (int) $kept );
	}

	/**
	 * Runs steps until the job is done (WP-CLI). Starts a job if none is running. Waits while another process
	 * holds the lock.
	 *
	 * @param callable|null $on_step Called with each step's result.
	 * @return array|false Meta, or false if another process kept the lock for too long.
	 */
	public static function run_all( $on_step = null ) {
		$start  = true;
		$waited = 0;
		while ( true ) {
			$result = self::step( $start );
			if ( null !== $on_step ) {
				call_user_func( $on_step, $result );
			}
			if ( $result['busy'] ) {
				if ( $waited > self::LOCK_TTL + 10 ) {
					return false;
				}
				sleep( 2 );
				$waited += 2;
				continue;
			}
			$start  = false;
			$waited = 0;
			if ( ! $result['running'] ) {
				return self::meta();
			}
		}
	}

	/**
	 * One time-budgeted step: min( 5 s, max_execution_time / 3 ). Works through as many phases as the budget
	 * allows; always makes some progress.
	 *
	 * @param bool $start Start a new job if none is running.
	 * @return array progress() plus busy, seconds, mem_start, mem_peak (bytes) and work (phase => seconds).
	 * @throws \RuntimeException On a DB error, or when memory is too close to the limit for a step.
	 */
	public static function step( $start ) {
		$t0 = microtime( true );
		// Cron runs with the front-end memory limit; steps need the admin one, like a scan batch (DECISIONS D51).
		$mem_start = self::make_room();
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage(); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.memory_reset_peak_usageFound -- guarded by function_exists().
		}
		$work = array();
		$busy = false;
		$job  = null;

		$token = self::lock();
		if ( false === $token ) {
			$busy = true;
			$raw  = self::job_raw();
			$job  = null === $raw ? null : self::try_decode( $raw );
		} else {
			self::begin( $token );
			try {
				$raw = self::job_raw();
				if ( null !== $raw && null === self::try_decode( $raw ) ) {
					self::drop_invalid( $raw );
					$raw = null;
				}
				if ( null === $raw && $start ) {
					$raw = self::create_job();
				}
				// Sets and write stop at 80 % of the budget, so saving the totals still fits in it.
				$deadline = $t0 + self::budget() * 0.8;
				while ( is_string( $raw ) ) {
					$job   = self::decode( $raw );
					$phase = $job['phase'];
					if ( 'swap' === $phase && ! empty( $work ) ) {
						// The swap is one transaction the budget can't split: it gets a step of its own.
						break;
					}
					$t = microtime( true );
					switch ( $phase ) {
						case 'init':
							$raw = self::phase_init( $raw, $job );
							break;
						case 'sets':
							$raw = self::phase_sets( $raw, $job, $deadline );
							break;
						case 'write':
							$raw = self::phase_write( $raw, $job, $deadline );
							break;
						case 'swap':
							$raw = self::phase_swap( $raw, $job );
							break;
						case 'fold':
							$raw = self::phase_fold( $raw, $job );
							break;
						default:
							$raw = self::phase_purge( $raw, $job, $deadline );
					}
					$work[ $phase ] = ( isset( $work[ $phase ] ) ? $work[ $phase ] : 0 ) + microtime( true ) - $t;
					if ( 'swap' === $phase || microtime( true ) >= $deadline ) {
						break;
					}
				}
				// false = lost the lock or the job row changed (nothing committed); null = the job finished.
				$job = is_string( $raw ) ? self::decode( $raw ) : self::job();
			} finally {
				self::end( $token );
			}
		}

		$out              = self::progress( $job );
		$out['busy']      = $busy;
		$out['seconds']   = round( microtime( true ) - $t0, 3 );
		$out['mem_start'] = $mem_start;
		$out['mem_peak']  = memory_get_peak_usage();
		$out['work']      = array_map(
			function ( $s ) {
				return round( $s, 3 );
			},
			$work
		);
		return $out;
	}

	/**
	 * Seconds one step may use.
	 *
	 * @return float
	 */
	private static function budget() {
		$budget = (float) self::STEP_SECONDS;
		$limit  = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$budget = min( $budget, $limit / 3 );
		}
		return $budget;
	}

	/**
	 * Progress of a job for the admin page, AJAX and WP-CLI.
	 *
	 * @param array|null $job Job, or null if none is running.
	 * @return array running, phase, sets_done, sets_total, files_done, files_total, percent.
	 */
	private static function progress( $job ) {
		$out = array(
			'running'     => null !== $job,
			'busy'        => false,
			'phase'       => '',
			'sets_done'   => 0,
			'sets_total'  => 0,
			'files_done'  => 0,
			'files_total' => 0,
			'percent'     => 0,
		);
		if ( null === $job ) {
			return $out;
		}
		$out['phase']       = $job['phase'];
		$out['sets_done']   = (int) $job['sets_done'];
		$out['sets_total']  = (int) $job['sets_total'];
		$out['files_total'] = (int) $job['files'];
		if ( 'sets' === $job['phase'] ) {
			$out['percent'] = $job['sets_total'] > 0 ? (int) floor( 90 * $job['sets_done'] / $job['sets_total'] ) : 0;
		} elseif ( 'write' === $job['phase'] ) {
			$out['files_done'] = (int) $job['cursor'];
			$out['percent']    = 90 + ( $job['files'] > 0 ? (int) floor( 9 * $job['cursor'] / $job['files'] ) : 0 );
		} elseif ( 'init' !== $job['phase'] ) {
			$out['percent'] = 99;
		}
		return $out;
	}

	/**
	 * Takes the step lock: option row `qpucg_rollup_lock` = "{token}|{expires}", SQL only. INSERT IGNORE on the
	 * unique option_name is atomic; an expired lock (a step that died) is taken over by compare-and-swap. Same
	 * pattern as the scan lock (DECISIONS D47).
	 *
	 * @param int $ttl Seconds until the lock expires.
	 * @return string|false Token, or false when another step holds it.
	 */
	private static function lock( $ttl = self::LOCK_TTL ) {
		global $wpdb;
		$token = wp_generate_password( 12, false );
		$value = $token . '|' . ( time() + max( self::LOCK_TTL, (int) $ttl ) );
		if ( 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value ) ) ) {
			return $token;
		}
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		if ( ! is_string( $current ) ) {
			return false;
		}
		$bar = strrpos( $current, '|' );
		if ( false !== $bar && (int) substr( $current, $bar + 1 ) > time() ) {
			return false;
		}
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK, $current ) );
		return 1 === $taken ? $token : false;
	}

	/**
	 * Extends the lock if it is still ours. Called before every write, so a step that lost its lock writes nothing.
	 *
	 * @return bool
	 */
	private static function hold() {
		global $wpdb;
		if ( '' === self::$token ) {
			return false;
		}
		$mine = $wpdb->esc_like( self::$token . '|' ) . '%';
		if ( 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", self::$token . '|' . ( time() + self::$ttl ), self::LOCK, $mine ) ) ) {
			return true;
		}
		// 0 rows also when the value didn't change (same second).
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		return is_string( $current ) && 0 === strpos( $current, self::$token . '|' );
	}

	/**
	 * Releases the lock if it is still ours.
	 *
	 * @param string $token Token from lock().
	 */
	private static function unlock( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK, $wpdb->esc_like( $token . '|' ) . '%' ) );
	}

	/**
	 * Raw job row (JSON), or null when no job is running. SQL only, never the option cache.
	 *
	 * @return string|null
	 */
	private static function job_raw() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::JOB ) );
		return null === $raw ? null : (string) $raw;
	}

	/**
	 * Decoded current job, or null.
	 *
	 * @return array|null
	 */
	private static function job() {
		$raw = self::job_raw();
		return null === $raw ? null : self::decode( $raw );
	}

	/**
	 * Decodes a job row.
	 *
	 * @param string $raw JSON.
	 * @return array
	 * @throws \RuntimeException If the row is not a job.
	 */
	private static function decode( $raw ) {
		$job = json_decode( $raw, true );
		if ( ! is_array( $job ) || ! isset( $job['phase'] ) ) {
			throw new \RuntimeException( 'job row is not valid' );
		}
		return $job;
	}

	/**
	 * Decodes a job row; null if it is not a job.
	 *
	 * @param string $raw JSON.
	 * @return array|null
	 */
	private static function try_decode( $raw ) {
		try {
			return self::decode( $raw );
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Deletes a job row that can't be decoded (compare-and-swap on its value, while the lock is ours), so the next
	 * step can start a new job instead of failing forever (DECISIONS D51).
	 *
	 * @param string $raw Raw job row.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function drop_invalid( $raw ) {
		global $wpdb;
		if ( ! self::hold() ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::JOB, $raw ) );
		self::check_db( 'job' );
		Recorder::set_error( 'rollup: the job row was not valid and was deleted; a new rollup starts' );
	}

	/**
	 * Inserts a new job row (INSERT IGNORE: atomic if two requests start one at once).
	 *
	 * @return string Raw job row.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function create_job() {
		global $wpdb;

		$job = self::new_job();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::JOB, wp_json_encode( $job ) ) );
		self::check_db( 'job' );
		$raw = self::job_raw();
		if ( null === $raw ) {
			throw new \RuntimeException( 'job row could not be created' );
		}
		return $raw;
	}

	/**
	 * A new job: it folds the oldest finished day if there is one, else it is a rollup.
	 *
	 * @return array
	 * @throws \RuntimeException On a DB error.
	 */
	private static function new_job() {
		return array(
			'id'         => md5( uniqid( '', true ) . wp_rand() ),
			'started'    => time(),
			'phase'      => 'init',
			'cursor'     => 0,
			'max_id'     => 0,
			'sets_total' => 0,
			'sets_done'  => 0,
			'state'      => '',
			'state_rows' => 0,
			'state_gz'   => false,
			'files'      => 0,
			'meta'       => array(),
			'cleaned'    => false,
			'restarts'   => 0,
			// A finished day still in the hit rows: this job folds it into qpucg_usage_days (DECISIONS D63).
			'day'        => self::fold_day(),
		);
	}

	/**
	 * Compare-and-swap of the job row, only while the lock is ours.
	 *
	 * @param string $old_raw Row value the step started from.
	 * @param array  $job     New job.
	 * @return string|false New raw row, or false if the lock was lost or another step changed the row first.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function cas( $old_raw, array $job ) {
		global $wpdb;

		if ( ! self::hold() ) {
			return false;
		}
		$new_raw = (string) wp_json_encode( $job );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new_raw, self::JOB, $old_raw ) );
		self::check_db( 'job' );
		return 1 === (int) $changed ? $new_raw : false;
	}

	/**
	 * Runs queries in one transaction; rolls back when the callback returns false or throws.
	 *
	 * @param callable $callback Returns the result, false to roll back.
	 * @return mixed Callback result.
	 * @throws \Throwable Re-thrown after the rollback.
	 */
	private static function transaction( $callback ) {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		self::check_db( 'transaction' );
		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		if ( false === $result ) {
			$wpdb->query( 'ROLLBACK' );
		} else {
			$wpdb->query( 'COMMIT' );
		}
		self::check_db( 'commit' );
		return $result;
	}

	/**
	 * Init: empty the staging tables and fix the set of filesets this job reads (ids up to the current maximum).
	 *
	 * @param string $raw Raw job row.
	 * @param array  $job Job.
	 * @return string|false
	 * @throws \RuntimeException On a DB error.
	 */
	private static function phase_init( $raw, array $job ) {
		global $wpdb;

		if ( ! self::hold() ) {
			return false;
		}
		foreach ( array( 'usage_next', 'rollup_state' ) as $name ) {
			$table = Install::table( $name );
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- clearing staging tables before rollup.
			self::check_db( $name );
		}
		$sets_table = Install::table( 'filesets' );
		$hits_table = Install::table( 'fileset_hits' );
		$day        = self::job_day( $job );
		if ( '' === $day ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS sets, COALESCE(MAX(id), 0) AS max_id FROM %i', $sets_table ), ARRAY_A );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS sets, COALESCE(MAX(id), 0) AS max_id FROM %i WHERE set_hash IN (SELECT set_hash FROM %i WHERE day = %s)', $sets_table, $hits_table, $day ), ARRAY_A );
		}
		self::check_db( 'filesets' );

		$job['phase']      = 'sets';
		$job['cursor']     = 0;
		$job['max_id']     = (int) $row['max_id'];
		$job['sets_total'] = (int) $row['sets'];
		$job['sets_done']  = 0;
		$job['state']      = '';
		$job['state_rows'] = 0;
		return self::cas( $raw, $job );
	}

	/**
	 * Sets (§7.2 step 2): stream filesets after the cursor; per page, sum their hits in SQL and add them to
	 * each set's files. The totals are saved with the cursor at the end of the step.
	 *
	 * @param string $raw      Raw job row.
	 * @param array  $job      Job.
	 * @param float  $deadline microtime() at which the step stops.
	 * @return string|false
	 * @throws \RuntimeException On a DB error.
	 */
	private static function phase_sets( $raw, array $job, $deadline ) {
		global $wpdb;

		$state = self::load_state( $job );
		if ( false === $state ) {
			return self::restart( $raw, $job );
		}
		$hits_table = Install::table( 'fileset_hits' );
		$sets_table = Install::table( 'filesets' );

		$done = false;
		$day  = self::job_day( $job );
		do {
			// A fold reads only the sets with hit rows on its day (DECISIONS D63).
			if ( '' === $day ) {
				$page = $wpdb->get_results( $wpdb->prepare( 'SELECT id, set_hash, files, is_compressed, format FROM %i WHERE id > %d AND id <= %d ORDER BY id LIMIT %d', $sets_table, $job['cursor'], $job['max_id'], self::PAGE_SIZE ), ARRAY_A );
			} else {
				$page = $wpdb->get_results( $wpdb->prepare( 'SELECT id, set_hash, files, is_compressed, format FROM %i WHERE id > %d AND id <= %d AND set_hash IN (SELECT set_hash FROM %i WHERE day = %s) ORDER BY id LIMIT %d', $sets_table, $job['cursor'], $job['max_id'], $hits_table, $day, self::PAGE_SIZE ), ARRAY_A );
			}
			self::check_db( 'filesets' );
			$page   = (array) $page;
			$by_set = self::hits_for( $hits_table, wp_list_pluck( $page, 'set_hash' ), self::job_day( $job ) );
			$done   = count( $page ) < self::PAGE_SIZE;
			if ( ! self::hold() ) {
				return false;
			}
			self::load_dictionary();

			foreach ( $page as $i => $set ) {
				$job['cursor'] = (int) $set['id'];
				++$job['sets_done'];
				++$state['filesets'];
				// A list the hourly step hasn't reached yet (its cron may not be running) is converted here.
				$runs = Recorder::FORMAT_IDS === (int) $set['format'] ? self::stored_runs( $set['files'], (int) $set['is_compressed'] ) : self::convert( $set );
				if ( isset( $by_set[ $set['set_hash'] ] ) && ! empty( $runs ) ) {
					self::add_set( $state, $runs, $by_set[ $set['set_hash'] ] );
				}
				unset( $page[ $i ] );
				if ( microtime( true ) >= $deadline ) {
					$done = $done && empty( $page );
					break 2;
				}
			}
		} while ( ! $done );

		if ( $done && '' !== self::job_day( $job ) ) {
			$job['phase'] = 'fold';
			$job['meta']  = self::totals( $state, self::job_day( $job ) );
		} elseif ( $done ) {
			// The folded days count as if their sets were still here (DECISIONS D63).
			self::add_days( $state );
			$job['phase']  = 'write';
			$job['cursor'] = 0;
			$job['files']  = count( $state['hits'] );
			$job['meta']   = self::totals( $state );
		}
		return self::commit_state( $raw, $job, $state );
	}

	/**
	 * Adds one set's hits to each of its files.
	 *
	 * @param array $state    Totals (see empty_state()).
	 * @param int[] $runs     Runs of file ids: start, length - 1, ...
	 * @param array $set_hits List of array( context, hits, first_day, last_day ).
	 */
	private static function add_set( array &$state, array $runs, array $set_hits ) {
		// The set's hits per context bit, total, OR of the bits, first and last day (days as Ymd integers).
		$total = 0;
		$mask  = 0;
		$bits  = array();
		$first = 0;
		$last  = 0;
		foreach ( $set_hits as $hit ) {
			list( $context, $hits, $day_first, $day_last ) = $hit;

			$total += $hits;
			$mask  |= $context;
			// Shares are computed over requests with a file list only (DECISIONS D55).
			$primary = $context & Recorder::PRIMARY_MASK;

			$state['requests'][ $primary ] = ( isset( $state['requests'][ $primary ] ) ? $state['requests'][ $primary ] : 0 ) + $hits;
			for ( $bit = 1; $bit <= $context; $bit <<= 1 ) {
				if ( $context & $bit ) {
					$bits[ $bit ] = ( isset( $bits[ $bit ] ) ? $bits[ $bit ] : 0 ) + $hits;
				}
			}
			if ( 0 === $first || $day_first < $first ) {
				$first = $day_first;
			}
			if ( $day_last > $last ) {
				$last = $day_last;
			}
		}
		$state['mapped'] += $total;

		$hits_of    = &$state['hits'];
		$contexts   = &$state['contexts'];
		$firsts     = &$state['first'];
		$lasts      = &$state['last'];
		$by         = &$state['by'];
		$components = self::$file_components;
		$count      = count( $runs );

		$set_components = array();
		for ( $r = 0; $r < $count; $r += 2 ) {
			$end = $runs[ $r ] + $runs[ $r + 1 ];
			for ( $id = $runs[ $r ]; $id <= $end; $id++ ) {
				if ( isset( $hits_of[ $id ] ) ) {
					$hits_of[ $id ]  += $total;
					$contexts[ $id ] |= $mask;
					if ( $first < $firsts[ $id ] ) {
						$firsts[ $id ] = $first;
					}
					if ( $last > $lasts[ $id ] ) {
						$lasts[ $id ] = $last;
					}
				} else {
					$hits_of[ $id ]  = $total;
					$contexts[ $id ] = $mask;
					$firsts[ $id ]   = $first;
					$lasts[ $id ]    = $last;
				}
				foreach ( $bits as $bit => $hits ) {
					$by[ $bit ][ $id ] = ( isset( $by[ $bit ][ $id ] ) ? $by[ $bit ][ $id ] : 0 ) + $hits;
				}
				if ( isset( $components[ $id ] ) ) {
					$set_components[ $components[ $id ] ] = true;
				}
			}
		}

		foreach ( array_keys( $set_components ) as $component ) {
			$state['component_hits'][ $component ] = ( isset( $state['component_hits'][ $component ] ) ? $state['component_hits'][ $component ] : 0 ) + $total;
		}
	}

	/**
	 * Request totals and overhead (§7.2 steps 1 and 4), read once all sets are added, and the meta
	 * without its time. Counters only grow while the job runs, so a file's share never exceeds 1.
	 *
	 * @param array  $state Totals.
	 * @param string $day   Only this day (Y-m-d, a fold), or '' for all.
	 * @return array Meta.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function totals( array $state, $day = '' ) {
		global $wpdb;

		$hits_table = Install::table( 'fileset_hits' );

		// Every sampled request, with or without a file list; the shares use $state['requests'] (DECISIONS D55).
		if ( '' === $day ) {
			$sampled = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(hits), 0) FROM %i', $hits_table ) );
		} else {
			$sampled = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(hits), 0) FROM %i WHERE day = %s', $hits_table, $day ) );
		}
		self::check_db( 'fileset_hits' );
		$requests = $state['requests'];

		if ( '' === $day ) {
			$overhead = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(hits), 0) AS hits, COALESCE(SUM(prep_ms_total), 0) AS prep_ms_total, COALESCE(MAX(prep_ms_max), 0) AS prep_ms_max, COALESCE(SUM(slow_hits), 0) AS slow_hits, COALESCE(SUM(req_hits), 0) AS req_hits, COALESCE(SUM(req_ms_total), 0) AS req_ms_total, COALESCE(SUM(ovh_0), 0) AS ovh_0, COALESCE(SUM(ovh_1), 0) AS ovh_1, COALESCE(SUM(ovh_2), 0) AS ovh_2, COALESCE(SUM(ovh_3), 0) AS ovh_3, COALESCE(SUM(ovh_4), 0) AS ovh_4, COALESCE(SUM(ovh_5), 0) AS ovh_5, COALESCE(SUM(ovh_6), 0) AS ovh_6, COUNT(DISTINCT day) AS days, MIN(day) AS first_day, MAX(day) AS last_day FROM %i', $hits_table ), ARRAY_A );
		} else {
			$overhead = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(hits), 0) AS hits, COALESCE(SUM(prep_ms_total), 0) AS prep_ms_total, COALESCE(MAX(prep_ms_max), 0) AS prep_ms_max, COALESCE(SUM(slow_hits), 0) AS slow_hits, COALESCE(SUM(req_hits), 0) AS req_hits, COALESCE(SUM(req_ms_total), 0) AS req_ms_total, COALESCE(SUM(ovh_0), 0) AS ovh_0, COALESCE(SUM(ovh_1), 0) AS ovh_1, COALESCE(SUM(ovh_2), 0) AS ovh_2, COALESCE(SUM(ovh_3), 0) AS ovh_3, COALESCE(SUM(ovh_4), 0) AS ovh_4, COALESCE(SUM(ovh_5), 0) AS ovh_5, COALESCE(SUM(ovh_6), 0) AS ovh_6, COUNT(DISTINCT day) AS days, MIN(day) AS first_day, MAX(day) AS last_day FROM %i WHERE day = %s', $hits_table, $day ), ARRAY_A );
		}
		self::check_db( 'fileset_hits' );
		$overhead = is_array( $overhead ) ? $overhead : array();
		$by_day   = self::lost_by_day( $day );
		if ( isset( $state['extra'] ) ) {
			$sampled += self::add_extra( $overhead, $state['extra'], $by_day );
		}
		$lost = 0;
		foreach ( $by_day as $counts ) {
			$lost += $counts[1];
		}
		$hist    = array();
		$buckets = count( Recorder::OVERHEAD_EDGES );
		for ( $i = 0; $i <= $buckets; $i++ ) {
			$hist[] = isset( $overhead[ 'ovh_' . $i ] ) ? (int) $overhead[ 'ovh_' . $i ] : 0;
		}

		$components = $state['component_hits'];
		ksort( $requests );
		arsort( $components );

		return array(
			'time'             => 0,
			'requests'         => $requests,
			'sampled_requests' => $sampled,
			// Counted directly, never as "sampled minus mapped": hits that arrive while a job runs, or sets stored after it
			// started, are not losses (DECISIONS D66).
			'unmapped_hits'    => $lost,
			'recent'           => self::recent( $by_day ),
			'days_observed'    => isset( $overhead['days'] ) ? (int) $overhead['days'] : 0,
			'first_day'        => isset( $overhead['first_day'] ) ? (string) $overhead['first_day'] : '',
			'last_day'         => isset( $overhead['last_day'] ) ? (string) $overhead['last_day'] : '',
			'filesets'         => $state['filesets'],
			'files'            => count( $state['hits'] ),
			'components'       => $components,
			'overhead'         => array(
				'hits'          => isset( $overhead['hits'] ) ? (int) $overhead['hits'] : 0,
				'prep_ms_total' => isset( $overhead['prep_ms_total'] ) ? (float) $overhead['prep_ms_total'] : 0.0,
				'prep_ms_max'   => isset( $overhead['prep_ms_max'] ) ? (float) $overhead['prep_ms_max'] : 0.0,
				'slow_hits'     => isset( $overhead['slow_hits'] ) ? (int) $overhead['slow_hits'] : 0,
				// Relative overhead (DECISIONS D38): hits with a known request time, their summed request ms,
				// and the prep / request-time histogram (buckets per Recorder::OVERHEAD_EDGES).
				'req_hits'      => isset( $overhead['req_hits'] ) ? (int) $overhead['req_hits'] : 0,
				'req_ms_total'  => isset( $overhead['req_ms_total'] ) ? (float) $overhead['req_ms_total'] : 0.0,
				'ovh_hist'      => $hist,
			),
		);
	}

	/**
	 * Write (§7.2 steps 3 + 4): per-file rows with max_share into `qpucg_usage_next`, tagged with the job id, in
	 * chunks of 200. REPLACE, so rows rewritten after a dead step are the same rows.
	 *
	 * @param string $raw      Raw job row.
	 * @param array  $job      Job.
	 * @param float  $deadline microtime() at which the step stops.
	 * @return string|false
	 * @throws \RuntimeException On a DB error.
	 */
	private static function phase_write( $raw, array $job, $deadline ) {
		global $wpdb;

		$state = self::load_state( $job );
		if ( false === $state ) {
			return self::restart( $raw, $job );
		}
		$table = Install::table( 'usage_next' );
		$ids   = array_map( 'intval', array_keys( $state['hits'] ) );
		sort( $ids, SORT_NUMERIC );
		$count = count( $ids );

		$job['files']         = $count;
		$job['meta']['files'] = $count;

		$requests = $job['meta']['requests'];
		$bits     = array_keys( $state['by'] );
		sort( $bits );

		while ( $job['cursor'] < $count ) {
			$end        = min( $count, $job['cursor'] + self::INSERT_CHUNK );
			$files      = self::file_rows( array_slice( $ids, $job['cursor'], $end - $job['cursor'] ) );
			$batch_rows = array();
			for ( $i = $job['cursor']; $i < $end; $i++ ) {
				$id = $ids[ $i ];
				if ( ! isset( $files[ $id ] ) ) {
					// A dictionary row is never deleted during a job; the swap's row count catches it if it happens.
					continue;
				}
				$by = array();
				foreach ( $bits as $bit ) {
					if ( isset( $state['by'][ $bit ][ $id ] ) ) {
						$by[ $bit ] = $state['by'][ $bit ][ $id ];
					}
				}
				$max_share = 0.0;
				foreach ( $requests as $primary => $total ) {
					if ( $total >= self::MIN_REQUESTS && isset( $by[ $primary ] ) ) {
						$max_share = max( $max_share, $by[ $primary ] / $total );
					}
				}

				$batch_rows[] = array(
					$job['id'],
					$files[ $id ]['path_hash'],
					$files[ $id ]['path_key'],
					$files[ $id ]['component'],
					$state['contexts'][ $id ],
					$state['hits'][ $id ],
					wp_json_encode( (object) $by ),
					$max_share,
					self::day( $state['first'][ $id ] ),
					self::day( $state['last'][ $id ] ),
				);
			}
			if ( ! self::hold() ) {
				return false;
			}
			if ( ! empty( $batch_rows ) ) {
				$wpdb->query( 'START TRANSACTION' );
				try {
					foreach ( $batch_rows as $r ) {
						$wpdb->query(
							$wpdb->prepare(
								'REPLACE INTO %i (job, path_hash, path_key, component, contexts, hits_total, hits_by_context, max_share, first_seen, last_seen) VALUES (%s, %s, %s, %s, %d, %d, %s, %f, %s, %s)',
								$table,
								$r[0],
								$r[1],
								$r[2],
								$r[3],
								$r[4],
								$r[5],
								$r[6],
								$r[7],
								$r[8],
								$r[9]
							)
						);
						self::check_db( 'usage_next' );
					}
					$wpdb->query( 'COMMIT' );
				} catch ( \Throwable $e ) {
					$wpdb->query( 'ROLLBACK' );
					throw $e;
				}
			}

			$job['cursor'] = $end;
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( $job['cursor'] >= $count ) {
			$job['phase'] = 'swap';
		}
		return self::cas( $raw, $job );
	}

	/**
	 * Dictionary rows by id.
	 *
	 * @param int[] $ids File ids (at most INSERT_CHUNK).
	 * @return array id => row (path_hash, path_key, component).
	 * @throws \RuntimeException On a DB error.
	 */
	private static function file_rows( array $ids ) {
		global $wpdb;

		$out = array();
		if ( empty( $ids ) ) {
			return $out;
		}
		$table = Install::table( 'files' );
		foreach ( array_chunk( array_values( array_map( 'intval', $ids ) ), 20 ) as $chunk ) {
			$padded = array_pad( $chunk, 20, end( $chunk ) );
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, path_hash, path_key, component FROM %i WHERE id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
					$table,
					$padded[0],
					$padded[1],
					$padded[2],
					$padded[3],
					$padded[4],
					$padded[5],
					$padded[6],
					$padded[7],
					$padded[8],
					$padded[9],
					$padded[10],
					$padded[11],
					$padded[12],
					$padded[13],
					$padded[14],
					$padded[15],
					$padded[16],
					$padded[17],
					$padded[18],
					$padded[19]
				),
				ARRAY_A
			);
			self::check_db( 'files' );
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['id'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Swap: replaces the rows of `qpucg_usage` with this job's staged rows, stores the meta and moves the job on,
	 * in one transaction, so readers see either the old rows and meta or all new ones (InnoDB; see DECISIONS D48
	 * for MyISAM).
	 *
	 * @param string $raw Raw job row.
	 * @param array  $job Job.
	 * @return string|false
	 * @throws \RuntimeException On a DB error.
	 */
	private static function phase_swap( $raw, array $job ) {
		global $wpdb;

		$next  = Install::table( 'usage_next' );
		$usage = Install::table( 'usage' );
		$rows  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE job = %s', $next, $job['id'] ) );
		self::check_db( 'usage_next' );
		if ( $rows !== (int) $job['files'] ) {
			// Not all rows of this job are staged (should not happen): write them again, at most MAX_RESTARTS times.
			$why = sprintf( 'staged %d rows, expected %d', $rows, (int) $job['files'] );
			$job = self::count_restart( $raw, $job, $why );
			if ( false === $job ) {
				return false;
			}
			Recorder::set_error( 'rollup: ' . $why . '; the rows are written again' );
			$job['phase']  = 'write';
			$job['cursor'] = 0;
			return self::cas( $raw, $job );
		}
		if ( ! self::hold() ) {
			return false;
		}

		$job['meta']['time'] = time();
		$job['phase']        = 'purge';
		$job['cursor']       = 0;

		$new_raw = self::transaction(
			function () use ( $wpdb, $raw, $job, $next, $usage, $rows ) {
				$t = microtime( true );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $usage ) );
				self::check_db( 'usage' );
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (path_hash, path_key, component, contexts, hits_total, hits_by_context, max_share, first_seen, last_seen) SELECT path_hash, path_key, component, contexts, hits_total, hits_by_context, max_share, first_seen, last_seen FROM %i WHERE job = %s ORDER BY id',
						$usage,
						$next,
						$job['id']
					)
				);
				self::check_db( 'usage' );
				// Shown on the Usage tab (DECISIONS D51).
				$job['meta']['swap_seconds'] = round( microtime( true ) - $t, 3 );
				$job['meta']['swap_rows']    = $rows;

				$meta    = (string) wp_json_encode( $job['meta'] );
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $meta, self::META ) );
				self::check_db( 'meta' );
				if ( 1 !== (int) $updated ) {
					$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::META, $meta ) );
					self::check_db( 'meta' );
				}
				return self::cas( $raw, $job );
			}
		);
		if ( false !== $new_raw ) {
			// The meta before T7.3 (transient of the same name) is replaced by the option now.
			delete_transient( self::META );
			delete_option( self::STOPPED );
		}
		return $new_raw;
	}

	/**
	 * Purge (after the swap): empties the staging tables, then deletes hit rows older than the retention period in
	 * chunks of 100 rows and leftover benchmark filesets. Ends the job.
	 *
	 * @param string $raw      Raw job row.
	 * @param array  $job      Job.
	 * @param float  $deadline microtime() at which the step stops.
	 * @return string|false|null Null when the job is finished.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function phase_purge( $raw, array $job, $deadline ) {
		global $wpdb;

		if ( ! self::hold() ) {
			return false;
		}
		if ( empty( $job['cleaned'] ) ) {
			foreach ( array( 'usage_next', 'rollup_state' ) as $name ) {
				$table = Install::table( $name );
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- clearing staging tables after swap.
				self::check_db( $name );
			}
			$job['cleaned']    = true;
			$job['state']      = '';
			$job['state_rows'] = 0;
		}

		$table  = Install::table( 'fileset_hits' );
		$cutoff = wp_date( 'Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		// Small chunks: one DELETE can't be split by the budget, and a row costs ~5 ms on a slow disk (XAMPP, D48).
		do {
			$count = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s LIMIT %d', $table, $cutoff, self::PURGE_CHUNK ) );
			if ( false === $count ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored in the recorder flags, escaped when displayed.
				throw new \RuntimeException( 'purge: ' . $wpdb->last_error );
			}
			$job['cursor'] += (int) $count;
			$finished       = self::PURGE_CHUNK !== (int) $count;
		} while ( ! $finished && microtime( true ) < $deadline && self::hold() );

		if ( ! $finished ) {
			return self::cas( $raw, $job );
		}
		if ( ! self::purge_orphans( $job, $deadline ) ) {
			return self::cas( $raw, $job );
		}
		$days = Install::table( 'usage_days' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', $days, $cutoff ) );
		self::check_db( 'usage_days' );
		Bench::delete_stale();
		if ( ! self::hold() ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::JOB, $raw ) );
		self::check_db( 'job' );
		return null;
	}

	/**
	 * Deletes filesets no hit row refers to any more, in chunks of 100, so storage follows the retention period
	 * (DECISIONS D55). Only sets older than an hour: the recorder writes the hit row before the set, so an hour only
	 * guards a request still in flight. A day (the old value) kept a fold's sets for a further day (D63).
	 *
	 * @param array $job      Job (`orphan_id` = cursor).
	 * @param float $deadline  microtime() at which the step stops.
	 * @param int   $page_size Sets read per page.
	 * @return bool Whether the purge is finished.
	 * @throws \RuntimeException On a DB error.
	 */
	public static function purge_orphans( array &$job, $deadline, $page_size = self::ORPHAN_PAGE ) {
		global $wpdb;

		$sets   = Install::table( 'filesets' );
		$hits   = Install::table( 'fileset_hits' );
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$cursor = isset( $job['orphan_id'] ) ? (int) $job['orphan_id'] : 0;
		// One page at a time: a single join over all sets can't be split by the budget (74 s on the large test dataset).
		do {
			$page = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, set_hash, created FROM %i WHERE id > %d ORDER BY id LIMIT %d', $sets, $cursor, $page_size ), ARRAY_A );
			self::check_db( 'filesets' );
			$orphans = array();
			if ( ! empty( $page ) ) {
				$used = array();
				foreach ( array_chunk( array_values( wp_list_pluck( $page, 'set_hash' ) ), 20 ) as $hash_chunk ) {
					$padded = array_pad( $hash_chunk, 20, end( $hash_chunk ) );
					$part   = (array) $wpdb->get_col(
						$wpdb->prepare(
							'SELECT DISTINCT set_hash FROM %i WHERE set_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
							$hits,
							$padded[0],
							$padded[1],
							$padded[2],
							$padded[3],
							$padded[4],
							$padded[5],
							$padded[6],
							$padded[7],
							$padded[8],
							$padded[9],
							$padded[10],
							$padded[11],
							$padded[12],
							$padded[13],
							$padded[14],
							$padded[15],
							$padded[16],
							$padded[17],
							$padded[18],
							$padded[19]
						)
					);
					self::check_db( 'fileset_hits' );
					foreach ( $part as $used_hash ) {
						$used[ $used_hash ] = true;
					}
				}
				foreach ( $page as $set ) {
					if ( ! isset( $used[ $set['set_hash'] ] ) && $set['created'] < $cutoff ) {
						$orphans[] = (int) $set['id'];
					}
				}
				$cursor = (int) $set['id'];
			}
			foreach ( array_chunk( $orphans, 20 ) as $ids ) {
				if ( ! self::hold() ) {
					return false;
				}
				$padded = array_pad( $ids, 20, end( $ids ) );
				$wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
						$sets,
						$padded[0],
						$padded[1],
						$padded[2],
						$padded[3],
						$padded[4],
						$padded[5],
						$padded[6],
						$padded[7],
						$padded[8],
						$padded[9],
						$padded[10],
						$padded[11],
						$padded[12],
						$padded[13],
						$padded[14],
						$padded[15],
						$padded[16],
						$padded[17],
						$padded[18],
						$padded[19]
					)
				);
				self::check_db( 'filesets' );
			}
			$job['orphan_id'] = $cursor;
			$finished         = count( $page ) < $page_size;
		} while ( ! $finished && microtime( true ) < $deadline );
		return $finished;
	}

	/**
	 * Starts the job over (state rows missing or unreadable).
	 *
	 * @param string $raw Raw job row.
	 * @param array  $job Job.
	 * @return string|false
	 */
	private static function restart( $raw, array $job ) {
		$job = self::count_restart( $raw, $job, 'saved totals could not be read' );
		if ( false === $job ) {
			return false;
		}
		Recorder::set_error( 'rollup: saved totals could not be read; the rollup starts over' );
		$job['phase']      = 'init';
		$job['sets_done']  = 0;
		$job['state']      = '';
		$job['state_rows'] = 0;
		return self::cas( $raw, $job );
	}

	/**
	 * Counts a restart or a swap mismatch. After MAX_RESTARTS the failure is taken as permanent: the job row is
	 * deleted, the stop time stored (no overdue restart for a day; the daily cron, the button and WP-CLI still start
	 * one) and the step ends with an error, so cron stops re-queuing it (DECISIONS D51).
	 *
	 * @param string $raw Raw job row.
	 * @param array  $job Job.
	 * @param string $why What went wrong.
	 * @return array|false Job with the count raised, or false if the lock was lost.
	 * @throws \RuntimeException When the job is stopped, or on a DB error.
	 */
	private static function count_restart( $raw, array $job, $why ) {
		global $wpdb;

		$restarts = isset( $job['restarts'] ) ? (int) $job['restarts'] : 0;
		if ( $restarts < self::MAX_RESTARTS ) {
			$job['restarts'] = $restarts + 1;
			return $job;
		}
		if ( ! self::hold() ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::JOB, $raw ) );
		self::check_db( 'job' );
		update_option( self::STOPPED, time(), false );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored in the recorder flags, escaped when displayed.
		throw new \RuntimeException( sprintf( 'stopped after %d restarts (%s); it starts over with the next daily run', $restarts, $why ) );
	}

	/**
	 * Empty per-file totals, indexed by dictionary id.
	 *
	 * @return array
	 */
	private static function empty_state() {
		return array(
			'hits'           => array(),
			'contexts'       => array(),
			'first'          => array(), // Ymd integer.
			'last'           => array(),
			'by'             => array(), // context bit => array( id => hits ).
			'requests'       => array(), // primary context => requests with a file list.
			'component_hits' => array(), // component => requests that loaded any of its files.
			'filesets'       => 0,
			'mapped'         => 0,
		);
	}

	/**
	 * Loads the saved totals of the job (JSON, gzcompressed when zlib is there, in chunks).
	 *
	 * @param array $job Job.
	 * @return array|false False if the rows are missing or unreadable.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function load_state( array $job ) {
		global $wpdb;

		if ( '' === $job['state'] ) {
			return self::empty_state();
		}
		$table  = Install::table( 'rollup_state' );
		$chunks = $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM %i WHERE token = %s ORDER BY seq', $table, $job['state'] ) );
		self::check_db( 'rollup_state' );
		if ( count( $chunks ) !== (int) $job['state_rows'] ) {
			return false;
		}
		$data = implode( '', $chunks );
		unset( $chunks );
		if ( ! empty( $job['state_gz'] ) ) {
			$data = self::decompress( $data );
		}
		$state = false === $data ? null : json_decode( $data, true );
		if ( ! is_array( $state ) || ! isset( $state['hits'] ) ) {
			return false;
		}
		// Empty maps come back as JSON lists; both are arrays, so nothing else to fix.
		return $state;
	}

	/**
	 * Saves the totals under a new token and moves the job on, in one transaction. The old rows are deleted after
	 * the commit.
	 *
	 * @param string $raw   Raw job row.
	 * @param array  $job   New job (cursor already moved).
	 * @param array  $state Totals.
	 * @return string|false
	 * @throws \RuntimeException On a DB error.
	 */
	private static function commit_state( $raw, array $job, array $state ) {
		global $wpdb;

		$table     = Install::table( 'rollup_state' );
		$old_token = $job['state'];
		$data      = (string) wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		unset( $state );
		$gz = function_exists( 'gzcompress' );
		if ( $gz ) {
			$data = gzcompress( $data, 1 );
		}
		// Chunks keep each query far below a 1 MB max_allowed_packet (hex doubles the size).
		$chunks = str_split( $data, self::STATE_CHUNK );
		unset( $data );

		$job['state']      = md5( uniqid( '', true ) . wp_rand() );
		$job['state_rows'] = count( $chunks );
		$job['state_gz']   = $gz;

		if ( ! self::hold() ) {
			return false;
		}
		$new_raw = self::transaction(
			function () use ( $wpdb, $table, $raw, $job, $chunks ) {
				foreach ( $chunks as $seq => $chunk ) {
					$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (token, seq, data) VALUES (%s, %d, UNHEX(%s))', $table, $job['state'], $seq, bin2hex( $chunk ) ) );
					self::check_db( 'rollup_state' );
				}
				return self::cas( $raw, $job );
			}
		);
		if ( false !== $new_raw && '' !== $old_token ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE token = %s', $table, $old_token ) );
		}
		return $new_raw;
	}

	/**
	 * Deletes all usage data and restarts the observation period. Also ends a running rollup job (a step still
	 * running can't commit: its job row is gone).
	 */
	public static function reset() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", self::JOB, self::META ) );
		foreach ( array( 'filesets', 'fileset_hits', 'usage', 'usage_next', 'rollup_state', 'files', 'usage_days' ) as $name ) {
			$table = Install::table( $name );
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- emptying our own table on user reset.
		}
		delete_transient( self::META );
		delete_option( Recorder::FLAGS );
		delete_option( self::STOPPED );
		wp_clear_scheduled_hook( self::STEP_HOOK );
		// Empty observe_since is set to today again if usage is on. A reset also ends a recorder pause.
		Settings::update(
			array(
				'observe_since'         => '',
				'recorder_paused_until' => 0,
			)
		);
	}

	/**
	 * Hits per context for a page of sets, summed over all days in SQL.
	 *
	 * @param string   $hits_table Hits table.
	 * @param string[] $hashes     Set hashes (at most PAGE_SIZE).
	 * @param string   $day        Only this day (Y-m-d), or '' for all.
	 * @return array set_hash => list of array( context, hits, first_day, last_day ), days as Ymd integers.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function hits_for( $hits_table, array $hashes, $day = '' ) {
		global $wpdb;

		$out = array();
		if ( empty( $hashes ) ) {
			return $out;
		}
		foreach ( array_chunk( array_values( $hashes ), 20 ) as $chunk ) {
			$padded = array_pad( $chunk, 20, end( $chunk ) );
			if ( '' === $day ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT set_hash, context, SUM(hits) AS hits, MIN(day) AS first_day, MAX(day) AS last_day FROM %i WHERE set_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) GROUP BY set_hash, context',
						$hits_table,
						$padded[0],
						$padded[1],
						$padded[2],
						$padded[3],
						$padded[4],
						$padded[5],
						$padded[6],
						$padded[7],
						$padded[8],
						$padded[9],
						$padded[10],
						$padded[11],
						$padded[12],
						$padded[13],
						$padded[14],
						$padded[15],
						$padded[16],
						$padded[17],
						$padded[18],
						$padded[19]
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT set_hash, context, SUM(hits) AS hits, MIN(day) AS first_day, MAX(day) AS last_day FROM %i WHERE set_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) AND day = %s GROUP BY set_hash, context',
						$hits_table,
						$padded[0],
						$padded[1],
						$padded[2],
						$padded[3],
						$padded[4],
						$padded[5],
						$padded[6],
						$padded[7],
						$padded[8],
						$padded[9],
						$padded[10],
						$padded[11],
						$padded[12],
						$padded[13],
						$padded[14],
						$padded[15],
						$padded[16],
						$padded[17],
						$padded[18],
						$padded[19],
						$day
					),
					ARRAY_A
				);
			}
			self::check_db( 'fileset_hits' );
			foreach ( (array) $rows as $row ) {
				$out[ $row['set_hash'] ][] = array( (int) $row['context'], (int) $row['hits'], (int) str_replace( '-', '', $row['first_day'] ), (int) str_replace( '-', '', $row['last_day'] ) );
			}
		}
		return $out;
	}

	/**
	 * The day a job folds, or '' for a normal rollup.
	 *
	 * @param array $job Job.
	 * @return string Y-m-d or ''.
	 */
	private static function job_day( array $job ) {
		return isset( $job['day'] ) ? (string) $job['day'] : '';
	}

	/**
	 * Whether a finished day still waits for its fold (the storage cap lets the fold shrink it instead of deleting days).
	 *
	 * @return bool
	 * @throws \RuntimeException On a DB error.
	 */
	public static function fold_pending() {
		return '' !== self::fold_day();
	}

	/**
	 * The oldest finished day (before today, within the retention period) that still has hit rows, or ''. The storage cap
	 * never deletes this day or a later one: a day is folded before its hit rows go (DECISIONS D66).
	 *
	 * @return string
	 * @throws \RuntimeException On a DB error.
	 */
	public static function fold_day() {
		global $wpdb;

		$table  = Install::table( 'fileset_hits' );
		$days   = Install::table( 'usage_days' );
		$cutoff = wp_date( 'Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		// A day already folded is never folded again: hit rows written after its fold (a request across midnight) stay as hit rows.
		$day = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i WHERE day < %s AND day >= %s AND day NOT IN (SELECT day FROM %i)', $table, current_time( 'Y-m-d' ), $cutoff, $days ) );
		self::check_db( 'fileset_hits' );
		return null === $day ? '' : (string) $day;
	}

	/**
	 * Fold (DECISIONS D63): stores the finished day's per-file totals in `qpucg_usage_days` and deletes its hit rows, in
	 * one transaction, then moves the job row on to the next job (another day, or the rollup itself). The day's sets go
	 * with the rollup's orphan purge. A step that dies before the commit leaves everything as it was.
	 *
	 * @param string $raw Raw job row.
	 * @param array  $job Job.
	 * @return string|false
	 * @throws \RuntimeException On a DB error.
	 */
	private static function phase_fold( $raw, array $job ) {
		global $wpdb;

		$state = self::load_state( $job );
		if ( false === $state ) {
			return self::restart( $raw, $job );
		}
		$day    = self::job_day( $job );
		$chunks = str_split( self::encode_day( $state, $job['meta'] ), self::STATE_CHUNK );
		$days   = Install::table( 'usage_days' );
		$hits   = Install::table( 'fileset_hits' );
		$state  = null;
		if ( ! self::hold() ) {
			return false;
		}
		$next = self::new_job();
		// The day being folded still has hit rows, so new_job() would pick it again: the next job starts after it.
		if ( $next['day'] === $day ) {
			$next['day'] = self::fold_day_after( $day );
		}
		$new = self::transaction(
			function () use ( $wpdb, $raw, $day, $chunks, $days, $hits, $next ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day = %s', $days, $day ) );
				self::check_db( 'usage_days' );
				foreach ( $chunks as $seq => $chunk ) {
					$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, seq, data) VALUES (%s, %d, UNHEX(%s))', $days, $day, $seq, bin2hex( $chunk ) ) );
					self::check_db( 'usage_days' );
				}
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day = %s', $hits, $day ) );
				self::check_db( 'fileset_hits' );
				return self::cas( $raw, $next );
			}
		);
		if ( false !== $new && '' !== $job['state'] ) {
			$table = Install::table( 'rollup_state' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE token = %s', $table, $job['state'] ) );
		}
		return $new;
	}

	/**
	 * The next finished day after $day that still has hit rows, or ''.
	 *
	 * @param string $day Y-m-d.
	 * @return string
	 * @throws \RuntimeException On a DB error.
	 */
	private static function fold_day_after( $day ) {
		global $wpdb;

		$table = Install::table( 'fileset_hits' );
		$days  = Install::table( 'usage_days' );
		$next  = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i WHERE day > %s AND day < %s AND day NOT IN (SELECT day FROM %i)', $table, $day, current_time( 'Y-m-d' ), $days ) );
		self::check_db( 'fileset_hits' );
		return null === $next ? '' : (string) $next;
	}

	/**
	 * One day's totals in a compact form: a JSON header (meta, request and component counts, context bits) and, per
	 * context bit, one 32-bit counter per file id. The per-file hits, contexts and days follow from those counters.
	 *
	 * @param array $state Totals of the day.
	 * @param array $meta  totals() of the day.
	 * @return string
	 */
	private static function encode_day( array $state, array $meta ) {
		$max = empty( $state['hits'] ) ? 0 : max( array_keys( $state['hits'] ) );
		$by  = $state['by'];
		ksort( $by );
		$header = (string) wp_json_encode(
			array(
				'v'              => 1,
				'meta'           => $meta,
				'requests'       => (object) $state['requests'],
				'component_hits' => (object) $state['component_hits'],
				'mapped'         => $state['mapped'],
				'max'            => $max,
				'bits'           => array_keys( $by ),
			)
		);
		$body   = '';
		foreach ( $by as $list ) {
			$dense = array_fill( 0, $max + 1, 0 );
			foreach ( $list as $id => $hits ) {
				$dense[ (int) $id ] = min( 4294967295, (int) $hits );
			}
			$body .= pack( 'N*', ...$dense );
		}
		$data = pack( 'N', strlen( $header ) ) . $header . $body;
		return function_exists( 'gzcompress' ) ? 'z' . gzcompress( $data, 9 ) : 'r' . $data;
	}

	/**
	 * Adds the folded days of the retention period to the totals; their meta goes to $state['extra'] for totals().
	 *
	 * @param array $state Totals (changed).
	 * @throws \RuntimeException On a DB error, or when a stored day can't be read.
	 */
	private static function add_days( array &$state ) {
		global $wpdb;

		$table  = Install::table( 'usage_days' );
		$cutoff = wp_date( 'Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$days   = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT day FROM %i WHERE day >= %s ORDER BY day', $table, $cutoff ) );
		self::check_db( 'usage_days' );
		$extra = array(
			'days'  => array(),
			'metas' => array(),
		);
		foreach ( $days as $day ) {
			$data = implode( '', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM %i WHERE day = %s ORDER BY seq', $table, $day ) ) );
			self::check_db( 'usage_days' );
			if ( 'z' === substr( $data, 0, 1 ) ) {
				$data = self::decompress( substr( $data, 1 ) );
			} else {
				$data = substr( $data, 1 );
			}
			$length = false === $data || strlen( $data ) < 4 ? false : unpack( 'N', substr( $data, 0, 4 ) );
			$header = false === $length ? null : json_decode( substr( $data, 4, $length[1] ), true );
			if ( ! is_array( $header ) || ! isset( $header['bits'], $header['max'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored in the recorder flags, escaped when displayed.
				throw new \RuntimeException( 'usage_days: day ' . $day . ' can not be read' );
			}
			self::merge_day( $state, $header, substr( $data, 4 + $length[1] ), (int) str_replace( '-', '', $day ) );
			$extra['days'][]  = (string) $day;
			$extra['metas'][] = $header['meta'];
			unset( $data );
		}
		$state['extra'] = $extra;
	}

	/**
	 * Adds one decoded day to the totals, as add_set() would have for the day's sets.
	 *
	 * @param array  $state  Totals (changed).
	 * @param array  $header Day header.
	 * @param string $body   Counters.
	 * @param int    $ymd    The day as a Ymd integer.
	 */
	private static function merge_day( array &$state, array $header, $body, $ymd ) {
		$width = (int) $header['max'] + 1;
		foreach ( array_values( $header['bits'] ) as $i => $bit ) {
			$bit     = (int) $bit;
			$counts  = unpack( 'N*', substr( $body, $i * 4 * $width, 4 * $width ) );
			$primary = 0 !== ( $bit & Recorder::PRIMARY_MASK );
			foreach ( (array) $counts as $pos => $hits ) {
				if ( 0 === $hits ) {
					continue;
				}
				$id                         = $pos - 1;
				$state['by'][ $bit ][ $id ] = ( isset( $state['by'][ $bit ][ $id ] ) ? $state['by'][ $bit ][ $id ] : 0 ) + $hits;
				$state['contexts'][ $id ]   = ( isset( $state['contexts'][ $id ] ) ? $state['contexts'][ $id ] : 0 ) | $bit;
				if ( ! isset( $state['hits'][ $id ] ) ) {
					$state['hits'][ $id ]  = 0;
					$state['first'][ $id ] = $ymd;
					$state['last'][ $id ]  = $ymd;
				}
				// Every hit has exactly one primary context, so the primary counters add up to the file's hits.
				if ( $primary ) {
					$state['hits'][ $id ] += $hits;
				}
				$state['first'][ $id ] = min( $state['first'][ $id ], $ymd );
				$state['last'][ $id ]  = max( $state['last'][ $id ], $ymd );
			}
		}
		foreach ( (array) $header['requests'] as $primary => $count ) {
			$state['requests'][ $primary ] = ( isset( $state['requests'][ $primary ] ) ? $state['requests'][ $primary ] : 0 ) + (int) $count;
		}
		foreach ( (array) $header['component_hits'] as $component => $count ) {
			$state['component_hits'][ $component ] = ( isset( $state['component_hits'][ $component ] ) ? $state['component_hits'][ $component ] : 0 ) + (int) $count;
		}
		$state['mapped'] += (int) $header['mapped'];
	}

	/**
	 * Page views per day in the hit rows, and those whose file list is really missing: no stored set at all. A set that
	 * exists but lists no plugin or theme file is not a loss (DECISIONS D66).
	 *
	 * @param string $day Only this day (Y-m-d), or '' for all.
	 * @return array Y-m-d => array( page views, page views without a file list ).
	 * @throws \RuntimeException On a DB error.
	 */
	private static function lost_by_day( $day ) {
		global $wpdb;

		$hits = Install::table( 'fileset_hits' );
		$sets = Install::table( 'filesets' );
		if ( '' === $day ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT h.day AS day, SUM(h.hits) AS hits, SUM(CASE WHEN s.id IS NULL THEN h.hits ELSE 0 END) AS lost FROM %i h LEFT JOIN %i s ON s.set_hash = h.set_hash GROUP BY h.day', $hits, $sets ), ARRAY_A );
		} else {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT h.day AS day, SUM(h.hits) AS hits, SUM(CASE WHEN s.id IS NULL THEN h.hits ELSE 0 END) AS lost FROM %i h LEFT JOIN %i s ON s.set_hash = h.set_hash WHERE h.day = %s GROUP BY h.day', $hits, $sets, $day ), ARRAY_A );
		}
		self::check_db( 'fileset_hits' );
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['day'] ] = array( (int) $row['hits'], (int) $row['lost'] );
		}
		return $out;
	}

	/**
	 * Page views and losses of the newest RECENT_DAYS days, for the Settings notice: old losses are history, not a
	 * current problem (DECISIONS D66).
	 *
	 * @param array $by_day Y-m-d => array( page views, without a file list ).
	 * @return array days, from (Y-m-d), sampled, lost.
	 */
	private static function recent( array $by_day ) {
		$out = array(
			'days'    => self::RECENT_DAYS,
			'from'    => '',
			'sampled' => 0,
			'lost'    => 0,
		);
		if ( empty( $by_day ) ) {
			return $out;
		}
		ksort( $by_day );
		$out['from'] = gmdate( 'Y-m-d', strtotime( max( array_keys( $by_day ) ) . ' 00:00:00 UTC' ) - ( self::RECENT_DAYS - 1 ) * DAY_IN_SECONDS );
		// Days up to the one this build was installed on may hold an older build's losses; they are left out.
		$build = Health::build()['day'];
		foreach ( $by_day as $day => $counts ) {
			if ( $day >= $out['from'] && $day > $build ) {
				$out['sampled'] += $counts[0];
				$out['lost']    += $counts[1];
			}
		}
		return $out;
	}

	/**
	 * Adds the folded days' request counts and overhead to the hit-row figures of totals().
	 *
	 * @param array $overhead totals() overhead row (changed: sums, days, first and last day).
	 * @param array $extra    $state['extra'] from add_days().
	 * @param array $by_day   lost_by_day() (changed: the folded days are added).
	 * @return int Sampled requests of the folded days.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function add_extra( array &$overhead, array $extra, array &$by_day ) {
		global $wpdb;

		$sampled = 0;
		foreach ( $extra['metas'] as $i => $meta ) {
			$o        = $meta['overhead'];
			$sampled += (int) $meta['sampled_requests'];
			$day      = (string) $extra['days'][ $i ];
			if ( ! isset( $by_day[ $day ] ) ) {
				$by_day[ $day ] = array( 0, 0 );
			}
			$by_day[ $day ][0] += (int) $meta['sampled_requests'];
			$by_day[ $day ][1] += max( 0, (int) $meta['unmapped_hits'] );
			foreach ( array( 'hits', 'prep_ms_total', 'slow_hits', 'req_hits', 'req_ms_total' ) as $key ) {
				$overhead[ $key ] = ( isset( $overhead[ $key ] ) ? (float) $overhead[ $key ] : 0 ) + (float) $o[ $key ];
			}
			$overhead['prep_ms_max'] = max( isset( $overhead['prep_ms_max'] ) ? (float) $overhead['prep_ms_max'] : 0, (float) $o['prep_ms_max'] );
			foreach ( (array) $o['ovh_hist'] as $bucket => $count ) {
				$overhead[ 'ovh_' . $bucket ] = ( isset( $overhead[ 'ovh_' . $bucket ] ) ? (int) $overhead[ 'ovh_' . $bucket ] : 0 ) + (int) $count;
			}
		}
		// Days: those still in the hit rows and the folded ones.
		$table = Install::table( 'fileset_hits' );
		$days  = array_unique( array_merge( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT day FROM %i', $table ) ), $extra['days'] ) );
		self::check_db( 'fileset_hits' );
		sort( $days );
		$overhead['days']      = count( $days );
		$overhead['first_day'] = empty( $days ) ? '' : reset( $days );
		$overhead['last_day']  = empty( $days ) ? '' : end( $days );
		return $sampled;
	}

	/**
	 * Ymd integer → Y-m-d.
	 *
	 * @param int $day Day.
	 * @return string
	 */
	private static function day( $day ) {
		$day = sprintf( '%08d', $day );
		return substr( $day, 0, 4 ) . '-' . substr( $day, 4, 2 ) . '-' . substr( $day, 6, 2 );
	}

	/**
	 * Decompresses a stored file list.
	 *
	 * @param string $data       Stored data.
	 * @param int    $compressed Whether gzcompress was used.
	 * @return string '' if it can't be read.
	 */
	private static function unpack( $data, $compressed ) {
		if ( ! $compressed ) {
			return (string) $data;
		}
		$list = self::decompress( (string) $data );
		return false === $list ? '' : $list;
	}

	/**
	 * Safely decompresses a zlib-compressed database blob with a size cap.
	 *
	 * @param string $blob Compressed data.
	 * @return string|false Decompressed string, or false on failure or corrupt data.
	 */
	private static function decompress( $blob ) {
		if ( ! function_exists( 'gzuncompress' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped and restored in finally; avoids warnings on corrupt DB blobs.
		set_error_handler(
			static function () {
				return true;
			}
		);
		try {
			return gzuncompress( (string) $blob, 16 * 1024 * 1024 );
		} finally {
			restore_error_handler();
		}
	}
}
