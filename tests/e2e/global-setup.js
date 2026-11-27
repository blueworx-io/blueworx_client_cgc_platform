// Prepares the disposable test site before the specs run:
// 1. Installs the plugin's Composer packages if they are missing (the check starts the site
//    before any Composer install), then switches the plugin off and on once so its install
//    routine runs with the packages present.
// 2. Writes a test-only mu-plugin into the site's wp-content (never shipped). It lets a test
//    read back what the plugin's install routine created.
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( '@playwright/test' );

const ROOT = path.join( __dirname, '..', '..' );
const SLUG = 'cgc-championship';

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
			$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			$indexes = (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
			$out['tables'][ $short ] = array(
				'columns'         => $columns,
				'indexes'         => array_values( array_unique( array_column( $indexes, 'Key_name' ) ) ),
				'missing_columns' => array_values( array_diff( Schema::expected_columns( $short ), $columns ) ),
				'bad_indexes'     => Schema::index_mismatches( $indexes, $short ),
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

// The plugin folder the test site really loads (the site may link or copy it).
function pluginDir( contentDir ) {
	const installed = path.join( contentDir, 'plugins', SLUG );
	return fs.existsSync( installed ) ? fs.realpathSync( installed ) : ROOT;
}

function ensureComposerPackages( dir ) {
	if ( fs.existsSync( path.join( dir, 'vendor', 'autoload.php' ) ) ) {
		return false;
	}
	execFileSync( 'composer', [ 'install', '--no-dev', '--no-interaction', '--no-progress' ], {
		cwd: dir,
		stdio: 'inherit',
		shell: process.platform === 'win32',
	} );
	return true;
}

async function cyclePlugin( baseURL ) {
	const user = process.env.WP_ADMIN_USER || 'admin';
	const pass = process.env.WP_ADMIN_PASS || 'admin';
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage( { baseURL } );
		await page.goto( '/wp-login.php', { timeout: 60000 } );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/, { timeout: 60000 } );
		await page.goto( '/wp-admin/plugins.php' );
		const off = page.locator( `#deactivate-${ SLUG }` );
		if ( await off.count() ) {
			await off.click();
		}
		await page.locator( `#activate-${ SLUG }` ).click();
		await page.locator( `#deactivate-${ SLUG }` ).waitFor( { timeout: 60000 } );
	} finally {
		await browser.close();
	}
}

module.exports = async ( config ) => {
	const baseURL = config.projects[ 0 ].use.baseURL;
	const local = [ '127.0.0.1', 'localhost' ].includes( new URL( baseURL ).hostname );
	if ( ! local && ! process.env.CGC_WP_CONTENT_DIR ) {
		throw new Error( `${ baseURL } is not a local test site. Set CGC_WP_CONTENT_DIR to that site's wp-content folder.` );
	}
	const contentDir = process.env.CGC_WP_CONTENT_DIR || path.join( ROOT, '.wp-test', 'wp', 'wp-content' );
	if ( ! fs.existsSync( contentDir ) ) {
		throw new Error(
			`The test site's wp-content folder was not found at ${ contentDir }. Start the test site first, or set CGC_WP_CONTENT_DIR.`
		);
	}
	const dir = path.join( contentDir, 'mu-plugins' );
	fs.mkdirSync( dir, { recursive: true } );
	fs.writeFileSync( path.join( dir, 'cgc-test-harness.php' ), MU_PLUGIN );

	if ( ensureComposerPackages( pluginDir( contentDir ) ) ) {
		await cyclePlugin( baseURL );
	}
};
