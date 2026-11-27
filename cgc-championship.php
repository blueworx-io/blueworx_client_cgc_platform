<?php
/**
 * Plugin Name: CGC Championship
 * Description: Creator Golf Club championship platform: entry, groups, brackets, scoring and finals (demo build).
 * Version: 0.1.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: BlueWorx
 * Text Domain: cgc
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mirrors the Version header above. Used for asset cache-busting.
const CGC_VERSION = '0.1.1';

// Changes only when the database schema changes. Triggers dbDelta and data migrations.
const CGC_DB_VERSION = '2';

const CGC_PLUGIN_FILE = __FILE__;

// Demo-only code (time travel, simulators, seed data) runs only when this is true.
// Set it to true in wp-config.php on the demo site.
if ( ! defined( 'CGC_DEMO_MODE' ) ) {
	define( 'CGC_DEMO_MODE', false );
}

$cgc_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $cgc_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'CGC Championship: run "composer install" in the plugin folder.', 'cgc' )
				. '</p></div>';
		}
	);
	return;
}
require_once $cgc_autoload;

// Action Scheduler is loaded from the Composer copy unless another plugin already loaded it.
$cgc_action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( is_readable( $cgc_action_scheduler ) ) {
	require_once $cgc_action_scheduler;
}

register_activation_hook( __FILE__, array( \CGC\Championship\Infrastructure\Installer::class, 'activate' ) );

add_action( 'plugins_loaded', array( \CGC\Championship\Plugin::class, 'boot' ) );
