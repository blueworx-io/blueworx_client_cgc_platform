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
	public const FAILED_TRANSIENT  = 'cgc_install_failed';
	public const RETRY_SECONDS     = 300;

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
		if ( get_option( self::DB_VERSION_OPTION ) === CGC_DB_VERSION ) {
			return;
		}
		// After a failure, wait before trying again so a broken database is not hit on every page load.
		if ( false !== get_transient( self::FAILED_TRANSIENT ) ) {
			return;
		}
		if ( self::install() ) {
			delete_transient( self::FAILED_TRANSIENT );
			return;
		}
		set_transient( self::FAILED_TRANSIENT, 1, self::RETRY_SECONDS );
	}

	/**
	 * Tells administrators when the last database setup failed.
	 */
	public static function failure_notice(): void {
		if ( false === get_transient( self::FAILED_TRANSIENT ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'CGC Championship could not finish its database setup. Check that the database user can create and alter tables. It will try again in a few minutes.', 'cgc' )
			. '</p></div>';
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
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( array() !== array_diff( Schema::expected_columns( $short ), $columns )
				|| ! is_array( $indexes )
				|| array() !== Schema::index_mismatches( $indexes, $short ) ) {
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
