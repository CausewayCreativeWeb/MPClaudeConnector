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

/**
 * Starts the extension's server with the manifest's command and env, the way Claude Desktop does,
 * and returns a JSON-RPC client for it.
 */
function startProxy( dir, manifest, credentials ) {
	const [ username, password ] = credentials.split( ':' );
	const config = manifest.server.mcp_config;
	const env = { ...process.env };
	for ( const [ key, value ] of Object.entries( config.env ) ) {
		env[ key ] = value.replace( '${user_config.username}', username ).replace( '${user_config.app_password}', password );
	}
	// The test site is plain http: tell WordPress the request came through HTTPS (bin/e2e-server.sh trusts this header).
	env.CUSTOM_HEADERS = JSON.stringify( { 'X-Forwarded-Proto': 'https' } );
	const child = spawn( config.command, config.args.map( ( a ) => a.replace( '${__dirname}', dir ) ), { env } );

	const waiting = new Map();
	let buffer = '';
	let nextId = 1;
	child.stdout.on( 'data', ( chunk ) => {
		buffer += chunk;
		let index;
		while ( ( index = buffer.indexOf( '\n' ) ) >= 0 ) {
			const line = buffer.slice( 0, index ).trim();
			buffer = buffer.slice( index + 1 );
			if ( ! line ) {
				continue;
			}
			const message = JSON.parse( line );
			if ( waiting.has( message.id ) ) {
				waiting.get( message.id )( message );
				waiting.delete( message.id );
			}
		}
	} );

	const request = ( method, params ) => new Promise( ( resolveCall, reject ) => {
		const id = nextId++;
		const timer = setTimeout( () => reject( new Error( `No reply to ${ method }` ) ), 60000 );
		waiting.set( id, ( message ) => {
			clearTimeout( timer );
			resolveCall( message );
		} );
		child.stdin.write( JSON.stringify( { jsonrpc: '2.0', id, method, params } ) + '\n' );
	} );
	const tool = async ( name, args = {} ) => {
		const reply = await request( 'tools/call', { name, arguments: args } );
		const text = reply.result.content[ 0 ].text;
		if ( reply.result.isError ) {
			throw new Error( `${ name }: ${ text }` );
		}
		return JSON.parse( text );
	};

	return {
		async init() {
			await request( 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } } );
			child.stdin.write( JSON.stringify( { jsonrpc: '2.0', method: 'notifications/initialized' } ) + '\n' );
		},
		request,
		tool,
		stop: () => child.kill(),
	};
}

/** Downloads the pack as the given user and unpacks it; returns the folders. */
async function downloadPack( page, user ) {
	await login( page, user );
	await page.goto( '/wp-admin/profile.php' );
	const download = page.waitForEvent( 'download' );
	await page.getByRole( 'link', { name: 'Download setup pack' } ).click();
	const file = await download;
	const dir = mkdtempSync( join( tmpdir(), 'ccb-pack-' ) );
	const zip = join( dir, 'pack.zip' );
	await file.saveAs( zip );
	execFileSync( 'unzip', [ '-q', zip, '-d', join( dir, 'pack' ) ] );
	const ext = join( dir, 'ext' );
	execFileSync( 'unzip', [ '-q', join( dir, 'pack/example-constituency-publishing.mcpb' ), '-d', ext ] );
	return { name: file.suggestedFilename(), dir, ext, manifest: JSON.parse( readFileSync( join( ext, 'manifest.json' ), 'utf8' ) ) };
}

test( 'setup pack downloads, validates and connects', async ( { page } ) => {
	test.skip( ! existsSync( join( ROOT, 'setup-pack/extension/server/index.js' ) ), 'Run npm run build:extension first' );

	const pack = await downloadPack( page, 'casey' );
	expect( pack.name ).toMatch( /^setup-pack-example-constituency-[\d.]+\.zip$/ );
	expect( existsSync( join( pack.dir, 'pack/how-to.pdf' ) ) ).toBe( true );
	execFileSync( join( ROOT, 'node_modules/.bin/mcpb' ), [ 'validate', join( pack.ext, 'manifest.json' ) ], { stdio: 'pipe' } );

	const skillDir = join( pack.dir, 'skill' );
	execFileSync( 'unzip', [ '-q', join( pack.dir, 'pack/example-constituency-publishing-skill.zip' ), '-d', skillDir ] );
	expect( readFileSync( join( skillDir, 'example-constituency-publishing/SKILL.md' ), 'utf8' ) ).toContain( 'name: example-constituency-publishing' );

	const proxy = startProxy( pack.ext, pack.manifest, readFileSync( join( SITE, '.e2e-app-password' ), 'utf8' ).trim() );
	try {
		await proxy.init();
		const tools = ( await proxy.request( 'tools/list', {} ) ).result.tools.map( ( t ) => t.name );
		expect( tools ).toHaveLength( 21 );
		expect( tools.every( ( n ) => n.startsWith( 'ccb-' ) ) ).toBe( true );
		const check = await proxy.tool( 'ccb-check-connection', { skill_version: pack.manifest.version } );
		expect( check.user.display_name ).toBe( 'Casey Contributor' );
		expect( check.credential.name ).toBe( 'Casey – test laptop' );
		expect( check.issues.map( ( i ) => i.code ) ).not.toContain( 'skill_outdated' );
	} finally {
		proxy.stop();
	}

	await login( page, 'admin' );
	await page.goto( '/wp-admin/options-general.php?page=claude-publishing#activity' );
	await expect( page.locator( '#ccb-panel-activity table' ) ).toContainText( 'setup_pack_download' );
} );

test( 'a Publisher creates, confirms and publishes a post through the extension', async ( { page } ) => {
	test.skip( ! existsSync( join( ROOT, 'setup-pack/extension/server/index.js' ) ), 'Run npm run build:extension first' );
	const pack = await downloadPack( page, 'publisher' );
	const proxy = startProxy( pack.ext, pack.manifest, readFileSync( join( SITE, '.e2e-publisher-password' ), 'utf8' ).trim() );

	// A 1600 × 900 photo, made with PHP's GD (no image library in Node).
	const photo = execFileSync( 'php', [ '-r', '$i=imagecreatetruecolor(1600,900);imagefilledrectangle($i,0,0,800,900,imagecolorallocate($i,30,90,160));ob_start();imagejpeg($i,null,85);echo base64_encode(ob_get_clean());' ] ).toString();

	try {
		await proxy.init();
		const guide = await proxy.tool( 'ccb-get-publishing-guide', { section: 'news' } );
		expect( guide.sections[ 0 ].guide ).toContain( 'News' );

		const created = await proxy.tool( 'ccb-create-post', {
			section: 'news',
			title: 'Bridge reopens after repairs',
			content: '<p>' + 'The bridge is open again. '.repeat( 40 ) + '</p>',
			status: 'draft',
			idempotency_key: 'e2e-' + Date.now(),
		} );
		expect( created.status ).toBe( 'draft' );

		const image = await proxy.tool( 'ccb-upload-image', { filename: 'bridge.jpg', data_base64: photo, alt: 'The reopened bridge', post_id: created.post_id, set_featured: true } );
		expect( image.attachment_id ).toBeGreaterThan( 0 );

		const prepared = await proxy.tool( 'ccb-prepare-publish', { post_id: created.post_id } );
		expect( prepared.token ).toBeTruthy();
		expect( JSON.stringify( prepared.summary ) ).toContain( 'Bridge reopens after repairs' );

		// Without the token: refused.
		await expect( proxy.tool( 'ccb-publish-post', { post_id: created.post_id, token: 'A'.repeat( 43 ) } ) ).rejects.toThrow( /ccb_token_invalid/ );
		// A wrong token matches no row, so the real one is still unused.
		const published = await proxy.tool( 'ccb-publish-post', { post_id: created.post_id, token: prepared.token } );
		expect( published.status ).toBe( 'publish' );
		await expect( proxy.tool( 'ccb-publish-post', { post_id: created.post_id, token: prepared.token } ) ).rejects.toThrow( /ccb_token_invalid/ );

		const response = await page.request.get( published.url.replace( /^https:/, 'http:' ) );
		expect( response.status() ).toBe( 200 );
		expect( await response.text() ).toContain( 'Bridge reopens after repairs' );
	} finally {
		proxy.stop();
	}
} );
