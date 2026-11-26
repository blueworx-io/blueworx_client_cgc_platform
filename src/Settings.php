<?php
/**
 * Plugin settings.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship;

/**
 * Typed access to the single cgc_settings option. Defaults live here.
 *
 * Rules that CGC has not confirmed are settings, not constants (see HANDOVER.md).
 */
final class Settings {

	public const OPTION = 'cgc_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, scalar>
	 */
	public static function defaults(): array {
		return array(
			// "cgc" applies the allowance before subtracting the lowest handicap; "whs" after.
			'handicap_method'       => 'cgc',
			'confirm_window_hours'  => 48,
			'escalation_hours'      => 48,
			'second_chance_rule'    => 'first_played_match',
			'second_chance_penalty' => 1,
			'default_tenant_id'     => 1,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting. Unknown keys return the supplied fallback.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value for unknown keys.
	 * @return mixed
	 */
	public static function get( string $key, mixed $fallback = null ): mixed {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Saves one setting. Only known keys are accepted.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return bool False for an unknown key.
	 */
	public static function set( string $key, mixed $value ): bool {
		if ( ! array_key_exists( $key, self::defaults() ) ) {
			return false;
		}
		$all         = self::all();
		$all[ $key ] = $value;
		update_option( self::OPTION, $all );
		return true;
	}
}
