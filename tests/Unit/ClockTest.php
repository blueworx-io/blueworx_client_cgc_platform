<?php
/**
 * Clock tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Clock;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * CGC_DEMO_MODE is a constant, so each case runs in its own process.
 */
final class ClockTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_outside_demo_mode_the_stored_time_is_ignored(): void {
		define( 'CGC_DEMO_MODE', false );
		update_option( Clock::DEMO_NOW_OPTION, '2027-06-01 12:00:00' );
		$this->assertNotSame( '2027-06-01', Clock::now()->format( 'Y-m-d' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_demo_mode_reads_the_stored_time_as_utc(): void {
		define( 'CGC_DEMO_MODE', true );
		update_option( Clock::DEMO_NOW_OPTION, '2027-06-01T12:00:00+02:00' );
		$now = Clock::now();
		$this->assertSame( '2027-06-01 10:00:00', $now->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'UTC', $now->getTimezone()->getName() );
		$this->assertSame( '2027-06-01 10:00:00', Clock::mysql() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_demo_mode_with_no_or_broken_value_falls_back_to_real_time(): void {
		define( 'CGC_DEMO_MODE', true );
		$before = time();
		$this->assertGreaterThanOrEqual( $before, Clock::now()->getTimestamp() );
		update_option( Clock::DEMO_NOW_OPTION, 'not a date' );
		$this->assertGreaterThanOrEqual( $before, Clock::now()->getTimestamp() );
	}
}
