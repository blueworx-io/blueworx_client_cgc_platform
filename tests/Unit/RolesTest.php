<?php
/**
 * Roles tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Infrastructure\Roles;
use PHPUnit\Framework\TestCase;

/**
 * Checks the role and capability table from BUILD_BRIEF section 5.
 */
final class RolesTest extends TestCase {

	public function test_four_roles_are_defined(): void {
		$this->assertSame(
			array( 'cgc_super_admin', 'cgc_tournament_admin', 'cgc_group_manager', 'cgc_player' ),
			array_keys( Roles::definitions() )
		);
	}

	public function test_super_admin_has_every_capability(): void {
		$caps = Roles::definitions()['cgc_super_admin']['caps'];
		foreach ( Roles::all_caps() as $cap ) {
			$this->assertContains( $cap, $caps );
		}
		$this->assertContains( 'cgc_manage_platform', $caps );
	}

	public function test_tournament_admin_cannot_manage_the_platform(): void {
		$caps = Roles::definitions()['cgc_tournament_admin']['caps'];
		$this->assertNotContains( 'cgc_manage_platform', $caps );
		$this->assertContains( 'cgc_resolve_disputes', $caps );
		$this->assertContains( 'cgc_view_audit', $caps );
	}

	public function test_player_can_only_play(): void {
		$this->assertSame( array( 'read', 'cgc_play' ), Roles::definitions()['cgc_player']['caps'] );
	}
}
