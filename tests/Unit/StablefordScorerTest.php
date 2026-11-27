<?php
/**
 * Stableford scorer tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Domain\Scoring\StablefordScorer;
use CGC\Championship\Tests\Support\Fixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Points = max(0, 2 + par - net).
 */
final class StablefordScorerTest extends TestCase {

	public function test_points_table_on_a_par_four(): void {
		$this->assertSame( 5, StablefordScorer::points( 4, 1, 0 ), 'Albatross.' );
		$this->assertSame( 4, StablefordScorer::points( 4, 2, 0 ), 'Eagle.' );
		$this->assertSame( 3, StablefordScorer::points( 4, 3, 0 ), 'Birdie.' );
		$this->assertSame( 2, StablefordScorer::points( 4, 4, 0 ), 'Par.' );
		$this->assertSame( 1, StablefordScorer::points( 4, 5, 0 ), 'Bogey.' );
		$this->assertSame( 0, StablefordScorer::points( 4, 6, 0 ), 'Double bogey.' );
		$this->assertSame( 0, StablefordScorer::points( 4, 9, 0 ), 'Never below zero.' );
	}

	public function test_strokes_on_the_hole_are_taken_off_first(): void {
		$this->assertSame( 2, StablefordScorer::points( 4, 5, 1 ), 'Net par.' );
		$this->assertSame( 2, StablefordScorer::points( 4, 6, 2 ), 'Net par with two strokes.' );
		$this->assertSame( 1, StablefordScorer::points( 3, 5, 1 ), 'Net bogey on a par three.' );
		$this->assertSame( 1, StablefordScorer::points( 5, 5, -1 ), 'A plus handicap gives a stroke back.' );
	}

	public function test_a_pick_up_scores_zero(): void {
		$this->assertSame( 0, StablefordScorer::points( 4, null, 2 ) );
	}

	public function test_a_round_adds_up_the_hole_points(): void {
		// 10 strokes: one on SI 1 to 10. Hole 1 is par 4 SI 7 (gets a stroke), hole 2 par 4 SI 1 (stroke), hole 3 par 3 SI 15 (none).
		$round = StablefordScorer::score_round(
			Fixtures::tee(),
			10,
			array(
				1 => 5,
				2 => 5,
				3 => 5,
				4 => null,
			)
		);
		$this->assertSame( array( 2, 2, 0, 0 ), array_column( $round['holes'], 'points' ) );
		$this->assertSame( 4, $round['total'] );
	}

	public function test_four_ball_takes_the_better_partner_on_each_hole(): void {
		$result = StablefordScorer::score_fourball(
			Fixtures::tee(),
			array(
				array(
					'strokes' => 0,
					'gross'   => array(
						1 => 4,
						2 => 6,
						3 => 3,
					),
				),
				array(
					'strokes' => 0,
					'gross'   => array(
						1 => 5,
						2 => 4,
						3 => null,
					),
				),
			)
		);
		$this->assertSame( array( 2, 2, 2 ), array_column( $result['holes'], 'points' ) );
		$this->assertSame( 6, $result['total'] );
	}

	public function test_a_hole_not_on_the_tee_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		StablefordScorer::score_round( Fixtures::tee(), 0, array( 0 => 4 ) );
	}
}
