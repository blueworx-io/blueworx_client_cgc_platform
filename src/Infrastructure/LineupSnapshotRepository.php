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
 * be audited later. A stored snapshot is only replaced with a reason, and every replacement or
 * removal is audited (Rule 2.10: the organiser may adjust any handicap at any stage).
 *
 * A whole lineup is saved in one transaction: either every player is stored, replaced or
 * removed together with its audit row, or nothing changes. The database allows one snapshot per
 * tenant, match and player.
 *
 * This class does not check who is asking. The caller must.
 */
final class LineupSnapshotRepository {

	/**
	 * Tables a snapshot change writes to. All must support transactions.
	 *
	 * @var list<string>
	 */
	private const TABLES = array( 'match_lineups', 'audit_log' );

	/**
	 * Works out the handicaps for a match lineup and stores one snapshot per player.
	 *
	 * Replacing existing snapshots needs a reason. Players who had a snapshot but are not in
	 * the new lineup (a reserve came in) are removed, and the removal is audited.
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

		return self::locked(
			$match_id,
			$tenant_id,
			static function () use ( $match_id, $results, $profile, $reason, $tenant_id ): array|WP_Error {
				// Locked read: it also proves the database is answering before anything is written.
				$existing = self::load_locked( $match_id, $tenant_id );
				if ( null === $existing ) {
					return self::db_error();
				}
				if ( array() !== $existing && '' === trim( $reason ) ) {
					return new WP_Error( 'cgc_snapshot_exists', __( 'This match already has handicap snapshots. Give a reason to replace them.', 'cgc' ) );
				}

				$stored  = array();
				$kept_by = array();
				foreach ( $results as $result ) {
					$snapshot = $result->to_snapshot( $profile );
					$saved    = self::store_row( $match_id, (int) $result->input->team, $result->input->player_id, $snapshot, $reason, $tenant_id );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}
					$kept_by[ $result->input->player_id ] = true;
					$stored[]                             = $snapshot;
				}

				foreach ( $existing as $row ) {
					if ( isset( $kept_by[ $row['player_id'] ] ) ) {
						continue;
					}
					$removed = self::remove_row( $match_id, $row['player_id'], $row['snapshot'], $reason, $tenant_id );
					if ( is_wp_error( $removed ) ) {
						return $removed;
					}
				}
				return $stored;
			}
		);
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
	 * @return bool|WP_Error
	 */
	public static function store( int $match_id, int $team_id, int $player_id, array $snapshot, string $reason = '', int $tenant_id = 1 ): bool|WP_Error {
		return self::locked(
			$match_id,
			$tenant_id,
			static fn(): bool|WP_Error => self::store_row( $match_id, $team_id, $player_id, $snapshot, $reason, $tenant_id )
		);
	}

	/**
	 * Every snapshot stored for a match.
	 *
	 * @param int $match_id  Match id.
	 * @param int $tenant_id Tenant.
	 * @return list<array{team_id: int, player_id: int, snapshot: array<string, mixed>}>
	 */
	public static function get_for_match( int $match_id, int $tenant_id = 1 ): array {
		return self::read_match( $match_id, $tenant_id, false ) ?? array();
	}

	/**
	 * Runs a save while holding the match's lock, inside one transaction.
	 *
	 * The lock is taken first, so two saves for the same match (even the very first ones, when there
	 * are no rows to lock yet) run one after the other and never mix their lineups. If the lock or the
	 * transaction cannot be had, nothing is written.
	 *
	 * @template T
	 * @param int                      $match_id  Match id.
	 * @param int                      $tenant_id Tenant.
	 * @param callable(): (T|WP_Error) $work      The writes. Return a WP_Error to undo them.
	 * @return T|WP_Error
	 */
	private static function locked( int $match_id, int $tenant_id, callable $work ): mixed {
		$lock = 'cgc_lineup_' . $tenant_id . '_' . $match_id;
		if ( ! Db::lock( $lock ) ) {
			return new WP_Error( 'cgc_busy', __( 'Another change to this match is in progress. Try again in a moment.', 'cgc' ) );
		}
		try {
			if ( ! Db::begin( self::TABLES ) ) {
				return self::db_error();
			}
			$result = $work();
			if ( is_wp_error( $result ) ) {
				Db::rollback();
				return $result;
			}
			return Db::commit() ? $result : self::db_error();
		} finally {
			Db::unlock( $lock );
		}
	}

	/**
	 * Reads a match's snapshots and locks the rows until the open transaction ends.
	 *
	 * @param int $match_id  Match id.
	 * @param int $tenant_id Tenant.
	 * @return list<array{team_id: int, player_id: int, snapshot: array<string, mixed>}>|null Null when the read failed.
	 */
	private static function load_locked( int $match_id, int $tenant_id ): ?array {
		return self::read_match( $match_id, $tenant_id, true );
	}

	/**
	 * Reads a match's snapshot rows.
	 *
	 * @param int  $match_id  Match id.
	 * @param int  $tenant_id Tenant.
	 * @param bool $lock      Lock the rows (inside a transaction only).
	 * @return list<array{team_id: int, player_id: int, snapshot: array<string, mixed>}>|null Null when the query failed.
	 */
	private static function read_match( int $match_id, int $tenant_id, bool $lock ): ?array {
		global $wpdb;

		$table    = Schema::table( 'match_lineups' );
		$lock_sql = $lock ? ' FOR UPDATE' : '';
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT team_id, player_id, snapshot_json FROM {$table} WHERE match_id = %d AND tenant_id = %d ORDER BY id ASC{$lock_sql}", $match_id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		if ( ! is_array( $rows ) || Db::last_query_failed() ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$snapshot = json_decode( (string) $row['snapshot_json'], true );
			$out[]    = array(
				'team_id'   => (int) $row['team_id'],
				'player_id' => (int) $row['player_id'],
				'snapshot'  => is_array( $snapshot ) ? $snapshot : array(),
			);
		}
		return $out;
	}
	/**
	 * Inserts or replaces one player's row. The caller owns the transaction.
	 *
	 * @param int                  $match_id  Match id.
	 * @param int                  $team_id   Team id.
	 * @param int                  $player_id Player id.
	 * @param array<string, mixed> $snapshot  Snapshot.
	 * @param string               $reason    Required to replace an existing row.
	 * @param int                  $tenant_id Tenant.
	 * @return bool|WP_Error
	 */
	private static function store_row( int $match_id, int $team_id, int $player_id, array $snapshot, string $reason, int $tenant_id ): bool|WP_Error {
		global $wpdb;

		$json = wp_json_encode( $snapshot );
		if ( false === $json ) {
			return new WP_Error( 'cgc_invalid', __( 'The snapshot could not be encoded.', 'cgc' ) );
		}

		$table = Schema::table( 'match_lineups' );
		$row   = self::find_row( $match_id, $player_id, $tenant_id );
		if ( false === $row ) {
			return self::db_error();
		}

		if ( null === $row ) {
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
			if ( false !== $ok ) {
				return true;
			}
			// A save that overlapped this one may have won the unique key. Never overwrite it.
			return is_array( self::find_row( $match_id, $player_id, $tenant_id ) )
				? new WP_Error( 'cgc_snapshot_exists', __( 'This player already has a handicap snapshot for the match.', 'cgc' ) )
				: self::db_error();
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'cgc_snapshot_exists', __( 'This player already has a handicap snapshot for the match. Give a reason to replace it.', 'cgc' ) );
		}

		$ok = $wpdb->update(
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
			return self::db_error();
		}
		$before = json_decode( (string) $row['snapshot_json'], true );
		$logged = AuditLogger::log( 'handicap.snapshot_replace', 'match', $match_id, $reason, is_array( $before ) ? $before : null, $snapshot, $tenant_id );
		return false === $logged ? self::db_error() : true;
	}

	/**
	 * Removes a player's row (they were replaced in the lineup) and audits it. The caller owns the transaction.
	 *
	 * @param int                  $match_id  Match id.
	 * @param int                  $player_id Player id.
	 * @param array<string, mixed> $before    The snapshot being removed.
	 * @param string               $reason    Why.
	 * @param int                  $tenant_id Tenant.
	 * @return bool|WP_Error
	 */
	private static function remove_row( int $match_id, int $player_id, array $before, string $reason, int $tenant_id ): bool|WP_Error {
		global $wpdb;

		$ok = $wpdb->delete(
			Schema::table( 'match_lineups' ),
			array(
				'match_id'  => $match_id,
				'player_id' => $player_id,
				'tenant_id' => $tenant_id,
			)
		);
		if ( false === $ok ) {
			return self::db_error();
		}
		$logged = AuditLogger::log( 'handicap.snapshot_remove', 'match', $match_id, $reason, $before, null, $tenant_id );
		return false === $logged ? self::db_error() : true;
	}

	/**
	 * One player's stored row for a match.
	 *
	 * @param int $match_id  Match id.
	 * @param int $player_id Player id.
	 * @param int $tenant_id Tenant.
	 * @return array<string, mixed>|false|null The row, null when there is none, false when the query failed.
	 */
	private static function find_row( int $match_id, int $player_id, int $tenant_id ): array|false|null {
		global $wpdb;

		$table = Schema::table( 'match_lineups' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, snapshot_json FROM {$table} WHERE match_id = %d AND player_id = %d AND tenant_id = %d FOR UPDATE", $match_id, $player_id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		if ( Db::last_query_failed() ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A database failure.
	 */
	private static function db_error(): WP_Error {
		return new WP_Error( 'cgc_db_error', __( 'The database could not save that.', 'cgc' ) );
	}
}
