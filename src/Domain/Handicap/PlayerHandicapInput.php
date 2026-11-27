<?php
/**
 * One player's details going into the handicap engine.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Domain\Handicap;

use CGC\Championship\Domain\Course\Tee;

/**
 * What the engine needs to know about one player in one match.
 */
final class PlayerHandicapInput {

	/**
	 * Constructor.
	 *
	 * @param int        $player_id     Player id.
	 * @param float      $index         WHS handicap index. Negative for a plus handicap.
	 * @param int        $rounds_12m    Rounds recorded in the 12 months before the stage start.
	 * @param Tee        $tee           The tee this player plays from (own tee and gender rating).
	 * @param bool       $is_pro        Whether the player is a professional.
	 * @param float|null $esr_pct       Exceptional score reduction as a percent of the index, such as 50.
	 * @param int|null   $tier_override Tier the organiser has set by hand, which skips the rounds check.
	 * @param bool       $penalty       Whether the stage penalty applies (such as a 2nd Chance qualifier at Regionals).
	 * @param int|string $team          Team the player belongs to.
	 * @param string     $side          Side in the match, "a" or "b" (used by the per-side low reference).
	 */
	public function __construct(
		public readonly int $player_id,
		public readonly float $index,
		public readonly int $rounds_12m,
		public readonly Tee $tee,
		public readonly bool $is_pro = false,
		public readonly ?float $esr_pct = null,
		public readonly ?int $tier_override = null,
		public readonly bool $penalty = false,
		public readonly int|string $team = 0,
		public readonly string $side = 'a'
	) {}
}
