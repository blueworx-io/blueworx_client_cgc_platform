<?php
/**
 * Handicap engine tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Domain\Handicap\HandicapEngine;
use CGC\Championship\Domain\Handicap\HandicapProfile;
use CGC\Championship\Domain\Handicap\PlayerHandicap;
use CGC\Championship\Domain\Handicap\PlayerHandicapInput;
use CGC\Championship\Domain\Handicap\StrokeAllocator;
use CGC\Championship\Tests\Support\Fixtures;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The worked example and check cases from BUILD_BRIEF section 3.
 */
final class HandicapEngineTest extends TestCase {

	/**
	 * Players A to D of the worked example. A and C are Tier 1 (12+ rounds), B and D Tier 2.
	 *
	 * @return list<PlayerHandicapInput>
	 */
	private function worked_example(): array {
		return array(
			Fixtures::player( 1, 4.2, 14 ),
			Fixtures::player( 2, 12.8, 6 ),
			Fixtures::player( 3, 18.5, 20 ),
			Fixtures::player( 4, 31.0, 8 ),
		);
	}

	/**
	 * Strokes of each result, in order.
	 *
	 * @param array<int, PlayerHandicap> $results Results.
	 * @return list<int>
	 */
	private function strokes( array $results ): array {
		return array_map( static fn( $r ): int => $r->strokes, $results );
	}

	public function test_cgc_worked_example_gives_0_7_15_20(): void {
		$results = HandicapEngine::calculate( $this->worked_example(), HandicapProfile::cgc() );

		$this->assertSame( array( 0, 7, 15, 20 ), $this->strokes( $results ) );
		$this->assertSame( array( 3, 10, 18, 23 ), array_map( static fn( $r ): int => $r->playing_handicap, $results ) );
		$this->assertEqualsWithDelta( 3.846, $results[0]->course_handicap_raw, 0.001 );
		$this->assertEqualsWithDelta( 13.359, $results[1]->course_handicap_raw, 0.001 );
		$this->assertEqualsWithDelta( 19.665, $results[2]->course_handicap_raw, 0.001 );
		$this->assertEqualsWithDelta( 30.173, $results[3]->course_handicap_raw, 0.001 );
		$this->assertSame( 28.0, $results[3]->index_used, 'Index 31.0 is capped to 28.0.' );
		$this->assertSame( array( 1, 2, 1, 2 ), array_map( static fn( $r ): ?int => $r->tier, $results ) );
	}

	public function test_allowance_on_index_gives_0_7_15_19(): void {
		$profile = HandicapProfile::cgc()->with( array( 'allowance_applies_to' => HandicapProfile::APPLIES_INDEX ) );
		$results = HandicapEngine::calculate( $this->worked_example(), $profile );

		$this->assertSame( array( 0, 7, 15, 19 ), $this->strokes( $results ) );
	}

	public function test_plain_whs_2024_gives_0_8_14_22(): void {
		$players = array(
			Fixtures::player( 1, 4.2 ),
			Fixtures::player( 2, 12.8 ),
			Fixtures::player( 3, 18.5 ),
			Fixtures::player( 4, 26.0 ),
		);
		$results = HandicapEngine::calculate( $players, HandicapProfile::whs_2024() );

		$this->assertSame( array( 0, 8, 14, 22 ), $this->strokes( $results ) );
		$this->assertSame( 90.0, $results[0]->allowance_pct );
	}

	public function test_plain_whs_old_method_gives_0_9_15_22(): void {
		$players = array(
			Fixtures::player( 1, 4.2 ),
			Fixtures::player( 2, 12.8 ),
			Fixtures::player( 3, 18.5 ),
			Fixtures::player( 4, 26.0 ),
		);
		$results = HandicapEngine::calculate( $players, HandicapProfile::whs_old() );

		$this->assertSame( array( 0, 9, 15, 22 ), $this->strokes( $results ) );
	}

	public function test_plain_whs_does_not_cap_the_index(): void {
		$results = HandicapEngine::calculate(
			array( Fixtures::player( 1, 4.2 ), Fixtures::player( 4, 31.0 ) ),
			HandicapProfile::whs_2024()
		);
		$this->assertSame( 26, $results[1]->strokes, 'Plain WHS gives the worked example player D 26 strokes.' );
		$this->assertSame( 31.0, $results[1]->index_used );
	}

	public function test_course_handicap_is_not_capped(): void {
		$tee     = Fixtures::tee( 155, 78.0, 72 );
		$player  = new PlayerHandicapInput( 1, 54.0, 20, $tee );
		$results = HandicapEngine::calculate( array( $player ), HandicapProfile::whs_2024(), HandicapEngine::FORMAT_INDIVIDUAL );
		$this->assertGreaterThan( 54, $results[0]->course_handicap );
	}

	public function test_plus_four_becomes_plus_three_for_tier_one(): void {
		// Slope 113 and rating equal to par: course handicap equals the index.
		$tee    = Fixtures::tee( 113, 72.0, 72 );
		$player = new PlayerHandicapInput( 1, -4.0, 14, $tee );
		$result = HandicapEngine::calculate( array( $player ), HandicapProfile::cgc(), HandicapEngine::FORMAT_INDIVIDUAL )[0];

		$this->assertSame( 75.0, $result->allowance_pct, 'Plus handicaps reverse the percentages.' );
		$this->assertSame( -3, $result->playing_handicap );
		$this->assertSame( -3, $result->strokes );
	}

	public function test_plus_four_becomes_plus_four_for_tier_two(): void {
		$tee    = Fixtures::tee( 113, 72.0, 72 );
		$player = new PlayerHandicapInput( 1, -4.0, 6, $tee );
		$result = HandicapEngine::calculate( array( $player ), HandicapProfile::cgc(), HandicapEngine::FORMAT_INDIVIDUAL )[0];

		$this->assertSame( 90.0, $result->allowance_pct );
		$this->assertEqualsWithDelta( -3.6, $result->playing_handicap_raw, 0.0001 );
		$this->assertSame( -4, $result->playing_handicap );
	}

	public function test_plus_index_is_not_touched_by_the_cap(): void {
		$tee    = Fixtures::tee( 113, 72.0, 72 );
		$result = HandicapEngine::calculate( array( new PlayerHandicapInput( 1, -4.0, 14, $tee ) ), HandicapProfile::cgc() )[0];
		$this->assertSame( -4.0, $result->index_used );
	}

	public function test_twenty_strokes_is_two_on_si_one_and_two_and_one_elsewhere(): void {
		$this->assertSame( 2, StrokeAllocator::on_hole( 20, 1 ) );
		$this->assertSame( 2, StrokeAllocator::on_hole( 20, 2 ) );
		for ( $si = 3; $si <= 18; $si++ ) {
			$this->assertSame( 1, StrokeAllocator::on_hole( 20, $si ), "SI $si" );
		}
	}

	public function test_strokes_follow_the_stroke_index(): void {
		$this->assertSame( 0, StrokeAllocator::on_hole( 0, 1 ) );
		$this->assertSame( 1, StrokeAllocator::on_hole( 7, 7 ) );
		$this->assertSame( 0, StrokeAllocator::on_hole( 7, 8 ) );
		$this->assertSame( 1, StrokeAllocator::on_hole( 15, 15 ) );
		$this->assertSame( 0, StrokeAllocator::on_hole( 15, 16 ) );
		$this->assertSame( 1, StrokeAllocator::on_hole( 18, 18 ) );
		$this->assertSame( 3, StrokeAllocator::on_hole( 36 + 1, 1 ) );
	}

	public function test_plus_strokes_are_given_back_from_si_18(): void {
		$this->assertSame( -1, StrokeAllocator::on_hole( -2, 18 ) );
		$this->assertSame( -1, StrokeAllocator::on_hole( -2, 17 ) );
		$this->assertSame( 0, StrokeAllocator::on_hole( -2, 16 ) );
		$this->assertSame( 0, StrokeAllocator::on_hole( 0, 18 ) );
		$this->assertSame( -1, StrokeAllocator::on_hole( -18, 1 ) );
	}

	public function test_player_hole_strokes_use_the_stroke_index(): void {
		$result = HandicapEngine::calculate( $this->worked_example(), HandicapProfile::cgc() )[3];
		$this->assertSame( 2, $result->strokes_on_hole( 1 ) );
		$this->assertSame( 1, $result->strokes_on_hole( 3 ) );
	}

	public function test_a_player_with_too_few_rounds_is_not_eligible(): void {
		$this->expectException( InvalidArgumentException::class );
		HandicapEngine::calculate( array( Fixtures::player( 1, 10.0, 2 ) ), HandicapProfile::cgc() );
	}

	public function test_an_organiser_tier_override_skips_the_rounds_check(): void {
		$player  = new PlayerHandicapInput( 1, 10.0, 0, Fixtures::tee(), false, null, 2 );
		$results = HandicapEngine::calculate( array( $player ), HandicapProfile::cgc() );
		$this->assertSame( 2, $results[0]->tier );
		$this->assertTrue( $results[0]->tier_overridden );
		$this->assertSame( 75.0, $results[0]->allowance_pct );
	}

	public function test_exactly_the_minimum_rounds_is_tier_two_and_twelve_is_tier_one(): void {
		$profile = HandicapProfile::cgc();
		$this->assertNull( $profile->tier_for_rounds( 2 ) );
		$this->assertSame( 2, $profile->tier_for_rounds( 3 )['tier'] ?? null );
		$this->assertSame( 2, $profile->tier_for_rounds( 11 )['tier'] ?? null );
		$this->assertSame( 1, $profile->tier_for_rounds( 12 )['tier'] ?? null );
	}

	public function test_esr_reduces_the_index_before_the_cap(): void {
		$tee    = Fixtures::tee( 113, 72.0, 72 );
		$player = new PlayerHandicapInput( 1, 20.0, 14, $tee, false, 50.0 );
		$result = HandicapEngine::calculate( array( $player ), HandicapProfile::cgc() )[0];
		$this->assertSame( 10.0, $result->index_used );
		$this->assertSame( 9, $result->playing_handicap ); // 10 x 90% = 9.
	}

	public function test_pro_plays_off_zero_by_default_and_counts_as_the_lowest(): void {
		$pro     = new PlayerHandicapInput( 1, 2.0, 14, Fixtures::tee(), true );
		$results = HandicapEngine::calculate( array( $pro, Fixtures::player( 2, 12.8, 6 ) ), HandicapProfile::cgc() );
		$this->assertSame( 0, $results[0]->playing_handicap );
		$this->assertSame( 10, $results[1]->strokes );
	}

	public function test_pro_handling_index_zero_uses_a_zero_index_through_the_tee(): void {
		$profile = HandicapProfile::cgc()->with( array( 'pro_handling' => HandicapProfile::PRO_INDEX_ZERO ) );
		$pro     = new PlayerHandicapInput( 1, 2.0, 14, Fixtures::tee(), true );
		$result  = HandicapEngine::calculate( array( $pro ), $profile )[0];
		$this->assertSame( 0.0, $result->index_used );
		$this->assertEqualsWithDelta( -0.8, $result->course_handicap_raw, 0.001 );
		$this->assertSame( -1, $result->course_handicap );
		$this->assertSame( -1, $result->playing_handicap ); // -0.8 x 90% = -0.72.
	}

	public function test_penalty_takes_one_stroke_off_each_flagged_player(): void {
		$profile = HandicapProfile::cgc()->with( array( 'penalty_shots' => 1 ) );
		$players = array(
			new PlayerHandicapInput( 1, 4.2, 14, Fixtures::tee(), false, null, null, true, 'x' ),
			new PlayerHandicapInput( 2, 12.8, 6, Fixtures::tee(), false, null, null, true, 'x' ),
			new PlayerHandicapInput( 3, 18.5, 20, Fixtures::tee(), false, null, null, false, 'y', 'b' ),
		);
		$results = HandicapEngine::calculate( $players, $profile, HandicapEngine::FORMAT_INDIVIDUAL );
		$this->assertSame( array( 2, 9, 18 ), array_map( static fn( $r ): int => $r->strokes, $results ) );
		$this->assertSame( 1, $results[0]->penalty );
		$this->assertSame( 0, $results[2]->penalty );
	}

	public function test_a_per_team_penalty_is_taken_once_from_the_highest_handicap_in_the_team(): void {
		$profile = HandicapProfile::cgc()->with(
			array(
				'penalty_shots' => 1,
				'penalty_scope' => HandicapProfile::PENALTY_PER_TEAM,
			)
		);
		$players = array(
			new PlayerHandicapInput( 1, 4.2, 14, Fixtures::tee(), false, null, null, true, 'x' ),
			new PlayerHandicapInput( 2, 12.8, 6, Fixtures::tee(), false, null, null, true, 'x' ),
		);
		$results = HandicapEngine::calculate( $players, $profile, HandicapEngine::FORMAT_INDIVIDUAL );
		$this->assertSame( array( 3, 9 ), array_map( static fn( $r ): int => $r->strokes, $results ) );
	}

	public function test_four_ball_stableford_uses_85_percent_of_course_handicap_with_no_low(): void {
		$profile = HandicapProfile::whs_2024();
		$results = HandicapEngine::calculate(
			array( Fixtures::player( 1, 12.8 ), Fixtures::player( 2, 18.5 ) ),
			$profile,
			HandicapEngine::FORMAT_STABLEFORD
		);
		// CH 13 and 20: 85% is 11.05 and 17.
		$this->assertSame( array( 11, 17 ), $this->strokes( $results ) );
		$this->assertNull( $results[0]->low );
	}

	public function test_format_allowances_follow_the_whs_table(): void {
		$this->assertSame(
			array(
				'singles_mp'    => 100.0,
				'fourball_mp'   => 90.0,
				'foursomes'     => 50.0,
				'individual_sp' => 95.0,
				'stableford'    => 85.0,
			),
			HandicapProfile::whs_2024()->format_allowances
		);
	}

	public function test_singles_matchplay_is_100_percent_of_the_difference(): void {
		$results = HandicapEngine::calculate(
			array( Fixtures::player( 1, 4.2 ), Fixtures::player( 2, 12.8, 15, 'b' ) ),
			HandicapProfile::whs_2024(),
			HandicapEngine::FORMAT_SINGLES_MP
		);
		$this->assertSame( array( 0, 9 ), $this->strokes( $results ) ); // CH 4 and 13.
	}

	public function test_foursomes_gives_half_the_difference_in_combined_course_handicaps(): void {
		$result = HandicapEngine::foursomes_side_strokes( array( 4, 13 ), array( 20, 28 ), HandicapProfile::whs_2024() );
		$this->assertSame(
			array(
				'side'    => 'b',
				'strokes' => 16,
			),
			$result
		);
		$level = HandicapEngine::foursomes_side_strokes( array( 10, 10 ), array( 5, 15 ), HandicapProfile::whs_2024() );
		$this->assertNull( $level['side'] );
	}

	public function test_low_reference_per_side_subtracts_each_sides_own_lowest(): void {
		$profile = HandicapProfile::cgc()->with( array( 'low_reference' => HandicapProfile::LOW_PER_SIDE ) );
		$players = array(
			Fixtures::player( 1, 4.2, 14, 'a' ),
			Fixtures::player( 2, 12.8, 6, 'a' ),
			Fixtures::player( 3, 18.5, 20, 'b' ),
			Fixtures::player( 4, 31.0, 8, 'b' ),
		);
		$this->assertSame( array( 0, 7, 0, 5 ), $this->strokes( HandicapEngine::calculate( $players, $profile ) ) );
	}

	public function test_half_even_rounding_is_a_setting(): void {
		$this->assertSame( 4, HandicapEngine::round_value( 3.5, HandicapProfile::ROUND_HALF_UP ) );
		$this->assertSame( 4, HandicapEngine::round_value( 3.5, HandicapProfile::ROUND_HALF_EVEN ) );
		$this->assertSame( 3, HandicapEngine::round_value( 2.5, HandicapProfile::ROUND_HALF_UP ) );
		$this->assertSame( 2, HandicapEngine::round_value( 2.5, HandicapProfile::ROUND_HALF_EVEN ) );
		$this->assertSame( -4, HandicapEngine::round_value( -3.5, HandicapProfile::ROUND_HALF_UP ), 'Half rounds away from zero.' );
	}

	public function test_rounding_ignores_floating_point_dust(): void {
		$this->assertSame( 15, HandicapEngine::round_value( 14.499999999999998, HandicapProfile::ROUND_HALF_UP ) );
	}

	public function test_freeze_point_chooses_the_moment_the_index_is_read(): void {
		$tz    = new DateTimeZone( 'Europe/London' );
		$match = new DateTimeImmutable( '2027-06-10 09:00', $tz );
		$stage = new DateTimeImmutable( '2027-04-27 00:00', $tz );

		$this->assertEquals( $match, HandicapEngine::freeze_instant( HandicapProfile::cgc(), $match, $stage ) );
		$this->assertEquals( $stage, HandicapEngine::freeze_instant( HandicapProfile::cgc()->with( array( 'freeze_at' => 'stage_start' ) ), $match, $stage ) );
		$fixed = HandicapEngine::freeze_instant( HandicapProfile::cgc()->with( array( 'freeze_at' => '2027-04-01' ) ), $match, $stage );
		$this->assertSame( '2027-04-01 00:00', $fixed->format( 'Y-m-d H:i' ) );
	}

	public function test_the_snapshot_records_everything_needed_to_audit_the_result(): void {
		$profile  = HandicapProfile::cgc();
		$result   = HandicapEngine::calculate( $this->worked_example(), $profile )[3];
		$snapshot = $result->to_snapshot( $profile );

		$this->assertSame( 4, $snapshot['player_id'] );
		$this->assertSame( 31.0, $snapshot['index'] );
		$this->assertSame( 28.0, $snapshot['index_used'] );
		$this->assertSame( 8, $snapshot['rounds_12m'] );
		$this->assertSame( 2, $snapshot['tier'] );
		$this->assertSame( 125, $snapshot['tee']['slope'] );
		$this->assertSame( 71.2, $snapshot['tee']['course_rating'] );
		$this->assertSame( 72, $snapshot['tee']['par'] );
		$this->assertSame( 23, $snapshot['playing_handicap'] );
		$this->assertSame( 3, $snapshot['low'] );
		$this->assertSame( 20, $snapshot['strokes'] );
		$this->assertSame( 'pct_then_diff', $snapshot['method'] );
		$this->assertSame( $profile->hash(), $snapshot['settings_hash'] );
		$this->assertIsArray( json_decode( (string) json_encode( $snapshot ), true ) );
	}

	public function test_a_per_team_penalty_finds_numeric_team_ids(): void {
		$profile = HandicapProfile::cgc()->with(
			array(
				'penalty_shots' => 1,
				'penalty_scope' => HandicapProfile::PENALTY_PER_TEAM,
			)
		);
		$players = array(
			new PlayerHandicapInput( 1, 4.2, 14, Fixtures::tee(), false, null, null, true, 17 ),
			new PlayerHandicapInput( 2, 12.8, 6, Fixtures::tee(), false, null, null, false, 17 ),
		);
		$results = HandicapEngine::calculate( $players, $profile, HandicapEngine::FORMAT_INDIVIDUAL );
		$this->assertSame( array( 3, 9 ), $this->strokes( $results ), 'Taken from the highest handicap in team 17.' );
		$this->assertSame( 1, $results[1]->penalty );
	}

	public function test_a_penalty_still_costs_a_shot_when_the_percentage_comes_last(): void {
		// Course handicaps 4 and 10: the difference of 6 at 90% is 5 shots. A one-shot penalty must make it 4.
		$tee   = Fixtures::tee( 113, 72.0, 72 );
		$low   = new PlayerHandicapInput( 1, 4.0, 15, $tee, false, null, null, false, 1, 'a' );
		$other = new PlayerHandicapInput( 2, 10.0, 15, $tee, false, null, null, false, 2, 'b' );
		$this->assertSame( 5, HandicapEngine::calculate( array( $low, $other ), HandicapProfile::whs_2024() )[1]->strokes );

		$penalised = new PlayerHandicapInput( 2, 10.0, 15, $tee, false, null, null, true, 2, 'b' );
		$profile   = HandicapProfile::whs_2024()->with( array( 'penalty_shots' => 1 ) );
		$result    = HandicapEngine::calculate( array( $low, $penalised ), $profile )[1];
		$this->assertSame( 4, $result->strokes );
		$this->assertSame( 1, $result->penalty );
	}

	public function test_minimum_rounds_apply_to_format_allowances_too(): void {
		$profile = HandicapProfile::whs_2024()->with( array( 'min_rounds_12m' => 3 ) );
		try {
			HandicapEngine::calculate( array( Fixtures::player( 1, 10.0, 0 ) ), $profile );
			$this->fail( 'A player with no rounds should be refused.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'not eligible', $e->getMessage() );
		}
		$player = new PlayerHandicapInput( 1, 10.0, 0, Fixtures::tee(), false, null, 2 );
		$this->assertCount( 1, HandicapEngine::calculate( array( $player ), $profile ), 'An organiser tier skips the check.' );
	}

	public function test_a_made_up_tier_does_not_waive_the_minimum_rounds(): void {
		$profile = HandicapProfile::whs_2024()->with( array( 'min_rounds_12m' => 3 ) );
		$player  = new PlayerHandicapInput( 1, 10.0, 0, Fixtures::tee(), false, null, 999 );
		$this->expectException( InvalidArgumentException::class );
		HandicapEngine::calculate( array( $player ), $profile );
	}

	public function test_a_team_penalty_goes_to_the_player_who_receives_the_most_shots(): void {
		// Tier 1 gets 90% and Tier 2 75% of the difference to the lowest. Course handicaps 0 (low), 21 and 20:
		// the Tier 2 player has the higher course handicap but gets 16 shots, the Tier 1 player gets 18.
		$tee     = Fixtures::tee( 113, 72.0, 72 );
		$profile = HandicapProfile::cgc()->with(
			array(
				'method'        => HandicapProfile::METHOD_DIFF_THEN_PCT,
				'penalty_shots' => 1,
				'penalty_scope' => HandicapProfile::PENALTY_PER_TEAM,
			)
		);
		$players = array(
			new PlayerHandicapInput( 1, 0.0, 15, $tee, false, null, null, false, 'low', 'a' ),
			new PlayerHandicapInput( 2, 21.0, 5, $tee, false, null, null, true, 'x', 'b' ),
			new PlayerHandicapInput( 3, 20.0, 15, $tee, false, null, null, true, 'x', 'b' ),
		);
		$results = HandicapEngine::calculate( $players, $profile );
		$this->assertSame( 0, $results[1]->penalty, 'The Tier 2 player gets 16 shots.' );
		$this->assertSame( 1, $results[2]->penalty, 'The Tier 1 player gets 18 shots, so pays.' );
		$this->assertSame( 17, $results[2]->strokes );
		$this->assertSame( 16, $results[1]->strokes );
	}

	public function test_a_matchplay_penalty_shows_in_the_playing_handicap(): void {
		$tee     = Fixtures::tee( 113, 72.0, 72 );
		$low     = new PlayerHandicapInput( 1, 4.0, 15, $tee, false, null, null, false, 1, 'a' );
		$other   = new PlayerHandicapInput( 2, 10.0, 15, $tee, false, null, null, true, 2, 'b' );
		$profile = HandicapProfile::whs_2024()->with( array( 'penalty_shots' => 1 ) );
		$result  = HandicapEngine::calculate( array( $low, $other ), $profile )[1];
		$this->assertSame( 4, $result->strokes );
		$this->assertSame( 9, $result->playing_handicap );
	}

	public function test_stroke_play_playing_handicap_is_the_shots_received(): void {
		$result = HandicapEngine::calculate( array( Fixtures::player( 1, 4.2 ) ), HandicapProfile::whs_2024(), HandicapEngine::FORMAT_STABLEFORD )[0];
		$this->assertSame( 4, $result->course_handicap );
		$this->assertSame( 3, $result->strokes );
		$this->assertSame( 3, $result->playing_handicap );
	}

	public function test_the_snapshot_freezes_the_holes_of_the_tee(): void {
		$profile  = HandicapProfile::cgc();
		$snapshot = HandicapEngine::calculate( $this->worked_example(), $profile )[0]->to_snapshot( $profile );
		$this->assertCount( 18, $snapshot['tee']['holes'] );
		$this->assertSame(
			array(
				'hole' => 2,
				'par'  => 4,
				'si'   => 1,
			),
			$snapshot['tee']['holes'][1]
		);
	}

	public function test_an_unknown_format_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		HandicapEngine::calculate( array( Fixtures::player( 1, 4.2 ) ), HandicapProfile::cgc(), 'scramble' );
	}

	public function test_an_index_outside_the_whs_range_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		HandicapEngine::calculate( array( Fixtures::player( 1, 60.0 ) ), HandicapProfile::cgc() );
	}
}
