<?php
/**
 * Handicap profile: every rule of the handicap maths as one setting set per stage.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go to developers and logs (callers turn them into WP_Error), never into HTML.

namespace CGC\Championship\Domain\Handicap;

use InvalidArgumentException;

/**
 * Immutable set of handicap rules (BUILD_BRIEF section 3, "Settings the engine exposes").
 *
 * Stored per stage as JSON. Unknown or invalid values are rejected, never guessed.
 */
final class HandicapProfile {

	public const SOURCE_TIER   = 'tier';
	public const SOURCE_FORMAT = 'format';

	public const APPLIES_COURSE_HANDICAP = 'course_handicap';
	public const APPLIES_INDEX           = 'index';

	public const METHOD_PCT_THEN_DIFF = 'pct_then_diff';
	public const METHOD_DIFF_THEN_PCT = 'diff_then_pct';

	public const ROUND_HALF_UP   = 'half_up';
	public const ROUND_HALF_EVEN = 'half_even';

	public const LOW_ALL_PLAYERS = 'all_players_in_match';
	public const LOW_PER_SIDE    = 'per_side';

	public const PRO_PH_ZERO    = 'ph_zero';
	public const PRO_INDEX_ZERO = 'index_zero';

	public const PENALTY_PER_PLAYER = 'per_player';
	public const PENALTY_PER_TEAM   = 'per_team';

	public const FREEZE_MATCH_START = 'match_start';
	public const FREEZE_STAGE_START = 'stage_start';

	/**
	 * Constructor. Use from_array() or a preset instead of calling this directly.
	 *
	 * @param float|null                                                           $index_cap            Highest index used, or null for no cap.
	 * @param int                                                                  $min_rounds_12m       Rounds in 12 months needed to play.
	 * @param string                                                               $allowance_source     "tier" or "format".
	 * @param list<array{tier: int, min_rounds: int, pct: float, plus_pct: float}> $tier_rules           CGC tiers, in any order.
	 * @param string                                                               $allowance_applies_to "course_handicap" or "index".
	 * @param string                                                               $method               "pct_then_diff" or "diff_then_pct".
	 * @param string                                                               $rounding             "half_up" or "half_even".
	 * @param string                                                               $low_reference        "all_players_in_match" or "per_side".
	 * @param array<string, float>                                                 $format_allowances    Percent by format key.
	 * @param string                                                               $pro_handling         "ph_zero" or "index_zero".
	 * @param int                                                                  $penalty_shots        Shots taken off a penalised player.
	 * @param string                                                               $penalty_scope        "per_player" or "per_team".
	 * @param string                                                               $freeze_at            "match_start", "stage_start" or a date (Y-m-d).
	 */
	private function __construct(
		public readonly ?float $index_cap,
		public readonly int $min_rounds_12m,
		public readonly string $allowance_source,
		public readonly array $tier_rules,
		public readonly string $allowance_applies_to,
		public readonly string $method,
		public readonly string $rounding,
		public readonly string $low_reference,
		public readonly array $format_allowances,
		public readonly string $pro_handling,
		public readonly int $penalty_shots,
		public readonly string $penalty_scope,
		public readonly string $freeze_at
	) {}

	/**
	 * CGC's published method: Rule 6.3 and Rules 2.1 to 2.6.
	 *
	 * @return array<string, mixed>
	 */
	public static function cgc_defaults(): array {
		return array(
			'index_cap'            => 28.0,
			'min_rounds_12m'       => 3,
			'allowance_source'     => self::SOURCE_TIER,
			'tier_rules'           => array(
				array(
					'tier'       => 1,
					'min_rounds' => 12,
					'pct'        => 90,
					'plus_pct'   => 75,
				),
				array(
					'tier'       => 2,
					'min_rounds' => 3,
					'pct'        => 75,
					'plus_pct'   => 90,
				),
			),
			'allowance_applies_to' => self::APPLIES_COURSE_HANDICAP,
			'method'               => self::METHOD_PCT_THEN_DIFF,
			'rounding'             => self::ROUND_HALF_UP,
			'low_reference'        => self::LOW_ALL_PLAYERS,
			'format_allowances'    => self::whs_format_allowances(),
			'pro_handling'         => self::PRO_PH_ZERO,
			'penalty_shots'        => 0,
			'penalty_scope'        => self::PENALTY_PER_PLAYER,
			'freeze_at'            => self::FREEZE_MATCH_START,
		);
	}

	/**
	 * WHS allowances by format (R&A Appendix C).
	 *
	 * @return array<string, float>
	 */
	public static function whs_format_allowances(): array {
		return array(
			'singles_mp'    => 100.0,
			'fourball_mp'   => 90.0,
			'foursomes'     => 50.0,
			'individual_sp' => 95.0,
			'stableford'    => 85.0,
		);
	}

	/**
	 * The CGC Championship profile.
	 */
	public static function cgc(): self {
		return self::from_array( array() );
	}

	/**
	 * Current WHS four-ball method in GB&I (since 1 April 2024): no cap, no tiers, 90% of the
	 * difference to the lowest rounded course handicap.
	 */
	public static function whs_2024(): self {
		return self::from_array(
			array(
				'index_cap'        => null,
				'min_rounds_12m'   => 0,
				'allowance_source' => self::SOURCE_FORMAT,
				'method'           => self::METHOD_DIFF_THEN_PCT,
			)
		);
	}

	/**
	 * The pre-2024 WHS method: each player takes a percentage of their own course handicap,
	 * then the lowest is subtracted.
	 */
	public static function whs_old(): self {
		return self::from_array(
			array(
				'index_cap'        => null,
				'min_rounds_12m'   => 0,
				'allowance_source' => self::SOURCE_FORMAT,
				'method'           => self::METHOD_PCT_THEN_DIFF,
			)
		);
	}

	/**
	 * The profile for the plugin's "handicap_method" setting ("cgc" or "whs").
	 *
	 * @param string $setting Value of the handicap_method setting.
	 */
	public static function from_setting( string $setting ): self {
		return 'whs' === $setting ? self::whs_2024() : self::cgc();
	}

	/**
	 * Builds a profile from stored settings. Missing keys take the CGC value.
	 *
	 * @param array<string, mixed> $data Stored profile.
	 * @throws InvalidArgumentException When a value is not allowed.
	 */
	public static function from_array( array $data ): self {
		$unknown = array_diff( array_keys( $data ), array_keys( self::cgc_defaults() ) );
		if ( array() !== $unknown ) {
			throw new InvalidArgumentException( 'Unknown handicap setting: ' . implode( ', ', array_map( 'strval', $unknown ) ) . '.' );
		}
		$d = array_merge( self::cgc_defaults(), $data );

		$cap = $d['index_cap'];
		if ( null !== $cap && ( ! is_numeric( $cap ) || (float) $cap <= 0 ) ) {
			throw new InvalidArgumentException( 'index_cap must be a positive number or null.' );
		}

		$min_rounds = $d['min_rounds_12m'];
		if ( ! self::is_whole( $min_rounds ) || (int) $min_rounds < 0 ) {
			throw new InvalidArgumentException( 'min_rounds_12m must be zero or more.' );
		}

		$tier_rules = self::clean_tier_rules( $d['tier_rules'] );

		$allowances = array();
		if ( ! is_array( $d['format_allowances'] ) ) {
			throw new InvalidArgumentException( 'format_allowances must be a list of percentages.' );
		}
		foreach ( $d['format_allowances'] as $format => $pct ) {
			if ( ! is_numeric( $pct ) || (float) $pct <= 0 || (float) $pct > 100 ) {
				throw new InvalidArgumentException( "Allowance for $format must be above 0 and at most 100." );
			}
			$allowances[ (string) $format ] = (float) $pct;
		}

		$freeze = (string) $d['freeze_at'];
		if ( ! in_array( $freeze, array( self::FREEZE_MATCH_START, self::FREEZE_STAGE_START ), true ) && ! self::is_date( $freeze ) ) {
			throw new InvalidArgumentException( 'freeze_at must be match_start, stage_start or a date (Y-m-d).' );
		}

		$penalty = $d['penalty_shots'];
		if ( ! self::is_whole( $penalty ) || (int) $penalty < 0 ) {
			throw new InvalidArgumentException( 'penalty_shots must be zero or more.' );
		}

		return new self(
			null === $cap ? null : (float) $cap,
			(int) $min_rounds,
			self::one_of( $d['allowance_source'], array( self::SOURCE_TIER, self::SOURCE_FORMAT ), 'allowance_source' ),
			$tier_rules,
			self::one_of( $d['allowance_applies_to'], array( self::APPLIES_COURSE_HANDICAP, self::APPLIES_INDEX ), 'allowance_applies_to' ),
			self::one_of( $d['method'], array( self::METHOD_PCT_THEN_DIFF, self::METHOD_DIFF_THEN_PCT ), 'method' ),
			self::one_of( $d['rounding'], array( self::ROUND_HALF_UP, self::ROUND_HALF_EVEN ), 'rounding' ),
			self::one_of( $d['low_reference'], array( self::LOW_ALL_PLAYERS, self::LOW_PER_SIDE ), 'low_reference' ),
			$allowances,
			self::one_of( $d['pro_handling'], array( self::PRO_PH_ZERO, self::PRO_INDEX_ZERO ), 'pro_handling' ),
			(int) $penalty,
			self::one_of( $d['penalty_scope'], array( self::PENALTY_PER_PLAYER, self::PENALTY_PER_TEAM ), 'penalty_scope' ),
			$freeze
		);
	}

	/**
	 * A copy with some settings changed.
	 *
	 * @param array<string, mixed> $changes Settings to change.
	 * @throws InvalidArgumentException When a value is not allowed.
	 */
	public function with( array $changes ): self {
		return self::from_array( array_merge( $this->to_array(), $changes ) );
	}

	/**
	 * Settings as a plain array, ready to store as JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'index_cap'            => $this->index_cap,
			'min_rounds_12m'       => $this->min_rounds_12m,
			'allowance_source'     => $this->allowance_source,
			'tier_rules'           => $this->tier_rules,
			'allowance_applies_to' => $this->allowance_applies_to,
			'method'               => $this->method,
			'rounding'             => $this->rounding,
			'low_reference'        => $this->low_reference,
			'format_allowances'    => $this->format_allowances,
			'pro_handling'         => $this->pro_handling,
			'penalty_shots'        => $this->penalty_shots,
			'penalty_scope'        => $this->penalty_scope,
			'freeze_at'            => $this->freeze_at,
		);
	}

	/**
	 * Short fingerprint of the settings, stored in each snapshot so a result can be traced
	 * to the exact rules it was worked out under.
	 */
	public function hash(): string {
		$data = $this->to_array();
		ksort( $data );
		$encoded = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain code runs without WordPress.
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
	 * The tier rule that fits a number of rounds, or null when the player has too few.
	 *
	 * @param int $rounds_12m Rounds recorded in the last 12 months.
	 * @return array{tier: int, min_rounds: int, pct: float, plus_pct: float}|null
	 */
	public function tier_for_rounds( int $rounds_12m ): ?array {
		if ( $rounds_12m < $this->min_rounds_12m ) {
			return null;
		}
		$best = null;
		foreach ( $this->tier_rules as $rule ) {
			if ( $rounds_12m >= $rule['min_rounds'] && ( null === $best || $rule['min_rounds'] > $best['min_rounds'] ) ) {
				$best = $rule;
			}
		}
		return $best;
	}

	/**
	 * The tier rule with this number, or null.
	 *
	 * @param int $tier Tier number.
	 * @return array{tier: int, min_rounds: int, pct: float, plus_pct: float}|null
	 */
	public function tier_by_number( int $tier ): ?array {
		foreach ( $this->tier_rules as $rule ) {
			if ( $rule['tier'] === $tier ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Checks a value is one of the allowed strings.
	 *
	 * @param mixed              $value   Value to check.
	 * @param array<int, string> $allowed Allowed values.
	 * @param string             $name    Setting name for the error.
	 * @throws InvalidArgumentException When the value is not allowed.
	 */
	private static function one_of( mixed $value, array $allowed, string $name ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( "$name must be one of: " . implode( ', ', $allowed ) . '.' );
		}
		return $value;
	}

	/**
	 * Checks and tidies the tier rules.
	 *
	 * @param mixed $rules Stored tier rules.
	 * @return list<array{tier: int, min_rounds: int, pct: float, plus_pct: float}>
	 * @throws InvalidArgumentException When a rule is malformed.
	 */
	private static function clean_tier_rules( mixed $rules ): array {
		if ( ! is_array( $rules ) ) {
			throw new InvalidArgumentException( 'tier_rules must be a list.' );
		}
		$clean = array();
		$seen  = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				throw new InvalidArgumentException( 'Each tier rule must have tier, min_rounds, pct and plus_pct.' );
			}
			foreach ( array( 'tier', 'min_rounds', 'pct', 'plus_pct' ) as $key ) {
				if ( ! isset( $rule[ $key ] ) || ! is_numeric( $rule[ $key ] ) ) {
					throw new InvalidArgumentException( "Each tier rule needs a number for $key." );
				}
				if ( in_array( $key, array( 'tier', 'min_rounds' ), true ) && ! self::is_whole( $rule[ $key ] ) ) {
					throw new InvalidArgumentException( "Tier rule $key must be a whole number." );
				}
			}
			$tier = (int) $rule['tier'];
			if ( isset( $seen[ $tier ] ) ) {
				throw new InvalidArgumentException( "Tier $tier is listed twice." );
			}
			$seen[ $tier ] = true;
			foreach ( array( 'pct', 'plus_pct' ) as $key ) {
				if ( (float) $rule[ $key ] <= 0 || (float) $rule[ $key ] > 100 ) {
					throw new InvalidArgumentException( "Tier $tier $key must be above 0 and at most 100." );
				}
			}
			$clean[] = array(
				'tier'       => $tier,
				'min_rounds' => (int) $rule['min_rounds'],
				'pct'        => (float) $rule['pct'],
				'plus_pct'   => (float) $rule['plus_pct'],
			);
		}
		return $clean;
	}

	/**
	 * Whether a value is a whole number (an int, a digit string, or a float with no fraction).
	 *
	 * @param mixed $value Value to test.
	 */
	private static function is_whole( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return floor( $value ) === $value;
		}
		return is_string( $value ) && 1 === preg_match( '/^-?d+$/', $value );
	}

	/**
	 * Whether a string is a real Y-m-d date.
	 *
	 * @param string $value Text to check.
	 */
	private static function is_date( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
