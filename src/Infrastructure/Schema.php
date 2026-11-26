<?php
/**
 * Database schema.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

/**
 * Describes every cgc_* custom table (BUILD_BRIEF section 5) and builds the dbDelta SQL.
 *
 * Every table gets id, tenant_id, created_at, updated_at and is_demo, plus a tenant_id index.
 * Columns use short type names, expanded by column_sql(). Anything else is used as raw SQL.
 */
final class Schema {

	/**
	 * Short column type => SQL.
	 *
	 * @return array<string, string>
	 */
	private static function types(): array {
		return array(
			'fk'     => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
			'fkn'    => 'BIGINT UNSIGNED NULL',
			'str'    => "VARCHAR(191) NOT NULL DEFAULT ''",
			'strn'   => 'VARCHAR(191) NULL',
			'status' => "VARCHAR(32) NOT NULL DEFAULT ''",
			'int'    => 'INT NOT NULL DEFAULT 0',
			'intn'   => 'INT NULL',
			'pence'  => 'BIGINT NOT NULL DEFAULT 0',
			'bool'   => 'TINYINT(1) NOT NULL DEFAULT 0',
			'dt'     => 'DATETIME NULL',
			'date'   => 'DATE NULL',
			'json'   => 'LONGTEXT NULL',
			'text'   => 'LONGTEXT NULL',
			'dec'    => 'DECIMAL(6,2) NULL',
			'cur'    => "CHAR(3) NOT NULL DEFAULT 'GBP'",
			'hash'   => "CHAR(64) NOT NULL DEFAULT ''",
			'coord'  => 'DECIMAL(10,7) NULL',
		);
	}

	/**
	 * Table definitions: short name => columns and extra indexes.
	 *
	 * 'keys' entries are "KEY name (cols)" or "UNIQUE KEY name (cols)".
	 *
	 * @return array<string, array{cols: array<string, string>, keys: list<string>}>
	 */
	public static function definitions(): array {
		return array(
			'tenants'                => array(
				'cols' => array(
					'name'          => 'str',
					'slug'          => 'str',
					'branding_json' => 'json',
					'settings_json' => 'json',
				),
				'keys' => array( 'UNIQUE KEY slug (slug)' ),
			),
			'tournaments'            => array(
				'cols' => array(
					'name'                    => 'str',
					'season_year'             => 'int',
					'status'                  => 'status',
					'registration_mode'       => 'status', // open or invite.
					'fee_mode'                => 'status', // team or person.
					'fee_pence'               => 'pence',
					'currency'                => 'cur',
					'team_size'               => 'int',
					'timezone'                => 'str',
					'golden_ticket_cutoff_at' => 'dt',
					'template_key'            => 'str',
				),
				'keys' => array( 'KEY tenant_status (tenant_id, status)' ),
			),
			'stages'                 => array(
				'cols' => array(
					'tournament_id'           => 'fk',
					'seq'                     => 'int',
					'type'                    => 'status', // group_ko, regional_final, group_final, grand_final.
					'format'                  => 'status',
					'team_size'               => 'int',
					'handicap_profile_json'   => 'json',
					'qualification_rule_json' => 'json',
					'starts_at'               => 'dt',
					'ends_at'                 => 'dt',
				),
				'keys' => array( 'KEY tournament_seq (tournament_id, seq)' ),
			),
			'regions'                => array(
				'cols' => array(
					'tournament_id'           => 'fk',
					'name'                    => 'str',
					'kind'                    => 'status', // uk_region, europe, row.
					'regional_final_venue_id' => 'fkn',
					'travel_allowance_pence'  => 'pence',
				),
				'keys' => array( 'KEY tournament_id (tournament_id)' ),
			),
			'locations'              => array(
				'cols' => array(
					'tournament_id' => 'fk',
					'country'       => 'str',
					'city'          => 'str',
					'region_id'     => 'fkn',
				),
				'keys' => array( 'KEY tournament_id (tournament_id)', 'KEY region_id (region_id)' ),
			),
			'groups'                 => array(
				'cols' => array(
					'stage_id'          => 'fk',
					'region_id'         => 'fkn',
					'name'              => 'str',
					'target_size'       => 'int',
					'bracket_size'      => 'int',
					'seeding_method'    => 'status',
					'bye_policy'        => 'status',
					'draw_seed'         => 'strn',
					'draw_published_at' => 'dt',
				),
				'keys' => array( 'KEY stage_region (stage_id, region_id)' ),
			),
			'rounds'                 => array(
				'cols' => array(
					'stage_id'  => 'fk',
					'group_id'  => 'fkn',
					'bracket'   => 'status', // main, second_chance, final.
					'round_no'  => 'int',
					'opens_at'  => 'dt',
					'closes_at' => 'dt',
				),
				'keys' => array( 'KEY stage_bracket_round (stage_id, bracket, round_no)', 'KEY group_id (group_id)' ),
			),
			'players'                => array(
				'cols' => array(
					'user_id'             => 'fk',
					'display_name'        => 'str',
					'gender'              => 'status',
					'dob'                 => 'date',
					'is_pro'              => 'bool',
					'guardian_player_id'  => 'fkn',
					'phone'               => 'status',
					'home_club_id'        => 'fkn',
					'governing_body'      => 'str',
					'whs_id'              => 'strn',
					'handicap_index'      => 'dec',
					'index_as_at'         => 'date',
					'rounds_12m'          => 'int',
					'tier'                => 'status',
					'tier_override'       => 'strn',
					'esr_pct'             => 'dec',
					'verification_status' => 'status',
				),
				'keys' => array( 'UNIQUE KEY tenant_user (tenant_id, user_id)', 'KEY whs_id (whs_id)' ),
			),
			'handicap_verifications' => array(
				'cols' => array(
					'player_id'              => 'fk',
					'claimed_index'          => 'dec',
					'claimed_rounds'         => 'intn',
					'evidence_attachment_id' => 'fkn',
					'provider'               => 'str',
					'status'                 => 'status',
					'reviewed_by'            => 'fkn',
					'reviewed_at'            => 'dt',
					'notes'                  => 'text',
				),
				'keys' => array( 'KEY status (status)', 'KEY player_id (player_id)' ),
			),
			'teams'                  => array(
				'cols' => array(
					'tournament_id'     => 'fk',
					'name'              => 'str',
					'captain_player_id' => 'fk',
					'location_id'       => 'fkn',
					'group_id'          => 'fkn',
					'seed'              => 'intn',
					'status'            => 'status', // draft, paid, approved, active, eliminated, qualified, withdrawn, dq.
					'route'             => 'status', // main, second_chance, golden_ticket, group_final.
					'golden_ticket_id'  => 'fkn',
					'paid_at'           => 'dt',
				),
				'keys' => array( 'KEY group_status (group_id, status)', 'KEY tournament_id (tournament_id)' ),
			),
			'team_members'           => array(
				'cols' => array(
					'tournament_id'     => 'fk',
					'team_id'           => 'fk',
					'player_id'         => 'fkn',
					// Equals player_id while the member is active, NULL after leaving. The unique key
					// below then allows one active team per player per tournament (NULLs never clash).
					'active_player_id'  => 'fkn',
					'role'              => 'status', // captain, player2, reserve.
					'invite_email'      => 'strn',
					'invite_token_hash' => 'strn',
					'invite_status'     => 'status',
					'joined_at'         => 'dt',
					'left_at'           => 'dt',
				),
				'keys' => array(
					'UNIQUE KEY one_active_team (tournament_id, active_player_id)',
					'KEY team_id (team_id)',
					'KEY invite_token_hash (invite_token_hash)',
				),
			),
			'courses'                => array(
				'cols' => array(
					'name' => 'str',
					'club' => 'str',
					'lat'  => 'coord',
					'lng'  => 'coord',
				),
				'keys' => array(),
			),
			'tees'                   => array(
				'cols' => array(
					'course_id'     => 'fk',
					'name'          => 'str',
					'gender'        => 'status',
					'par'           => 'int',
					'course_rating' => 'dec',
					'slope'         => 'int',
				),
				'keys' => array( 'KEY course_id (course_id)' ),
			),
			'tee_holes'              => array(
				'cols' => array(
					'tee_id' => 'fk',
					'hole'   => 'int',
					'par'    => 'int',
					'si'     => 'int',
					'yards'  => 'int',
				),
				'keys' => array( 'UNIQUE KEY tee_hole (tee_id, hole)' ),
			),
			'venues'                 => array(
				'cols' => array(
					'course_id'  => 'fk',
					'event_date' => 'date',
					'stage_id'   => 'fkn',
					'region_id'  => 'fkn',
					'capacity'   => 'int',
				),
				'keys' => array( 'KEY course_id (course_id)', 'KEY stage_region (stage_id, region_id)' ),
			),
			'matches'                => array(
				'cols' => array(
					'stage_id'            => 'fk',
					'group_id'            => 'fkn',
					'bracket'             => 'status',
					'round_no'            => 'int',
					'slot'                => 'int',
					'code'                => 'str', // e.g. CGC27-NW-G2-M-R3-05.
					'team_a_id'           => 'fkn',
					'team_b_id'           => 'fkn',
					'home_team_id'        => 'fkn',
					'status'              => 'status',
					'deadline_at'         => 'dt',
					'scheduled_at'        => 'dt',
					'course_id'           => 'fkn',
					'played_at'           => 'dt',
					'completed_at'        => 'dt',
					'winner_team_id'      => 'fkn',
					'loser_team_id'       => 'fkn',
					'margin_text'         => 'strn',
					'result_method'       => 'strn',
					'holes_played'        => 'intn',
					'next_match_id'       => 'fkn',
					'next_slot'           => 'intn',
					'loser_next_match_id' => 'fkn',
					'loser_next_slot'     => 'intn',
					'source'              => 'status', // manual, wedge, admin.
				),
				'keys' => array(
					'KEY status_deadline (status, deadline_at)',
					'KEY group_bracket_round (group_id, bracket, round_no)',
					'KEY code (code)',
					'KEY team_a_id (team_a_id)',
					'KEY team_b_id (team_b_id)',
				),
			),
			'match_lineups'          => array(
				'cols' => array(
					'match_id'      => 'fk',
					'team_id'       => 'fk',
					'player_id'     => 'fk',
					'snapshot_json' => 'json', // index, tier, tee, CH, PH, strokes, settings hash.
				),
				'keys' => array( 'KEY match_id (match_id)' ),
			),
			'match_proposals'        => array(
				'cols' => array(
					'match_id'     => 'fk',
					'team_id'      => 'fk',
					'slots_json'   => 'json',
					'course_id'    => 'fkn',
					'status'       => 'status', // open, accepted, countered, declined, expired.
					'responded_at' => 'dt',
				),
				'keys' => array( 'KEY match_id (match_id)' ),
			),
			'match_results'          => array(
				'cols' => array(
					'match_id'         => 'fk',
					'submitted_by'     => 'fk',
					'submitted_at'     => 'dt',
					'hole_scores_json' => 'json',
					'computed_json'    => 'json',
					'winner_team_id'   => 'fkn',
					'margin_text'      => 'strn',
					'status'           => 'status', // submitted, confirmed, auto_confirmed, disputed, superseded.
					'confirmed_by'     => 'fkn',
					'confirmed_at'     => 'dt',
				),
				'keys' => array( 'KEY match_status (match_id, status)' ),
			),
			'disputes'               => array(
				'cols' => array(
					'match_id'    => 'fk',
					'raised_by'   => 'fk',
					'reason'      => 'text',
					'status'      => 'status',
					'sla_due_at'  => 'dt',
					'resolution'  => 'text',
					'resolved_by' => 'fkn',
				),
				'keys' => array( 'KEY status_sla (status, sla_due_at)', 'KEY match_id (match_id)' ),
			),
			'payments'               => array(
				'cols' => array(
					'team_id'        => 'fk',
					'player_id'      => 'fkn',
					'amount_pence'   => 'pence',
					'currency'       => 'cur',
					'provider'       => 'status', // mock in the demo.
					'provider_ref'   => 'strn',
					'status'         => 'status',
					'refunded_pence' => 'pence',
					'refund_reason'  => 'text',
				),
				'keys' => array( 'KEY status (status)', 'KEY team_id (team_id)' ),
			),
			'golden_ticket_draws'    => array(
				'cols' => array(
					'tournament_id'  => 'fk',
					'rng_seed'       => 'str',
					'eligible_count' => 'int',
					'drawn_by'       => 'fkn',
					'drawn_at'       => 'dt',
				),
				'keys' => array( 'KEY tournament_id (tournament_id)' ),
			),
			'golden_tickets'         => array(
				'cols' => array(
					'draw_id'    => 'fk',
					'team_id'    => 'fk',
					'venue_id'   => 'fkn',
					'status'     => 'status', // offered, accepted, declined, expired, reassigned.
					'respond_by' => 'dt',
				),
				'keys' => array( 'KEY draw_id (draw_id)', 'KEY team_id (team_id)', 'KEY status_respond (status, respond_by)' ),
			),
			'threads'                => array(
				'cols' => array(
					'context_type' => 'status', // match, team, group.
					'context_id'   => 'fk',
				),
				'keys' => array( 'KEY context (context_type, context_id)' ),
			),
			'thread_participants'    => array(
				'cols' => array(
					'thread_id'    => 'fk',
					'user_id'      => 'fk',
					'last_read_at' => 'dt',
				),
				'keys' => array( 'UNIQUE KEY thread_user (thread_id, user_id)', 'KEY user_id (user_id)' ),
			),
			'messages'               => array(
				'cols' => array(
					'thread_id'  => 'fk',
					'user_id'    => 'fk',
					'body'       => 'text',
					'flagged'    => 'bool',
					'flagged_by' => 'fkn',
				),
				'keys' => array( 'KEY thread_created (thread_id, created_at)' ),
			),
			'announcements'          => array(
				'cols' => array(
					'audience_json'   => 'json',
					'subject'         => 'str',
					'body'            => 'text',
					'channels'        => 'str',
					'sent_by'         => 'fkn',
					'sent_at'         => 'dt',
					'recipient_count' => 'int',
				),
				'keys' => array(),
			),
			'notifications'          => array(
				'cols' => array(
					'user_id'      => 'fk',
					'type'         => 'str',
					'payload_json' => 'json',
					'read_at'      => 'dt',
					'emailed_at'   => 'dt',
				),
				'keys' => array( 'KEY user_read (user_id, read_at)' ),
			),
			'email_log'              => array(
				'cols' => array(
					'to_email'  => 'str',
					'subject'   => 'str',
					'template'  => 'str',
					'body_html' => 'text',
					'status'    => 'status', // logged, sent, failed.
				),
				'keys' => array( 'KEY status (status)' ),
			),
			'audit_log'              => array(
				'cols' => array(
					'actor_user_id' => 'fk',
					'action'        => 'str',
					'entity_type'   => 'str',
					'entity_id'     => 'fk',
					'before_json'   => 'json',
					'after_json'    => 'json',
					'reason'        => 'text',
					'ip'            => 'status',
				),
				'keys' => array( 'KEY entity (entity_type, entity_id)', 'KEY actor_user_id (actor_user_id)' ),
			),
			'webhook_events'         => array(
				'cols' => array(
					'source'          => 'status',
					'event_id'        => 'str',
					'signature_valid' => 'bool',
					'payload_json'    => 'json',
					'received_at'     => 'dt',
					'processed_at'    => 'dt',
					'error'           => 'text',
				),
				'keys' => array( 'UNIQUE KEY event_id (event_id)' ),
			),
			'magic_tokens'           => array(
				'cols' => array(
					'user_id'    => 'fk',
					'token_hash' => 'hash',
					'expires_at' => 'dt',
					'used_at'    => 'dt',
				),
				'keys' => array( 'KEY token_hash (token_hash)' ),
			),
		);
	}

	/**
	 * Full table name for a short name, with the site's prefix.
	 *
	 * @param string $short Short name, e.g. "matches".
	 */
	public static function table( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . 'cgc_' . $short;
	}

	/**
	 * Short names of every table.
	 *
	 * @return list<string>
	 */
	public static function table_names(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * Expands a short type to SQL.
	 *
	 * @param string $type Short type or raw SQL.
	 */
	private static function column_sql( string $type ): string {
		$types = self::types();
		return $types[ $type ] ?? $type;
	}

	/**
	 * The CREATE TABLE statement for one table, formatted the way dbDelta needs it.
	 *
	 * @param string $short           Short table name.
	 * @param string $prefix          Table prefix including "cgc_".
	 * @param string $charset_collate Charset clause.
	 */
	public static function create_sql( string $short, string $prefix, string $charset_collate ): string {
		$def   = self::definitions()[ $short ];
		$lines = array(
			'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
			'tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1',
			'created_at DATETIME NOT NULL',
			'updated_at DATETIME NOT NULL',
			'is_demo TINYINT(1) NOT NULL DEFAULT 0',
		);
		foreach ( $def['cols'] as $name => $type ) {
			$lines[] = $name . ' ' . self::column_sql( $type );
		}
		// dbDelta needs two spaces after PRIMARY KEY and each KEY on its own line.
		$lines[] = 'PRIMARY KEY  (id)';
		$lines[] = 'KEY tenant_id (tenant_id)';
		foreach ( $def['keys'] as $key ) {
			$lines[] = $key;
		}

		return 'CREATE TABLE ' . $prefix . $short . " (\n" . implode( ",\n", $lines ) . "\n) " . $charset_collate . ';';
	}

	/**
	 * All CREATE TABLE statements, in definition order.
	 *
	 * @param string $prefix          Table prefix including "cgc_".
	 * @param string $charset_collate Charset clause.
	 * @return list<string>
	 */
	public static function all_sql( string $prefix, string $charset_collate ): array {
		$sql = array();
		foreach ( self::table_names() as $short ) {
			$sql[] = self::create_sql( $short, $prefix, $charset_collate );
		}
		return $sql;
	}
}
