<?php
/**
 * Demo mode switch.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship;

/**
 * Single place that answers "is demo mode on?" (the CGC_DEMO_MODE constant).
 */
final class DemoMode {

	/**
	 * Whether demo mode is on.
	 */
	public static function enabled(): bool {
		return defined( 'CGC_DEMO_MODE' ) && true === constant( 'CGC_DEMO_MODE' );
	}
}
