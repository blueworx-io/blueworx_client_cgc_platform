<?php
/**
 * Stableford scorer.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go to developers and logs (callers turn them into WP_Error), never into HTML.

namespace CGC\Championship\Domain\Scoring;

use CGC\Championship\Domain\Course\Tee;
use CGC\Championship\Domain\Handicap\StrokeAllocator;
use InvalidArgumentException;

/**
 * Stableford points: net double bogey or worse 0, bogey 1, par 2, birdie 3, eagle 4, albatross 5.
 */
final class StablefordScorer {

	/**
	 * Points for one hole: max(0, 2 + par - net). A pick-up (null gross) scores 0.
	 *
	 * @param int      $par             Par of the hole.
	 * @param int|null $gross           Gross score, or null for a pick-up.
	 * @param int      $strokes_on_hole Strokes received on the hole.
	 */
	public static function points( int $par, ?int $gross, int $strokes_on_hole ): int {
		if ( null === $gross || $gross < 1 ) {
			return 0;
		}
		return max( 0, 2 + $par - ( $gross - $strokes_on_hole ) );
	}

	/**
	 * One player's round.
	 *
	 * @param Tee                  $tee     The tee played, for par and stroke index.
	 * @param int                  $strokes Strokes received over 18 holes.
	 * @param array<int, int|null> $gross Gross score by hole number. Null is a pick-up.
	 * @return array{holes: list<array{hole: int, gross: int|null, points: int}>, total: int}
	 * @throws InvalidArgumentException When a hole number is not on the tee.
	 */
	public static function score_round( Tee $tee, int $strokes, array $gross ): array {
		$numbers = array_keys( $gross );
		sort( $numbers );
		$holes = array();
		$total = 0;
		foreach ( $numbers as $number ) {
			$hole = $tee->hole( $number );
			if ( null === $hole ) {
				throw new InvalidArgumentException( "Hole $number is not on the tee." );
			}
			$points  = self::points( $hole->par, $gross[ $number ], StrokeAllocator::on_hole( $strokes, $hole->si ) );
			$holes[] = array(
				'hole'   => $number,
				'gross'  => $gross[ $number ],
				'points' => $points,
			);
			$total  += $points;
		}
		return array(
			'holes' => $holes,
			'total' => $total,
		);
	}

	/**
	 * A four-ball Stableford side: on each hole the side scores its better partner's points.
	 *
	 * @param Tee                                                    $tee     The tee played.
	 * @param list<array{strokes: int, gross: array<int, int|null>}> $players The side's players.
	 * @return array{holes: list<array{hole: int, points: int}>, total: int}
	 * @throws InvalidArgumentException When a hole number is not on the tee.
	 */
	public static function score_fourball( Tee $tee, array $players ): array {
		$best = array();
		foreach ( $players as $player ) {
			foreach ( self::score_round( $tee, $player['strokes'], $player['gross'] )['holes'] as $row ) {
				$best[ $row['hole'] ] = max( $best[ $row['hole'] ] ?? 0, $row['points'] );
			}
		}
		ksort( $best );
		$holes = array();
		$total = 0;
		foreach ( $best as $number => $points ) {
			$holes[] = array(
				'hole'   => $number,
				'points' => $points,
			);
			$total  += $points;
		}
		return array(
			'holes' => $holes,
			'total' => $total,
		);
	}
}
