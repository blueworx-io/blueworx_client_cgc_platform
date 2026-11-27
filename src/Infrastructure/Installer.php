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
		if ( self::install() ) {
			return;
		}
		wp_die(
			esc_html__( 'CGC Championship could not set up its database tables, so it was not activated. Check that the database user can create tables, then try again.', 'cgc' ),
			'',
			array( 'back_link' => true )
		);
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
	 * Creates or updates tables, roles and the default tenant. The schema version is
	 * saved only when every table and the default tenant exist, so a failed install retries.
	 *
	 * @return bool Whether the install completed.
	 */
	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::all_sql( $wpdb->prefix . 'cgc_', $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		Roles::sync();

		if ( ! self::schema_is_complete() || ! self::ensure_default_tenant() ) {
			return false;
		}

		update_option( self::DB_VERSION_OPTION, CGC_DB_VERSION );
		return true;
	}

	/**
	 * Whether every table has all its columns and indexes (dbDelta fails quietly).
	 */
	private static function schema_is_complete(): bool {
		global $wpdb;

		foreach ( Schema::table_names() as $short ) {
			$table = Schema::table( $short );
			// Query the table instead of comparing names: some servers lowercase them.
			$suppress = $wpdb->suppress_errors( true );
			$columns  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->suppress_errors( $suppress );
			if ( ! is_array( $columns ) || array() === $columns ) {
				return false;
			}
			$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( array() !== array_diff( Schema::expected_columns( $short ), $columns )
				|| array() !== array_diff( Schema::expected_indexes( $short ), $indexes ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Makes sure tenant 1 exists, because every table defaults to tenant_id = 1.
	 *
	 * @return bool Whether the tenant exists afterwards.
	 */
	private static function ensure_default_tenant(): bool {
		global $wpdb;

		$table  = Schema::table( 'tenants' );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", 'default' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null !== $exists ) {
			return true;
		}

		$now      = Clock::mysql();
		$inserted = $wpdb->insert(
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

		return false !== $inserted;
	}
}
