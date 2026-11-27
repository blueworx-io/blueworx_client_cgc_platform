<?php
/**
 * Clock.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The only source of "now" for domain code. Never call time() or current_time() there.
 *
 * In demo mode it returns the cgc_demo_now option, so the demo console can time travel.
 */
final class Clock {

	public const DEMO_NOW_OPTION = 'cgc_demo_now';

	/**
	 * Current time in UTC.
	 */
	public static function now(): DateTimeImmutable {
		$utc = new DateTimeZone( 'UTC' );

		if ( DemoMode::enabled() ) {
			$stored = get_option( self::DEMO_NOW_OPTION, '' );
			if ( is_string( $stored ) && '' !== $stored ) {
				try {
					return ( new DateTimeImmutable( $stored, $utc ) )->setTimezone( $utc );
				} catch ( \Exception $e ) {
					// A broken stored value falls back to real time.
					unset( $e );
				}
			}
		}

		return new DateTimeImmutable( 'now', $utc );
	}

	/**
	 * Current time as a MySQL UTC datetime string.
	 */
	public static function mysql(): string {
		return self::now()->format( 'Y-m-d H:i:s' );
	}
}
