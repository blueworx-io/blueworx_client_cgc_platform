const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, PHP_PROBLEM } = require( './helpers' );

async function readSchema( page ) {
	const res = await page.request.get( '/?cgc_test_schema=1' );
	expect( res.ok() ).toBe( true );
	return res.json();
}

test.beforeEach( async ( { page } ) => {
	await loginAsAdmin( page );
} );

test( 'the plugin is active and the plugins screen shows no PHP notices', async ( { page } ) => {
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( '#deactivate-cgc-championship' ) ).toBeVisible();
	// The whole page, not just the content area: startup warnings print before it.
	expect( await page.content() ).not.toMatch( PHP_PROBLEM );
	await expect( page.locator( '#wpbody-content' ) ).not.toContainText( 'run "composer install"' );
} );

test( 'every table exists with its columns and indexes, and the roles exist', async ( { page } ) => {
	const schema = await readSchema( page );
	expect( schema.active ).toBe( true );
	expect( schema.db_version ).toBeTruthy();
	const names = Object.keys( schema.tables );
	expect( names.length ).toBeGreaterThan( 0 );
	for ( const name of names ) {
		const table = schema.tables[ name ];
		expect( table.columns, `${ name } has columns` ).not.toHaveLength( 0 );
		for ( const col of [ 'id', 'tenant_id', 'created_at', 'updated_at', 'is_demo' ] ) {
			expect( table.columns, `${ name } has ${ col }` ).toContain( col );
		}
		expect( table.missing_columns, `${ name } missing columns` ).toEqual( [] );
		expect( table.bad_indexes, `${ name } wrong or missing indexes` ).toEqual( [] );
	}
	for ( const role of Object.keys( schema.roles ) ) {
		expect( schema.roles[ role ], role ).toBe( true );
	}
} );

test( 'activating again is safe and changes nothing', async ( { page } ) => {
	const before = await readSchema( page );

	await page.goto( '/wp-admin/plugins.php' );
	await page.click( '#deactivate-cgc-championship' );
	await expect( page.locator( '#activate-cgc-championship' ) ).toBeVisible();
	await page.click( '#activate-cgc-championship' );
	await expect( page.locator( '#deactivate-cgc-championship' ) ).toBeVisible();
	expect( await page.content() ).not.toMatch( PHP_PROBLEM );

	const after = await readSchema( page );
	expect( after.tables ).toEqual( before.tables );
	expect( after.db_version ).toBe( before.db_version );
	expect( after.tenant_rows ).toBe( 1 );
} );
