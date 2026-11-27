<?php
/**
 * Settings tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings fall back to defaults and reject unknown keys.
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cgc_test_options'] = array();
	}

	public function test_defaults_are_returned_when_nothing_is_saved(): void {
		$this->assertSame( 'cgc', Settings::get( 'handicap_method' ) );
		$this->assertSame( 48, Settings::get( 'confirm_window_hours' ) );
	}

	public function test_a_saved_value_wins_and_others_keep_their_default(): void {
		$this->assertTrue( Settings::set( 'handicap_method', 'whs' ) );
		$this->assertSame( 'whs', Settings::get( 'handicap_method' ) );
		$this->assertSame( 48, Settings::get( 'escalation_hours' ) );
	}

	public function test_unknown_keys_are_rejected(): void {
		$this->assertFalse( Settings::set( 'nope', 1 ) );
		$this->assertSame( 'fallback', Settings::get( 'nope', 'fallback' ) );
	}
}
