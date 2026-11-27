<?php
/**
 * One hole of a tee.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Domain\Course;

/**
 * Par, stroke index and length of one hole.
 */
final class TeeHole {

	/**
	 * Constructor.
	 *
	 * @param int $hole  Hole number, 1 to 18.
	 * @param int $par   Par.
	 * @param int $si    Stroke index, 1 (hardest) to 18.
	 * @param int $yards Length in yards. 0 when unknown.
	 */
	public function __construct(
		public readonly int $hole,
		public readonly int $par,
		public readonly int $si,
		public readonly int $yards = 0
	) {}
}
