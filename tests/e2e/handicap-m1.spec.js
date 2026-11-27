const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, PHP_PROBLEM } = require( './helpers' );

test.beforeEach( async ( { page } ) => {
	await loginAsAdmin( page );
} );

test( 'courses, tees and handicap snapshots save, validate and read back on a real site', async ( { page } ) => {
	const res = await page.request.get( '/?cgc_test_m1=1' );
	expect( res.ok() ).toBe( true );
	expect( await res.text() ).not.toMatch( PHP_PROBLEM );
	const out = await res.json();

	// Validation refuses bad data and saves nothing.
	expect( out.course_created ).toBe( true );
	expect( out.bad_course ).toMatchObject( { code: 'cgc_invalid', fields: [ 'name' ] } );
	expect( out.bad_slope ).toMatchObject( { code: 'cgc_invalid' } );
	expect( out.bad_slope.fields ).toContain( 'slope' );
	expect( out.bad_si.fields ).toContain( 'holes.2.si' );
	expect( out.bad_par_total.fields ).toContain( 'par_total' );
	expect( out.tees_after_bad ).toBe( 0 );

	// A good tee reads back whole, and can be changed.
	expect( out.tee_created ).toBe( true );
	expect( out.tee ).toMatchObject( { slope: 125, rating: 71.2, par: 72, holes: 18, first_si: 7 } );
	expect( out.tee_updated ).toBe( true );
	expect( out.slope_after ).toBe( 130 );
	expect( out.hole_rows ).toBe( 18 );

	// One snapshot per player per match; replacing needs a reason and is audited.
	expect( out.snapshot_saved ).toBe( 4 );
	expect( out.snapshot_again ).toBe( 'cgc_snapshot_exists' );
	expect( out.stored_count ).toBe( 4 );
	expect( out.stored_team ).toEqual( [ 1, 1, 2, 2 ] );
	expect( out.stored_hash ).toBe( true );
	expect( out.replaced ).toBe( true );
	expect( out.no_dupes ).toBe( 4 );
	expect( out.audit_rows ).toBe( 4 );

	// The stored snapshots hold the worked-example strokes (the tee was read before it was changed).
	expect( out.stored_strokes ).toEqual( [ 0, 7, 15, 20 ] );

	// Deleting a course takes its tees and holes with it.
	expect( out.course_deleted ).toBe( true );
	expect( out.tee_gone ).toBe( true );
	expect( out.course_gone ).toBe( true );
} );
