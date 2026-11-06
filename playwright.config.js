// Browser tests for the settings screen (docs/08 acceptance, docs/11). Runs against a throwaway
// SQLite WordPress built by bin/e2e-server.sh (needs bin/install-wp-tests.sh and npm run build first).
const { defineConfig } = require( '@playwright/test' );

const port = process.env.E2E_PORT || '8889';

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60000,
	reporter: [ [ 'list' ] ],
	outputDir: '.wp-tests/e2e-results',
	use: {
		baseURL: `http://127.0.0.1:${ port }`,
		trace: 'off',
		screenshot: 'only-on-failure',
	},
	webServer: {
		command: `bin/e2e-server.sh ${ port }`,
		url: `http://127.0.0.1:${ port }/wp-login.php`,
		timeout: 120000,
		reuseExistingServer: ! process.env.CI,
		stdout: 'ignore',
		stderr: 'pipe',
	},
} );
