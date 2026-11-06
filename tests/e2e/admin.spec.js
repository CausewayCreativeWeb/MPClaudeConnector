// Settings → Claude Publishing in a real browser (docs/08 "Acceptance (UI)").
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const PAGE = '/wp-admin/options-general.php?page=claude-publishing';
const TABS = [ 'overview', 'sections', 'writing', 'media', 'access', 'notifications', 'activity', 'setup-pack', 'advanced' ];
const SCHEMES = [ 'fresh', 'light', 'modern', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise' ];

test.describe.configure( { mode: 'serial' } );

async function login( page, user = 'admin' ) {
	await page.context().clearCookies();
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', 'e2e-password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

async function open( page, tab = 'overview' ) {
	await page.goto( `${ PAGE }#${ tab }` );
	await expect( page.locator( `#ccb-panel-${ tab }` ) ).toBeVisible();
}

async function axe( page ) {
	const results = await new AxeBuilder( { page } ).include( '#ccb-app' ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] ).analyze();
	return results.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) }` );
}

test( 'every tab renders without errors and passes axe', async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' && ! m.text().includes( 'ERR_TUNNEL' ) && ! m.text().includes( 'Failed to load resource' ) ) {
			errors.push( m.text() );
		}
	} );
	await login( page );
	for ( const tab of TABS ) {
		await open( page, tab );
		await page.waitForTimeout( 300 );
		expect( await axe( page ), `axe on ${ tab }` ).toEqual( [] );
	}
	expect( errors ).toEqual( [] );
} );

test( 'tabs work with the keyboard and the URL hash', async ( { page } ) => {
	await login( page );
	await open( page, 'overview' );
	await page.locator( '#ccb-tab-overview' ).focus();
	await page.keyboard.press( 'ArrowDown' );
	await expect( page.locator( '#ccb-tab-sections' ) ).toBeFocused();
	await expect( page.locator( '#ccb-tab-sections' ) ).toHaveAttribute( 'aria-selected', 'true' );
	await expect( page ).toHaveURL( /#sections$/ );
	await page.keyboard.press( 'End' );
	await expect( page.locator( '#ccb-tab-advanced' ) ).toBeFocused();
	await page.keyboard.press( 'Home' );
	await expect( page.locator( '#ccb-panel-overview' ) ).toBeVisible();
} );

test( 'saving bumps the version, marks the tab and logs the change', async ( { page } ) => {
	await login( page );
	await open( page, 'access' );
	const before = Number( await page.locator( 'header [x-text*="versionBy"]' ).innerText().then( ( t ) => t.match( /\d+/ )[ 0 ] ) );
	await page.fill( '#ccb-rpm', '45' );
	await expect( page.locator( '#ccb-tab-access .ccb-dot:not(.ccb-dot-error)' ) ).toBeVisible();
	await expect( page.locator( '.ccb-savebar' ) ).toContainText( 'Unsaved changes in 1 section' );
	await page.fill( '#ccb-save-note', 'Raise the limit' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.ccb-savebar' ) ).toBeHidden();
	await expect( page.locator( 'header' ) ).toContainText( `Settings version ${ before + 1 } by admin` );

	await page.reload();
	await expect( page.locator( '#ccb-rpm' ) ).toHaveValue( '45' );
	await open( page, 'activity' );
	await expect( page.locator( '#ccb-panel-activity table' ) ).toContainText( 'config_save' );
} );

test( 'invalid settings are refused and each error links to its field', async ( { page } ) => {
	await login( page );
	await open( page, 'advanced' );
	await page.fill( '#ccb-support-email', 'not-an-email' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	const summary = page.locator( '#ccb-error-summary' );
	await expect( summary ).toBeVisible();
	await expect( page.locator( '#ccb-tab-advanced .ccb-dot-error' ) ).toBeVisible();
	await open( page, 'overview' );
	await summary.locator( 'button' ).first().click();
	await expect( page.locator( '#ccb-support-email' ) ).toBeFocused();
	await expect( page.locator( '#ccb-support-email' ) ).toHaveAttribute( 'aria-invalid', 'true' );
	await page.getByRole( 'button', { name: 'Discard' } ).click();
	await expect( page.locator( '.ccb-savebar' ) ).toBeHidden();
} );

test( 'a second admin saving gets the conflict prompt', async ( { browser } ) => {
	const first = await browser.newPage();
	const second = await browser.newPage();
	await login( first );
	await open( first, 'access' );
	await second.context().addCookies( await first.context().cookies() );
	await open( second, 'access' );

	await first.fill( '#ccb-lpd', '25' );
	await first.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( first.locator( '.ccb-savebar' ) ).toBeHidden();

	await second.fill( '#ccb-lpd', '30' );
	await second.getByRole( 'button', { name: 'Save changes' } ).click();
	const dialog = second.locator( 'dialog.ccb-dialog' );
	await expect( dialog ).toBeVisible();
	await expect( dialog ).toContainText( 'Someone else saved changes' );
	await expect( dialog ).toContainText( '/access/live_changes_per_day' );
	await dialog.getByRole( 'button', { name: 'Overwrite with mine' } ).click();
	await expect( second.locator( '.ccb-savebar' ) ).toBeHidden();
	await second.reload();
	await expect( second.locator( '#ccb-lpd' ) ).toHaveValue( '30' );
	await first.close();
	await second.close();
} );

test( '"What Claude sees" follows unsaved edits', async ( { page } ) => {
	await login( page );
	await open( page, 'sections' );
	await page.locator( '#ccb-notes-news' ).fill( 'Always name the ward.' );
	await expect( page.locator( '.ccb-preview' ) ).toContainText( 'Always name the ward.', { timeout: 2000 } );
	await page.getByRole( 'button', { name: 'Discard' } ).click();
} );

test( 'versions can be compared and restored', async ( { page } ) => {
	await login( page );
	await open( page, 'advanced' );
	await page.getByRole( 'button', { name: 'Compare and restore' } ).first().click();
	const dialog = page.locator( 'dialog.ccb-dialog' );
	await expect( dialog ).toBeVisible();
	await expect( dialog.locator( 'table' ) ).toBeVisible();
	await dialog.getByRole( 'button', { name: 'Restore this version' } ).click();
	await expect( page.locator( '[role="status"]' ) ).toContainText( 'restored as a new version' );
	await open( page, 'activity' );
	await expect( page.locator( '#ccb-panel-activity table' ) ).toContainText( 'config_restore' );
} );

test( 'roles switch and device passwords can be revoked', async ( { page } ) => {
	await login( page );
	await open( page, 'access' );
	await page.selectOption( '#ccb-picker', { label: 'Casey Contributor (casey@example.org)' } );
	await page.getByRole( 'button', { name: 'Make Publisher' } ).first().click();
	await expect( page.locator( '#ccb-panel-access .ccb-card' ).first() ).toContainText( 'Casey Contributor' );

	const sam = page.locator( 'tr', { hasText: 'Sam Contributor' } );
	await sam.getByRole( 'button', { name: '1 device' } ).click();
	await expect( sam ).toContainText( 'Sam – office laptop' );
	await sam.getByRole( 'button', { name: 'Revoke' } ).click();
	const dialog = page.locator( 'dialog.ccb-dialog' );
	await expect( dialog ).toContainText( 'Sam – office laptop' );
	await dialog.getByRole( 'button', { name: 'Revoke' } ).click();
	await expect( sam ).toContainText( '0 devices' );
} );

test( 'Publishers see Overview and Activity only; Contributors get no menu', async ( { page } ) => {
	await login( page, 'publisher' );
	await page.goto( PAGE );
	await expect( page.locator( '[role="tab"]' ) ).toHaveCount( 2 );
	await expect( page.locator( '#ccb-tab-sections' ) ).toHaveCount( 0 );
	await expect( page.locator( '.ccb-savebar' ) ).toHaveCount( 0 );

	await login( page, 'contributor' );
	await page.goto( '/wp-admin/' );
	await expect( page.locator( '#adminmenu' ) ).not.toContainText( 'Claude Publishing' );
	const response = await page.goto( PAGE );
	expect( response.status() ).toBe( 403 );
} );

test( 'other admin screens are untouched', async ( { page } ) => {
	await login( page );
	for ( const screen of [ '/wp-admin/', '/wp-admin/edit.php', '/wp-admin/options-general.php', '/wp-admin/profile.php' ] ) {
		await page.goto( screen );
		await expect( page.locator( 'link#ccb-admin-css' ) ).toHaveCount( 0 );
		await expect( page.locator( 'script#ccb-admin-js' ) ).toHaveCount( 0 );
	}
} );

test( 'works at 360, 782, 1024 and 1440 px without sideways scrolling', async ( { page } ) => {
	await login( page );
	for ( const width of [ 360, 782, 1024, 1440 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		for ( const tab of [ 'overview', 'sections', 'activity', 'access' ] ) {
			await open( page, tab );
			const overflow = await page.evaluate( () => {
				const app = document.getElementById( 'ccb-app' );
				return app.scrollWidth - app.clientWidth;
			} );
			expect( overflow, `${ tab } at ${ width }px` ).toBeLessThanOrEqual( 1 );
		}
	}
} );

test( 'contrast passes with every WP Admin colour scheme', async ( { page } ) => {
	await login( page );
	for ( const scheme of SCHEMES ) {
		await page.goto( '/wp-admin/profile.php' );
		await page.check( `#admin_color_${ scheme }` );
		await page.click( '#submit' );
		await open( page, 'overview' );
		await expect( page.locator( '.ccb-card' ).first() ).toBeVisible();
		const results = await new AxeBuilder( { page } ).include( '#ccb-app' ).withRules( [ 'color-contrast' ] ).analyze();
		expect( results.violations.map( ( v ) => v.nodes.map( ( n ) => n.target.join( ' ' ) ) ), scheme ).toEqual( [] );
	}
	await page.goto( '/wp-admin/profile.php' );
	await page.check( '#admin_color_fresh' );
	await page.click( '#submit' );
} );
