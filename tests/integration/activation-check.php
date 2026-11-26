<?php
/**
 * Activation check against a real WordPress (the Foundation test site).
 *
 * Usage: php tests/integration/activation-check.php <path-to-wordpress>
 *
 * Re-runs the install twice, then checks tables, indexes, roles, the default
 * tenant and that no PHP notice or warning was raised. Exits 1 on any failure.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

use CGC\Championship\Infrastructure\AuditLogger;
use CGC\Championship\Infrastructure\Installer;
use CGC\Championship\Infrastructure\Roles;
use CGC\Championship\Infrastructure\Schema;

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 1 );
}

$cgc_wp = $argv[1] ?? '';
if ( ! is_file( $cgc_wp . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php activation-check.php <path-to-wordpress>\n" );
	exit( 2 );
}

$cgc_errors = array();
set_error_handler(
	static function ( int $no, string $msg, string $file, int $line ) use ( &$cgc_errors ): bool {
		$cgc_errors[] = "PHP error ($no): $msg in $file:$line";
		return true;
	}
);

$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $cgc_wp . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;
$cgc_failures = array();

// Activate through WordPress twice (deactivate in between), so the real activation hook runs.
$cgc_plugin = '';
foreach ( get_plugins() as $cgc_file => $cgc_data ) {
	if ( 'CGC Championship' === $cgc_data['Name'] ) {
		$cgc_plugin = $cgc_file;
	}
}
if ( '' === $cgc_plugin ) {
	fwrite(
		STDERR,
		'FAILED: plugin not found in the plugins folder
'
	);
	exit( 1 );
}
for ( $cgc_i = 0; $cgc_i < 2; $cgc_i++ ) {
	deactivate_plugins( $cgc_plugin );
	$cgc_result = activate_plugin( $cgc_plugin );
	if ( is_wp_error( $cgc_result ) ) {
		$cgc_failures[] = 'Activation failed: ' . $cgc_result->get_error_message();
	}
}
if ( ! is_plugin_active( $cgc_plugin ) ) {
	$cgc_failures[] = 'Plugin is not active after activation';
}
if ( ! class_exists( Installer::class ) ) {
	require_once WP_PLUGIN_DIR . '/' . $cgc_plugin;
}

foreach ( Schema::definitions() as $short => $def ) {
	$table = Schema::table( $short );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		$cgc_failures[] = "Missing table $table";
		continue;
	}
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( array( 'id', 'tenant_id', 'created_at', 'updated_at', 'is_demo' ) as $col ) {
		if ( ! in_array( $col, $columns, true ) ) {
			$cgc_failures[] = "$table lacks column $col";
		}
	}
	$indexes = array_unique( $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wanted  = array( 'PRIMARY', 'tenant_id' );
	foreach ( $def['keys'] as $key ) {
		if ( preg_match( '/KEY (\w+) /', $key, $m ) ) {
			$wanted[] = $m[1];
		}
	}
	foreach ( $wanted as $index ) {
		if ( ! in_array( $index, $indexes, true ) ) {
			$cgc_failures[] = "$table lacks index $index";
		}
	}
}

foreach ( array_keys( Roles::definitions() ) as $slug ) {
	if ( null === get_role( $slug ) ) {
		$cgc_failures[] = "Missing role $slug";
	}
}
$cgc_admin = get_role( 'administrator' );
if ( null === $cgc_admin || ! $cgc_admin->has_cap( 'cgc_manage_platform' ) ) {
	$cgc_failures[] = 'Administrator lacks cgc_manage_platform';
}

$cgc_tenants = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'tenants' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
if ( 1 !== $cgc_tenants ) {
	$cgc_failures[] = "Expected exactly 1 default tenant, found $cgc_tenants";
}
if ( get_option( Installer::DB_VERSION_OPTION ) !== CGC_DB_VERSION ) {
	$cgc_failures[] = 'cgc_db_version not stored';
}

if ( false !== AuditLogger::log( 'test.check', 'test', 1, '' ) ) {
	$cgc_failures[] = 'Audit log accepted an empty reason';
}
if ( false === AuditLogger::log( 'test.check', 'test', 1, 'integration check', array( 'a' => 1 ), array( 'a' => 2 ) ) ) {
	$cgc_failures[] = 'Audit log insert failed';
}

$cgc_failures = array_merge( $cgc_failures, $cgc_errors );
if ( array() !== $cgc_failures ) {
	fwrite( STDERR, "FAILED:\n - " . implode( "\n - ", $cgc_failures ) . "\n" );
	exit( 1 );
}
echo 'OK: ' . count( Schema::definitions() ) . " tables, roles, tenant, no notices (activated twice).\n";
