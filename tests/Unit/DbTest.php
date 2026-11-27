<?php
/**
 * Transaction and lock helper tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Infrastructure\Db;
use CGC\Championship\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/SqliteDbStub.php';

/**
 * Db must never start a write it cannot undo, and never carry on after a failed check or lock.
 */
final class DbTest extends TestCase {

	/**
	 * Scripted database.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $db;

	protected function setUp(): void {
		// Db keeps its guard count in static properties, so one test cannot leave a lock open for the next.
		foreach ( array(
			'guards'     => 0,
			'open'       => false,
			'lock_error' => false,
			'connection' => null,
		) as $name => $value ) {
			( new \ReflectionProperty( Db::class, $name ) )->setValue( null, $value );
		}
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function test_a_transaction_starts_when_every_table_is_innodb(): void {
		$this->db->all_innodb();
		$this->assertTrue( Db::begin( array( 'match_lineups', 'audit_log' ) ) );
		$this->assertNotNull( $this->db->position( '/START TRANSACTION/' ) );
	}

	public function test_a_myisam_table_refuses_the_transaction(): void {
		$this->db->when(
			'/information_schema/',
			array(
				array(
					'name'   => 'wp_cgc_match_lineups',
					'engine' => 'MyISAM',
				),
				array(
					'name'   => 'wp_cgc_audit_log',
					'engine' => 'InnoDB',
				),
			)
		);
		$this->assertFalse( Db::begin( array( 'match_lineups', 'audit_log' ) ) );
		$this->assertNull( $this->db->position( '/START TRANSACTION/' ) );
	}

	public function test_a_failed_engine_check_stops_the_write(): void {
		$this->db->when( '/information_schema/', null, true );
		$this->assertFalse( Db::begin( array( 'match_lineups' ) ) );
		$this->assertNull( $this->db->position( '/START TRANSACTION/' ), 'No transaction when rollback cannot be proven.' );
	}

	public function test_a_missing_table_stops_the_write(): void {
		$this->db->all_innodb( array( 'audit_log' ) );
		$this->assertFalse( Db::begin( array( 'match_lineups', 'audit_log' ) ) );
	}

	public function test_a_lock_is_held_only_when_the_database_says_so(): void {
		$this->db->when( '/GET_LOCK/', '1' );
		$this->assertTrue( Db::lock( 'x' ) );

		$busy = new FakeWpdb();
		$busy->when( '/GET_LOCK/', '0' );
		$GLOBALS['wpdb'] = $busy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertFalse( Db::lock( 'x' ) );

		$broken = new FakeWpdb();
		$broken->when( '/GET_LOCK/', null, true );
		$GLOBALS['wpdb'] = $broken; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertFalse( Db::lock( 'x' ) );
	}

	/**
	 * A MyISAM answer for the engine check.
	 *
	 * @param FakeWpdb $db The scripted database.
	 */
	private function myisam( FakeWpdb $db ): void {
		$db->when(
			'/information_schema/',
			array(
				array(
					'name'   => 'wp_cgc_match_lineups',
					'engine' => 'MyISAM',
				),
			)
		);
	}

	public function test_a_refused_lock_query_is_told_apart_from_a_busy_lock(): void {
		$busy = new FakeWpdb();
		$busy->when( '/GET_LOCK/', '0' );
		$GLOBALS['wpdb'] = $busy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertFalse( Db::lock( 'x' ) );
		$this->assertFalse( Db::lock_unavailable(), 'A wait that ran out is just busy.' );

		$cluster = new FakeWpdb();
		$cluster->when( '/GET_LOCK/', null, true );
		$GLOBALS['wpdb'] = $cluster; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertFalse( Db::lock( 'x' ) );
		$this->assertTrue( Db::lock_unavailable(), 'An error from GET_LOCK (for example on Galera) is not a wait.' );

		$null = new FakeWpdb();
		$null->when( '/GET_LOCK/', null );
		$GLOBALS['wpdb'] = $null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertFalse( Db::lock( 'x' ) );
		$this->assertTrue( Db::lock_unavailable(), 'NULL means the lock query could not run.' );

		$GLOBALS['wpdb'] = $busy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Db::lock( 'x' );
		$this->assertFalse( Db::lock_unavailable(), 'The answer is for the last attempt only.' );
	}

	public function test_the_sqlite_marker_alone_does_not_skip_the_checks(): void {
		// The drop-in defines this constant even when WordPress is set to use MySQL.
		if ( ! defined( 'SQLITE_DB_DROPIN_VERSION' ) ) {
			define( 'SQLITE_DB_DROPIN_VERSION', '1.8.0' );
		}
		$this->myisam( $this->db );
		$this->db->when( '/GET_LOCK/', '0' );

		$this->assertFalse( Db::is_sqlite() );
		$this->assertFalse( Db::begin( array( 'match_lineups' ) ), 'A MyISAM table is still refused on a MySQL site.' );
		$this->assertFalse( Db::lock( 'x' ), 'The lock is still really taken on a MySQL site.' );
	}

	public function test_the_sqlite_driver_skips_the_mysql_only_checks(): void {
		$sqlite = new \WP_SQLite_DB();
		$this->myisam( $sqlite );
		$GLOBALS['wpdb'] = $sqlite; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertTrue( Db::is_sqlite() );
		$this->assertTrue( Db::begin( array( 'match_lineups' ) ) );
		$this->assertTrue( Db::lock( 'x' ) );
		$this->assertNull( $sqlite->position( '/GET_LOCK|information_schema/' ) );
	}

	public function test_lock_names_are_private_to_the_site(): void {
		$this->db->when( '/GET_LOCK/', '1' );
		Db::lock( 'cgc_lineup_1_55' );

		$other         = new FakeWpdb();
		$other->prefix = 'wp2_';
		$other->when( '/GET_LOCK/', '1' );
		$GLOBALS['wpdb'] = $other; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Db::lock( 'cgc_lineup_1_55' );

		$third         = new FakeWpdb();
		$third->dbname = 'site_two';
		$third->when( '/GET_LOCK/', '1' );
		$GLOBALS['wpdb'] = $third; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Db::lock( 'cgc_lineup_1_55' );

		$names = array(
			$this->db->matching( '/GET_LOCK/' )[0],
			$other->matching( '/GET_LOCK/' )[0],
			$third->matching( '/GET_LOCK/' )[0],
		);
		$this->assertCount( 3, array_unique( $names ), 'Another prefix or database gets another lock.' );
		$this->assertStringContainsString( 'cgc_lineup_1_55', $names[0] );
		$this->assertLessThanOrEqual( 64, strlen( $this->lock_name( $names[0] ) ) );
	}

	public function test_unlock_uses_the_same_name_as_lock(): void {
		$this->db->when( '/GET_LOCK/', '1' );
		Db::lock( 'abc' );
		Db::unlock( 'abc' );
		$locked = $this->lock_name( $this->db->matching( '/GET_LOCK/' )[0] );
		$freed  = $this->lock_name( $this->db->matching( '/RELEASE_LOCK/' )[0] );
		$this->assertSame( $locked, $freed );
	}

	public function test_a_commit_on_a_new_connection_is_a_failure(): void {
		// WordPress reconnected after the transaction started.
		$this->db->all_innodb()->connection_ids( array( '10', '10', '11' ) );
		$this->assertTrue( Db::begin( array( 'match_lineups', 'audit_log' ) ) );
		$this->assertFalse( Db::commit() );
		$this->assertNotNull( $this->db->position( '/^ROLLBACK/' ) );
		$this->assertSame( 5, $this->db->reconnect_retries );
	}

	public function test_a_commit_that_was_run_again_on_a_new_connection_is_a_failure(): void {
		// The connection is the same before COMMIT and different after it: COMMIT ran on a fresh connection.
		$this->db->all_innodb()->connection_ids( array( '10', '10', '10', '11' ) );
		$this->assertTrue( Db::begin( array( 'match_lineups', 'audit_log' ) ) );
		$this->assertFalse( Db::commit() );
	}

	public function test_a_transaction_that_started_on_a_new_connection_is_refused(): void {
		// The connection changed while START TRANSACTION ran, so the transaction is not on the connection we know.
		$this->db->all_innodb()->connection_ids( array( '10', '11' ) );
		$this->assertFalse( Db::begin( array( 'match_lineups', 'audit_log' ) ) );
		$this->assertSame( 5, $this->db->reconnect_retries, 'WordPress reconnecting is put back.' );
	}

	public function test_wordpress_does_not_reconnect_while_a_lock_or_transaction_is_open(): void {
		$seen = array();
		$db   = $this->db;
		$this->db->all_innodb()->when( '/GET_LOCK/', '1' )->when(
			'/START TRANSACTION|^COMMIT|RELEASE_LOCK/',
			static function ( string $sql ) use ( &$seen, $db ): int {
				$seen[ $sql ] = $db->reconnect_retries;
				return 1;
			}
		);

		$this->assertTrue( Db::lock( 'x' ) );
		$this->assertSame( 0, $this->db->reconnect_retries );
		$this->assertTrue( Db::begin( array( 'match_lineups' ) ) );
		$this->assertTrue( Db::commit() );
		$this->assertSame( 0, $this->db->reconnect_retries, 'Still off while the lock is held.' );
		Db::unlock( 'x' );
		$this->assertSame( 5, $this->db->reconnect_retries, 'Back to what WordPress had.' );
		$this->assertSame( 0, $seen['START TRANSACTION'] );
		$this->assertSame( 0, $seen['COMMIT'] );
	}

	public function test_reconnecting_comes_back_after_a_rollback_and_after_a_refused_lock(): void {
		$this->db->all_innodb();
		$this->assertTrue( Db::begin( array( 'match_lineups' ) ) );
		Db::rollback();
		$this->assertSame( 5, $this->db->reconnect_retries );

		$this->db->when( '/GET_LOCK/', '0' );
		$this->assertFalse( Db::lock( 'x' ) );
		$this->assertSame( 5, $this->db->reconnect_retries );

		Db::rollback(); // A second rollback with nothing open changes nothing.
		$this->assertSame( 5, $this->db->reconnect_retries );
	}
	/**
	 * The lock name inside a GET_LOCK or RELEASE_LOCK statement.
	 *
	 * @param string $sql Statement.
	 */
	private function lock_name( string $sql ): string {
		preg_match( "/LOCK\( '([^']*)'/", $sql, $m );
		return $m[1] ?? '';
	}
}
