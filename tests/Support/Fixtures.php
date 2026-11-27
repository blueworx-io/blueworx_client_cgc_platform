<?php
/**
 * Shared test data.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Support;

use CGC\Championship\Domain\Course\Tee;
use CGC\Championship\Domain\Course\TeeHole;
use CGC\Championship\Domain\Handicap\PlayerHandicapInput;

/**
 * Builds the tee and players used by the worked example in BUILD_BRIEF section 3.
 */
final class Fixtures {

	public const PARS = array( 4, 4, 3, 5, 4, 4, 3, 4, 5, 4, 4, 3, 5, 4, 4, 3, 4, 5 );
	public const SIS  = array( 7, 1, 15, 11, 3, 13, 17, 5, 9, 8, 2, 16, 12, 4, 14, 18, 6, 10 );

	/**
	 * Hole rows as the validator and repository take them.
	 *
	 * @return list<array{hole: int, par: int, si: int, yards: int}>
	 */
	public static function hole_rows(): array {
		$rows = array();
		foreach ( self::PARS as $i => $par ) {
			$rows[] = array(
				'hole'  => $i + 1,
				'par'   => $par,
				'si'    => self::SIS[ $i ],
				'yards' => 300 + $i * 10,
			);
		}
		return $rows;
	}

	/**
	 * The worked-example tee: slope 125, course rating 71.2, par 72.
	 *
	 * @param int   $slope  Slope.
	 * @param float $rating Course rating.
	 * @param int   $par    Par (the hole pars add up to 72).
	 */
	public static function tee( int $slope = 125, float $rating = 71.2, int $par = 72 ): Tee {
		$holes = array();
		foreach ( self::hole_rows() as $row ) {
			$holes[] = new TeeHole( $row['hole'], $row['par'], $row['si'], $row['yards'] );
		}
		return new Tee( 1, 'Yellow', 'male', $par, $rating, $slope, $holes );
	}

	/**
	 * A player on the worked-example tee.
	 *
	 * @param int    $id     Player id.
	 * @param float  $index  Index.
	 * @param int    $rounds Rounds in 12 months.
	 * @param string $side   Side.
	 */
	public static function player( int $id, float $index, int $rounds = 15, string $side = 'a' ): PlayerHandicapInput {
		return new PlayerHandicapInput( $id, $index, $rounds, self::tee(), false, null, null, false, $id, $side );
	}
}
