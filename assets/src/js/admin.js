/**
 * Claude Publishing settings screen (docs/08). One Alpine component holds the config being edited,
 * the live site structure and the state of every tab. Bundled with Alpine by esbuild; no CDN.
 */
import Alpine from 'alpinejs';

const boot = window.ccbBoot || {};
const t = ( key ) => ( boot.i18n && boot.i18n[ key ] ) || key;
const clone = ( v ) => JSON.parse( JSON.stringify( v ) );
const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

/** Config keys edited on each tab (for the unsaved-changes dots). */
const TAB_KEYS = {
	sections: [ 'sections' ],
	writing: [ 'writing' ],
	media: [ 'media' ],
	access: [ 'access' ],
	notifications: [ 'notifications' ],
	activity: [ 'activity' ],
	advanced: [ 'support' ],
};

/** Which tab a config path belongs to. */
const tabForPath = ( path ) => {
	const top = String( path ).split( '/' )[ 1 ] || '';
	for ( const [ tab, keys ] of Object.entries( TAB_KEYS ) ) {
		if ( keys.includes( top ) ) {
			return tab;
		}
	}
	return 'advanced';
};

/** Same rule as SiteStructure::templates_for(). */
const templatesFor = ( structure, postType ) =>
	( structure?.elementor?.single_templates || [] ).filter( ( tpl ) => {
		const c = tpl.conditions || [];
		if ( c.includes( 'exclude/singular/' + postType ) ) {
			return false;
		}
		return c.some( ( x ) => [ 'include/general', 'include/singular', 'include/singular/' + postType ].includes( x ) || x.startsWith( 'include/singular/' + postType + '/' ) );
	} );

async function api( method, path, body ) {
	const opts = { method, headers: { 'X-WP-Nonce': boot.nonce, Accept: 'application/json' }, credentials: 'same-origin' };
	let url = boot.rest + path;
	if ( body && method === 'GET' ) {
		const q = new URLSearchParams();
		Object.entries( body ).forEach( ( [ k, v ] ) => {
			if ( v !== '' && v !== null && v !== undefined && v !== false ) {
				q.set( k, v === true ? '1' : v );
			}
		} );
		url += ( url.includes( '?' ) ? '&' : '?' ) + q.toString();
	} else if ( body ) {
		opts.headers[ 'Content-Type' ] = 'application/json';
		opts.body = JSON.stringify( body );
	}
	const res = await fetch( url, opts );
	let data = null;
	try {
		data = await res.json();
	} catch ( e ) {
		data = null;
	}
	if ( ! res.ok ) {
		const err = new Error( ( data && data.message ) || t( 'requestFailed' ) );
		err.status = res.status;
		err.code = data && data.code;
		err.data = ( data && data.data ) || {};
		throw err;
	}
	return data;
}

Alpine.data( 'ccbApp', () => ( {
	boot,
	tabs: boot.tabs || [ 'overview' ],
	tab: 'overview',
	canManage: !! boot.canManage,
	config: boot.config ? clone( boot.config ) : null,
	saved: boot.config ? clone( boot.config ) : null,
	structure: boot.structure || { post_types: [], taxonomies: {}, acf: { available: false, fields_by_post_type: {} }, elementor: { active: false } },
	version: boot.version,
	savedBy: boot.savedBy,
	errors: [],
	warnings: [],
	saving: false,
	note: '',
	toast: '',
	live: '',
	dialog: null,
	status: null,
	statusLoading: false,
	openSection: '',
	newSection: { key: '', label: '', post_type: '' },
	preview: { markdown: '', note: '', loading: false },
	previewTimer: null,
	activity: { rows: [], total: 0, page: 1, pages: 1, users: [], actions: [], loading: false },
	filters: { user_id: '', action: '', section: '', result: '', from: '', to: '', search: '', include_reads: false, page: 1 },
	users: null,
	picker: '',
	passwords: {},
	versions: null,
	presets: null,
	settings: null,
	termSearch: {},
	tagInput: '',
	extraInput: '',

	t,

	init() {
		if ( ! this.status ) {
			this.loadStatus();
		}
		const fromHash = window.location.hash.replace( '#', '' );
		this.tab = this.tabs.includes( fromHash ) ? fromHash : 'overview';
		window.addEventListener( 'hashchange', () => {
			const h = window.location.hash.replace( '#', '' );
			if ( this.tabs.includes( h ) ) {
				this.show( h, false );
			}
		} );
		window.addEventListener( 'beforeunload', ( e ) => {
			if ( this.dirtyCount() > 0 ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
		this.$watch( 'config.sections', () => this.queuePreview() );
		this.load( this.tab );
	},

	/* ---------- Navigation ---------- */

	show( tab, push = true ) {
		this.tab = tab;
		if ( push ) {
			history.replaceState( null, '', '#' + tab );
		}
		this.load( tab );
	},

	navKey( e, index ) {
		const keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
		let next = null;
		if ( e.key in keys ) {
			next = ( index + keys[ e.key ] + this.tabs.length ) % this.tabs.length;
		} else if ( e.key === 'Home' ) {
			next = 0;
		} else if ( e.key === 'End' ) {
			next = this.tabs.length - 1;
		}
		if ( next !== null ) {
			e.preventDefault();
			this.show( this.tabs[ next ] );
			this.$nextTick( () => document.getElementById( 'ccb-tab-' + this.tabs[ next ] )?.focus() );
		}
	},

	load( tab ) {
		if ( tab === 'overview' && ! this.status && ! this.statusLoading ) {
			this.loadStatus();
		}
		if ( tab === 'activity' ) {
			this.loadActivity();
		}
		if ( tab === 'access' && ! this.users ) {
			this.loadUsers();
		}
		if ( tab === 'advanced' ) {
			this.loadVersions();
			if ( ! this.presets ) {
				api( 'GET', 'presets' ).then( ( p ) => ( this.presets = p ) ).catch( ( e ) => this.notify( e.message ) );
			}
			if ( ! this.settings ) {
				api( 'GET', 'settings' ).then( ( s ) => ( this.settings = s ) ).catch( ( e ) => this.notify( e.message ) );
			}
		}
		if ( tab === 'sections' && ! this.openSection && this.config ) {
			const first = Object.keys( this.config.sections )[ 0 ];
			if ( first ) {
				this.openSection = first;
				this.queuePreview();
			}
		}
	},

	async go( fn ) {
		try {
			return await fn();
		} catch ( e ) {
			this.notify( e.message );
		}
	},

	notify( message ) {
		this.toast = message;
		this.live = message;
		clearTimeout( this._toastTimer );
		this._toastTimer = setTimeout( () => ( this.toast = '' ), 5000 );
	},

	/* ---------- Dirty state and errors ---------- */

	dirtyTab( tab ) {
		if ( ! this.config || ! TAB_KEYS[ tab ] ) {
			return false;
		}
		return TAB_KEYS[ tab ].some( ( k ) => ! same( this.config[ k ], this.saved[ k ] ) );
	},

	dirtyCount() {
		return Object.keys( TAB_KEYS ).filter( ( tab ) => this.dirtyTab( tab ) ).length;
	},

	errorTab( tab ) {
		return this.errors.some( ( e ) => tabForPath( e.path ) === tab );
	},

	err( path ) {
		return this.errors.some( ( e ) => e.path === path || e.path.startsWith( path + '/' ) );
	},

	errText( path ) {
		const found = this.errors.find( ( e ) => e.path === path || e.path.startsWith( path + '/' ) );
		return found ? found.message : '';
	},

	warnText( path ) {
		const found = this.warnings.find( ( e ) => e.path === path || e.path.startsWith( path + '/' ) );
		return found ? found.message : '';
	},

	goToError( e ) {
		const tab = tabForPath( e.path );
		const parts = e.path.split( '/' );
		if ( parts[ 1 ] === 'sections' && parts[ 2 ] ) {
			this.openSection = parts[ 2 ];
		}
		this.show( tab );
		this.$nextTick( () => {
			let path = e.path;
			let el = null;
			while ( path && ! el ) {
				el = document.querySelector( '[data-path="' + CSS.escape( path ) + '"]' );
				path = path.substring( 0, path.lastIndexOf( '/' ) );
			}
			el?.focus();
		} );
	},

	/* ---------- Save flow (docs/08 "Save flow") ---------- */

	async save( acceptWarnings = false, baseVersion = null ) {
		if ( this.saving ) {
			return;
		}
		this.saving = true;
		try {
			const res = await api( 'PUT', 'config', {
				config: this.config,
				base_version: baseVersion ?? this.version,
				note: this.note,
				accept_warnings: acceptWarnings,
			} );
			this.applySaved( res );
			this.notify( t( 'saved' ).replace( '%d', res.version ) );
		} catch ( e ) {
			this.handleSaveError( e, () => this.save( true, baseVersion ), ( live ) => this.save( acceptWarnings, live ) );
		} finally {
			this.saving = false;
		}
	},

	applySaved( res ) {
		this.config = clone( res.config );
		this.saved = clone( res.config );
		this.version = res.version;
		this.savedBy = res.saved_by_name;
		this.errors = [];
		this.warnings = [];
		this.note = '';
		this.dialog = null;
		this.status = null;
		this.versions = null;
	},

	handleSaveError( e, confirm, overwrite ) {
		if ( e.code === 'ccb_config_invalid' ) {
			this.errors = e.data.errors || [];
			this.warnings = e.data.warnings || [];
			this.live = t( 'invalid' ).replace( '%d', this.errors.length );
			this.$nextTick( () => document.getElementById( 'ccb-error-summary' )?.focus() );
		} else if ( e.code === 'ccb_config_needs_confirm' ) {
			this.warnings = e.data.warnings || [];
			this.openDialog( { kind: 'warnings', warnings: this.warnings, onConfirm: confirm } );
		} else if ( e.code === 'ccb_config_conflict' ) {
			this.openDialog( { kind: 'conflict', live: e.data.live, diff: e.data.diff || [], onConfirm: () => overwrite( e.data.live.version ) } );
		} else {
			this.notify( e.message );
		}
	},

	discard() {
		this.config = clone( this.saved );
		this.errors = [];
		this.warnings = [];
		this.notify( t( 'discarded' ) );
	},

	reloadFromLive( live ) {
		this.applySaved( live );
		this.notify( t( 'reloaded' ) );
	},

	openDialog( d ) {
		this._returnFocus = document.activeElement;
		this.dialog = d;
		this.$nextTick( () => {
			const el = this.$refs.dialog;
			if ( el && ! el.open ) {
				el.showModal();
			}
		} );
	},

	closeDialog() {
		this.$refs.dialog?.close();
		this.dialog = null;
		this._returnFocus?.focus?.();
	},

	async confirmDialog() {
		const d = this.dialog;
		if ( d?.typed !== undefined && d.typed !== d.expect ) {
			return;
		}
		this.closeDialog();
		await d?.onConfirm?.();
	},

	/* ---------- Overview ---------- */

	async loadStatus( run = false ) {
		this.statusLoading = true;
		try {
			this.status = await api( run ? 'POST' : 'GET', run ? 'status/run' : 'status' );
			if ( run ) {
				this.notify( t( 'checksDone' ) );
			}
		} catch ( e ) {
			this.notify( e.message );
		} finally {
			this.statusLoading = false;
		}
	},

	statusLabel( state ) {
		return t( 'state_' + state );
	},

	/* ---------- Sections ---------- */

	sectionKeys() {
		return this.config ? Object.keys( this.config.sections ) : [];
	},

	postType( name ) {
		return this.structure.post_types.find( ( p ) => p.name === name ) || null;
	},

	usedPostTypes( except ) {
		return Object.entries( this.config.sections ).filter( ( [ k ] ) => k !== except ).map( ( [ , s ] ) => s.post_type );
	},

	taxonomiesFor( postTypeName ) {
		const pt = this.postType( postTypeName );
		return ( pt ? pt.taxonomies : [] ).filter( ( tax ) => this.structure.taxonomies[ tax ] ).map( ( tax ) => ( { slug: tax, ...this.structure.taxonomies[ tax ] } ) );
	},

	acfFields( postTypeName ) {
		return ( this.structure.acf?.fields_by_post_type?.[ postTypeName ] ) || [];
	},

	templates( postTypeName ) {
		return templatesFor( this.structure, postTypeName );
	},

	summary( key ) {
		const s = this.config.sections[ key ];
		const bits = [ s.post_type ];
		if ( Object.values( s.extra_fields || {} ).some( ( f ) => f.type === 'uk_postcode_to_latlng' ) ) {
			bits.push( t( 'postcodeMap' ) );
		}
		if ( s.limits.max_words ) {
			bits.push( t( 'maxWords' ).replace( '%d', s.limits.max_words ) );
		}
		return bits.join( ' · ' );
	},

	addSection() {
		const n = this.newSection;
		const key = ( n.key || n.post_type || '' ).toLowerCase().replace( /[^a-z0-9-]+/g, '-' ).replace( /^[^a-z]+/, '' ).slice( 0, 31 );
		if ( ! key || key.length < 2 || this.config.sections[ key ] || ! n.post_type ) {
			this.notify( t( 'sectionKey' ) );
			return;
		}
		const section = clone( boot.sectionDefault );
		section.post_type = n.post_type;
		section.label = n.label || this.postType( n.post_type )?.label || key;
		this.config.sections = { ...this.config.sections, [ key ]: section };
		this.openSection = key;
		this.newSection = { key: '', label: '', post_type: '' };
		this.$nextTick( () => document.getElementById( 'ccb-section-' + key + '-label' )?.focus() );
	},

	removeSection( key ) {
		this.openDialog( {
			kind: 'confirm',
			title: t( 'removeSectionTitle' ),
			body: t( 'removeSectionBody' ),
			onConfirm: () => {
				const copy = { ...this.config.sections };
				delete copy[ key ];
				this.config.sections = copy;
				if ( this.openSection === key ) {
					this.openSection = '';
				}
			},
		} );
	},

	termName( taxonomy, id ) {
		const term = ( this.structure.taxonomies[ taxonomy ]?.terms || [] ).find( ( x ) => x.id === id );
		return term ? term.name : null;
	},

	termMatches( key, taxonomy ) {
		const q = ( this.termSearch[ key + taxonomy ] || '' ).toLowerCase();
		const chosen = this.config.sections[ key ].default_terms[ taxonomy ] || [];
		return ( this.structure.taxonomies[ taxonomy ]?.terms || [] ).filter( ( x ) => ! chosen.includes( x.id ) && ( ! q || x.name.toLowerCase().includes( q ) ) ).slice( 0, 20 );
	},

	addTerm( key, taxonomy, id ) {
		const s = this.config.sections[ key ];
		const list = s.default_terms[ taxonomy ] || [];
		if ( ! list.includes( id ) ) {
			s.default_terms = { ...s.default_terms, [ taxonomy ]: [ ...list, id ] };
		}
		this.termSearch[ key + taxonomy ] = '';
	},

	removeTerm( key, taxonomy, id ) {
		const s = this.config.sections[ key ];
		const list = ( s.default_terms[ taxonomy ] || [] ).filter( ( x ) => x !== id );
		const copy = { ...s.default_terms };
		if ( list.length ) {
			copy[ taxonomy ] = list;
		} else {
			delete copy[ taxonomy ];
		}
		s.default_terms = copy;
	},

	toggleIn( list, value ) {
		const i = list.indexOf( value );
		if ( i === -1 ) {
			list.push( value );
		} else {
			list.splice( i, 1 );
		}
	},

	fieldRule( key, field ) {
		if ( ! field.writable ) {
			return 'hidden';
		}
		return this.config.sections[ key ].field_rules[ field.name ] || ( field.required ? 'required' : 'optional' );
	},

	setFieldRule( key, field, rule ) {
		const s = this.config.sections[ key ];
		s.field_rules = { ...s.field_rules, [ field.name ]: rule };
	},

	missingRules( key ) {
		const names = this.acfFields( this.config.sections[ key ].post_type ).map( ( f ) => f.name );
		return Object.keys( this.config.sections[ key ].field_rules ).filter( ( n ) => ! names.includes( n ) );
	},

	removeRule( key, name ) {
		const copy = { ...this.config.sections[ key ].field_rules };
		delete copy[ name ];
		this.config.sections[ key ].field_rules = copy;
	},

	missingTerms( key ) {
		const out = [];
		Object.entries( this.config.sections[ key ].default_terms ).forEach( ( [ tax, ids ] ) => ids.forEach( ( id ) => {
			if ( this.termName( tax, id ) === null ) {
				out.push( { tax, id } );
			}
		} ) );
		return out;
	},

	addExtraField( key ) {
		const s = this.config.sections[ key ];
		let n = 1;
		while ( s.extra_fields[ 'field_' + n ] ) {
			n++;
		}
		s.extra_fields = { ...s.extra_fields, [ 'field_' + n ]: { label: t( 'newField' ), type: 'text', required: false, instructions: '' } };
	},

	renameExtraField( key, oldName, newName ) {
		const s = this.config.sections[ key ];
		newName = String( newName ).toLowerCase().replace( /[^a-z0-9_]+/g, '_' );
		if ( ! newName || newName === oldName || s.extra_fields[ newName ] ) {
			return;
		}
		const copy = {};
		Object.entries( s.extra_fields ).forEach( ( [ k, v ] ) => ( copy[ k === oldName ? newName : k ] = v ) );
		s.extra_fields = copy;
	},

	removeExtraField( key, name ) {
		const copy = { ...this.config.sections[ key ].extra_fields };
		delete copy[ name ];
		this.config.sections[ key ].extra_fields = copy;
	},

	setChoices( field, text ) {
		const list = [ ...new Set( String( text ).split( '\n' ).map( ( x ) => x.trim() ).filter( Boolean ) ) ];
		if ( list.length ) {
			field.choices = list;
		} else {
			delete field.choices;
		}
	},

	queuePreview() {
		if ( ! this.canManage || ! this.openSection ) {
			return;
		}
		clearTimeout( this.previewTimer );
		this.previewTimer = setTimeout( () => this.loadPreview(), 400 );
	},

	async loadPreview() {
		const key = this.openSection;
		if ( ! key || ! this.config.sections[ key ] ) {
			return;
		}
		this.preview.loading = true;
		try {
			const res = await api( 'POST', 'preview/' + key, { config: this.config } );
			if ( key === this.openSection ) {
				this.preview = { markdown: res.markdown, note: res.note, loading: false };
			}
		} catch ( e ) {
			this.preview = { markdown: '', note: e.message, loading: false };
		}
	},

	refreshStructure() {
		return this.go( async () => {
			this.structure = await api( 'GET', 'site-structure', { refresh: true } );
			this.notify( t( 'refreshed' ) );
		} );
	},

	/* ---------- Writing ---------- */

	addTag( list, value ) {
		value = String( value ).trim();
		if ( value && ! list.includes( value ) ) {
			list.push( value );
		}
	},

	addExtraEmail() {
		const v = this.extraInput.trim();
		if ( ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( v ) ) {
			this.notify( t( 'badEmail' ) );
			return;
		}
		this.addTag( this.config.notifications.recipients.extra, v );
		this.extraInput = '';
	},

	/* ---------- Access ---------- */

	loadUsers() {
		return this.go( async () => {
			this.users = await api( 'GET', 'users' );
		} );
	},

	setRole( id, role ) {
		return this.go( async () => {
			await api( 'POST', 'users/' + id + '/role', { role } );
			await this.loadUsers();
			this.picker = '';
			this.notify( role === 'publisher' ? t( 'madePublisher' ) : t( 'madeContributor' ) );
		} );
	},

	loadPasswords( id ) {
		return this.go( async () => {
			this.passwords = { ...this.passwords, [ id ]: await api( 'GET', 'users/' + id + '/app-passwords' ) };
		} );
	},

	revoke( user, item ) {
		this.openDialog( {
			kind: 'confirm',
			danger: true,
			title: t( 'revokeTitle' ),
			body: t( 'revokeBody' ).replace( '%1$s', item.name ).replace( '%2$s', user.name ),
			confirmLabel: t( 'revoke' ),
			onConfirm: async () => {
				try {
					await api( 'DELETE', 'users/' + user.id + '/app-passwords/' + item.uuid );
					await this.loadPasswords( user.id );
					await this.loadUsers();
					this.notify( t( 'revoked' ) );
				} catch ( e ) {
					this.notify( e.message );
				}
			},
		} );
	},

	/* ---------- Notifications ---------- */

	testEmail() {
		return this.go( async () => {
			const res = await api( 'POST', 'notifications/test' );
			this.notify( t( 'testSent' ).replace( '%s', res.sent_to ) );
			this.status = null;
		} );
	},

	/* ---------- Activity ---------- */

	async loadActivity( page = null ) {
		if ( page ) {
			this.filters.page = page;
		}
		this.activity.loading = true;
		try {
			const res = await api( 'GET', 'activity', this.filters );
			this.activity = { ...res, loading: false };
		} catch ( e ) {
			this.activity.loading = false;
			this.notify( e.message );
		}
	},

	filter() {
		this.filters.page = 1;
		this.loadActivity();
	},

	csvUrl() {
		const q = new URLSearchParams( { format: 'csv', _wpnonce: boot.nonce } );
		Object.entries( this.filters ).forEach( ( [ k, v ] ) => {
			if ( k !== 'page' && v !== '' && v !== false ) {
				q.set( k, v === true ? '1' : v );
			}
		} );
		return boot.rest + 'activity' + ( boot.rest.includes( '?' ) ? '&' : '?' ) + q.toString();
	},

	resultLabel( r ) {
		return { success: t( 'success' ), rejected: t( 'rejected' ), error: t( 'error' ) }[ r ] || r;
	},

	resultState( r ) {
		return { success: 'good', rejected: 'warning', error: 'problem' }[ r ] || 'neutral';
	},

	/* ---------- Advanced ---------- */

	loadVersions() {
		return this.go( async () => {
			this.versions = await api( 'GET', 'config/versions' );
		} );
	},

	viewVersion( v ) {
		return this.go( async () => {
			const res = await api( 'GET', 'config/versions/' + v.id );
			this.openDialog( {
				kind: 'diff',
				title: t( 'versionTitle' ).replace( '%d', v.version ),
				diff: res.diff,
				confirmLabel: t( 'restore' ),
				onConfirm: () => this.restoreVersion( v ),
			} );
		} );
	},

	async restoreVersion( v, accept = false ) {
		try {
			const res = await api( 'POST', 'config/versions/' + v.id + '/restore', { base_version: this.version, accept_warnings: accept } );
			this.applySaved( res );
			this.loadVersions();
			this.notify( t( 'restored' ).replace( '%d', v.version ) );
		} catch ( e ) {
			this.handleSaveError( e, () => this.restoreVersion( v, true ), ( live ) => {
				this.version = live;
				return this.restoreVersion( v, accept );
			} );
		}
	},

	previewPreset( name ) {
		return this.go( async () => {
			const res = await api( 'POST', 'presets/' + name + '/preview' );
			this.openDialog( {
				kind: 'diff',
				title: t( 'presetTitle' ),
				diff: res.changes,
				notes: [ ...( res.skipped_sections || [] ).map( ( s ) => t( 'skippedSection' ).replace( '%s', s.key ) ), ...( res.missing_terms || [] ).map( ( m ) => t( 'missingTerm' ).replace( '%s', m.slug ) ) ],
				confirmLabel: t( 'apply' ),
				onConfirm: () => this.applyConfig( res.config, 'preset' ),
			} );
		} );
	},

	async importFile( event ) {
		const file = event.target.files[ 0 ];
		event.target.value = '';
		if ( ! file ) {
			return;
		}
		if ( file.size > 262144 ) {
			this.notify( t( 'tooLarge' ) );
			return;
		}
		try {
			const res = await api( 'POST', 'config/import', { json: await file.text() } );
			this.openDialog( {
				kind: 'diff',
				title: t( 'importTitle' ),
				diff: res.diff,
				notes: res.valid ? [] : res.errors.map( ( x ) => x.path + ': ' + x.message ),
				blocked: ! res.valid,
				confirmLabel: t( 'apply' ),
				onConfirm: () => this.applyConfig( res.config, 'import' ),
			} );
		} catch ( e ) {
			this.notify( e.message );
		}
	},

	async applyConfig( config, source, accept = false ) {
		try {
			const res = await api( 'PUT', 'config', { config, base_version: this.version, accept_warnings: accept, source } );
			this.applySaved( res );
			this.notify( t( 'saved' ).replace( '%d', res.version ) );
		} catch ( e ) {
			this.handleSaveError( e, () => this.applyConfig( config, source, true ), ( live ) => {
				this.version = live;
				return this.applyConfig( config, source, accept );
			} );
		}
	},

	exportConfig() {
		const blob = new Blob( [ JSON.stringify( this.saved, null, 2 ) ], { type: 'application/json' } );
		const a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = 'claude-publishing-settings-v' + this.version + '.json';
		document.body.appendChild( a );
		a.click();
		a.remove();
		setTimeout( () => URL.revokeObjectURL( a.href ), 1000 );
	},

	resetDefaults() {
		this.openDialog( {
			kind: 'confirm',
			danger: true,
			title: t( 'resetTitle' ),
			body: t( 'resetBody' ),
			typed: '',
			expect: t( 'resetWord' ),
			confirmLabel: t( 'reset' ),
			onConfirm: () => this.go( async () => {
				const res = await api( 'GET', 'config/defaults' );
				await this.applyConfig( res.config, 'ui' );
			} ),
		} );
	},

	saveSetting( key, value ) {
		return this.go( async () => {
			this.settings = await api( 'POST', 'settings', { [ key ]: value } );
			this.notify( t( 'settingSaved' ) );
		} );
	},

	diffValue( v ) {
		if ( v === null || v === undefined ) {
			return '—';
		}
		return typeof v === 'object' ? JSON.stringify( v ) : String( v );
	},

	copy( text ) {
		navigator.clipboard?.writeText( text ).then( () => this.notify( t( 'copied' ) ) );
	},
} ) );

window.Alpine = Alpine;
Alpine.start();
