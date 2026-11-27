<?php
/**
 * Better-ball net matchplay scorer.
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
 * Scores a four-ball (or singles) better-ball net matchplay match hole by hole.
 *
 * Side "a" is the first team and "b" the second. The lead is positive when side a is ahead.
 * A player who picks up has a null score and cannot win a hole for the side.
 */
final class MatchplayScorer {

	public const HOLES = 18;

	/**
	 * Builds the net scores per hole from gross scores, strokes and the course's stroke indexes.
	 *
	 * A hole counts as played when any player has an entry for it (null means a pick-up).
	 *
	 * @param Tee                                                    $tee    Tee that supplies the stroke index of each hole.
	 * @param list<array{strokes: int, gross: array<int, int|null>}> $side_a Players of side A: strokes received and gross by hole number.
	 * @param list<array{strokes: int, gross: array<int, int|null>}> $side_b Players of side B.
	 * @return list<array{hole: int, a: list<int|null>, b: list<int|null>}>
	 * @throws InvalidArgumentException When a hole number is not on the tee.
	 */
	public static function holes_from_gross( Tee $tee, array $side_a, array $side_b ): array {
		$numbers = array();
		foreach ( array_merge( $side_a, $side_b ) as $player ) {
			foreach ( array_keys( $player['gross'] ) as $number ) {
				$numbers[ $number ] = true;
			}
		}
		ksort( $numbers );

		$holes = array();
		foreach ( array_keys( $numbers ) as $number ) {
			$hole = $tee->hole( $number );
			if ( null === $hole ) {
				throw new InvalidArgumentException( "Hole $number is not on the tee." );
			}
			$row = array(
				'hole' => $number,
				'a'    => array(),
				'b'    => array(),
			);
			foreach ( array(
				'a' => $side_a,
				'b' => $side_b,
			) as $key => $players ) {
				foreach ( $players as $player ) {
					$gross         = $player['gross'][ $number ] ?? null;
					$row[ $key ][] = ( null === $gross || $gross < 1 )
						? null
						: $gross - StrokeAllocator::on_hole( $player['strokes'], $hole->si );
				}
			}
			$holes[] = $row;
		}
		return $holes;
	}

	/**
	 * The result of one hole.
	 *
	 * @param list<int|null> $net_a Net scores of side A. Null is a pick-up.
	 * @param list<int|null> $net_b Net scores of side B.
	 * @return string "a", "b" or "halved".
	 */
	public static function hole_result( array $net_a, array $net_b ): string {
		$best_a = self::best( $net_a );
		$best_b = self::best( $net_b );
		if ( null === $best_a && null === $best_b ) {
			return 'halved';
		}
		if ( null === $best_b || ( null !== $best_a && $best_a < $best_b ) ) {
			return 'a';
		}
		if ( null === $best_a || $best_b < $best_a ) {
			return 'b';
		}
		return 'halved';
	}

	/**
	 * Scores a match.
	 *
	 * The match ends as soon as one side is more holes up than there are holes left. After 18
	 * holes a level match goes to extra holes (the next hole won decides), unless halves are
	 * allowed. Holes entered after the match is decided are ignored.
	 *
	 * @param list<array{hole: int, a: list<int|null>, b: list<int|null>}> $holes        Holes in play order, numbered 1, 2, 3 and so on.
	 * @param bool                                                         $allow_halved Whether a level match after 18 holes is a halved match.
	 * @return array{
	 *     holes: list<array{hole: int, net_a: int|null, net_b: int|null, result: string, lead: int}>,
	 *     holes_played: int,
	 *     lead: int,
	 *     leader: string|null,
	 *     remaining: int,
	 *     dormie: bool,
	 *     decided: bool,
	 *     winner: string|null,
	 *     margin_text: string|null,
	 *     state_text: string,
	 *     needs_extra_holes: bool,
	 *     ignored_holes: int
	 * }
	 * @throws InvalidArgumentException When the hole numbers are not 1, 2, 3 and so on in order.
	 */
	public static function score( array $holes, bool $allow_halved = false ): array {
		$rows        = array();
		$lead        = 0;
		$played      = 0;
		$decided     = false;
		$winner      = null;
		$margin      = null;
		$ignored     = 0;
		$needs_extra = false;

		foreach ( array_values( $holes ) as $i => $hole ) {
			if ( $hole['hole'] !== $i + 1 ) {
				throw new InvalidArgumentException( 'Holes must be numbered 1, 2, 3 and so on, in play order.' );
			}
			if ( $decided ) {
				++$ignored;
				continue;
			}

			$result = self::hole_result( $hole['a'], $hole['b'] );
			if ( 'a' === $result ) {
				++$lead;
			} elseif ( 'b' === $result ) {
				--$lead;
			}
			++$played;
			$rows[] = array(
				'hole'   => $hole['hole'],
				'net_a'  => self::best( $hole['a'] ),
				'net_b'  => self::best( $hole['b'] ),
				'result' => $result,
				'lead'   => $lead,
			);

			$remaining = max( 0, self::HOLES - $played );
			if ( $played > self::HOLES ) {
				// Extra holes: the first hole won decides.
				if ( 0 !== $lead ) {
					$decided     = true;
					$needs_extra = false;
					$margin      = sprintf(
						/* translators: %s: hole number with its ending, such as 19th. */
						__( '%s hole', 'cgc' ),
						self::ordinal( $played )
					);
				}
			} elseif ( abs( $lead ) > $remaining ) {
				$decided = true;
				$margin  = $remaining > 0
					? abs( $lead ) . '&' . $remaining
					: abs( $lead ) . ' UP';
			} elseif ( self::HOLES === $played && 0 === $lead ) {
				if ( $allow_halved ) {
					$decided = true;
					$margin  = __( 'Halved', 'cgc' );
					$winner  = 'halved';
				} else {
					$needs_extra = true;
				}
			}

			if ( $decided && 'halved' !== $winner ) {
				$winner = $lead > 0 ? 'a' : 'b';
			}
		}

		$remaining = max( 0, self::HOLES - $played );
		$leader    = 0 === $lead ? null : ( $lead > 0 ? 'a' : 'b' );
		$dormie    = ! $decided && $played < self::HOLES && 0 !== $lead && abs( $lead ) === $remaining;

		if ( $decided ) {
			$state = (string) $margin;
		} elseif ( 0 === $played ) {
			$state = __( 'Not started', 'cgc' );
		} elseif ( 0 === $lead ) {
			$state = 'AS thru ' . $played;
		} else {
			$state = abs( $lead ) . ' UP thru ' . $played;
		}

		return array(
			'holes'             => $rows,
			'holes_played'      => $played,
			'lead'              => $lead,
			'leader'            => $leader,
			'remaining'         => $remaining,
			'dormie'            => $dormie,
			'decided'           => $decided,
			'winner'            => $winner,
			'margin_text'       => $margin,
			'state_text'        => $state,
			'needs_extra_holes' => $needs_extra,
			'ignored_holes'     => $ignored,
		);
	}

	/**
	 * A number with its ending: 19th, 21st, 22nd, 23rd.
	 *
	 * @param int $n Number.
	 */
	public static function ordinal( int $n ): string {
		$mod100 = $n % 100;
		if ( $mod100 >= 11 && $mod100 <= 13 ) {
			return $n . 'th';
		}
		return $n . match ( $n % 10 ) {
			1 => 'st',
			2 => 'nd',
			3 => 'rd',
			default => 'th',
		};
	}

	/**
	 * The better (lower) of a side's net scores, ignoring pick-ups.
	 *
	 * @param list<int|null> $nets Net scores.
	 */
	private static function best( array $nets ): ?int {
		$scores = array_filter( $nets, static fn( $n ): bool => null !== $n );
		return array() === $scores ? null : min( $scores );
	}
}
