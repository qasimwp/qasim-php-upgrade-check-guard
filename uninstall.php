<?php
/**
 * Uninstall: drops tables, deletes options/transients, clears cron (spec §7.11).
 *
 * @package QasimPHPUpgradeCheckGuard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Install.php';

\Qpucg\Install::uninstall();
