<?php
/**
 * Schema tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Infrastructure\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Checks the table definitions against BUILD_BRIEF section 5.
 */
final class SchemaTest extends TestCase {

	private const EXPECTED = array(
		'tenants',
		'tournaments',
		'stages',
		'regions',
		'locations',
		'groups',
		'rounds',
		'players',
		'handicap_verifications',
		'teams',
		'team_members',
		'courses',
		'tees',
		'tee_holes',
		'venues',
		'matches',
		'match_lineups',
		'match_proposals',
		'match_results',
		'disputes',
		'payments',
		'golden_ticket_draws',
		'golden_tickets',
		'threads',
		'thread_participants',
		'messages',
		'announcements',
		'notifications',
		'email_log',
		'audit_log',
		'webhook_events',
		'magic_tokens',
	);

	public function test_every_table_from_the_brief_is_defined(): void {
		$this->assertEqualsCanonicalizing( self::EXPECTED, Schema::table_names() );
	}

	public function test_every_table_has_the_common_columns_and_a_tenant_index(): void {
		foreach ( Schema::table_names() as $short ) {
			$sql = Schema::create_sql( $short, 'wp_cgc_', 'DEFAULT CHARSET=utf8mb4' );
			foreach ( array( 'id BIGINT', 'tenant_id BIGINT', 'created_at DATETIME', 'updated_at DATETIME', 'is_demo TINYINT(1)', 'PRIMARY KEY  (id)', 'KEY tenant_id (tenant_id)' ) as $needle ) {
				$this->assertStringContainsString( $needle, $sql, "$short is missing: $needle" );
			}
		}
	}

	public function test_key_indexes_from_the_brief_exist(): void {
		$expected = array(
			'matches'        => 'KEY status_deadline (status, deadline_at)',
			'notifications'  => 'KEY user_read (user_id, read_at)',
			'webhook_events' => 'UNIQUE KEY event_id (event_id)',
			'tenants'        => 'UNIQUE KEY slug (slug)',
			'tee_holes'      => 'UNIQUE KEY tee_hole (tee_id, hole)',
			'players'        => 'UNIQUE KEY tenant_user (tenant_id, user_id)',
			'disputes'       => 'KEY status_sla (status, sla_due_at)',
			'audit_log'      => 'KEY entity (entity_type, entity_id)',
			'team_members'   => 'UNIQUE KEY one_active_team (tournament_id, active_player_id)',
		);
		foreach ( $expected as $short => $key ) {
			$this->assertStringContainsString( $key, Schema::create_sql( $short, 'wp_cgc_', '' ), $short );
		}
	}

	public function test_sql_is_shaped_for_dbdelta(): void {
		$sql = Schema::create_sql( 'matches', 'wp_cgc_', 'DEFAULT CHARSET=utf8mb4' );
		$this->assertStringStartsWith( 'CREATE TABLE wp_cgc_matches (', $sql );
		// One definition per line, and no reserved word used as a bare column name.
		$this->assertDoesNotMatchRegularExpression( '/^(to|index|group|order) /m', $sql );
		$this->assertCount( 1, array_filter( explode( "\n", $sql ), static fn( $l ) => str_starts_with( $l, 'PRIMARY KEY  (id)' ) ) );
	}

	public function test_no_duplicate_columns_within_a_table(): void {
		$common = array( 'id', 'tenant_id', 'created_at', 'updated_at', 'is_demo' );
		foreach ( Schema::definitions() as $short => $def ) {
			$this->assertSame( array(), array_intersect( $common, array_keys( $def['cols'] ) ), $short );
		}
	}
}
