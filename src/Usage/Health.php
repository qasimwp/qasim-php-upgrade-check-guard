<?php
/**
 * Plain-words state of the recording: dropped page views and an hourly job that stopped (DECISIONS D63).
 *
 * @package Upshift
 */

namespace Upshift\Usage;

use Upshift\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read by the Settings and Check tabs, Site Health and the Developer tab. Never loaded on a visitor request.
 */
class Health {

	const HEARTBEAT   = 'upshift_compact_last';
	const OVERDUE     = DAY_IN_SECONDS;
	const DROP_WINDOW = 7 * DAY_IN_SECONDS;
	const LOST_SHARE  = 0.05; // Share of the recent page views without a file list before the notice shows (DECISIONS D66).
	const LOST_MIN    = 50;   // And at least this many.
	const BUILD       = 'upshift_build'; // This build's stamp, the day and time it was first seen.

	/**
	 * Dropped page views by reason, from the recorder flags.
	 *
	 * @return array reason => count.
	 */
	public static function dropped() {
		$flags = Recorder::flags();
		return isset( $flags['dropped'] ) && is_array( $flags['dropped'] ) ? array_map( 'intval', $flags['dropped'] ) : array();
	}

	/**
	 * The day and time this build of Upshift was first seen. The version and DB version stay the same between builds of
	 * one release, so the stamp also holds the main file's time, which an update by zip, FTP or wordpress.org changes.
	 * Called only from admin pages, Site Health and the hourly job: never on a visitor's request.
	 *
	 * @param int|null $now Current time (tests).
	 * @return array day (Y-m-d), at (Unix time).
	 */
	public static function build( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$stamp = UPSHIFT_VERSION . '|' . UPSHIFT_DB_VERSION . '|' . (int) filemtime( UPSHIFT_FILE );
		$build = get_option( self::BUILD, array() );
		if ( ! is_array( $build ) || ! isset( $build['stamp'], $build['day'], $build['at'] ) || $stamp !== $build['stamp'] ) {
			$build = array(
				'stamp' => $stamp,
				'day'   => wp_date( 'Y-m-d', $now ),
				'at'    => $now,
			);
			update_option( self::BUILD, $build, false );
		}
		return array(
			'day' => (string) $build['day'],
			'at'  => (int) $build['at'],
		);
	}

	/**
	 * Whether the hourly job has been silent for more than a day. A site without a heartbeat yet starts the clock here,
	 * so a site whose cron never runs is still reported a day later.
	 *
	 * @param int|null $now Current time (tests).
	 * @return bool
	 */
	public static function job_overdue( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$last = get_option( self::HEARTBEAT, false );
		if ( false === $last ) {
			update_option( self::HEARTBEAT, $now, false );
			return false;
		}
		return $now - (int) $last > self::OVERDUE;
	}

	/**
	 * What the owner should know, one sentence each. Empty when all is well or Smart results is off.
	 *
	 * @param int|null $now Current time (tests).
	 * @return string[]
	 */
	public static function messages( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = array();
		if ( empty( Settings::get( 'usage_enabled' ) ) ) {
			return $out;
		}
		if ( self::job_overdue( $now ) ) {
			$out[] = __( "Upshift's hourly job has not run for more than a day, so Smart results is not being updated. WordPress scheduled tasks (WP-Cron) may be off on this site. Your host can check this.", 'upshift' );
		}

		$flags   = Recorder::flags();
		$dropped = array_sum( self::dropped() );
		if ( $dropped > 0 && ! empty( $flags['dropped_at'] ) && $now - (int) $flags['dropped_at'] <= self::DROP_WINDOW ) {
			$out[] = sprintf(
				/* translators: %s: number of page views. */
				_n( 'Upshift could not save %s page view because of a database problem. Recording continues by itself.', 'Upshift could not save %s page views because of a database problem. Recording continues by itself.', $dropped, 'upshift' ),
				number_format_i18n( $dropped )
			);
		}

		// Only a large loss in the newest days of the last finished rollup: stable data (a running job or cleanup never changes
		// it), and old losses are history. A meta without these figures (an older build's) shows nothing (DECISIONS D66).
		// A rollup from before this build was installed may count an older build's losses: it shows nothing (pre-submit polish).
		$meta   = Rollup::meta();
		$recent = false !== $meta && isset( $meta['recent']['sampled'], $meta['recent']['lost'] ) && (int) $meta['time'] >= self::build( $now )['at'] ? $meta['recent'] : null;
		if ( null !== $recent && (int) $recent['sampled'] > 0 && (int) $recent['lost'] >= self::LOST_MIN && (int) $recent['lost'] >= self::LOST_SHARE * (int) $recent['sampled'] ) {
			$out[] = sprintf(
				/* translators: 1: number of days, 2: page views without a file list, 3: all page views of those days. */
				_n( 'In the last %1$s day, %2$s of %3$s recorded page views were saved without their list of files, so Smart results may miss where some code runs. Recording continues normally.', 'In the last %1$s days, %2$s of %3$s recorded page views were saved without their list of files, so Smart results may miss where some code runs. Recording continues normally.', (int) $recent['days'], 'upshift' ),
				number_format_i18n( (int) $recent['days'] ),
				number_format_i18n( (int) $recent['lost'] ),
				number_format_i18n( (int) $recent['sampled'] )
			);
		}
		return $out;
	}
}
