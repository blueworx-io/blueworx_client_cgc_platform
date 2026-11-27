<?php
/**
 * Upgrade step tests, against a scripted database.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Infrastructure\Migrations;
use CGC\Championship\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The steps that run before dbDelta on a site upgraded from an earlier version.
 */
final class MigrationsTest extends TestCase {

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

	/**
	 * A tenant 1, match 5, player 101 duplicate group.
	 *
	 * @param array<int, string> $ids Row ids, newest first.
	 */
	private function with_duplicates( array $ids ): void {
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = array(
				'id'            => $id,
				'snapshot_json' => '{"strokes":' . $id . '}',
			);
		}
		$this->db->all_innodb()
			->when( '/SHOW TABLES/', 'wp_cgc_match_lineups' )
			->when(
				'/GROUP BY/',
				array(
					array(
						'tenant_id' => '1',
						'match_id'  => '5',
						'player_id' => '101',
					),
				)
			)
			->when( '/FOR UPDATE/', $rows );
	}

	public function test_a_myisam_table_is_converted_to_innodb(): void {
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
		$this->assertTrue( Migrations::convert_to_innodb() );
		$this->assertSame( array( 'ALTER TABLE `wp_cgc_match_lineups` ENGINE=InnoDB' ), $this->db->matching( '/^ALTER/' ) );
	}

	public function test_the_upgrade_fails_when_a_table_cannot_be_converted(): void {
		$this->db->when( '/^ALTER/', false, true )->when(
			'/information_schema/',
			array(
				array(
					'name'   => 'wp_cgc_match_lineups',
					'engine' => 'MyISAM',
				),
			)
		);
		$this->assertFalse( Migrations::convert_to_innodb() );
	}

	public function test_the_upgrade_fails_when_the_engines_cannot_be_read(): void {
		$this->db->when( '/information_schema/', null, true );
		$this->assertFalse( Migrations::convert_to_innodb() );
	}

	public function test_a_first_install_has_no_duplicates_to_fix(): void {
		$this->db->when( '/SHOW TABLES/', null );
		$this->assertTrue( Migrations::remove_duplicate_snapshots() );
		$this->assertSame( array(), $this->db->matching( '/DELETE|START TRANSACTION/' ) );
	}

	public function test_duplicate_snapshots_are_audited_then_removed_keeping_the_newest(): void {
		$this->with_duplicates( array( '9', '7', '4' ) );

		$this->assertTrue( Migrations::remove_duplicate_snapshots() );
		$deletes = $this->db->matching( '/^DELETE FROM/' );
		$this->assertCount( 2, $deletes );
		$this->assertStringContainsString( 'id=7', $deletes[0] );
		$this->assertStringContainsString( 'id=4', $deletes[1] );
		$this->assertCount( 2, $this->db->matching( '/^INSERT INTO wp_cgc_audit_log/' ), 'One audit row per removed row.' );
		$this->assertNotNull( $this->db->position( '/^COMMIT/' ) );
	}

	public function test_nothing_is_deleted_when_the_audit_row_cannot_be_written(): void {
		$this->db->when( '/^INSERT INTO wp_cgc_audit_log/', false, true );
		$this->with_duplicates( array( '9', '7' ) );

		$this->assertFalse( Migrations::remove_duplicate_snapshots() );
		$this->assertSame( array(), $this->db->matching( '/^DELETE FROM/' ) );
		$this->assertNotNull( $this->db->position( '/^ROLLBACK/' ) );
	}
}
