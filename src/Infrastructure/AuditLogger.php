<?php
/**
 * Audit logger.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

use CGC\Championship\Clock;
use CGC\Championship\DemoMode;

/**
 * Writes immutable rows to cgc_audit_log. There is deliberately no update or delete method.
 */
final class AuditLogger {

	/**
	 * Records one audited action.
	 *
	 * @param string                       $action      Verb such as "draw.publish".
	 * @param string                       $entity_type Entity kind such as "match".
	 * @param int                          $entity_id   Entity id.
	 * @param string                       $reason      Why. Required.
	 * @param array<array-key, mixed>|null $before State before the change.
	 * @param array<array-key, mixed>|null $after  State after the change.
	 * @param int                          $tenant_id   Tenant.
	 * @return int|false New row id, or false when the reason is empty the snapshots cannot be encoded as JSON, or the insert failed.
	 */
	public static function log(
		string $action,
		string $entity_type,
		int $entity_id,
		string $reason,
		?array $before = null,
		?array $after = null,
		int $tenant_id = 1
	): int|false {
		global $wpdb;

		if ( '' === trim( $reason ) ) {
			return false;
		}

		$before_json = null === $before ? null : wp_json_encode( $before );
		$after_json  = null === $after ? null : wp_json_encode( $after );
		if ( false === $before_json || false === $after_json ) {
			return false;
		}

		$now      = Clock::mysql();
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$inserted = $wpdb->insert(
			Schema::table( 'audit_log' ),
			array(
				'tenant_id'     => $tenant_id,
				'created_at'    => $now,
				'updated_at'    => $now,
				'is_demo'       => DemoMode::enabled() ? 1 : 0,
				'actor_user_id' => get_current_user_id(),
				'action'        => $action,
				'entity_type'   => $entity_type,
				'entity_id'     => $entity_id,
				'before_json'   => $before_json,
				'after_json'    => $after_json,
				'reason'        => $reason,
				'ip'            => $ip,
			)
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}
}
