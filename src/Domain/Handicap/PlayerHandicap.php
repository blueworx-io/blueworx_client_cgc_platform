<?php
/**
 * One player's worked-out handicap for one match.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Domain\Handicap;

/**
 * The result of the handicap engine for one player. to_snapshot() is what gets stored with the match.
 */
final class PlayerHandicap {

	/**
	 * Constructor.
	 *
	 * @param PlayerHandicapInput $input               What went in.
	 * @param string              $format              Format key the maths was run for.
	 * @param float               $index_used          Index after pro, ESR and cap.
	 * @param int|null            $tier                Tier number, or null when the allowance comes from the format table.
	 * @param bool                $tier_overridden     Whether the tier was set by hand.
	 * @param float               $allowance_pct       Allowance percent used.
	 * @param float               $course_handicap_raw Index x slope / 113 + (CR - par), before any allowance or rounding.
	 * @param int                 $course_handicap     That value rounded. Not capped.
	 * @param float               $playing_handicap_raw Value before rounding (after the allowance when it comes first).
	 * @param int                 $playing_handicap    Playing handicap after pro handling and penalty, before the lowest is subtracted.
	 * @param int                 $penalty             Shots taken off this player.
	 * @param int|null            $low                 Lowest playing handicap used, or null when the format has none.
	 * @param int                 $strokes             Strokes received in this match.
	 */
	public function __construct(
		public readonly PlayerHandicapInput $input,
		public readonly string $format,
		public readonly float $index_used,
		public readonly ?int $tier,
		public readonly bool $tier_overridden,
		public readonly float $allowance_pct,
		public readonly float $course_handicap_raw,
		public readonly int $course_handicap,
		public readonly float $playing_handicap_raw,
		public readonly int $playing_handicap,
		public readonly int $penalty,
		public readonly ?int $low,
		public readonly int $strokes
	) {}

	/**
	 * Strokes received on a hole.
	 *
	 * @param int $si Stroke index of the hole.
	 */
	public function strokes_on_hole( int $si ): int {
		return StrokeAllocator::on_hole( $this->strokes, $si );
	}

	/**
	 * The record stored with the match so any result can be audited later.
	 *
	 * @param HandicapProfile $profile Rules the number was worked out under.
	 * @return array<string, mixed>
	 */
	public function to_snapshot( HandicapProfile $profile ): array {
		return array(
			'player_id'            => $this->input->player_id,
			'format'               => $this->format,
			'index'                => $this->input->index,
			'index_used'           => $this->index_used,
			'rounds_12m'           => $this->input->rounds_12m,
			'tier'                 => $this->tier,
			'tier_overridden'      => $this->tier_overridden,
			'is_pro'               => $this->input->is_pro,
			'esr_pct'              => $this->input->esr_pct,
			'tee'                  => $this->input->tee->to_array(),
			'allowance_pct'        => $this->allowance_pct,
			'course_handicap_raw'  => $this->course_handicap_raw,
			'course_handicap'      => $this->course_handicap,
			'playing_handicap_raw' => $this->playing_handicap_raw,
			'playing_handicap'     => $this->playing_handicap,
			'penalty'              => $this->penalty,
			'low'                  => $this->low,
			'strokes'              => $this->strokes,
			'method'               => $profile->method,
			'allowance_applies_to' => $profile->allowance_applies_to,
			'freeze_at'            => $profile->freeze_at,
			'settings_hash'        => $profile->hash(),
			'settings'             => $profile->to_array(),
		);
	}
}
