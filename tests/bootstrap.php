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
