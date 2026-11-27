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

// M1: round-trips courses, tees and handicap snapshots through the real repositories.
add_action( 'init', function () {
	if ( ! isset( $_GET['cgc_test_m1'] ) || ! current_user_can( 'manage_options' ) || ! class_exists( \CGC\Championship\Infrastructure\CourseRepository::class ) ) {
		return;
	}
	$repo  = \CGC\Championship\Infrastructure\CourseRepository::class;
	$snaps = \CGC\Championship\Infrastructure\LineupSnapshotRepository::class;
	$pars  = array( 4, 4, 3, 5, 4, 4, 3, 4, 5, 4, 4, 3, 5, 4, 4, 3, 4, 5 );
	$sis   = array( 7, 1, 15, 11, 3, 13, 17, 5, 9, 8, 2, 16, 12, 4, 14, 18, 6, 10 );
	$holes = array();
	foreach ( $pars as $i => $par ) {
		$holes[] = array( 'hole' => $i + 1, 'par' => $par, 'si' => $sis[ $i ], 'yards' => 300 + $i );
	}
	$tee = array( 'name' => 'Yellow', 'gender' => 'male', 'par' => 72, 'course_rating' => 71.2, 'slope' => 125 );
	$err = static function ( $r ) {
		return is_wp_error( $r ) ? array( 'code' => $r->get_error_code(), 'fields' => array_keys( (array) ( $r->get_error_data()['fields'] ?? array() ) ) ) : null;
	};

	$out       = array();
	$course_id = $repo::create_course( array( 'name' => 'Test Links', 'club' => 'Test GC', 'lat' => 51.5, 'lng' => -1.2 ) );
	$out['course_created'] = is_int( $course_id );
	$out['bad_course']     = $err( $repo::create_course( array( 'name' => '' ) ) );
	$out['tag_only_name']  = $err( $repo::create_course( array( 'name' => '<b></b>' ) ) );

	$bad_slope             = $repo::create_tee( $course_id, array_merge( $tee, array( 'slope' => 156 ) ), $holes );
	$out['bad_slope']      = $err( $bad_slope );
	$dup                   = $holes;
	$dup[1]['si']          = $dup[0]['si'];
	$out['bad_si']         = $err( $repo::create_tee( $course_id, $tee, $dup ) );
	$out['bad_par_total']  = $err( $repo::create_tee( $course_id, array_merge( $tee, array( 'par' => 71 ) ), $holes ) );
	$out['tees_after_bad'] = count( $repo::tees_for_course( $course_id ) );

	$tee_id               = $repo::create_tee( $course_id, $tee, $holes );
	$out['tee_created']   = is_int( $tee_id );
	$loaded               = $repo::get_tee( $tee_id );
	$out['tee']           = array( 'slope' => $loaded->slope, 'rating' => $loaded->course_rating, 'par' => $loaded->par, 'holes' => count( $loaded->holes ), 'first_si' => $loaded->holes[0]->si );
	$out['tee_updated']   = true === $repo::update_tee( $tee_id, array_merge( $tee, array( 'slope' => 130 ) ), $holes );
	$out['slope_after']   = $repo::get_tee( $tee_id )->slope;
	$out['hole_rows']     = count( $repo::get_tee( $tee_id )->holes );

	$profile = \CGC\Championship\Domain\Handicap\HandicapProfile::cgc();
	$mk      = static function ( $id, $idx, $rounds, $team ) use ( $loaded ) {
		return new \CGC\Championship\Domain\Handicap\PlayerHandicapInput( $id, $idx, $rounds, $loaded, false, null, null, false, $team, $team === 1 ? 'a' : 'b' );
	};
	$players = array( $mk( 101, 4.2, 14, 1 ), $mk( 102, 12.8, 6, 1 ), $mk( 103, 18.5, 20, 2 ), $mk( 104, 31.0, 8, 2 ) );
	$match   = random_int( 100000, 2000000000 ); // Fresh each run: audit rows are never deleted.

	$first                 = $snaps::snapshot_match( $match, $players, $profile, 'fourball_mp' );
	$out['snapshot_saved'] = is_array( $first ) ? count( $first ) : $err( $first );
	$again                 = $snaps::snapshot_match( $match, $players, $profile, 'fourball_mp' );
	$out['snapshot_again'] = is_wp_error( $again ) ? $again->get_error_code() : 'replaced';
	$stored                = $snaps::get_for_match( $match );
	$out['stored_count']   = count( $stored );
	$out['stored_strokes'] = array_map( static fn( $r ) => $r['snapshot']['strokes'], $stored );
	$out['stored_team']    = array_map( static fn( $r ) => $r['team_id'], $stored );
	$out['stored_hash']    = $stored[0]['snapshot']['settings_hash'] === $profile->hash();
	$with_reason           = $snaps::snapshot_match( $match, $players, $profile, 'fourball_mp', 'Organiser corrected a tee' );
	$out['replaced']       = is_array( $with_reason );
	$out['no_dupes']       = count( $snaps::get_for_match( $match ) );

	global $wpdb;
	$audit               = \CGC\Championship\Infrastructure\Schema::table( 'audit_log' );
	$out['audit_rows']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s AND entity_id = %d", 'handicap.snapshot_replace', $match ) );

	// A reserve replaces player 104: the old row goes, the removal is audited, three rows stay unchanged in number.
	$reserve_lineup        = array( $players[0], $players[1], $players[2], $mk( 105, 20.0, 9, 2 ) );
	$swap                  = $snaps::snapshot_match( $match, $reserve_lineup, $profile, 'fourball_mp', 'Reserve came in' );
	$out['swap_ok']        = is_array( $swap );
	$out['after_swap']     = array_map( static fn( $r ) => $r['player_id'], $snaps::get_for_match( $match ) );
	$out['removal_audits'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s AND entity_id = %d", 'handicap.snapshot_remove', $match ) );

	// A second row for the same player is refused by the database itself.
	$lineups_table         = Schema::table( 'match_lineups' );
	$now                   = gmdate( 'Y-m-d H:i:s' );
	$suppress              = $wpdb->suppress_errors( true );
	$dupe                  = $wpdb->insert( $lineups_table, array( 'tenant_id' => 1, 'created_at' => $now, 'updated_at' => $now, 'match_id' => $match, 'team_id' => 1, 'player_id' => 101, 'snapshot_json' => '{}' ) );
	$wpdb->suppress_errors( $suppress );
	$out['duplicate_blocked'] = false === $dupe;
	$lineups             = \CGC\Championship\Infrastructure\Schema::table( 'match_lineups' );
	$wpdb->delete( $lineups, array( 'match_id' => $match ) );

	$out['course_deleted'] = true === $repo::delete_course( $course_id );
	$out['tee_gone']       = null === $repo::get_tee( $tee_id );
	$out['course_gone']    = null === $repo::get_course( $course_id );
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
