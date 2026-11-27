<?php
/**
 * Per-player, per-match handicap snapshots.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

use CGC\Championship\Clock;
use CGC\Championship\DemoMode;
use CGC\Championship\Domain\Handicap\HandicapEngine;
use CGC\Championship\Domain\Handicap\HandicapProfile;
use CGC\Championship\Domain\Handicap\PlayerHandicapInput;
use InvalidArgumentException;
use WP_Error;

/**
 * Stores each player's handicap snapshot in cgc_match_lineups.snapshot_json, so any result can
 * be audited later. A stored snapshot is only replaced with a reason, and the change is audited
 * (Rule 2.10: the organiser may adjust any handicap at any stage).
 *
 * This class does not check who is asking. The caller must.
 */
final class LineupSnapshotRepository {

	/**
	 * Works out the handicaps for a match lineup and stores one snapshot per player.
	 *
	 * @param int                             $match_id  Match id.
	 * @param array<int, PlayerHandicapInput> $players   Everyone in the match. PlayerHandicapInput::$team is the team id.
	 * @param HandicapProfile                 $profile   Rules for the stage.
	 * @param string                          $format    Format key, such as fourball_mp.
	 * @param string                          $reason    Required to replace snapshots that already exist.
	 * @param int                             $tenant_id Tenant.
	 * @return list<array<string, mixed>>|WP_Error The stored snapshots.
	 */
	public static function snapshot_match( int $match_id, array $players, HandicapProfile $profile, string $format, string $reason = '', int $tenant_id = 1 ): array|WP_Error {
		try {
			$results = HandicapEngine::calculate( $players, $profile, $format );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'cgc_handicap', $e->getMessage() );
		}

		// Check first, so a refused replacement changes nothing.
		$existing = self::get_for_match( $match_id, $tenant_id );
		if ( array() !== $existing && '' === trim( $reason ) ) {
			return new WP_Error( 'cgc_snapshot_exists', __( 'This match already has handicap snapshots. Give a reason to replace them.', 'cgc' ) );
		}

		$stored = array();
		foreach ( $results as $result ) {
			$snapshot = $result->to_snapshot( $profile );
			$saved    = self::store( $match_id, (int) $result->input->team, $result->input->player_id, $snapshot, $reason, $tenant_id );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$stored[] = $snapshot;
		}
		return $stored;
	}

	/**
	 * Stores one player's snapshot.
	 *
	 * @param int                  $match_id  Match id.
	 * @param int                  $team_id   Team id.
	 * @param int                  $player_id Player id.
	 * @param array<string, mixed> $snapshot  Snapshot from PlayerHandicap::to_snapshot().
	 * @param string               $reason    Required when this player already has a snapshot for the match.
	 * @param int                  $tenant_id Tenant.
	 * @return true|WP_Error
	 */
	public static function store( int $match_id, int $team_id, int $player_id, array $snapshot, string $reason = '', int $tenant_id = 1 ): true|WP_Error {
		global $wpdb;

		$json = wp_json_encode( $snapshot );
		if ( false === $json ) {
			return new WP_Error( 'cgc_invalid', __( 'The snapshot could not be encoded.', 'cgc' ) );
		}

		$table = Schema::table( 'match_lineups' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, snapshot_json FROM {$table} WHERE match_id = %d AND player_id = %d AND tenant_id = %d", $match_id, $player_id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			$now = Clock::mysql();
			$ok  = $wpdb->insert(
				$table,
				array(
					'tenant_id'     => $tenant_id,
					'created_at'    => $now,
					'updated_at'    => $now,
					'is_demo'       => DemoMode::enabled() ? 1 : 0,
					'match_id'      => $match_id,
					'team_id'       => $team_id,
					'player_id'     => $player_id,
					'snapshot_json' => $json,
				)
			);
			return false === $ok ? new WP_Error( 'cgc_db_error', __( 'The database could not save that.', 'cgc' ) ) : true;
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'cgc_snapshot_exists', __( 'This player already has a handicap snapshot for the match. Give a reason to replace it.', 'cgc' ) );
		}

		$before = json_decode( (string) ( $row['snapshot_json'] ?? '' ), true );
		$ok     = $wpdb->update(
			$table,
			array(
				'team_id'       => $team_id,
				'snapshot_json' => $json,
				'updated_at'    => Clock::mysql(),
			),
			array(
				'id'        => (int) $row['id'],
				'tenant_id' => $tenant_id,
			)
		);
		if ( false === $ok ) {
			return new WP_Error( 'cgc_db_error', __( 'The database could not save that.', 'cgc' ) );
		}
		AuditLogger::log( 'handicap.snapshot_replace', 'match', $match_id, $reason, is_array( $before ) ? $before : null, $snapshot, $tenant_id );
		return true;
	}

	/**
	 * Every snapshot stored for a match.
	 *
	 * @param int $match_id  Match id.
	 * @param int $tenant_id Tenant.
	 * @return list<array{team_id: int, player_id: int, snapshot: array<string, mixed>}>
	 */
	public static function get_for_match( int $match_id, int $tenant_id = 1 ): array {
		global $wpdb;

		$table = Schema::table( 'match_lineups' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT team_id, player_id, snapshot_json FROM {$table} WHERE match_id = %d AND tenant_id = %d ORDER BY id ASC", $match_id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$snapshot = json_decode( (string) $row['snapshot_json'], true );
			$out[]    = array(
				'team_id'   => (int) $row['team_id'],
				'player_id' => (int) $row['player_id'],
				'snapshot'  => is_array( $snapshot ) ? $snapshot : array(),
			);
		}
		return $out;
	}
}
