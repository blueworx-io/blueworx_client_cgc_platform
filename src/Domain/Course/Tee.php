<?php
/**
 * A tee: the numbers a course handicap is worked out from.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Domain\Course;

/**
 * Par, course rating and slope for one tee and gender, with its 18 holes.
 */
final class Tee {

	/**
	 * Constructor.
	 *
	 * @param int                 $id            Tee id. 0 when not saved yet.
	 * @param string              $name          Tee name, such as "Yellow".
	 * @param string              $gender        "male" or "female".
	 * @param int                 $par           Total par.
	 * @param float               $course_rating Course rating.
	 * @param int                 $slope         Slope rating.
	 * @param array<int, TeeHole> $holes         The holes, in hole order.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $gender,
		public readonly int $par,
		public readonly float $course_rating,
		public readonly int $slope,
		public readonly array $holes = array()
	) {}

	/**
	 * A hole by number. Hole numbers past the last hole wrap round (extra holes of a tied match).
	 *
	 * @param int $number Hole number, 1 or more.
	 */
	public function hole( int $number ): ?TeeHole {
		$count = count( $this->holes );
		if ( $count < 1 || $number < 1 ) {
			return null;
		}
		$wrapped = ( ( $number - 1 ) % $count ) + 1;
		foreach ( $this->holes as $hole ) {
			if ( $hole->hole === $wrapped ) {
				return $hole;
			}
		}
		return null;
	}

	/**
	 * Plain array for storing in a snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'name'          => $this->name,
			'gender'        => $this->gender,
			'par'           => $this->par,
			'course_rating' => $this->course_rating,
			'slope'         => $this->slope,
			// The holes are frozen with the snapshot, so a later edit to the scorecard cannot change a result.
			'holes'         => array_map(
				static fn( TeeHole $h ): array => array(
					'hole' => $h->hole,
					'par'  => $h->par,
					'si'   => $h->si,
				),
				$this->holes
			),
		);
	}
}
