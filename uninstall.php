<?php
/**
 * Runs when the plugin is deleted from WP Admin.
 *
 * @package Causeway\ClaudeBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/causeway-claude-bridge.php';

if ( ! ccb_requirements_met() ) {
	return;
}

ccb_load();
\Causeway\ClaudeBridge\Uninstaller::run();
