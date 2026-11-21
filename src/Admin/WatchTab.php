<?php
/**
 * The Upgrade watch tab (docs/UI-v1.md §5).
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Admin;

use Qpucg\Settings;
use Qpucg\Watch\Collector;
use Qpucg\Watch\Report;
use Qpucg\Watch\State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered from Page::render(), which checks the capability first. The handler checks it again, then its nonce.
 */
class WatchTab {

	const START = 'qpucg_watch_start';
	const STOP  = 'qpucg_watch_stop';

	/**
	 * Renders the tab.
	 */
	public static function render() {
		self::render_notice();
		if ( empty( Settings::get( 'upgrade_watch' ) ) ) {
			self::render_off();
			return;
		}

		$report  = Report::build();
		$waiting = Report::BEFORE === $report['phase'];
		if ( $waiting ) {
			self::render_before( $report );
		} else {
			self::render_result( $report );
		}
		self::render_notes( $report );
		self::render_counting( $report );
		if ( ! $waiting ) {
			self::render_switch( true );
		}
	}

	/**
	 * Notice after our own redirect.
	 */
	private static function render_notice() {
		// Read-only notice selector after our own redirect.
		$msg = isset( $_GET['qpucg_msg'] ) ? sanitize_key( wp_unslash( $_GET['qpucg_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saved' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		} elseif ( 'started' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Upgrade guard started. Qasim PHP Upgrade Check & Guard compares the next 7 days with the 7 days before.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		} elseif ( 'stopped' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Comparison stopped. Upgrade guard keeps watching.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		} elseif ( 'guard_off' === $msg ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Upgrade guard is already off. Nothing to stop.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		}
	}

	/**
	 * The switch.
	 *
	 * @param bool $on Whether the watch is on.
	 */
	private static function render_switch( $on ) {
		echo '<form method="post" class="qpucg-switch" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SettingsTab::SAVE ) . '" /><input type="hidden" name="qpucg_section" value="watch" />';
		wp_nonce_field( SettingsTab::SAVE );
		echo '<p class="qpucg-switch__row"><label><input type="checkbox" name="upgrade_watch" value="1"' . checked( $on, true, false ) . ' /> <strong>' . esc_html__( 'Watch for problems after a PHP upgrade', 'qasim-php-upgrade-check-guard' ) . '</strong></label> ';
		submit_button( __( 'Save', 'qasim-php-upgrade-check-guard' ), 'secondary', 'submit', false );
		echo '</p>';
		if ( $on ) {
			echo '<p class="qpucg-muted">' . esc_html__( 'Turn this off to stop Upgrade guard completely.', 'qasim-php-upgrade-check-guard' ) . '</p>';
		}
		// "Turn it on now" only makes sense while it is off.
		echo '<p class="qpucg-muted">' . esc_html( $on ? __( 'Tells you if any plugin starts crashing after your host changes PHP.', 'qasim-php-upgrade-check-guard' ) : __( 'Tells you if any plugin starts crashing after your host changes PHP. Turn it on now, so Qasim PHP Upgrade Check & Guard knows how your site behaves before the change.', 'qasim-php-upgrade-check-guard' ) ) . '</p>';
		if ( ! $on ) {
			echo '<p class="qpucg-muted qpucg-privacy">' . esc_html( SettingsTab::privacy_line() ) . '</p>';
		}
		echo '</form>';
	}

	/**
	 * Off: what it does and the switch.
	 */
	private static function render_off() {
		echo '<section class="qpucg-card qpucg-watch">';
		echo '<h2>' . esc_html__( 'Upgrade guard is off.', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		self::render_switch( false );
		echo '</section>';
	}

	/**
	 * Prints a decorative dashicon (the word is always next to it).
	 *
	 * @param string $name Dashicon class.
	 */
	private static function icon( $name ) {
		echo '<span class="dashicons ' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}

	/**
	 * The "Start comparing now" button.
	 *
	 * @param bool $primary Primary style.
	 */
	private static function render_start( $primary ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::START ) . '" />';
		wp_nonce_field( self::START );
		echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html__( 'Start comparing now', 'qasim-php-upgrade-check-guard' ) . '</button></form>';
	}

	/**
	 * "Stop comparing": the confirm text, always shown, and one button that sends it (no script).
	 */
	private static function render_stop() {
		echo '<div class="qpucg-stop"><p>' . esc_html__( 'Stop comparing? Qasim PHP Upgrade Check & Guard keeps watching and starts a new comparison when your PHP version changes.', 'qasim-php-upgrade-check-guard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::STOP ) . '" />';
		wp_nonce_field( self::STOP );
		echo '<button type="submit" class="button">' . esc_html__( 'Stop comparing', 'qasim-php-upgrade-check-guard' ) . '</button></form></div>';
	}

	/**
	 * The small "Already upgraded?" button while waiting for a PHP change. Same handler as "Start comparing now".
	 */
	private static function render_compare() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::START ) . '" />';
		wp_nonce_field( self::START );
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Already upgraded? Start comparing now', 'qasim-php-upgrade-check-guard' ) . '</button></form>';
		echo '<p class="qpucg-muted qpucg-compare__note">' . esc_html__( 'Use this only if your host changed PHP recently.', 'qasim-php-upgrade-check-guard' ) . '</p>';
	}

	/**
	 * Before a switch: the watch is on and waiting for a PHP change.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_before( array $report ) {
		$since = '' !== (string) $report['state']['since'] ? (string) $report['state']['since'] : (string) $report['today'];

		echo '<section class="qpucg-hero qpucg-hero--run">';
		self::icon( 'qpucg-hero__icon dashicons-visibility' );
		echo '<div class="qpucg-hero__body">';
		echo '<h2 class="qpucg-hero__answer">' . esc_html__( 'Upgrade guard is on. Nothing more to do. When your host changes PHP, Qasim PHP Upgrade Check & Guard starts comparing automatically for 7 days.', 'qasim-php-upgrade-check-guard' ) . '</h2>';

		echo '<ol class="qpucg-stages">';
		echo '<li class="qpucg-stages__step is-current" aria-current="step"><span class="qpucg-stages__marker" aria-hidden="true">1</span><span class="qpucg-stages__text"><strong>' . esc_html__( 'Learning how your site normally behaves', 'qasim-php-upgrade-check-guard' ) . '</strong>';
		/* translators: %s: date, for example "9 Nov". */
		$collecting = sprintf( __( 'Collecting since %s', 'qasim-php-upgrade-check-guard' ), Report::date( $since ) );
		/* translators: %s: PHP version. */
		$runs = sprintf( __( 'Your site runs PHP %s.', 'qasim-php-upgrade-check-guard' ), $report['php'] );
		echo '<span class="qpucg-stages__line">' . esc_html( $collecting . ' · ' . $runs ) . '</span></span></li>';
		echo '<li class="qpucg-stages__step"><span class="qpucg-stages__marker" aria-hidden="true">2</span><span class="qpucg-stages__text"><strong>' . esc_html__( 'Your host changes PHP', 'qasim-php-upgrade-check-guard' ) . '</strong></span></li>';
		echo '<li class="qpucg-stages__step"><span class="qpucg-stages__marker" aria-hidden="true">3</span><span class="qpucg-stages__text"><strong>' . esc_html__( '7-day comparison', 'qasim-php-upgrade-check-guard' ) . '</strong></span></li>';
		echo '</ol>';

		if ( $report['state']['since'] !== $report['today'] ) {
			/* translators: %s: for example "0 crashes, 3 warnings". */
			echo '<p>' . esc_html( sprintf( __( 'Last 7 days: %s.', 'qasim-php-upgrade-check-guard' ), Report::recent_words( $report['recent'] ) ) ) . '</p>';
		}
		self::render_also( $report['recent'][ Collector::DEPRECATION ] );
		self::render_switch( true );
		echo '</div><div class="qpucg-hero__action qpucg-compare">';
		self::render_compare();
		echo '</div></section>';
	}

	/**
	 * The muted line about old code.
	 *
	 * @param int $count Notes.
	 */
	private static function render_also( $count ) {
		if ( $count > 0 ) {
			/* translators: %s: for example "12 notes about old code that still work". */
			echo '<p class="qpucg-muted">' . esc_html( sprintf( __( 'Also seen: %s.', 'qasim-php-upgrade-check-guard' ), Report::count_words( $count, Collector::DEPRECATION ) ) ) . '</p>';
		}
	}

	/**
	 * After a switch: the answer, the table, advice and the report.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_result( array $report ) {
		$green = 'green' === $report['answer']['level'];

		echo '<section class="qpucg-hero qpucg-hero--' . esc_attr( $report['answer']['level'] ) . '">';
		self::icon( 'qpucg-hero__icon ' . ( $green ? 'dashicons-yes-alt' : 'dashicons-dismiss' ) );
		echo '<div class="qpucg-hero__body">';
		echo '<h2 class="qpucg-hero__answer">' . esc_html( $report['answer']['sentence'] ) . '</h2>';
		self::render_path( $report );
		echo '<p class="qpucg-muted">' . esc_html( Report::headline( $report ) ) . '</p>';
		self::render_also( $report['also'] );
		echo '</div>';
		echo '<div class="qpucg-hero__action">';
		if ( Report::WATCHING !== $report['phase'] ) {
			self::render_start( false );
		} else {
			self::render_stop();
		}
		echo '</div>';
		echo '</section>';

		$rows = array_values(
			array_filter(
				$report['items'],
				function ( $item ) {
					return $item[ Collector::CRASH ] + $item[ Collector::ERROR ] + $item[ Collector::WARNING ] > 0;
				}
			)
		);

		$advice = Report::advice( $report );
		if ( '' !== $advice ) {
			echo '<div class="qpucg-note' . ( $green ? '' : ' qpucg-note--warn' ) . '"><p>' . esc_html( $advice ) . '</p></div>';
		}

		if ( $rows ) {
			self::render_table( $rows );
		}

		echo '<div class="qpucg-actions">';
		echo '<button type="button" class="button qpucg-copy" data-copy-from="qpucg-watch-text">' . esc_html__( 'Copy report', 'qasim-php-upgrade-check-guard' ) . '</button>';
		echo '<span id="qpucg-copy-status" class="qpucg-muted" role="status"></span>';
		echo '</div>';
		echo '<textarea id="qpucg-watch-text" class="qpucg-offscreen" readonly tabindex="-1" aria-hidden="true">' . esc_textarea( Report::text( $report ) ) . '</textarea>';
	}

	/**
	 * The two version pills.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_path( array $report ) {
		$state = $report['state'];
		$from  = Report::BACK === $report['phase'] ? $state['back']['from'] : $state['from'];
		$to    = Report::BACK === $report['phase'] ? $state['back']['to'] : $state['to'];
		if ( '' === $from ) {
			return;
		}
		echo '<p class="qpucg-path">';
		/* translators: 1: PHP version before, 2: PHP version after. */
		echo '<span class="screen-reader-text">' . esc_html( sprintf( __( 'From PHP %1$s to PHP %2$s', 'qasim-php-upgrade-check-guard' ), $from, $to ) ) . '</span>';
		echo '<span class="qpucg-pill" aria-hidden="true">' . esc_html( $from ) . '</span>';
		self::icon( 'dashicons-arrow-right-alt qpucg-path__arrow' );
		echo '<span class="qpucg-pill" aria-hidden="true">' . esc_html( $to ) . '</span></p>';
	}

	/**
	 * The per-plugin table.
	 *
	 * @param array $rows Items.
	 */
	private static function render_table( array $rows ) {
		$heads = array(
			__( 'Plugin or theme', 'qasim-php-upgrade-check-guard' ),
			__( 'New crashes', 'qasim-php-upgrade-check-guard' ),
			__( 'New errors', 'qasim-php-upgrade-check-guard' ),
			__( 'New warnings', 'qasim-php-upgrade-check-guard' ),
			__( 'Where', 'qasim-php-upgrade-check-guard' ),
			__( 'Last seen', 'qasim-php-upgrade-check-guard' ),
		);
		echo '<section class="qpucg-section"><table class="qpucg-problems qpucg-watch-table"><thead><tr>';
		foreach ( $heads as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $item ) {
			echo '<tr>';
			echo '<th scope="row" data-label="' . esc_attr( $heads[0] ) . '">' . esc_html( $item['name'] ) . '</th>';
			echo '<td data-label="' . esc_attr( $heads[1] ) . '">' . esc_html( number_format_i18n( $item[ Collector::CRASH ] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $heads[2] ) . '">' . esc_html( number_format_i18n( $item[ Collector::ERROR ] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $heads[3] ) . '">' . esc_html( number_format_i18n( $item[ Collector::WARNING ] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $heads[4] ) . '"><span class="qpucg-chips">';
			foreach ( Report::chips( $item['context'] ) as $chip ) {
				echo '<span class="qpucg-chip">';
				self::icon( $chip[0] );
				echo esc_html( $chip[1] ) . '</span>';
			}
			echo '</span></td>';
			echo '<td data-label="' . esc_attr( $heads[5] ) . '">' . esc_html( Report::date( $item['day'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<details class="qpucg-tech"><summary>' . esc_html__( 'Technical details', 'qasim-php-upgrade-check-guard' ) . '</summary><ul>';
		foreach ( $rows as $item ) {
			foreach ( $item['samples'] as $sample ) {
				echo '<li><strong>' . esc_html( $item['name'] ) . '</strong> <code>' . esc_html( $sample['level'] ) . '</code> ' . esc_html( $sample['sample'] ) . '</li>';
			}
		}
		echo '</ul></details></section>';
	}

	/**
	 * Notes that are true right now.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_notes( array $report ) {
		$notes = array();
		if ( null !== $report['thin'] ) {
			if ( 0 === $report['thin'] ) {
				$notes[] = __( 'Qasim PHP Upgrade Check & Guard didn\'t have time to learn how your site normally behaves before this. A few warnings listed as new may have been there before.', 'qasim-php-upgrade-check-guard' );
			} else {
				/* translators: %s: number of days. */
				$notes[] = sprintf( _n( 'Qasim PHP Upgrade Check & Guard had only %s day to learn how your site normally behaves. A few warnings listed as new may have been there before.', 'Qasim PHP Upgrade Check & Guard had only %s days to learn how your site normally behaves. A few warnings listed as new may have been there before.', $report['thin'], 'qasim-php-upgrade-check-guard' ), number_format_i18n( $report['thin'] ) );
			}
		}
		if ( $report['paused'] ) {
			$notes[] = __( 'Upgrade guard paused itself for a few minutes after a database error. It starts again on its own.', 'qasim-php-upgrade-check-guard' );
		}
		if ( $report['capped'] ) {
			$notes[] = __( 'So many different warnings appeared today that Qasim PHP Upgrade Check & Guard stopped counting new ones until tomorrow. Crashes are still counted.', 'qasim-php-upgrade-check-guard' );
		}
		foreach ( $notes as $note ) {
			echo '<div class="qpucg-note qpucg-note--warn"><p>' . esc_html( $note ) . '</p></div>';
		}
	}

	/**
	 * "How Qpucg counts problems", collapsed.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_counting( array $report ) {
		echo '<details class="qpucg-howcount"><summary>' . esc_html__( 'How Qasim PHP Upgrade Check & Guard counts problems', 'qasim-php-upgrade-check-guard' ) . '</summary>';
		echo '<p>' . esc_html( self::counting_text( $report['rate'] ) ) . '</p></details>';
	}

	/**
	 * The counting text. The "1 in N" sentence follows the real share of page views and is left out when all are watched.
	 *
	 * @param int $rate Percent of page views watched (Report::build() 'rate').
	 * @return string
	 */
	public static function counting_text( $rate ) {
		$parts = array( __( 'Qasim PHP Upgrade Check & Guard counts every crash.', 'qasim-php-upgrade-check-guard' ) );
		if ( $rate > 0 && $rate < 100 ) {
			/* translators: %s: a number, e.g. 4 in "1 in 4 page views". */
			$parts[] = sprintf( __( 'To keep your site fast, it looks at about 1 in %s page views for smaller warnings.', 'qasim-php-upgrade-check-guard' ), number_format_i18n( (int) round( 100 / $rate ) ) );
		}
		$parts[] = __( "Must-use plugins load before Qasim PHP Upgrade Check & Guard, so their crashes aren't included.", 'qasim-php-upgrade-check-guard' );
		return implode( ' ', $parts );
	}

	/**
	 * Admin-post: "Start comparing now" (capability + nonce first).
	 */
	public static function handle_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'qasim-php-upgrade-check-guard' ), 403 );
		}
		check_admin_referer( self::START );

		State::save( State::manual( State::get(), State::version(), current_time( 'Y-m-d' ) ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'         => 'watch',
					'qpucg_msg' => 'started',
				),
				admin_url( 'tools.php?page=' . Page::SLUG )
			)
		);
		exit;
	}

	/**
	 * Admin-post: "Stop comparing" (capability + nonce first). Registered even while the guard is off, so a form sent from
	 * a tab that was open before the guard was turned off gets a calm notice instead of an empty page.
	 */
	public static function handle_stop() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'qasim-php-upgrade-check-guard' ), 403 );
		}
		check_admin_referer( self::STOP );

		$msg = 'guard_off';
		if ( ! empty( Settings::get( 'upgrade_watch' ) ) ) {
			State::save( State::stop( State::get() ) );
			$msg = 'stopped';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'         => 'watch',
					'qpucg_msg' => $msg,
				),
				admin_url( 'tools.php?page=' . Page::SLUG )
			)
		);
		exit;
	}
}
