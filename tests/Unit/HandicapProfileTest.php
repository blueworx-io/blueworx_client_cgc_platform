<?php
/**
 * Handicap profile tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Domain\Handicap\HandicapProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Defaults, validation and round-tripping.
 */
final class HandicapProfileTest extends TestCase {

	public function test_cgc_defaults_match_the_brief(): void {
		$p = HandicapProfile::cgc();
		$this->assertSame( 28.0, $p->index_cap );
		$this->assertSame( 3, $p->min_rounds_12m );
		$this->assertSame( 'tier', $p->allowance_source );
		$this->assertSame( 'course_handicap', $p->allowance_applies_to );
		$this->assertSame( 'pct_then_diff', $p->method );
		$this->assertSame( 'half_up', $p->rounding );
		$this->assertSame( 'all_players_in_match', $p->low_reference );
		$this->assertSame( 'ph_zero', $p->pro_handling );
		$this->assertSame( 'match_start', $p->freeze_at );
		$this->assertSame( 0, $p->penalty_shots );
		$this->assertSame( 90.0, $p->tier_for_rounds( 12 )['pct'] ?? null );
		$this->assertSame( 75.0, $p->tier_for_rounds( 12 )['plus_pct'] ?? null );
		$this->assertSame( 75.0, $p->tier_for_rounds( 3 )['pct'] ?? null );
		$this->assertSame( 90.0, $p->tier_for_rounds( 3 )['plus_pct'] ?? null );
	}

	public function test_it_survives_a_trip_through_json(): void {
		$p    = HandicapProfile::cgc()->with(
			array(
				'penalty_shots' => 1,
				'index_cap'     => null,
				'freeze_at'     => '2027-04-27',
			)
		);
		$back = HandicapProfile::from_array( json_decode( (string) json_encode( $p->to_array() ), true ) );
		$this->assertEquals( $p, $back );
		$this->assertSame( $p->hash(), $back->hash() );
	}

	public function test_the_hash_changes_when_a_rule_changes(): void {
		$this->assertNotSame( HandicapProfile::cgc()->hash(), HandicapProfile::cgc()->with( array( 'rounding' => 'half_even' ) )->hash() );
	}

	public function test_the_handicap_method_setting_picks_a_preset(): void {
		$this->assertSame( 'pct_then_diff', HandicapProfile::from_setting( 'cgc' )->method );
		$this->assertSame( 'diff_then_pct', HandicapProfile::from_setting( 'whs' )->method );
	}

	/**
	 * Bad settings.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function bad_settings(): array {
		return array(
			'method'       => array( array( 'method' => 'banana' ) ),
			'rounding'     => array( array( 'rounding' => 'down' ) ),
			'cap'          => array( array( 'index_cap' => -1 ) ),
			'freeze'       => array( array( 'freeze_at' => 'tomorrow' ) ),
			'freeze date'  => array( array( 'freeze_at' => '2027-02-30' ) ),
			'pct too big'  => array( array( 'format_allowances' => array( 'fourball_mp' => 120 ) ) ),
			'penalty'      => array( array( 'penalty_shots' => -1 ) ),
			'tier missing' => array( array( 'tier_rules' => array( array( 'tier' => 1 ) ) ) ),
			'tier twice'   => array(
				array(
					'tier_rules' => array(
						array(
							'tier'       => 1,
							'min_rounds' => 12,
							'pct'        => 90,
							'plus_pct'   => 75,
						),
						array(
							'tier'       => 1,
							'min_rounds' => 3,
							'pct'        => 75,
							'plus_pct'   => 90,
						),
					),
				),
			),
		);
	}

	/**
	 * A bad value is rejected, not guessed.
	 *
	 * @dataProvider bad_settings
	 * @param array<string, mixed> $settings Bad settings.
	 */
	public function test_bad_settings_are_rejected( array $settings ): void {
		$this->expectException( InvalidArgumentException::class );
		HandicapProfile::from_array( $settings );
	}
}
