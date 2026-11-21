<?php
/**
 * The Help tab. The only place with a review request or a link to the author's other plugin.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered from Page::render(), which checks the capability first. No external requests: links only.
 */
class HelpTab {

	/**
	 * A link that opens in a new tab.
	 *
	 * @param string $url  Address.
	 * @param string $text Link text.
	 */
	private static function link( $url, $text ) {
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Renders the tab: help on the left, the author on the right.
	 */
	public static function render() {
		echo '<div class="qpucg-help-grid">';
		self::render_help();
		self::render_author();
		echo '</div>';
	}

	/**
	 * Left card: what Qpucg does, quick start, questions, links.
	 */
	private static function render_help() {
		echo '<div class="qpucg-card qpucg-help">';

		echo '<h2>' . esc_html__( 'What Qasim PHP Upgrade Check & Guard does', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		echo '<p>' . esc_html__( 'Qasim PHP Upgrade Check & Guard reads the code of your plugins and themes and tells you what would break on a newer PHP version. It can also learn which plugins your site really uses, and tell you if a plugin starts crashing after your host changes PHP.', 'qasim-php-upgrade-check-guard' ) . '</p>';

		echo '<h2>' . esc_html__( 'Quick start', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Click Check.', 'qasim-php-upgrade-check-guard' ) . '</li>';
		echo '<li>' . esc_html__( 'Fix "Do this first".', 'qasim-php-upgrade-check-guard' ) . '</li>';
		echo '<li>' . esc_html__( 'Turn on Upgrade guard before your host changes PHP.', 'qasim-php-upgrade-check-guard' ) . '</li>';
		echo '</ol>';

		echo '<h2>' . esc_html__( 'Questions', 'qasim-php-upgrade-check-guard' ) . '</h2>';
		echo '<h3>' . esc_html__( 'Does this plugin send my data anywhere?', 'qasim-php-upgrade-check-guard' ) . '</h3>';
		echo '<p>' . esc_html__( "No. Everything stays in your database. The only exception is the crash email, if you turn it on: it goes to your site's admin address.", 'qasim-php-upgrade-check-guard' ) . '</p>';
		echo '<h3>' . esc_html__( 'Will it slow my site?', 'qasim-php-upgrade-check-guard' ) . '</h3>';
		echo '<p>' . esc_html__( 'Qasim PHP Upgrade Check & Guard is built to stay light. By default it looks at only some page views and stores very little.', 'qasim-php-upgrade-check-guard' ) . '</p>';
		echo '<h3>' . esc_html__( "What does 'not seen running' mean?", 'qasim-php-upgrade-check-guard' ) . '</h3>';
		echo '<p>' . esc_html__( "Qasim PHP Upgrade Check & Guard didn't see that code run on the page views it looked at, but it may still run at other times.", 'qasim-php-upgrade-check-guard' ) . '</p>';

		echo '<p class="qpucg-help__links">';
		self::link( Links::SUPPORT, __( 'Get help', 'qasim-php-upgrade-check-guard' ) );
		echo ' &nbsp;·&nbsp; ';
		self::link( Links::REVIEWS, __( 'Leave a review', 'qasim-php-upgrade-check-guard' ) );
		echo '</p>';

		echo '</div>';
	}

	/**
	 * Right card: the author. No image from outside: the circle is drawn with CSS.
	 */
	private static function render_author() {
		echo '<aside class="qpucg-card qpucg-author" aria-label="' . esc_attr__( 'About the author', 'qasim-php-upgrade-check-guard' ) . '">';
		echo '<div class="qpucg-author__head">';
		echo '<span class="qpucg-author__avatar" aria-hidden="true">' . esc_html_x( 'MQ', 'initials of the author', 'qasim-php-upgrade-check-guard' ) . '</span>';
		echo '<div><p class="qpucg-author__name">' . esc_html__( 'Muhammad Qasim', 'qasim-php-upgrade-check-guard' ) . '</p>';
		echo '<p class="qpucg-muted qpucg-author__role">' . esc_html__( 'WordPress developer', 'qasim-php-upgrade-check-guard' ) . '</p></div>';
		echo '</div>';
		echo '<p>' . esc_html__( "I've been building WordPress plugins, themes and WooCommerce integrations for over 10 years. I made Qasim PHP Upgrade Check & Guard because a PHP upgrade shouldn't be a guessing game for site owners.", 'qasim-php-upgrade-check-guard' ) . '</p>';
		echo '<p class="qpucg-author__also">' . esc_html__( 'Also by Muhammad:', 'qasim-php-upgrade-check-guard' ) . ' ';
		self::link( Links::CHECKOUT_LAB, __( 'Checkout Test Lab for WooCommerce', 'qasim-php-upgrade-check-guard' ) );
		echo ' ' . esc_html__( '- place safe test orders and see why a shipping or payment method is missing.', 'qasim-php-upgrade-check-guard' ) . '</p>';
		/* translators: %s: plugin version. */
		echo '<p class="qpucg-muted qpucg-author__version">' . esc_html( sprintf( __( 'Qasim PHP Upgrade Check & Guard version %s', 'qasim-php-upgrade-check-guard' ), QPUCG_VERSION ) ) . '</p>';
		echo '</aside>';
	}
}
