<?php
/**
 * Plugin bootstrap.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship;

use CGC\Championship\Infrastructure\Installer;

/**
 * Wires the plugin into WordPress. Keep this class thin.
 */
final class Plugin {

	/**
	 * Runs on plugins_loaded. Upgrades the database when the schema version changed.
	 */
	public static function boot(): void {
		Installer::maybe_upgrade();
		add_action( 'admin_notices', array( Installer::class, 'failure_notice' ) );
	}
}
