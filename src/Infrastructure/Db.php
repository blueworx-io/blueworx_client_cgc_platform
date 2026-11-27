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
	 * Connection id when the open transaction started (null when there is none, or on SQLite).
	 *
	 * @var string|null
	 */
	private static ?string $connection = null;

	/**
	 * How many open guards (a lock, a transaction) are holding WordPress's reconnecting switched off.
	 *
	 * @var int
	 */
	private static int $guards = 0;

	/**
	 * WordPress's own reconnect setting, put back when the last guard ends.
	 *
	 * @var mixed
	 */
	private static mixed $saved_retries = null;

	/**
	 * Whether a transaction is open.
	 *
	 * @var bool
	 */
	private static bool $open = false;

	/**
	 * The server's message when the last lock() failed because the lock query itself failed (empty otherwise).
	 *
	 * @var string
	 */
	private static string $lock_error = '';

	/**
	 * Whether WordPress is really talking to SQLite (the test database) instead of MySQL or MariaDB.
	 * The SQLite drop-in file defines its marker constant even on a site set to MySQL, so this asks
	 * which database class is in use.
	 */
	public static function is_sqlite(): bool {
		global $wpdb;
		return $wpdb instanceof \WP_SQLite_DB;
	}

	/**
	 * The database connection's id, or null on SQLite or when it cannot be read.
	 *
	 * WordPress reconnects and retries a statement when the server has gone away. A retried
	 * statement runs on a new connection, outside the open transaction and without its locks.
	 *
	 * @phpstan-impure
	 */
	public static function connection_id(): ?string {
		global $wpdb;
		if ( self::is_sqlite() ) {
			return null;
		}
		$id = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		return null === $id ? null : (string) $id;
	}

	/**
	 * Whether we are still on the connection the open transaction started on. Always true on SQLite.
	 *
	 * @phpstan-impure
	 */
	public static function connection_unchanged(): bool {
		if ( self::is_sqlite() ) {
			return true;
		}
		return null !== self::$connection && self::connection_id() === self::$connection;
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
		if ( ! self::enter() ) {
			return false;
		}
		if ( array() !== $tables && ! self::can_roll_back( $tables ) ) {
			self::leave();
			return false;
		}
		// The same connection before and after START TRANSACTION, or the transaction is not ours.
		$before = self::connection_id();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			self::leave();
			return false;
		}
		$after = self::connection_id();
		if ( ! self::is_sqlite() && ( null === $after || $after !== $before ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::leave();
			return false;
		}
		self::$connection = $after;
		self::$open       = true;
		return true;
	}

	/**
	 * Stops WordPress from silently reconnecting while a lock or transaction is open.
	 *
	 * When the server drops us, wpdb::query() reconnects and runs the statement again on the new
	 * connection. That connection has no transaction and no locks, so the statement would be saved
	 * on its own, half way through a change. With reconnecting off, the lost connection ends the
	 * request (WordPress shows its database error page), and the server undoes the open transaction.
	 *
	 * Guards nest: the setting comes back when the last one ends. Nothing to do on SQLite. If the
	 * setting cannot be switched off, the answer is false and the caller must not write.
	 */
	private static function enter(): bool {
		global $wpdb;
		if ( self::is_sqlite() ) {
			return true;
		}
		if ( 0 === self::$guards ) {
			try {
				$property            = new \ReflectionProperty( $wpdb, 'reconnect_retries' ); // Protected in wpdb, so reflection.
				self::$saved_retries = $property->getValue( $wpdb );
				$property->setValue( $wpdb, 0 );
			} catch ( \ReflectionException ) {
				return false;
			}
		}
		++self::$guards;
		return true;
	}

	/**
	 * Ends one guard started by enter(), restoring WordPress's reconnecting after the last one.
	 */
	private static function leave(): void {
		global $wpdb;
		if ( self::is_sqlite() || self::$guards < 1 ) {
			return;
		}
		if ( 0 === --self::$guards ) {
			try {
				( new \ReflectionProperty( $wpdb, 'reconnect_retries' ) )->setValue( $wpdb, self::$saved_retries );
			} catch ( \ReflectionException ) {
				return; // It was set a moment ago, so this cannot happen.
			}
		}
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
		// A new connection means the transaction is already gone, and so are the earlier writes. Say so,
		// both before COMMIT (writes lost) and after it (COMMIT itself run again on a fresh connection).
		if ( ! self::connection_unchanged() || false === $wpdb->query( 'COMMIT' ) || ! self::connection_unchanged() ) {
			self::rollback();
			return false;
		}
		self::finish();
		return true;
	}

	/**
	 * Undoes the open transaction.
	 */
	public static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		self::finish();
	}

	/**
	 * Marks the open transaction as over and ends its guard.
	 */
	private static function finish(): void {
		if ( self::$open ) {
			self::$open       = false;
			self::$connection = null;
			self::leave();
		}
	}

	/**
	 * Takes a named lock, so only one request at a time works on the same thing (for example one
	 * match's lineup). Take it before START TRANSACTION and release it after COMMIT or ROLLBACK.
	 * Locks are per database connection, and are dropped if the connection ends.
	 *
	 * The SQLite test site has one writer at a time already, so it always succeeds there.
	 *
	 * @param string $name    Lock name, up to 50 characters. The site's database and prefix are added.
	 * @param int    $seconds How long to wait for another request to finish.
	 * @return bool Whether this request now holds the lock.
	 */
	public static function lock( string $name, int $seconds = 10 ): bool {
		global $wpdb;
		if ( self::is_sqlite() ) {
			return true;
		}
		self::$lock_error = '';
		// A lock belongs to one connection, so no silent reconnecting while we hold it.
		if ( ! self::enter() ) {
			self::$lock_error = 'Could not switch off reconnecting';
			return false;
		}
		// Quiet, because a server that refuses locks would otherwise print its error on the page.
		$suppress = $wpdb->suppress_errors( true );
		$got      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::lock_name( $name ), $seconds ) );
		$failed   = self::last_query_failed();
		$reason   = $failed ? (string) $wpdb->last_error : '';
		$wpdb->suppress_errors( $suppress );
		self::$lock_error = ( $failed || null === $got ) ? ( '' !== $reason ? $reason : 'GET_LOCK gave no answer' ) : '';
		if ( '1' !== (string) $got ) {
			self::leave();
			return false;
		}
		return true;
	}

	/**
	 * Why the last lock() failed, when the lock query itself failed: the server's own message. Empty
	 * when the lock was taken or another request held it (GET_LOCK answers 0 after the wait). The
	 * cause can be passing (a dropped connection) or lasting (a Galera or Percona cluster refuses
	 * GET_LOCK), so callers must not claim which.
	 */
	public static function lock_failure(): string {
		return self::$lock_error;
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
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::lock_name( $name ) ) );
		self::leave();
	}

	/**
	 * The name the server sees. MySQL lock names are shared by every database on the server, so the
	 * database name and table prefix go in: two sites never wait for each other.
	 *
	 * @param string $name Lock name.
	 */
	private static function lock_name( string $name ): string {
		global $wpdb;
		$name = substr( md5( $wpdb->dbname . '|' . $wpdb->prefix ), 0, 12 ) . '_' . $name;
		return strlen( $name ) <= 64 ? $name : substr( $name, 0, 12 ) . '_' . md5( $name ); // 12 + 1 + 32 characters.
	}
}
