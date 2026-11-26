<?php
/**
 * Activation and upgrade routine.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

use CGC\Championship\Clock;

/**
 * Creates tables and roles. Runs on activation and whenever CGC_DB_VERSION changes.
 * Every step is idempotent, so running it twice is safe.
 */
final class Installer {

	public const DB_VERSION_OPTION = 'cgc_db_version';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Runs the install when the stored schema version differs from CGC_DB_VERSION.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== CGC_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or updates tables, roles and the default tenant.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::all_sql( $wpdb->prefix . 'cgc_', $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		Roles::sync();
		self::ensure_default_tenant();

		update_option( self::DB_VERSION_OPTION, CGC_DB_VERSION );
	}

	/**
	 * Makes sure tenant 1 exists, because every table defaults to tenant_id = 1.
	 */
	private static function ensure_default_tenant(): void {
		global $wpdb;

		$table  = Schema::table( 'tenants' );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", 'default' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null !== $exists ) {
			return;
		}

		$now = Clock::mysql();
		$wpdb->insert(
			$table,
			array(
				'id'         => 1,
				'tenant_id'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
				'name'       => 'Creator Golf Club',
				'slug'       => 'default',
			)
		);
	}
}
