<?php
/**
 * Lineup snapshot repository tests, against a scripted database.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Domain\Handicap\HandicapProfile;
use CGC\Championship\Infrastructure\LineupSnapshotRepository;
use CGC\Championship\Tests\Support\FakeWpdb;
use CGC\Championship\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Only the MySQL-specific safety is checked here (locks, failed reads, failed checks). The real
 * save and read-back runs in tests/e2e/handicap-m1.spec.js.
 */
final class LineupSnapshotRepositoryTest extends TestCase {

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
	 * Saves a two-player lineup for match 55.
	 *
	 * @return mixed
	 */
	private function save(): mixed {
		$players = array( Fixtures::player( 1, 4.0, 15, 'a' ), Fixtures::player( 2, 12.0, 15, 'b' ) );
		return LineupSnapshotRepository::snapshot_match( 55, $players, HandicapProfile::cgc(), 'fourball_mp' );
	}

	public function test_the_match_is_locked_before_the_transaction_and_released_after_the_commit(): void {
		$this->db->all_innodb()->when( '/GET_LOCK/', '1' );
		$result = $this->save();

		$this->assertIsArray( $result );
		$lock  = $this->db->position( "/GET_LOCK\\( 'cgc_lineup_1_55'/" );
		$begin = $this->db->position( '/START TRANSACTION/' );
		$done  = $this->db->position( '/^COMMIT/' );
		$free  = $this->db->position( '/RELEASE_LOCK/' );
		$this->assertNotNull( $lock );
		$this->assertTrue( $lock < $begin && $begin < $done && $done < $free, 'Lock, transaction, commit, release - in that order.' );
		$this->assertCount( 2, $this->db->matching( '/^INSERT INTO wp_cgc_match_lineups/' ) );
	}

	public function test_the_lock_is_released_after_a_rollback_too(): void {
		$this->db->all_innodb()->when( '/GET_LOCK/', '1' )->when( '/^INSERT INTO wp_cgc_match_lineups/', false, true );
		$result = $this->save();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$rollback = $this->db->position( '/^ROLLBACK/' );
		$free     = $this->db->position( '/RELEASE_LOCK/' );
		$this->assertNotNull( $rollback );
		$this->assertTrue( $rollback < $free );
	}

	public function test_nothing_is_saved_when_the_lock_cannot_be_taken(): void {
		$this->db->all_innodb()->when( '/GET_LOCK/', '0' );
		$result = $this->save();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cgc_busy', $result->get_error_code() );
		$this->assertNull( $this->db->position( '/START TRANSACTION/' ) );
		$this->assertSame( array(), $this->db->matching( '/^INSERT/' ) );
		$this->assertNull( $this->db->position( '/RELEASE_LOCK/' ), 'There is no lock to release.' );
	}

	public function test_nothing_is_saved_when_the_locked_read_fails(): void {
		$this->db->all_innodb()->when( '/GET_LOCK/', '1' )->when( '/FOR UPDATE/', null, true );
		$result = $this->save();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cgc_db_error', $result->get_error_code() );
		$this->assertSame( array(), $this->db->matching( '/^INSERT/' ) );
		$this->assertNotNull( $this->db->position( '/^ROLLBACK/' ) );
		$this->assertNotNull( $this->db->position( '/RELEASE_LOCK/' ) );
	}

	public function test_nothing_is_saved_when_the_engine_check_fails(): void {
		$this->db->when( '/information_schema/', null, true )->when( '/GET_LOCK/', '1' );
		$result = $this->save();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( $this->db->position( '/START TRANSACTION/' ) );
		$this->assertSame( array(), $this->db->matching( '/^INSERT/' ) );
		$this->assertNotNull( $this->db->position( '/RELEASE_LOCK/' ), 'The lock taken first is let go.' );
	}
}
