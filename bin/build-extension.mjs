// Builds the Claude Desktop extension's server (docs/10): bundles the pinned
// @automattic/mcp-wordpress-remote proxy into one file, and writes the licences of every
// bundled package next to it. Run by `npm run build:extension` and bin/build-release.sh.
import { build } from 'esbuild';
import { readFileSync, writeFileSync, existsSync, readdirSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const server = join( root, 'setup-pack/extension/server' );

const result = await build( {
	entryPoints: [ join( server, 'src/index.js' ) ],
	bundle: true,
	platform: 'node',
	format: 'esm',
	target: 'node18',
	minify: true,
	legalComments: 'none',
	metafile: true,
	logLevel: 'warning',
	banner: { js: "import { createRequire as __ccbRequire } from 'module'; const require = __ccbRequire(import.meta.url);" },
	outfile: join( server, 'index.js' ),
} );
rmSync( join( server, 'index.js.LEGAL.txt' ), { force: true } );

// Every package directory that contributed a file to the bundle.
const packages = new Map();
for ( const input of Object.keys( result.metafile.inputs ) ) {
	const match = input.match( /^(.*node_modules\/(?:@[^/]+\/)?[^/]+)\// );
	if ( match && ! packages.has( match[ 1 ] ) ) {
		const pkg = JSON.parse( readFileSync( join( root, match[ 1 ], 'package.json' ), 'utf8' ) );
		packages.set( match[ 1 ], pkg );
	}
}

const sections = [ ...packages.entries() ]
	.sort( ( a, b ) => a[ 1 ].name.localeCompare( b[ 1 ].name ) )
	.map( ( [ dir, pkg ] ) => {
		const file = readdirSync( join( root, dir ) ).find( ( f ) => /^(licen[sc]e|copying)(\.(md|txt))?$/i.test( f ) );
		const text = file ? readFileSync( join( root, dir, file ), 'utf8' ).trim() : `License: ${ pkg.license || 'see package' }`;
		return `${ pkg.name } ${ pkg.version }\n${ '-'.repeat( 60 ) }\n${ text }\n`;
	} );

writeFileSync(
	join( server, 'THIRD-PARTY-LICENSES.txt' ),
	'Third-party software bundled in server/index.js\n\n' + sections.join( '\n' )
);
console.log( `Bundled ${ packages.size } packages into setup-pack/extension/server/index.js` );
if ( ! existsSync( join( server, 'index.js' ) ) ) {
	process.exit( 1 );
}
