<?php
/**
 * Database transactions with their results checked.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

/**
 * Thin wrapper so callers never ignore a failed START TRANSACTION, COMMIT or ROLLBACK.
 */
final class Db {

	/**
	 * Starts a transaction, but only when the tables involved can roll back.
	 *
	 * MyISAM accepts START TRANSACTION and then keeps every write, so a later failure would leave
	 * half a change behind. A table with any other engine than InnoDB makes this return false.
	 *
	 * @param array<int, string> $tables Short names of the tables the transaction writes to.
	 * @return bool Whether it started.
	 */
	public static function begin( array $tables = array() ): bool {
		global $wpdb;
		if ( array() !== $tables && ! self::can_roll_back( $tables ) ) {
			return false;
		}
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Whether none of the tables uses an engine that cannot roll back. A database without
	 * information_schema (the SQLite test site) always rolls back, so it passes.
	 *
	 * @param array<int, string> $tables Short names of the tables.
	 */
	private static function can_roll_back( array $tables ): bool {
		global $wpdb;

		$names        = array_map( array( Schema::class, 'table' ), $tables );
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$suppress     = $wpdb->suppress_errors( true );
		$bad          = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders) AND ENGINE <> 'InnoDB'", $names ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the table count.
		);
		$wpdb->suppress_errors( $suppress );
		return 0 === (int) $bad;
	}

	/**
	 * Commits the open transaction.
	 *
	 * @return bool Whether the commit worked. When it did not, the changes are rolled back.
	 */
	public static function commit(): bool {
		global $wpdb;
		if ( false !== $wpdb->query( 'COMMIT' ) ) {
			return true;
		}
		self::rollback();
		return false;
	}

	/**
	 * Undoes the open transaction.
	 */
	public static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}
}
