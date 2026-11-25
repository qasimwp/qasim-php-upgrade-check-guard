<?php
/**
 * Upgrade watch: the crash email (DECISIONS D59). Sent at the end of the crashing request, after its row is written:
 * WP-Cron may never run on a site that crashes on every request. WordPress's recovery-mode email works the same way.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Watch;

use Qpucg\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom table and claim rows in wp_options; the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Sends at most one email a day.
 */
class Notify {

	const OPTION         = 'qpucg_watch_mail_day';
	const PENDING_OPTION = 'qpucg_pending_crash_mail';

	/**
	 * Sends the email if this is a new crash during a watch and none was sent today.
	 *
	 * @param array $row     Row from Collector::row().
	 * @param int   $context Page type bits.
	 */
	public static function on_crash( array $row, $context ) {
		if ( empty( Settings::get( 'email_crashes' ) ) ) {
			return;
		}
		$state = State::get();
		$today = current_time( 'Y-m-d' );
		if ( ! State::is_active( $state, $today ) || ! Report::is_new( $state, $row['hash'], $today ) || ! self::claim( $today ) ) {
			return;
		}

		// A crash while plugins load comes before WordPress allows translations, and the first __() would add a notice to the
		// log. Only our own text runs inside this window.
		$early = ! did_action( 'after_setup_theme' );
		if ( $early ) {
			add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		}
		$mail = self::message( $row, $context, self::count( $row['hash'], $state['start'] ) );
		if ( $early ) {
			remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		}

		if ( function_exists( 'wp_mail' ) ) {
			wp_mail( (string) get_option( 'admin_email' ), $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
			return;
		}

		// Crash occurred before WordPress loaded its mail API: defer sending to the next init (capped at 1 pending alert).
		update_option(
			self::PENDING_OPTION,
			array(
				'subject' => $mail['subject'],
				'body'    => $mail['body'],
			),
			false
		);
	}

	/**
	 * Sends any deferred early-crash alert once WordPress has loaded its mail API.
	 * Deletes the pending option atomically before calling wp_mail() so concurrent requests cannot duplicate it.
	 */
	public static function flush_pending() {
		global $wpdb;

		if ( ! function_exists( 'wp_mail' ) ) {
			return;
		}
		$pending = get_option( self::PENDING_OPTION, false );
		if ( ! is_array( $pending ) || empty( $pending['subject'] ) || empty( $pending['body'] ) ) {
			return;
		}
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::PENDING_OPTION ) );
		wp_cache_delete( self::PENDING_OPTION, 'options' );
		if ( 1 !== (int) $deleted ) {
			return;
		}
		wp_mail( (string) get_option( 'admin_email' ), (string) $pending['subject'], (string) $pending['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Claims today's email with one atomic write, before sending, so two crashing requests can't both send.
	 *
	 * @param string $today Y-m-d.
	 * @return bool Whether this request may send.
	 */
	private static function claim( $today ) {
		global $wpdb;

		// INSERT IGNORE + conditional UPDATE avoids relying on MySQL's rows_affected=0 for no-op ON DUPLICATE KEY UPDATE,
		// which SQLite reports as 1 affected row.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $today ) );
		if ( 1 === $inserted ) {
			return true;
		}
		if ( false === $inserted ) {
			return false;
		}
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value <> %s", $today, self::OPTION, $today ) );
		return 1 === $updated;
	}

	/**
	 * How often this crash happened since the watch started.
	 *
	 * @param string $hash  Message hash.
	 * @param string $start First day of the watch.
	 * @return int
	 */
	private static function count( $hash, $start ) {
		global $wpdb;

		$table = $wpdb->prefix . Collector::TABLE;
		return max( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(hits) FROM %i WHERE message_hash = %s AND day >= %s', $table, $hash, $start ) ) );
	}

	/**
	 * The email: only the plugin name, the count, the page type and a link to the tab.
	 *
	 * @param array $row     Row.
	 * @param int   $context Page type bits.
	 * @param int   $count   Crashes.
	 * @return array array( 'subject', 'body' ).
	 */
	public static function message( array $row, $context, $count ) {
		$where = Report::where( $context );
		$lines = array(
			/* translators: 1: plugin name, 2: number of crashes. */
			sprintf( _n( '%1$s has crashed %2$s time since your PHP version changed.', '%1$s has crashed %2$s times since your PHP version changed.', $count, 'qasim-php-upgrade-check-guard' ), self::name( $row['component'] ), number_format_i18n( $count ) ),
		);
		if ( '' !== $where ) {
			/* translators: %s: page type, e.g. checkout. */
			$lines[] = sprintf( __( 'Where: %s.', 'qasim-php-upgrade-check-guard' ), $where );
		}
		$lines[] = '';
		$lines[] = __( 'See what happened and what to do:', 'qasim-php-upgrade-check-guard' );
		$lines[] = admin_url( 'tools.php?page=qpucg&tab=watch' );

		return array(
			'subject' => __( 'A plugin started crashing after a PHP change', 'qasim-php-upgrade-check-guard' ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * A readable name, reading only the one plugin folder or theme concerned.
	 *
	 * @param string $component Component id.
	 * @return string
	 */
	private static function name( $component ) {
		$parts = explode( ':', (string) $component, 2 );
		$slug  = isset( $parts[1] ) ? $parts[1] : (string) $component;
		if ( 'plugin' === $parts[0] ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins( '/' . $slug ) as $data ) {
				if ( ! empty( $data['Name'] ) ) {
					return (string) $data['Name'];
				}
			}
			return $slug;
		}
		if ( 'theme' === $parts[0] ) {
			$name = wp_get_theme( $slug )->get( 'Name' );
			return is_string( $name ) && '' !== $name ? $name : $slug;
		}
		if ( 'mu-plugins' === $component ) {
			return __( 'Must-use plugins', 'qasim-php-upgrade-check-guard' );
		}
		return Collector::CORE === $component ? __( 'WordPress or your server', 'qasim-php-upgrade-check-guard' ) : $slug;
	}
}
