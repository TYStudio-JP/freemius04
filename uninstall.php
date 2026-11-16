<?php
/**
 * Removes the plugin's tables and options when it is deleted.
 *
 * @package UpgradeWarden
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

\TYStudio\UpgradeWarden\Store\Tables::uninstall();
