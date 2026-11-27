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
	 * Starts a transaction.
	 *
	 * @return bool Whether it started.
	 */
	public static function begin(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'START TRANSACTION' );
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
