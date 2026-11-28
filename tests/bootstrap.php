<?php
/**
 * PHPUnit bootstrap. Unit tests run without WordPress, so the few WordPress
 * functions the code under test calls are stubbed here.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$GLOBALS['cgc_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub of get_option().
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	function get_option( string $name, mixed $fallback = false ): mixed {
		return $GLOBALS['cgc_test_options'][ $name ] ?? $fallback;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub of update_option().
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	function update_option( string $name, mixed $value ): bool {
		$GLOBALS['cgc_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub of __(): returns the text unchanged.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once __DIR__ . '/Support/WpErrorStub.php';

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub of is_wp_error().
	 *
	 * @param mixed $thing Value.
	 */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub of wp_json_encode().
	 *
	 * @param mixed $value Value.
	 */
	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Stub of get_current_user_id().
	 */
	function get_current_user_id(): int {
		return 7;
	}
}
