<?php
/**
 * Roles and capabilities.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

/**
 * Creates the CGC roles and capabilities. Safe to run repeatedly.
 */
final class Roles {

	public const SUPER_ADMIN      = 'cgc_super_admin';
	public const TOURNAMENT_ADMIN = 'cgc_tournament_admin';
	public const GROUP_MANAGER    = 'cgc_group_manager';
	public const PLAYER           = 'cgc_player';

	/**
	 * Capabilities of the tournament admin (also given to the group manager).
	 *
	 * @return list<string>
	 */
	public static function tournament_caps(): array {
		return array(
			'cgc_manage_tournament',
			'cgc_manage_draw',
			'cgc_approve_handicaps',
			'cgc_resolve_disputes',
			'cgc_override_results',
			'cgc_send_announcements',
			'cgc_manage_payments',
			'cgc_moderate_chat',
			'cgc_view_audit',
		);
	}

	/**
	 * Every capability the plugin defines.
	 *
	 * @return list<string>
	 */
	public static function all_caps(): array {
		return array_merge( array( 'cgc_manage_platform', 'cgc_play' ), self::tournament_caps() );
	}

	/**
	 * Role slug => display name and capabilities.
	 *
	 * @return array<string, array{name: string, caps: list<string>}>
	 */
	public static function definitions(): array {
		return array(
			self::SUPER_ADMIN      => array(
				'name' => 'CGC Super Admin',
				'caps' => array_merge( array( 'read' ), self::all_caps() ),
			),
			self::TOURNAMENT_ADMIN => array(
				'name' => 'CGC Tournament Admin',
				'caps' => array_merge( array( 'read' ), self::tournament_caps() ),
			),
			// Object-scoped to assigned regions/groups later, through map_meta_cap.
			self::GROUP_MANAGER    => array(
				'name' => 'CGC Group Manager',
				'caps' => array_merge( array( 'read' ), self::tournament_caps() ),
			),
			self::PLAYER           => array(
				'name' => 'CGC Player',
				'caps' => array( 'read', 'cgc_play' ),
			),
		);
	}

	/**
	 * Creates roles and adds any missing capability. add_cap() is idempotent.
	 */
	public static function sync(): void {
		foreach ( self::definitions() as $slug => $definition ) {
			$role = get_role( $slug );
			if ( null === $role ) {
				$role = add_role( $slug, $definition['name'] );
			}
			if ( null === $role ) {
				continue;
			}
			foreach ( $definition['caps'] as $cap ) {
				$role->add_cap( $cap );
			}
		}

		$administrator = get_role( 'administrator' );
		if ( null !== $administrator ) {
			foreach ( self::all_caps() as $cap ) {
				$administrator->add_cap( $cap );
			}
		}
	}
}
