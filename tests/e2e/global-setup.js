// Writes a test-only mu-plugin into the disposable test site (.wp-test, never shipped).
// It lets a test read back what the plugin's install routine created.
const fs = require( 'fs' );
const path = require( 'path' );

const MU_PLUGIN = String.raw`<?php
/**
 * Plugin Name: CGC test harness
 * Description: Written by tests/e2e/global-setup.js into the disposable test site only.
 */
use CGC\Championship\Infrastructure\Schema;

add_action( 'init', function () {
	if ( ! isset( $_GET['cgc_test_schema'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$out = array( 'active' => class_exists( Schema::class ), 'db_version' => get_option( 'cgc_db_version' ), 'tables' => array(), 'roles' => array() );
	if ( $out['active'] ) {
		foreach ( Schema::table_names() as $short ) {
			$table   = Schema::table( $short );
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
			$out['tables'][ $short ] = array(
				'columns'          => (array) $columns,
				'indexes'          => (array) $indexes,
				'missing_columns'  => array_values( array_diff( Schema::expected_columns( $short ), (array) $columns ) ),
				'missing_indexes'  => array_values( array_diff( Schema::expected_indexes( $short ), (array) $indexes ) ),
			);
		}
		foreach ( array( 'cgc_super_admin', 'cgc_tournament_admin', 'cgc_group_manager', 'cgc_player' ) as $role ) {
			$out['roles'][ $role ] = null !== get_role( $role );
		}
		$tenants = Schema::table( 'tenants' );
		$out['tenant_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tenants}" );
	}
	wp_send_json( $out );
} );
`;

module.exports = async () => {
	const wpContent = path.join( __dirname, '..', '..', '.wp-test', 'wp', 'wp-content' );
	if ( ! fs.existsSync( wpContent ) ) {
		console.warn( 'global-setup: no .wp-test site found, so the test mu-plugin was not written.' );
		return;
	}
	const dir = path.join( wpContent, 'mu-plugins' );
	fs.mkdirSync( dir, { recursive: true } );
	fs.writeFileSync( path.join( dir, 'cgc-test-harness.php' ), MU_PLUGIN );
};
