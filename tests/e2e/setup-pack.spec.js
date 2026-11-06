// Setup pack (docs/10): downloaded from the profile page, validated with the MCPB CLI, and the
// extension's bundled proxy run against this site's ccb MCP server the way Claude Desktop runs it.
const { test, expect } = require( '@playwright/test' );
const { execFileSync, spawn } = require( 'node:child_process' );
const { mkdtempSync, readFileSync, existsSync } = require( 'node:fs' );
const { tmpdir } = require( 'node:os' );
const { join, resolve } = require( 'node:path' );

const ROOT = resolve( __dirname, '../..' );
const SITE = join( ROOT, '.wp-tests/e2e-site' );

async function login( page, user ) {
	await page.context().clearCookies();
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', 'e2e-password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/** Runs the proxy with the manifest's command and env, sends JSON-RPC lines, returns the replies. */
function runProxy( dir, manifest, credentials, messages ) {
	const [ username, password ] = credentials.split( ':' );
	const config = manifest.server.mcp_config;
	const env = { ...process.env };
	for ( const [ key, value ] of Object.entries( config.env ) ) {
		env[ key ] = value.replace( '${user_config.username}', username ).replace( '${user_config.app_password}', password );
	}
	// The test site is plain http: tell WordPress the request came through HTTPS (bin/e2e-server.sh trusts this header).
	env.CUSTOM_HEADERS = JSON.stringify( { 'X-Forwarded-Proto': 'https' } );
	const args = config.args.map( ( a ) => a.replace( '${__dirname}', dir ) );

	return new Promise( ( resolvePromise, reject ) => {
		const child = spawn( config.command, args, { env } );
		const replies = [];
		let buffer = '';
		child.stdout.on( 'data', ( chunk ) => {
			buffer += chunk;
			let index;
			while ( ( index = buffer.indexOf( '\n' ) ) >= 0 ) {
				const line = buffer.slice( 0, index ).trim();
				buffer = buffer.slice( index + 1 );
				if ( line ) {
					replies.push( JSON.parse( line ) );
				}
				if ( replies.filter( ( r ) => r.id !== undefined ).length === messages.filter( ( m ) => m.id !== undefined ).length ) {
					child.kill();
					resolvePromise( replies );
				}
			}
		} );
		child.on( 'error', reject );
		setTimeout( () => {
			child.kill();
			reject( new Error( 'Proxy timed out; replies so far: ' + JSON.stringify( replies ) ) );
		}, 30000 );
		for ( const message of messages ) {
			child.stdin.write( JSON.stringify( message ) + '\n' );
		}
	} );
}

test( 'setup pack downloads, validates and connects', async ( { page } ) => {
	test.skip( ! existsSync( join( ROOT, 'setup-pack/extension/server/index.js' ) ), 'Run npm run build:extension first' );

	await login( page, 'casey' );
	await page.goto( '/wp-admin/profile.php' );
	const download = page.waitForEvent( 'download' );
	await page.getByRole( 'link', { name: 'Download setup pack' } ).click();
	const file = await download;
	expect( file.suggestedFilename() ).toMatch( /^setup-pack-example-constituency-[\d.]+\.zip$/ );

	const dir = mkdtempSync( join( tmpdir(), 'ccb-pack-' ) );
	const zip = join( dir, 'pack.zip' );
	await file.saveAs( zip );
	execFileSync( 'unzip', [ '-q', zip, '-d', join( dir, 'pack' ) ] );
	expect( existsSync( join( dir, 'pack/how-to.pdf' ) ) ).toBe( true );

	const ext = join( dir, 'ext' );
	execFileSync( 'unzip', [ '-q', join( dir, 'pack/example-constituency-publishing.mcpb' ), '-d', ext ] );
	execFileSync( join( ROOT, 'node_modules/.bin/mcpb' ), [ 'validate', join( ext, 'manifest.json' ) ], { stdio: 'pipe' } );

	const skillDir = join( dir, 'skill' );
	execFileSync( 'unzip', [ '-q', join( dir, 'pack/example-constituency-publishing-skill.zip' ), '-d', skillDir ] );
	expect( readFileSync( join( skillDir, 'example-constituency-publishing/SKILL.md' ), 'utf8' ) ).toContain( 'name: example-constituency-publishing' );

	const manifest = JSON.parse( readFileSync( join( ext, 'manifest.json' ), 'utf8' ) );
	const replies = await runProxy( ext, manifest, readFileSync( join( SITE, '.e2e-app-password' ), 'utf8' ).trim(), [
		{ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } } },
		{ jsonrpc: '2.0', method: 'notifications/initialized' },
		{ jsonrpc: '2.0', id: 2, method: 'tools/list' },
		{ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'ccb-check-connection', arguments: { skill_version: manifest.version } } },
	] );

	const tools = replies.find( ( r ) => r.id === 2 ).result.tools.map( ( t ) => t.name );
	expect( tools ).toHaveLength( 21 );
	expect( tools.every( ( n ) => n.startsWith( 'ccb-' ) ) ).toBe( true );
	const check = JSON.parse( replies.find( ( r ) => r.id === 3 ).result.content[ 0 ].text );
	expect( check.user.display_name ).toBe( 'Casey Contributor' );
	expect( check.credential.name ).toBe( 'Casey – test laptop' );
	expect( check.issues.map( ( i ) => i.code ) ).not.toContain( 'skill_outdated' );

	// The download is in the activity log.
	await login( page, 'admin' );
	await page.goto( '/wp-admin/options-general.php?page=claude-publishing#activity' );
	await expect( page.locator( '#ccb-panel-activity table' ) ).toContainText( 'setup_pack_download' );
} );
