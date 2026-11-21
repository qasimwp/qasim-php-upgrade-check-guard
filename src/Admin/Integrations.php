<?php
/**
 * The Plugins screen badge, the Site Health test and the one notice after activation (docs/UI-v1.md §4, §8a).
 * All three read the small cached summary (Report\Status) and never query the findings table.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Admin;

use Qpucg\Report\Status;
use Qpucg\Usage\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks registered in Plugin::boot() with literal class names, so this class loads only when one of them fires.
 */
class Integrations {

	/**
	 * Colour of a status for the badge. The word is always shown, so colour is never the only signal.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function colour( $status ) {
		switch ( $status ) {
			case Status::BREAKS:
				return '#c62828';
			case Status::HOST:
				return '#7a4300';
			case Status::LATER:
				return '#614200';
			default:
				return '#005c12';
		}
	}

	/**
	 * Text of a badge, e.g. "PHP 8.4: Ready".
	 *
	 * @param string $status Status.
	 * @param string $target Target PHP version.
	 * @return string
	 */
	public static function badge_text( $status, $target ) {
		/* translators: 1: target PHP version, 2: result, e.g. "Ready" or "Will break". */
		return sprintf( __( 'PHP %1$s: %2$s', 'qasim-php-upgrade-check-guard' ), $target, Status::label( $status ) );
	}

	/**
	 * Badge for one plugin row, linked to the Check tab.
	 *
	 * @param string $status Status.
	 * @param string $target Target PHP version.
	 * @return string Escaped HTML.
	 */
	public static function badge_html( $status, $target ) {
		return sprintf(
			'<a href="%1$s" style="color:%2$s;font-weight:600;white-space:nowrap"><span class="dashicons %3$s" aria-hidden="true" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> %4$s</a>',
			esc_url( admin_url( 'tools.php?page=' . Page::SLUG ) ),
			esc_attr( self::colour( $status ) ),
			esc_attr( Status::icon( $status ) ),
			esc_html( self::badge_text( $status, $target ) )
		);
	}

	/**
	 * Filter plugin_action_links_{basename}: "Settings" and "Check my site" before the stock links.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		$base = admin_url( 'tools.php' );
		$mine = array(
			'qpucg_settings' => '<a href="' . esc_url(
				add_query_arg(
					array(
						'page' => Page::SLUG,
						'tab'  => 'settings',
					),
					$base
				)
			) . '">' . esc_html__( 'Settings', 'qasim-php-upgrade-check-guard' ) . '</a>',
			'qpucg_check'    => '<a href="' . esc_url( add_query_arg( 'page', Page::SLUG, $base ) ) . '">' . esc_html__( 'Check my site', 'qasim-php-upgrade-check-guard' ) . '</a>',
		);
		return array_merge( $mine, (array) $links );
	}

	/**
	 * Filter plugin_row_meta: adds the badge after a check exists.
	 *
	 * @param array  $meta Row meta links.
	 * @param string $file Plugin file, e.g. "foo/foo.php".
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		$summary = Status::summary();
		if ( null === $summary ) {
			return $meta;
		}
		$dir = dirname( (string) $file );
		$id  = 'plugin:' . ( '.' === $dir ? $file : $dir );
		if ( isset( $summary['components'][ $id ]['status'] ) ) {
			$meta[] = self::badge_html( (string) $summary['components'][ $id ]['status'], (string) $summary['target'] );
		}
		return $meta;
	}

	/**
	 * Filter site_status_tests: one direct test once a check exists.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function site_health_tests( $tests ) {
		if ( null !== Status::summary() ) {
			$tests['direct']['qpucg_php'] = array(
				'label' => __( 'Your plugins and PHP version', 'qasim-php-upgrade-check-guard' ),
				'test'  => array( __CLASS__, 'site_health_result' ),
			);
		}
		$tests['direct']['qpucg_recording'] = array(
			'label' => __( 'Qasim PHP Upgrade Check & Guard Smart results', 'qasim-php-upgrade-check-guard' ),
			'test'  => array( __CLASS__, 'site_health_recording' ),
		);
		return $tests;
	}

	/**
	 * Recording problems as one notice, on the Settings and Check tabs (DECISIONS D63). Prints nothing when all is well.
	 */
	public static function render_recording_notice() {
		$messages = Health::messages();
		if ( empty( $messages ) ) {
			return;
		}
		echo '<div class="notice notice-warning inline">';
		foreach ( $messages as $message ) {
			echo '<p>' . esc_html( $message ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The Site Health result for recording problems (DECISIONS D63).
	 *
	 * @return array
	 */
	public static function site_health_recording() {
		$messages = Health::messages();
		$ok       = empty( $messages );
		$text     = '';
		foreach ( $ok ? array( __( 'Qasim PHP Upgrade Check & Guard is recording normally, or Smart results is off.', 'qasim-php-upgrade-check-guard' ) ) : $messages as $message ) {
			$text .= '<p>' . esc_html( $message ) . '</p>';
		}
		return array(
			'label'       => $ok ? __( 'Qasim PHP Upgrade Check & Guard Smart results is working', 'qasim-php-upgrade-check-guard' ) : __( 'Qasim PHP Upgrade Check & Guard Smart results needs a look', 'qasim-php-upgrade-check-guard' ),
			'status'      => $ok ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'PHP upgrade', 'qasim-php-upgrade-check-guard' ),
				'color' => $ok ? 'green' : 'orange',
			),
			'description' => $text,
			'actions'     => '',
			'test'        => 'qpucg_recording',
		);
	}

	/**
	 * The Site Health result: the same answer sentence as the Check tab.
	 *
	 * @return array
	 */
	public static function site_health_result() {
		$summary = Status::summary();
		$answer  = null !== $summary ? Status::answer( $summary ) : array(
			'level'    => 'green',
			'sentence' => '',
		);
		$target  = null !== $summary ? (string) $summary['target'] : '';
		$map     = array(
			'green'  => array( 'good', 'green' ),
			'yellow' => array( 'recommended', 'orange' ),
			'orange' => array( 'recommended', 'orange' ),
			'red'    => array( 'critical', 'red' ),
		);
		$status  = isset( $map[ $answer['level'] ] ) ? $map[ $answer['level'] ] : $map['green'];

		return array(
			/* translators: %s: target PHP version. */
			'label'       => sprintf( __( 'Your plugins and PHP %s', 'qasim-php-upgrade-check-guard' ), $target ),
			'status'      => $status[0],
			'badge'       => array(
				'label' => __( 'PHP upgrade', 'qasim-php-upgrade-check-guard' ),
				'color' => $status[1],
			),
			'description' => '<p>' . esc_html( $answer['sentence'] ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . Page::SLUG ) ) . '">' . esc_html__( 'See the details', 'qasim-php-upgrade-check-guard' ) . '</a></p>',
			'test'        => 'qpucg_php',
		);
	}

	/**
	 * The one notice after activation. It is shown once: the flag goes as soon as an admin page prints it.
	 */
	public static function activation_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( 'qpucg_show_notice' );
		// Read-only check of the current page; no state change, so no nonce.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Page::SLUG === $page ) {
			return;
		}
		$link = '<a href="' . esc_url( admin_url( 'tools.php?page=' . Page::SLUG ) ) . '">' . esc_html__( 'Tools → PHP Upgrade', 'qasim-php-upgrade-check-guard' ) . '</a>';
		/* translators: %s: link to the Tools → PHP Upgrade page. */
		echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post( sprintf( esc_html__( 'Qasim PHP Upgrade Check & Guard is ready. Open %s to check your site for PHP upgrade problems.', 'qasim-php-upgrade-check-guard' ), $link ) ) . '</p></div>';
	}
}
