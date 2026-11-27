<?php
/**
 * Database transactions and locks with their results checked.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

/**
 * Thin wrapper so callers never ignore a failed START TRANSACTION, COMMIT, ROLLBACK or lock.
 */
final class Db {

	/**
	 * Whether the site runs on the SQLite test database instead of MySQL or MariaDB.
	 */
	public static function is_sqlite(): bool {
		return defined( 'SQLITE_DB_DROPIN_VERSION' );
	}

	/**
	 * Whether the last query failed. wpdb returns an empty list for a failed SELECT, so the error text is the only sign.
	 *
	 * @phpstan-impure
	 */
	public static function last_query_failed(): bool {
		global $wpdb;
		return '' !== (string) $wpdb->last_error;
	}

	/**
	 * Starts a transaction, but only when the tables involved can roll back.
	 *
	 * MyISAM accepts START TRANSACTION and then keeps every write, so a later failure would leave
	 * half a change behind. A table with any other engine than InnoDB makes this return false, and
	 * so does a check that could not be run: when we cannot prove a rollback works, nothing is written.
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
	 * The storage engine of each table, keyed by full table name.
	 *
	 * @param array<int, string> $tables Short names of the tables.
	 * @return array<string, string>|false Engine per table that exists, or false when it could not be read.
	 */
	public static function engines( array $tables ): array|false {
		global $wpdb;

		$names        = array_map( array( Schema::class, 'table' ), $tables );
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$suppress     = $wpdb->suppress_errors( true );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)", $names ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the table count.
			ARRAY_A
		);
		$failed       = self::last_query_failed();
		$wpdb->suppress_errors( $suppress );
		if ( ! is_array( $rows ) || $failed ) {
			return false;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) ( $row['name'] ?? $row['NAME'] ?? '' ) ] = (string) ( $row['engine'] ?? $row['ENGINE'] ?? '' );
		}
		return $out;
	}

	/**
	 * Whether every table exists and uses an engine that can roll back. The SQLite test site
	 * always rolls back, so it passes. On MySQL or MariaDB a failed check counts as "no".
	 *
	 * @param array<int, string> $tables Short names of the tables.
	 */
	private static function can_roll_back( array $tables ): bool {
		if ( self::is_sqlite() ) {
			return true;
		}
		$engines = self::engines( $tables );
		if ( false === $engines ) {
			return false;
		}
		foreach ( $tables as $short ) {
			$engine = $engines[ Schema::table( $short ) ] ?? ( array_change_key_case( $engines )[ strtolower( Schema::table( $short ) ) ] ?? null );
			if ( null === $engine || 'innodb' !== strtolower( $engine ) ) {
				return false;
			}
		}
		return true;
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

	/**
	 * Takes a named lock, so only one request at a time works on the same thing (for example one
	 * match's lineup). Take it before START TRANSACTION and release it after COMMIT or ROLLBACK.
	 * Locks are per database connection, and are dropped if the connection ends.
	 *
	 * The SQLite test site has one writer at a time already, so it always succeeds there.
	 *
	 * @param string $name    Lock name (64 characters at most).
	 * @param int    $seconds How long to wait for another request to finish.
	 * @return bool Whether this request now holds the lock.
	 */
	public static function lock( string $name, int $seconds = 10 ): bool {
		global $wpdb;
		if ( self::is_sqlite() ) {
			return true;
		}
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, $seconds ) );
		return '1' === (string) $got;
	}

	/**
	 * Releases a lock taken with lock().
	 *
	 * @param string $name Lock name.
	 */
	public static function unlock( string $name ): void {
		global $wpdb;
		if ( self::is_sqlite() ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
	}
}
