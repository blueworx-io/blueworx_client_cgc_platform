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
}
