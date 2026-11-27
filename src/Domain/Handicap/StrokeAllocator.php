<?php
/**
 * Hands out handicap strokes by stroke index.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Domain\Handicap;

/**
 * Stroke allocation (R&A Appendix C). 20 strokes is one on every hole and a second on SI 1 and 2.
 */
final class StrokeAllocator {

	/**
	 * Strokes received on one hole.
	 *
	 * A positive number of strokes is given from SI 1 upwards. A negative number (a plus
	 * handicap) is given back from SI 18 downwards, so it returns zero or a negative number.
	 *
	 * @param int $strokes Strokes received over 18 holes.
	 * @param int $si      Stroke index of the hole, 1 to 18.
	 */
	public static function on_hole( int $strokes, int $si ): int {
		if ( $strokes >= 0 ) {
			return intdiv( $strokes, 18 ) + ( $si <= $strokes % 18 ? 1 : 0 );
		}
		$back = -$strokes;
		return -( intdiv( $back, 18 ) + ( $si > 18 - ( $back % 18 ) ? 1 : 0 ) );
	}
}
