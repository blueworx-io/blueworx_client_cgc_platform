<?php
/**
 * Data fixes that must run before dbDelta changes a table.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

/**
 * WordPress's dbDelta can add columns and indexes but it cannot change a table's engine, and it cannot add a
 * unique index to a table that already breaks it. These steps do that first. Every step is safe to
 * run again, and returns false when it could not finish (the install then fails and retries).
 */
final class Migrations {

	private const AUDIT_ACTION = 'handicap.snapshot_duplicate_removed';

	/**
	 * Runs every step in order.
	 *
	 * @return bool Whether all steps finished.
	 */
	public static function run(): bool {
		return self::convert_to_innodb() && self::remove_duplicate_snapshots();
	}

	/**
	 * Turns any plugin table that is not InnoDB into InnoDB. Transactions and row locks need it;
	 * MyISAM keeps every write even after a rollback. Tables from before version 0.2.1 may be MyISAM
	 * on a server whose default engine was MyISAM.
	 *
	 * @return bool False when the engines could not be read or a table could not be converted.
	 */
	public static function convert_to_innodb(): bool {
		global $wpdb;

		if ( Db::is_sqlite() ) {
			return true; // SQLite has no engines.
		}
		$engines = Db::engines( Schema::table_names() );
		if ( false === $engines ) {
			return false;
		}
		foreach ( $engines as $table => $engine ) {
			if ( 'innodb' === strtolower( $engine ) ) {
				continue;
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Before version 0.2.0 a match could have two snapshot rows for the same player. The unique key
	 * on (tenant, match, player) cannot be added until they are gone. The most recently saved row is
	 * kept, and every removed row is written to the audit log first.
	 *
	 * @return bool False when a duplicate could not be audited and removed (nothing is lost then).
	 */
	public static function remove_duplicate_snapshots(): bool {
		global $wpdb;

		$table    = Schema::table( 'match_lineups' );
		$suppress = $wpdb->suppress_errors( true );
		$exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$wpdb->suppress_errors( $suppress );
		if ( null === $exists ) {
			return true; // First install: nothing to fix.
		}

		$groups = $wpdb->get_results( "SELECT tenant_id, match_id, player_id FROM {$table} GROUP BY tenant_id, match_id, player_id HAVING COUNT(*) > 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
		if ( ! is_array( $groups ) || Db::last_query_failed() ) {
			return false;
		}

		foreach ( $groups as $group ) {
			if ( ! Db::begin( array( 'match_lineups', 'audit_log' ) ) ) {
				return false;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, snapshot_json FROM {$table} WHERE tenant_id = %d AND match_id = %d AND player_id = %d ORDER BY updated_at DESC, id DESC FOR UPDATE", (int) $group['tenant_id'], (int) $group['match_id'], (int) $group['player_id'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
				ARRAY_A
			);
			if ( ! is_array( $rows ) || Db::last_query_failed() ) {
				Db::rollback();
				return false;
			}
			array_shift( $rows ); // Keep the newest.
			foreach ( $rows as $row ) {
				$before = json_decode( (string) $row['snapshot_json'], true );
				$reason = 'Upgrade removed duplicate handicap snapshot row ' . (int) $row['id'] . ' for player ' . (int) $group['player_id'] . ' (kept the newest).';
				$logged = AuditLogger::log(
					self::AUDIT_ACTION,
					'match',
					(int) $group['match_id'],
					$reason,
					is_array( $before ) ? $before : null,
					null,
					(int) $group['tenant_id']
				);
				// Db::begin() already stops WordPress reconnecting and retrying a write outside this transaction.
				// As well: check the connection, and let the delete work only while this attempt's audit row exists.
				if ( false === $logged || ! Db::connection_unchanged() ) {
					Db::rollback();
					return false;
				}
				$deleted = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE id = %d AND EXISTS ( SELECT 1 FROM %i WHERE id = %d AND tenant_id = %d AND action = %s AND entity_type = %s AND entity_id = %d AND reason = %s )',
						$table,
						(int) $row['id'],
						Schema::table( 'audit_log' ),
						$logged, // This attempt's own audit row: ids are never reused, so an old or rolled-back one cannot match.
						(int) $group['tenant_id'],
						self::AUDIT_ACTION,
						'match',
						(int) $group['match_id'],
						$reason
					)
				);
				if ( 1 !== $deleted || ! Db::connection_unchanged() ) {
					Db::rollback();
					return false;
				}
			}
			if ( ! Db::commit() ) {
				return false;
			}
		}
		return true;
	}
}
