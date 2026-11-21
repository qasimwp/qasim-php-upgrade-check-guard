<?php
/**
 * Tools → PHP Upgrade admin page with tabs (docs/UI-v1.md §2). Findings × Usage and Spike metrics are in Admin\Report.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Admin;

use Qpucg\Install;
use Qpucg\Report\Join;
use Qpucg\Settings;
use Qpucg\Usage\Bench;
use Qpucg\Usage\Recorder;
use Qpucg\Usage\Rollup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Admin page.
 */
class Page {

	const SLUG = 'qpucg';

	/**
	 * Hook suffix returned by add_management_page().
	 *
	 * @var string|false
	 */
	private static $hook = false;

	/**
	 * Registers the Tools → PHP Upgrade page.
	 */
	public static function register() {
		self::$hook = add_management_page(
			__( 'PHP Upgrade', 'qasim-php-upgrade-check-guard' ),
			__( 'PHP Upgrade', 'qasim-php-upgrade-check-guard' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Whether the Developer tab is available (docs/UI-v1.md §2). Needs QPUCG_DEV and the Developer code, which the
	 * release zip leaves out (DECISIONS D64), so the constant alone does nothing on a release install.
	 *
	 * @return bool
	 */
	public static function is_dev() {
		return defined( 'QPUCG_DEV' ) && QPUCG_DEV && is_file( QPUCG_DIR . '/src/Admin/Report.php' );
	}

	/**
	 * Tab slugs and labels.
	 *
	 * @return array
	 */
	private static function tabs() {
		$tabs = array(
			'check'    => __( 'Check', 'qasim-php-upgrade-check-guard' ),
			'watch'    => __( 'Upgrade guard', 'qasim-php-upgrade-check-guard' ),
			'settings' => __( 'Settings', 'qasim-php-upgrade-check-guard' ),
		);
		if ( self::is_dev() ) {
			$tabs['developer'] = __( 'Developer', 'qasim-php-upgrade-check-guard' );
		}
		$tabs['help'] = __( 'Help', 'qasim-php-upgrade-check-guard' );
		return $tabs;
	}

	/**
	 * Current tab from the request, validated against the allow-list. Old links (tab=scan and the like) land on Check.
	 *
	 * @return string
	 */
	private static function current_tab() {
		// Read-only navigation parameter; no state change, so no nonce.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'check'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'check';
	}

	/**
	 * Views of the Developer tab.
	 *
	 * @return array
	 */
	private static function developer_views() {
		return array(
			'usage'    => __( 'Usage', 'qasim-php-upgrade-check-guard' ),
			'findings' => __( 'Findings × Usage', 'qasim-php-upgrade-check-guard' ),
			'metrics'  => __( 'Spike metrics', 'qasim-php-upgrade-check-guard' ),
		);
	}

	/**
	 * Developer tab: the old Usage, Findings × Usage and Spike metrics screens (only when QPUCG_DEV is true).
	 *
	 * @param string $base Page URL.
	 */
	private static function render_developer( $base ) {
		// Read-only navigation parameter; no state change, so no nonce.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'usage'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = array_key_exists( $view, self::developer_views() ) ? $view : 'usage';

		echo '<ul class="subsubsub" style="float:none">';
		foreach ( self::developer_views() as $slug => $label ) {
			printf(
				'<li><a href="%1$s"%2$s>%3$s</a> </li>',
				esc_url(
					add_query_arg(
						array(
							'tab'  => 'developer',
							'view' => $slug,
						),
						$base
					)
				),
				$slug === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</ul>';

		if ( 'usage' === $view ) {
			self::render_usage();
		} elseif ( 'findings' === $view ) {
			Report::render_findings();
		} else {
			Report::render_metrics();
		}
	}

	/**
	 * Renders the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'qasim-php-upgrade-check-guard' ) );
		}

		$current = self::current_tab();
		$base    = admin_url( 'tools.php?page=' . self::SLUG );

		echo '<div class="wrap qpucg-wrap">';
		echo '<h1>' . esc_html__( 'Qasim PHP Upgrade Check & Guard', 'qasim-php-upgrade-check-guard' ) . '</h1>';
		echo '<nav class="qpucg-tabs" aria-label="' . esc_attr__( 'PHP Upgrade sections', 'qasim-php-upgrade-check-guard' ) . '">';
		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="qpucg-tabs__tab%2$s"%3$s>%4$s</a>',
				esc_url( add_query_arg( 'tab', $slug, $base ) ),
				$slug === $current ? ' is-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div class="qpucg-tab qpucg-tab-' . esc_attr( $current ) . '">';
		if ( 'check' === $current ) {
			CheckTab::render();
		} elseif ( 'developer' === $current ) {
			self::render_developer( $base );
		} elseif ( 'settings' === $current ) {
			SettingsTab::render();
		} elseif ( 'help' === $current ) {
			HelpTab::render();
		} else {
			WatchTab::render();
		}
		echo '</div>';
		self::render_footer();
		echo '</div>';
	}

	/**
	 * Version of an asset: its file time, so a CDN copy cached under the old ?ver= can't outlive an update (DECISIONS D53).
	 *
	 * @param string $name File name in assets/.
	 * @return string
	 */
	private static function asset_version( $name ) {
		$file  = QPUCG_DIR . '/assets/' . $name;
		$mtime = is_readable( $file ) ? filemtime( $file ) : false;
		return false !== $mtime ? (string) $mtime : QPUCG_VERSION;
	}

	/**
	 * Loads our script and stylesheet on our page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( false === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'qpucg-admin', plugins_url( 'assets/admin.css', QPUCG_FILE ), array( 'dashicons' ), self::asset_version( 'admin.css' ) );
		wp_enqueue_script( 'qpucg-admin', plugins_url( 'assets/admin.js', QPUCG_FILE ), array( 'jquery', 'wp-i18n' ), self::asset_version( 'admin.js' ), true );
		wp_set_script_translations( 'qpucg-admin', 'qasim-php-upgrade-check-guard', QPUCG_DIR . '/languages' );
		wp_localize_script(
			'qpucg-admin',
			'qpucgScan',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Ajax::NONCE ),
				'rollupNonce' => wp_create_nonce( Ajax::ROLLUP_NONCE ),
				// LiteSpeed shared hosts answered with 508 under load (DECISIONS D54): they start at the gentle pace (D57).
				'startGentle' => isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 'litespeed' ),
				'i18n'        => self::js_texts(),
			)
		);
	}

	/**
	 * Texts the script shows, as one array (also read by tests/ui-words-check.php).
	 *
	 * @return array
	 */
	public static function js_texts() {
		return array(
			'starting'       => __( 'Getting ready…', 'qasim-php-upgrade-check-guard' ),
			'stopping'       => __( 'Stopping…', 'qasim-php-upgrade-check-guard' ),
			'busy'           => __( 'Still finishing the last step…', 'qasim-php-upgrade-check-guard' ),
			/* translators: 1: error (e.g. "HTTP 508"), 2: seconds left, 3: try number, 4: number of tries. */
			'resourceWait'   => __( 'Your server is busy right now (%1$s). Trying again in %2$s seconds (try %3$s of %4$s). Continue tries now.', 'qasim-php-upgrade-check-guard' ),
			/* translators: %s: error (e.g. "HTTP 508"). */
			'paused'         => __( 'The check is paused because your server kept answering with errors (%s). Nothing is lost. Press Continue to carry on, preferably when the site is quieter.', 'qasim-php-upgrade-check-guard' ),
			/* translators: %s: attempt number. */
			'retry'          => __( 'That step failed. Trying again (%s of 3)…', 'qasim-php-upgrade-check-guard' ),
			/* translators: %s: error message. */
			'failed'         => __( 'The check stopped: %s', 'qasim-php-upgrade-check-guard' ),
			'done'           => __( 'Finished. Loading your result…', 'qasim-php-upgrade-check-guard' ),
			'confirm'        => __( 'Stop this check?', 'qasim-php-upgrade-check-guard' ),
			'copied'         => __( 'Copied.', 'qasim-php-upgrade-check-guard' ),
			/* translators: %s: percent done. */
			'percent'        => __( '%s%%', 'qasim-php-upgrade-check-guard' ),
			/* translators: 1: percent done, 2: rollup phase (technical). */
			'rollupProgress' => __( 'Updating usage data… %1$s %% (%2$s).', 'qasim-php-upgrade-check-guard' ),
			'rollupBusy'     => __( 'Another update step is running; waiting…', 'qasim-php-upgrade-check-guard' ),
			'rollupBusyLong' => __( 'Another update step has been running for 2 minutes. Try again later.', 'qasim-php-upgrade-check-guard' ),
			/* translators: %s: attempt number. */
			'rollupRetry'    => __( 'Update step failed, retrying (%s of 3)…', 'qasim-php-upgrade-check-guard' ),
			/* translators: %s: error message. */
			'rollupFailed'   => __( 'The usage data update stopped with an error: %s', 'qasim-php-upgrade-check-guard' ),
			'rollupDone'     => __( 'Usage data updated. Reloading…', 'qasim-php-upgrade-check-guard' ),
			'noResponse'     => __( 'no response from the server (network error or timeout)', 'qasim-php-upgrade-check-guard' ),
		);
	}

	/**
	 * Footer with the plugin version and the build id written by bin/build-zip.php (DECISIONS D32).
	 */
	private static function render_footer() {
		// The build line is for developers only.
		if ( ! self::is_dev() ) {
			return;
		}
		$build = '';
		$file  = QPUCG_DIR . '/build.txt';
		if ( is_readable( $file ) ) {
			$build = trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		}
		$build = preg_match( '/^[0-9a-f]{7,12}(-dirty)?$/', $build ) ? $build : __( 'dev (not from zip)', 'qasim-php-upgrade-check-guard' );
		/* translators: 1: plugin version, 2: build id. */
		echo '<p class="description" style="margin-top:3em">' . esc_html( sprintf( __( 'Qasim PHP Upgrade Check & Guard %1$s · build %2$s', 'qasim-php-upgrade-check-guard' ), QPUCG_VERSION, $build ) ) . '</p>';
	}

	/**
	 * Usage tab (spec §7.9): settings, reset, observation summary, per-component table.
	 */
	private static function render_usage() {
		$settings = Settings::get();
		$flags    = Recorder::flags();

		// Read-only notice selector after our own redirect.
		$msg = isset( $_GET['qpucg_msg'] ) ? sanitize_key( wp_unslash( $_GET['qpucg_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saved' === $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Usage settings saved.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		} elseif ( 'reset' === $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Usage data deleted.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		} elseif ( 'bench' === $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Recorder benchmark finished. Results are below.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		}

		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Records only file paths and counts. No URLs, IPs or user data.', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		$meta = self::render_rollup_status();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="qpucg_save_usage" />';
		wp_nonce_field( 'qpucg_save_usage' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Usage recording', 'qasim-php-upgrade-check-guard' ) . '</th><td><label><input type="checkbox" name="usage_enabled" value="1"' . checked( ! empty( $settings['usage_enabled'] ), true, false ) . ' /> ' . esc_html__( 'Record which plugin and theme files load (sampled)', 'qasim-php-upgrade-check-guard' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="qpucg-sample-rate">' . esc_html__( 'Sample rate', 'qasim-php-upgrade-check-guard' ) . '</label></th><td>';
		printf(
			'<input type="number" id="qpucg-sample-rate" name="sample_rate" min="%1$s" max="%2$s" step="0.01" value="%3$s" class="small-text" />',
			esc_attr( Settings::SAMPLE_MIN ),
			esc_attr( Settings::SAMPLE_MAX ),
			esc_attr( $settings['sample_rate'] )
		);
		echo '<p class="description">' . esc_html__( 'Share of requests recorded, 0.01 to 1.0. Use 0.25 for sites with 1,000+ visits a day, 1.0 for smaller sites.', 'qasim-php-upgrade-check-guard' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save usage settings', 'qasim-php-upgrade-check-guard' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Observation', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		self::render_summary( $settings, $meta, $flags );

		echo '<h2>' . esc_html__( 'Components', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		self::render_components( $meta );

		echo '<h2>' . esc_html__( 'Recorder benchmark', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		self::render_bench();

		echo '<h2>' . esc_html__( 'Reset', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Delete all recorded usage data? This cannot be undone.', 'qasim-php-upgrade-check-guard' ) ) . '\');">';
		echo '<input type="hidden" name="action" value="qpucg_reset_usage" />';
		wp_nonce_field( 'qpucg_reset_usage' );
		submit_button( __( 'Reset usage data', 'qasim-php-upgrade-check-guard' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Shown next to a button when admin.js runs without its `qpucgScan` settings (DECISIONS D53).
	 *
	 * @return string
	 */
	public static function no_config_message() {
		return __( 'This button cannot work: the page settings for this plugin\'s script (qpucgScan) did not load. A caching or script optimization plugin may have removed them; exclude this admin page from it and reload.', 'qasim-php-upgrade-check-guard' );
	}

	/**
	 * "Usage data updated X ago" line and the "Update usage data now" button (Usage, Findings × Usage, Spike metrics).
	 * Reads the last swapped rollup only. When a rollup is running or the data is over 1 hour old it schedules a
	 * background step (WP-Cron) and says so; it never does rollup work itself (DECISIONS D48). A step more than
	 * 15 minutes past its scheduled time means WP-Cron may not be running (DECISIONS D53).
	 *
	 * @return array|false Rollup meta.
	 */
	public static function render_rollup_status() {
		Rollup::maybe_schedule();
		$status   = Rollup::status();
		$meta     = $status['meta'];
		$progress = $status['progress'];
		$next     = wp_next_scheduled( Rollup::STEP_HOOK );
		$late     = false !== $next && time() - (int) $next > 15 * MINUTE_IN_SECONDS;
		$parts    = array(
			false === $meta
				? __( 'No usage data yet', 'qasim-php-upgrade-check-guard' )
				/* translators: %s: human time difference. */
				: sprintf( __( 'Usage data updated %s ago', 'qasim-php-upgrade-check-guard' ), human_time_diff( (int) $meta['time'] ) ),
		);
		if ( $progress['running'] ) {
			/* translators: %s: percent done. */
			$parts[] = sprintf( __( 'an update is %s %% done (it runs in the background, in short steps)', 'qasim-php-upgrade-check-guard' ), number_format_i18n( $progress['percent'] ) );
		} elseif ( $status['overdue'] && $status['stopped'] ) {
			$parts[] = __( 'update stopped after repeated errors (see "Last recorder error" on the Usage tab); the daily run tries again', 'qasim-php-upgrade-check-guard' );
		} elseif ( $status['overdue'] && false !== $next ) {
			$parts[] = __( 'a background update is scheduled; WP-Cron starts it on a later visit to the site', 'qasim-php-upgrade-check-guard' );
		} elseif ( $status['overdue'] ) {
			$parts[] = __( 'no background update could be scheduled; use the button', 'qasim-php-upgrade-check-guard' );
		}
		$notes = array();
		if ( $late ) {
			/* translators: %s: human time difference. */
			$notes[] = sprintf( __( 'The scheduled background update step is %s overdue, so background updates (WP-Cron) may not be running on this site. You can use the button to update now.', 'qasim-php-upgrade-check-guard' ), human_time_diff( (int) $next ) );
		}
		if ( ( $progress['running'] || ( $status['overdue'] && ! $status['stopped'] ) ) && $status['cron_disabled'] ) {
			$notes[] = __( 'WP-Cron is disabled on this site (DISABLE_WP_CRON), so the update runs when the server runs wp-cron.php, or press the button.', 'qasim-php-upgrade-check-guard' );
		}
		echo '<div class="notice notice-info inline"><p><span id="qpucg-rollup-message">' . esc_html( implode( ' · ', $parts ) . '.' ) . '</span> ';
		echo '<button type="button" class="button" id="qpucg-rollup-now" data-qpucg-no-config="' . esc_attr( self::no_config_message() ) . '">' . esc_html__( 'Update usage data now', 'qasim-php-upgrade-check-guard' ) . '</button>';
		foreach ( $notes as $note ) {
			echo '<br /><span class="description">' . esc_html( $note ) . '</span>';
		}
		echo '</p></div>';
		return $meta;
	}

	/**
	 * Observation summary table.
	 *
	 * @param array       $settings Settings.
	 * @param array|false $meta     Rollup meta.
	 * @param array       $flags    Recorder flags.
	 */
	private static function render_summary( array $settings, $meta, array $flags ) {
		$rows = array();

		$rows[] = array( __( 'Observing since', 'qasim-php-upgrade-check-guard' ), '' !== $settings['observe_since'] ? $settings['observe_since'] : __( 'not started', 'qasim-php-upgrade-check-guard' ) );

		if ( false === $meta ) {
			$rows[] = array( __( 'Data', 'qasim-php-upgrade-check-guard' ), __( 'No rollup yet.', 'qasim-php-upgrade-check-guard' ) );
		} else {
			$by_context = array();
			foreach ( $meta['requests'] as $bit => $count ) {
				$label        = Recorder::label( $bit );
				$by_context[] = ( '' !== $label ? $label : (string) $bit ) . ': ' . number_format_i18n( $count );
			}
			$overhead = $meta['overhead'];
			$avg      = $overhead['hits'] > 0 ? $overhead['prep_ms_total'] / $overhead['hits'] : 0;
			$slow     = $overhead['hits'] > 0 ? 100 * $overhead['slow_hits'] / $overhead['hits'] : 0;

			$rows[] = array( __( 'Days observed', 'qasim-php-upgrade-check-guard' ), number_format_i18n( $meta['days_observed'] ) );
			$rows[] = array( __( 'Sampled requests', 'qasim-php-upgrade-check-guard' ), number_format_i18n( $meta['sampled_requests'] ) . ( $by_context ? ' (' . implode( '; ', $by_context ) . ')' : '' ) );
			$rows[] = array( __( 'Filesets', 'qasim-php-upgrade-check-guard' ), number_format_i18n( $meta['filesets'] ) );
			$rows[] = array( __( 'Files seen', 'qasim-php-upgrade-check-guard' ), number_format_i18n( $meta['files'] ) );
			/* translators: 1: average prep ms, 2: max prep ms, 3: percent of requests with prep >= 3 ms. */
			$rows[]   = array( __( 'Recorder prep time (filter + sort + hash; no DB write)', 'qasim-php-upgrade-check-guard' ), sprintf( __( 'avg %1$s ms, max %2$s ms, %3$s%% of requests ≥ 3 ms', 'qasim-php-upgrade-check-guard' ), number_format_i18n( $avg, 3 ), number_format_i18n( $overhead['prep_ms_max'], 3 ), number_format_i18n( $slow, 2 ) ) );
			$relative = Join::relative_overhead( $overhead );
			$rows[]   = array(
				__( 'Recorder prep as % of request time', 'qasim-php-upgrade-check-guard' ),
				$relative['req_hits'] > 0
					/* translators: 1: median range, 2: p95 range, 3: requests, 4: average request ms. */
					? sprintf( __( 'median %1$s, p95 %2$s (%3$s requests with a request time, avg request %4$s ms)', 'qasim-php-upgrade-check-guard' ), $relative['rel_median'], $relative['rel_p95'], number_format_i18n( $relative['req_hits'] ), number_format_i18n( $relative['avg_req_ms'], 1 ) )
					: __( 'no data yet (recorded from version T6.1 on)', 'qasim-php-upgrade-check-guard' ),
			);
			$rows[]   = array( __( 'Requests without a file list', 'qasim-php-upgrade-check-guard' ), number_format_i18n( $meta['unmapped_hits'] ) );

			/* translators: %s: human time difference. */
			$last = sprintf( __( '%s ago', 'qasim-php-upgrade-check-guard' ), human_time_diff( $meta['time'] ) );
			if ( isset( $meta['swap_seconds'], $meta['swap_rows'] ) ) {
				// The swap is one transaction the step budget can't split (DECISIONS D51).
				/* translators: 1: seconds, 2: rows. */
				$last .= ' · ' . sprintf( __( 'swap %1$s s (%2$s rows)', 'qasim-php-upgrade-check-guard' ), number_format_i18n( (float) $meta['swap_seconds'], 2 ), number_format_i18n( (int) $meta['swap_rows'] ) );
			}
			$rows[] = array( __( 'Last rollup', 'qasim-php-upgrade-check-guard' ), $last );
		}

		$rows[] = array(
			__( 'Slowest recorder run over 500 ms', 'qasim-php-upgrade-check-guard' ),
			/* translators: 1: duration in ms, 2: date and time. */
			! empty( $flags['slow_record_ms_max'] ) ? sprintf( __( '%1$s ms (%2$s)', 'qasim-php-upgrade-check-guard' ), number_format_i18n( (float) $flags['slow_record_ms_max'], 1 ), isset( $flags['slow_record_at'] ) ? $flags['slow_record_at'] : '' ) : __( 'none', 'qasim-php-upgrade-check-guard' ),
		);
		if ( (int) $settings['recorder_paused_until'] > time() ) {
			/* translators: %s: date and time. */
			$rows[] = array( __( 'Recording paused', 'qasim-php-upgrade-check-guard' ), sprintf( __( 'until %s, after a database error (saving the settings with recording on resumes it)', 'qasim-php-upgrade-check-guard' ), wp_date( 'Y-m-d H:i:s', (int) $settings['recorder_paused_until'] ) ) );
		}
		$dropped = isset( $flags['dropped'] ) && is_array( $flags['dropped'] ) ? $flags['dropped'] : array();
		$rows[]  = array(
			__( 'Page views dropped', 'qasim-php-upgrade-check-guard' ),
			/* translators: 1: counter could not be written, 2: file list could not be stored, 3: unexpected error. */
			empty( $dropped ) ? __( 'none', 'qasim-php-upgrade-check-guard' ) : sprintf( __( '%1$d counter write failed, %2$d file list not stored, %3$d unexpected error', 'qasim-php-upgrade-check-guard' ), isset( $dropped['hit'] ) ? (int) $dropped['hit'] : 0, isset( $dropped['set'] ) ? (int) $dropped['set'] : 0, isset( $dropped['error'] ) ? (int) $dropped['error'] : 0 ) . ( ! empty( $flags['dropped_at'] ) ? ' (' . wp_date( 'Y-m-d H:i:s', (int) $flags['dropped_at'] ) . ')' : '' ),
		);
		$rows[]  = array(
			__( 'Last recorder error', 'qasim-php-upgrade-check-guard' ),
			! empty( $flags['last_error'] ) ? $flags['last_error'] . ( ! empty( $flags['last_error_at'] ) ? ' (' . $flags['last_error_at'] . ')' : '' ) : __( 'none', 'qasim-php-upgrade-check-guard' ),
		);

		echo '<table class="widefat striped" style="max-width:60em"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row" style="width:16em">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Per-component table: files seen / files total in the last scan, requests.
	 *
	 * @param array|false $meta Rollup meta.
	 */
	private static function render_components( $meta ) {
		global $wpdb;

		$usage_table = Install::table( 'usage' );
		$scans_table = Install::table( 'scans' );
		$files_table = Install::table( 'scan_files' );

		$seen = array();
		foreach ( (array) $wpdb->get_results( "SELECT component, COUNT(*) AS files FROM {$usage_table} GROUP BY component", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$seen[ $row['component'] ] = (int) $row['files'];
		}

		$total   = array();
		$scan_id = (int) $wpdb->get_var( "SELECT id FROM {$scans_table} WHERE status = 'done' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		if ( $scan_id > 0 ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT component, COUNT(*) AS files FROM {$files_table} WHERE scan_id = %d GROUP BY component", $scan_id ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$total[ $row['component'] ] = (int) $row['files'];
			}
		}

		$hits          = false !== $meta ? $meta['components'] : array();
		$sampled       = false !== $meta ? (int) $meta['sampled_requests'] : 0;
		$component_ids = array_unique( array_merge( array_keys( $hits ), array_keys( $seen ), array_keys( $total ) ) );
		if ( empty( $component_ids ) ) {
			echo '<p>' . esc_html__( 'No usage recorded yet.', 'qasim-php-upgrade-check-guard' ) . '</p>';
			return;
		}
		usort(
			$component_ids,
			function ( $a, $b ) use ( $hits ) {
				$ha = isset( $hits[ $a ] ) ? $hits[ $a ] : 0;
				$hb = isset( $hits[ $b ] ) ? $hits[ $b ] : 0;
				return $ha === $hb ? strcmp( $a, $b ) : $hb - $ha;
			}
		);

		echo '<table class="widefat striped" style="max-width:60em"><thead><tr>';
		echo '<th>' . esc_html__( 'Component', 'qasim-php-upgrade-check-guard' ) . '</th>';
		echo '<th>' . esc_html__( 'Files seen', 'qasim-php-upgrade-check-guard' ) . '</th>';
		echo '<th>' . esc_html__( 'Files total (last scan)', 'qasim-php-upgrade-check-guard' ) . '</th>';
		echo '<th>' . esc_html__( 'Requests', 'qasim-php-upgrade-check-guard' ) . '</th>';
		echo '<th>' . esc_html__( 'Share of sampled requests', 'qasim-php-upgrade-check-guard' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $component_ids as $id ) {
			$h = isset( $hits[ $id ] ) ? $hits[ $id ] : 0;
			echo '<tr>';
			echo '<td>' . esc_html( $id ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( isset( $seen[ $id ] ) ? $seen[ $id ] : 0 ) ) . '</td>';
			echo '<td>' . esc_html( isset( $total[ $id ] ) ? number_format_i18n( $total[ $id ] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $h ) ) . '</td>';
			echo '<td>' . esc_html( $sampled > 0 ? number_format_i18n( 100 * $h / $sampled, 1 ) . '%' : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( 0 === $scan_id ) {
			echo '<p class="description">' . esc_html__( 'Files total appears after the first completed scan.', 'qasim-php-upgrade-check-guard' ) . '</p>';
		}
	}

	/**
	 * Benchmark button and the last result (DECISIONS D14).
	 */
	private static function render_bench() {
		echo '<p>' . esc_html__( 'Measures the recorder on this request: hot-path prep, the database write, and the cost of storing a new file set. Runs up to 50 iterations or 10 seconds, using temporary rows that are deleted afterwards.', 'qasim-php-upgrade-check-guard' ) . '</p>';

		$result = Bench::last();
		if ( is_array( $result ) && isset( $result['stats'] ) ) {
			$labels = array(
				'prep'    => __( 'Hot-path prep (filter + sort + hash)', 'qasim-php-upgrade-check-guard' ),
				'db'      => __( 'Database write (lock limit + upsert + restore)', 'qasim-php-upgrade-check-guard' ),
				'new_set' => __( 'New file set (keys + insert)', 'qasim-php-upgrade-check-guard' ),
			);
			echo '<table class="widefat striped" style="max-width:60em"><thead><tr>';
			echo '<th>' . esc_html__( 'Part', 'qasim-php-upgrade-check-guard' ) . '</th><th>' . esc_html__( 'Median (ms)', 'qasim-php-upgrade-check-guard' ) . '</th><th>' . esc_html__( 'p95 (ms)', 'qasim-php-upgrade-check-guard' ) . '</th><th>' . esc_html__( 'Max (ms)', 'qasim-php-upgrade-check-guard' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $labels as $name => $label ) {
				$stats = $result['stats'][ $name ];
				echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( number_format_i18n( $stats['median'], 3 ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['p95'], 3 ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['max'], 3 ) ) . '</td></tr>';
			}
			$split = isset( $result['split'] ) && is_array( $result['split'] ) ? $result['split'] : array();
			foreach ( $split as $phase => $stats ) {
				/* translators: %s: prep phase name (setup, filter, sort, hash, context). */
				$label = sprintf( __( 'Prep phase: %s', 'qasim-php-upgrade-check-guard' ), $phase );
				echo '<tr><td style="padding-left:2em">' . esc_html( $label ) . '</td><td>' . esc_html( number_format_i18n( $stats['median'], 3 ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['p95'], 3 ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['max'], 3 ) ) . '</td></tr>';
			}
			echo '</tbody></table>';

			$notes = array(
				/* translators: 1: iterations run, 2: iterations requested, 3: content files per set, 4: human time difference. */
				sprintf( __( '%1$d of %2$d iterations, %3$d content files per set, %4$s ago.', 'qasim-php-upgrade-check-guard' ), $result['iterations'], $result['requested'], $result['paths'], human_time_diff( $result['time'] ) ),
			);
			if ( isset( $result['path_bytes'], $result['file_bytes'], $result['hash_algo'] ) ) {
				/* translators: 1: included files, 2: their path bytes, 3: content files per set, 4: their path bytes, 5: hash algorithm. */
				$notes[] = sprintf( __( 'Included files: %1$d (%2$s path bytes). Content files per set: %3$d (%4$s path bytes). Set hash: %5$s. The prep phases come from a separate phase-by-phase run of the same prep.', 'qasim-php-upgrade-check-guard' ), $result['files'], number_format_i18n( $result['file_bytes'] ), $result['paths'], number_format_i18n( $result['path_bytes'] ), $result['hash_algo'] );
			}
			if ( isset( $result['env']['php'] ) ) {
				/* translators: %s: PHP version, PCRE JIT state and filter prefix counts (technical, English). */
				$notes[] = sprintf( __( 'Environment: %s', 'qasim-php-upgrade-check-guard' ), Bench::describe_environment( $result['env'] ) );
			}
			if ( $result['stopped_early'] ) {
				$notes[] = __( 'Stopped early at the 10-second limit.', 'qasim-php-upgrade-check-guard' );
			}
			if ( '' !== $result['error'] ) {
				/* translators: %s: error message. */
				$notes[] = sprintf( __( 'Error: %s', 'qasim-php-upgrade-check-guard' ), $result['error'] );
			} elseif ( $result['iterations'] > 0 ) {
				$notes[] = $result['stats']['prep']['median'] <= Bench::PREP_GATE_MS
					/* translators: %s: limit in ms. */
					? sprintf( __( 'M3b: PASS (prep median at most %s ms).', 'qasim-php-upgrade-check-guard' ), number_format_i18n( Bench::PREP_GATE_MS, 1 ) )
					/* translators: %s: limit in ms. */
					: sprintf( __( 'M3b: FAIL (prep median over %s ms).', 'qasim-php-upgrade-check-guard' ), number_format_i18n( Bench::PREP_GATE_MS, 1 ) );
				if ( $result['stats']['db']['median'] > Bench::DB_WARN_MS ) {
					/* translators: %s: limit in ms. */
					$notes[] = sprintf( __( 'The database write median is over %s ms: lower the sample rate on this site.', 'qasim-php-upgrade-check-guard' ), number_format_i18n( Bench::DB_WARN_MS ) );
				}
			}
			foreach ( $notes as $note ) {
				echo '<p>' . esc_html( $note ) . '</p>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="qpucg_bench" />';
		wp_nonce_field( 'qpucg_bench' );
		submit_button( __( 'Run recorder benchmark', 'qasim-php-upgrade-check-guard' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Admin-post: run the recorder benchmark (50 iterations, 10 s cap).
	 */
	public static function handle_bench() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'qasim-php-upgrade-check-guard' ), 403 );
		}
		check_admin_referer( 'qpucg_bench' );

		Bench::store( Bench::run( 50, 10.0 ) );

		self::redirect_usage( 'bench' );
	}

	/**
	 * Admin-post: save usage settings.
	 */
	public static function handle_save_usage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'qasim-php-upgrade-check-guard' ), 403 );
		}
		check_admin_referer( 'qpucg_save_usage' );

		$changes = array( 'usage_enabled' => ! empty( $_POST['usage_enabled'] ) );
		if ( $changes['usage_enabled'] ) {
			// Enabling (or re-saving with recording on) ends a recorder pause.
			$changes['recorder_paused_until'] = 0;
		}
		$rate = isset( $_POST['sample_rate'] ) ? sanitize_text_field( wp_unslash( $_POST['sample_rate'] ) ) : '';
		if ( is_numeric( $rate ) ) {
			$changes['sample_rate'] = (float) $rate;
		}
		Settings::update( $changes );

		self::redirect_usage( 'saved' );
	}

	/**
	 * Admin-post: delete all usage data.
	 */
	public static function handle_reset_usage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'qasim-php-upgrade-check-guard' ), 403 );
		}
		check_admin_referer( 'qpucg_reset_usage' );

		Rollup::reset();

		self::redirect_usage( 'reset' );
	}

	/**
	 * Redirects back to the Usage view of the Developer tab and exits.
	 *
	 * @param string $msg Notice key.
	 */
	private static function redirect_usage( $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'         => 'developer',
					'view'        => 'usage',
					'qpucg_msg' => $msg,
				),
				admin_url( 'tools.php?page=' . self::SLUG )
			)
		);
		exit;
	}
}
