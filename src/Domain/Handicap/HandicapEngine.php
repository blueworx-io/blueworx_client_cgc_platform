<?php
/**
 * The handicap engine.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go to developers and logs (callers turn them into WP_Error), never into HTML.

namespace CGC\Championship\Domain\Handicap;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Works out how many strokes each player gets in a match (BUILD_BRIEF section 3).
 *
 * Pure maths: no WordPress, no database. Every rule comes from the HandicapProfile.
 */
final class HandicapEngine {

	public const FORMAT_SINGLES_MP  = 'singles_mp';
	public const FORMAT_FOURBALL_MP = 'fourball_mp';
	public const FORMAT_INDIVIDUAL  = 'individual_sp';
	public const FORMAT_STABLEFORD  = 'stableford';
	public const FORMAT_FOURSOMES   = 'foursomes';

	/**
	 * Matchplay formats take strokes off the lowest handicap. Stroke-play formats do not.
	 *
	 * @var list<string>
	 */
	private const OFF_THE_LOW = array( self::FORMAT_SINGLES_MP, self::FORMAT_FOURBALL_MP );

	/**
	 * Formats calculate() can work out. Foursomes works on a side, see foursomes_side_strokes().
	 *
	 * @var list<string>
	 */
	private const PLAYER_FORMATS = array( self::FORMAT_SINGLES_MP, self::FORMAT_FOURBALL_MP, self::FORMAT_INDIVIDUAL, self::FORMAT_STABLEFORD );

	/**
	 * Works out every player's strokes for one match.
	 *
	 * @param array<int, PlayerHandicapInput> $players Everyone in the match.
	 * @param HandicapProfile                 $profile Rules for the stage.
	 * @param string                          $format  Format key: singles_mp, fourball_mp, individual_sp or stableford.
	 * @return list<PlayerHandicap> Same order as $players.
	 * @throws InvalidArgumentException When a player is not eligible or the input is not valid.
	 */
	public static function calculate( array $players, HandicapProfile $profile, string $format = self::FORMAT_FOURBALL_MP ): array {
		if ( ! in_array( $format, self::PLAYER_FORMATS, true ) ) {
			throw new InvalidArgumentException( "Unknown format $format. Foursomes uses foursomes_side_strokes()." );
		}
		if ( array() === $players ) {
			return array();
		}

		$work = array();
		foreach ( $players as $i => $player ) {
			$work[ $i ] = self::work_out_player( $player, $profile, $format );
		}

		$off_the_low = in_array( $format, self::OFF_THE_LOW, true );
		self::apply_penalties( $work, $players, $profile, $off_the_low );
		$lows = $off_the_low ? self::lows( $work, $players, $profile ) : array();

		$results = array();
		foreach ( $players as $i => $player ) {
			$w   = $work[ $i ];
			$low = null;
			if ( $off_the_low ) {
				$group = HandicapProfile::LOW_PER_SIDE === $profile->low_reference ? $player->side : '';
				$low   = $lows[ $group ];
				$diff  = $w['base'] - $low;
				// Percentage of the difference only when the allowance has not been applied already.
				$strokes = $w['pct_on_diff'] ? self::round_value( $diff * $w['pct'] / 100, $profile->rounding ) : $diff;
			} else {
				$strokes = $w['pct_on_diff'] ? self::round_value( $w['base'] * $w['pct'] / 100, $profile->rounding ) : $w['base'];
			}
			if ( $w['pct_on_diff'] ) {
				// The percentage came last, so the penalty comes off the shots. Taking it off the
				// number being scaled could round it away (6 x 90% and 5 x 90% are both 5).
				$strokes -= $w['penalty'];
			}
			// Stroke play has no lowest to subtract: the playing handicap is the shots received.
			// In matchplay it is the number before the lowest comes off, with any penalty taken off it.
			$playing = $off_the_low ? $w['base'] - ( $w['pct_on_diff'] ? $w['penalty'] : 0 ) : $strokes;

			$results[] = new PlayerHandicap(
				$player,
				$format,
				$w['index_used'],
				$w['tier'],
				$w['tier_overridden'],
				$w['pct'],
				$w['ch_raw'],
				$w['ch'],
				$w['ph_raw'],
				$playing,
				$w['penalty'],
				$low,
				$strokes
			);
		}
		return $results;
	}

	/**
	 * Foursomes: the side with the higher combined course handicap gets a percentage (50 by
	 * default) of the difference between the two combined course handicaps.
	 *
	 * @param array<int, int> $side_a  Rounded course handicaps of side A.
	 * @param array<int, int> $side_b  Rounded course handicaps of side B.
	 * @param HandicapProfile $profile Rules for the stage.
	 * @return array{side: string|null, strokes: int} The side that receives strokes, or null when level.
	 * @throws InvalidArgumentException When the profile has no foursomes allowance.
	 */
	public static function foursomes_side_strokes( array $side_a, array $side_b, HandicapProfile $profile ): array {
		if ( ! isset( $profile->format_allowances[ self::FORMAT_FOURSOMES ] ) ) {
			throw new InvalidArgumentException( 'The profile has no foursomes allowance.' );
		}
		$diff = array_sum( $side_a ) - array_sum( $side_b );
		if ( 0 === $diff ) {
			return array(
				'side'    => null,
				'strokes' => 0,
			);
		}
		$strokes = self::round_value( abs( $diff ) * $profile->format_allowances[ self::FORMAT_FOURSOMES ] / 100, $profile->rounding );
		return array(
			'side'    => $diff > 0 ? 'a' : 'b',
			'strokes' => $strokes,
		);
	}

	/**
	 * When the index must be read: at the match start, the stage start, or a fixed date.
	 *
	 * @param HandicapProfile   $profile     Rules for the stage.
	 * @param DateTimeImmutable $match_start When the match starts.
	 * @param DateTimeImmutable $stage_start When the stage starts.
	 */
	public static function freeze_instant( HandicapProfile $profile, DateTimeImmutable $match_start, DateTimeImmutable $stage_start ): DateTimeImmutable {
		return match ( $profile->freeze_at ) {
			HandicapProfile::FREEZE_MATCH_START => $match_start,
			HandicapProfile::FREEZE_STAGE_START => $stage_start,
			default => new DateTimeImmutable( $profile->freeze_at . ' 00:00:00', $match_start->getTimezone() ),
		};
	}

	/**
	 * Rounds to a whole number. Half-up rounds .5 away from zero, so -3.5 becomes -4.
	 *
	 * @param float  $value Number to round.
	 * @param string $mode  "half_up" or "half_even".
	 */
	public static function round_value( float $value, string $mode ): int {
		// Take off floating-point dust first, so 14.499999999999998 counts as 14.5.
		$value = round( $value, 6 );
		return (int) round( $value, 0, HandicapProfile::ROUND_HALF_EVEN === $mode ? PHP_ROUND_HALF_EVEN : PHP_ROUND_HALF_UP );
	}

	/**
	 * Index, course handicap and playing handicap of one player, before penalties and the lowest.
	 *
	 * @param PlayerHandicapInput $player  The player.
	 * @param HandicapProfile     $profile Rules.
	 * @param string              $format  Format key.
	 * @return array{index_used: float, tier: int|null, tier_overridden: bool, pct: float, ch_raw: float, ch: int, ph_raw: float, base: int, pct_on_diff: bool, penalty: int}
	 * @throws InvalidArgumentException When the player cannot play.
	 */
	private static function work_out_player( PlayerHandicapInput $player, HandicapProfile $profile, string $format ): array {
		if ( $player->index < -10.0 || $player->index > 54.0 ) {
			throw new InvalidArgumentException( "Player {$player->player_id}: index {$player->index} is outside -10 to 54." );
		}
		if ( $player->tee->slope < 1 ) {
			throw new InvalidArgumentException( "Player {$player->player_id}: the tee has no slope." );
		}

		$hi = ( $player->is_pro && HandicapProfile::PRO_INDEX_ZERO === $profile->pro_handling ) ? 0.0 : $player->index;
		if ( null !== $player->esr_pct ) {
			$hi *= ( 1 - $player->esr_pct / 100 );
		}
		if ( null !== $profile->index_cap ) {
			$hi = min( $hi, $profile->index_cap ); // A plus (negative) index is never touched by the cap.
		}

		// Eligibility: enough rounds, whatever the allowance source. An organiser's tier skips it,
		// but only a tier that exists in the profile.
		if ( null !== $player->tier_override && null === $profile->tier_by_number( $player->tier_override ) ) {
			throw new InvalidArgumentException( "Player {$player->player_id}: tier {$player->tier_override} does not exist in the profile." );
		}
		if ( null === $player->tier_override && $player->rounds_12m < $profile->min_rounds_12m ) {
			throw new InvalidArgumentException(
				"Player {$player->player_id} is not eligible: {$player->rounds_12m} rounds in 12 months, {$profile->min_rounds_12m} needed."
			);
		}

		// Allowance: CGC tier, or the format table.
		$tier       = null;
		$overridden = null !== $player->tier_override;
		if ( HandicapProfile::SOURCE_TIER === $profile->allowance_source ) {
			$rule = $overridden
				? $profile->tier_by_number( (int) $player->tier_override )
				: $profile->tier_for_rounds( $player->rounds_12m );
			if ( null === $rule ) {
				throw new InvalidArgumentException( "Player {$player->player_id} has no matching tier." );
			}
			$tier = $rule['tier'];
			$pct  = $hi < 0 ? $rule['plus_pct'] : $rule['pct']; // Plus handicaps reverse the percentages.
		} else {
			if ( ! isset( $profile->format_allowances[ $format ] ) ) {
				throw new InvalidArgumentException( "The profile has no allowance for $format." );
			}
			$pct = $profile->format_allowances[ $format ];
		}

		$slope   = $player->tee->slope;
		$offset  = $player->tee->course_rating - $player->tee->par;
		$ch_raw  = $hi * $slope / 113 + $offset; // Player's own tee. Course handicap is never capped.
		$ch      = self::round_value( $ch_raw, $profile->rounding );
		$on_diff = false;

		if ( HandicapProfile::APPLIES_INDEX === $profile->allowance_applies_to ) {
			$ph_raw = ( $hi * $pct / 100 ) * $slope / 113 + $offset;
			$base   = self::round_value( $ph_raw, $profile->rounding );
		} elseif ( HandicapProfile::METHOD_PCT_THEN_DIFF === $profile->method ) {
			$ph_raw = $ch_raw * $pct / 100;
			$base   = self::round_value( $ph_raw, $profile->rounding );
		} else {
			// Difference first: work from the rounded course handicap, apply the percentage later.
			$ph_raw  = (float) $ch;
			$base    = $ch;
			$on_diff = true;
		}

		if ( $player->is_pro && HandicapProfile::PRO_PH_ZERO === $profile->pro_handling ) {
			$base   = 0;
			$ph_raw = 0.0;
		}

		return array(
			'index_used'      => $hi,
			'tier'            => $tier,
			'tier_overridden' => $overridden,
			'pct'             => $pct,
			'ch_raw'          => $ch_raw,
			'ch'              => $ch,
			'ph_raw'          => $ph_raw,
			'base'            => $base,
			'pct_on_diff'     => $on_diff,
			'penalty'         => 0,
		);
	}

	/**
	 * The lowest playing handicap in each group (everyone, or each side).
	 *
	 * @param array<int, array<string, mixed>> $work    Working figures, by player position.
	 * @param array<int, PlayerHandicapInput>  $players The players.
	 * @param HandicapProfile                  $profile Rules.
	 * @return array<string, int>
	 */
	private static function lows( array $work, array $players, HandicapProfile $profile ): array {
		$lows = array();
		foreach ( $players as $i => $player ) {
			$group          = HandicapProfile::LOW_PER_SIDE === $profile->low_reference ? $player->side : '';
			$lows[ $group ] = isset( $lows[ $group ] ) ? min( $lows[ $group ], $work[ $i ]['base'] ) : $work[ $i ]['base'];
		}
		return $lows;
	}

	/**
	 * Takes the stage penalty off the players it applies to (one fewer stroke each).
	 *
	 * Per player: every flagged player loses the shots. Per team: each team with a flagged
	 * player loses the shots once, from its player with the highest playing handicap
	 * (the first listed on a tie). CGC has not said which it means (BUILD_BRIEF section 2, open).
	 *
	 * @param array<int, array<string, mixed>> $work    Working figures, by player position. Changed in place.
	 * @param array<int, PlayerHandicapInput>  $players The players.
	 * @param HandicapProfile                  $profile Rules.
	 * @param bool                             $off_the_low Whether the format subtracts the lowest handicap.
	 */
	private static function apply_penalties( array &$work, array $players, HandicapProfile $profile, bool $off_the_low ): void {
		if ( $profile->penalty_shots < 1 ) {
			return;
		}

		$penalised = array();
		if ( HandicapProfile::PENALTY_PER_PLAYER === $profile->penalty_scope ) {
			foreach ( $players as $i => $player ) {
				if ( $player->penalty ) {
					$penalised[] = $i;
				}
			}
		} else {
			$flagged = array();
			foreach ( $players as $player ) {
				if ( $player->penalty ) {
					$flagged[ (string) $player->team ] = true;
				}
			}
			// The player who receives the most shots pays. Where the percentage comes last, that is
			// the rounded difference to the lowest at the player's own percentage, not the raw handicap.
			// Rounded, so two players who receive the same number of shots tie (the first listed pays).
			$lows  = $off_the_low ? self::lows( $work, $players, $profile ) : array();
			$shots = array();
			foreach ( $players as $i => $player ) {
				$group       = ( $off_the_low && HandicapProfile::LOW_PER_SIDE === $profile->low_reference ) ? $player->side : '';
				$shots[ $i ] = $work[ $i ]['pct_on_diff']
					? self::round_value( ( $work[ $i ]['base'] - ( $lows[ $group ] ?? 0 ) ) * $work[ $i ]['pct'] / 100, $profile->rounding )
					: $work[ $i ]['base'];
			}
			// Array keys turn numeric strings back into integers, so compare as strings on both sides.
			foreach ( array_keys( $flagged ) as $team ) {
				$pick = null;
				foreach ( $players as $i => $player ) {
					if ( (string) $player->team === (string) $team && ( null === $pick || $shots[ $i ] > $shots[ $pick ] ) ) {
						$pick = $i;
					}
				}
				if ( null !== $pick ) {
					$penalised[] = $pick;
				}
			}
		}

		foreach ( $penalised as $i ) {
			$work[ $i ]['penalty'] = $profile->penalty_shots;
			if ( ! $work[ $i ]['pct_on_diff'] ) {
				// The allowance is already in the number, so the shots come straight off it.
				$work[ $i ]['base'] -= $profile->penalty_shots;
			}
		}
	}
}
