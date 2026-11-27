<?php
/**
 * Matchplay scorer tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Domain\Scoring\MatchplayScorer;
use CGC\Championship\Tests\Support\Fixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Hole result, match state, margin text, dormie and extra holes.
 */
final class MatchplayScorerTest extends TestCase {

	/**
	 * Builds hole rows from a string of results, one letter per hole: A (side a wins),
	 * B (side b wins) or H (halved).
	 *
	 * @param string $pattern Hole results such as "AAAHHH".
	 * @return list<array{hole: int, a: list<int|null>, b: list<int|null>}>
	 */
	private function holes( string $pattern ): array {
		$holes = array();
		foreach ( str_split( $pattern ) as $i => $letter ) {
			$holes[] = array(
				'hole' => $i + 1,
				'a'    => array( 'A' === $letter ? 3 : 4 ),
				'b'    => array( 'B' === $letter ? 3 : 4 ),
			);
		}
		return $holes;
	}

	public function test_better_ball_lowest_net_wins_the_hole(): void {
		$this->assertSame( 'a', MatchplayScorer::hole_result( array( 5, 3 ), array( 4, 4 ) ) );
		$this->assertSame( 'b', MatchplayScorer::hole_result( array( 5, 5 ), array( 4, 6 ) ) );
		$this->assertSame( 'halved', MatchplayScorer::hole_result( array( 4, 6 ), array( 7, 4 ) ) );
	}

	public function test_a_pick_up_cannot_win_the_hole_and_the_partner_still_counts(): void {
		$this->assertSame( 'b', MatchplayScorer::hole_result( array( null, null ), array( 6, null ) ) );
		$this->assertSame( 'a', MatchplayScorer::hole_result( array( null, 5 ), array( null, 6 ) ) );
		$this->assertSame( 'halved', MatchplayScorer::hole_result( array( null ), array( null ) ) );
	}

	public function test_margin_is_3_and_2_when_three_up_with_two_to_play(): void {
		// Three holes won, then 13 halved: after 16 holes, 3 up with 2 to play.
		$result = MatchplayScorer::score( $this->holes( 'AAA' . str_repeat( 'H', 13 ) ) );

		$this->assertTrue( $result['decided'] );
		$this->assertSame( '3&2', $result['margin_text'] );
		$this->assertSame( 'a', $result['winner'] );
		$this->assertSame( 16, $result['holes_played'] );
	}

	public function test_match_state_reads_2_up_thru_12(): void {
		$result = MatchplayScorer::score( $this->holes( 'AAHHHHHHHHHH' ) );
		$this->assertSame( '2 UP thru 12', $result['state_text'] );
		$this->assertSame( 'a', $result['leader'] );
		$this->assertFalse( $result['decided'] );
	}

	public function test_match_state_for_side_b_and_all_square(): void {
		$this->assertSame( '1 UP thru 3', MatchplayScorer::score( $this->holes( 'HBH' ) )['state_text'] );
		$this->assertSame( 'b', MatchplayScorer::score( $this->holes( 'HBH' ) )['leader'] );
		$this->assertSame( 'AS thru 4', MatchplayScorer::score( $this->holes( 'ABHH' ) )['state_text'] );
		$this->assertSame( 'Not started', MatchplayScorer::score( array() )['state_text'] );
	}

	public function test_dormie_when_the_lead_equals_the_holes_left(): void {
		// 2 up after 16, 2 holes left.
		$result = MatchplayScorer::score( $this->holes( 'AAHHHHHHHHHHHHHH' ) );
		$this->assertTrue( $result['dormie'] );
		$this->assertFalse( $result['decided'] );
		$this->assertSame( 2, $result['remaining'] );

		$not = MatchplayScorer::score( $this->holes( 'AAHHHHHHHHHHHHH' ) );
		$this->assertFalse( $not['dormie'] );
	}

	public function test_closeout_is_the_first_hole_where_the_lead_beats_the_holes_left(): void {
		// 4 up after 4, all halved: 4 up with 4 left (dormie) after 14, then 4&3 on 15.
		$result = MatchplayScorer::score( $this->holes( 'AAAAHHHHHHHHHHHBBB' ) );
		$this->assertSame( '4&3', $result['margin_text'] );
		$this->assertSame( 15, $result['holes_played'] );
		$this->assertSame( 3, $result['ignored_holes'] );
	}

	public function test_a_win_after_18_is_one_up(): void {
		$result = MatchplayScorer::score( $this->holes( 'HHHHHHHHHHHHHHHHHA' ) );
		$this->assertTrue( $result['decided'] );
		$this->assertSame( '1 UP', $result['margin_text'] );
		$this->assertSame( 'a', $result['winner'] );
	}

	public function test_two_up_with_two_to_play_is_won_two_and_one_up(): void {
		$result = MatchplayScorer::score( $this->holes( 'AAHHHHHHHHHHHHHHH' ) );
		$this->assertSame( '2&1', $result['margin_text'] );
	}

	public function test_level_after_18_needs_extra_holes(): void {
		$result = MatchplayScorer::score( $this->holes( 'ABHHHHHHHHHHHHHHHH' ) );
		$this->assertFalse( $result['decided'] );
		$this->assertTrue( $result['needs_extra_holes'] );
		$this->assertNull( $result['winner'] );
		$this->assertSame( 'AS thru 18', $result['state_text'] );
	}

	public function test_extra_holes_go_on_until_a_hole_is_won(): void {
		$still = MatchplayScorer::score( $this->holes( 'AB' . str_repeat( 'H', 17 ) ) );
		$this->assertFalse( $still['decided'] );

		$won = MatchplayScorer::score( $this->holes( 'AB' . str_repeat( 'H', 18 ) . 'B' ) );
		$this->assertTrue( $won['decided'] );
		$this->assertSame( 'b', $won['winner'] );
		$this->assertSame( '21st hole', $won['margin_text'] );
		$this->assertFalse( $won['needs_extra_holes'], 'A decided match does not ask for another hole.' );
	}

	public function test_a_level_match_can_be_halved_when_the_format_allows_it(): void {
		$result = MatchplayScorer::score( $this->holes( 'ABHHHHHHHHHHHHHHHH' ), true );
		$this->assertTrue( $result['decided'] );
		$this->assertSame( 'halved', $result['winner'] );
		$this->assertSame( 'Halved', $result['margin_text'] );
	}

	public function test_ordinals(): void {
		$this->assertSame( '19th', MatchplayScorer::ordinal( 19 ) );
		$this->assertSame( '21st', MatchplayScorer::ordinal( 21 ) );
		$this->assertSame( '22nd', MatchplayScorer::ordinal( 22 ) );
		$this->assertSame( '23rd', MatchplayScorer::ordinal( 23 ) );
		$this->assertSame( '111th', MatchplayScorer::ordinal( 111 ) );
	}

	public function test_hole_numbers_must_run_in_order(): void {
		$this->expectException( InvalidArgumentException::class );
		MatchplayScorer::score(
			array(
				array(
					'hole' => 2,
					'a'    => array( 4 ),
					'b'    => array( 4 ),
				),
			)
		);
	}

	public function test_net_scores_are_built_from_gross_scores_and_strokes(): void {
		// Hole 2 is SI 1 and hole 3 is SI 15. Side A's player gets 15 strokes, side B's gets none.
		$tee   = Fixtures::tee();
		$holes = MatchplayScorer::holes_from_gross(
			$tee,
			array(
				array(
					'strokes' => 15,
					'gross'   => array(
						1 => 5,
						2 => 5,
						3 => 4,
					),
				),
			),
			array(
				array(
					'strokes' => 0,
					'gross'   => array(
						1 => 4,
						2 => 4,
						3 => null,
					),
				),
			)
		);
		$this->assertSame( array( 4, 4, 3 ), array_map( static fn( $h ): ?int => $h['a'][0], $holes ) );
		$this->assertSame( array( 4, 4, null ), array_map( static fn( $h ): ?int => $h['b'][0], $holes ) );

		$result = MatchplayScorer::score( $holes );
		$this->assertSame( array( 'halved', 'halved', 'a' ), array_column( $result['holes'], 'result' ) );
		$this->assertSame( '1 UP thru 3', $result['state_text'] );
	}
}
