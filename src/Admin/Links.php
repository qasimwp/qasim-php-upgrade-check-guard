<?php
/**
 * Outside links, in one place, so the docs domain can be added later with one edit.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link addresses. Nothing here is fetched: they are only printed as links.
 */
class Links {

	const SUPPORT = 'https://wordpress.org/support/plugin/qasim-php-upgrade-check-guard/';
	const REVIEWS = 'https://wordpress.org/support/plugin/qasim-php-upgrade-check-guard/reviews/';

	const CHECKOUT_LAB = 'https://wordpress.org/plugins/qasim-checkout-test-lab-for-woocommerce/';
}
