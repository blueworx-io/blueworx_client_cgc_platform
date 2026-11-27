const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

// PHP prints these labels when a notice, warning or fatal error reaches the page.
const PHP_PROBLEM = /(Notice|Warning|Deprecated|Fatal error|Parse error):\s/;

module.exports = { loginAsAdmin, PHP_PROBLEM };
