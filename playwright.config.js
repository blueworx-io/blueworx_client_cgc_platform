// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

// Runs against the disposable WordPress the check starts (PLAYWRIGHT_BASE_URL).
const baseURL = process.env.PLAYWRIGHT_BASE_URL || process.env.BASE_URL || 'http://127.0.0.1:8881';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: 'list',
	timeout: 90000,
	use: { baseURL, trace: 'on-first-retry' },
	projects: [ { name: 'wordpress', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
