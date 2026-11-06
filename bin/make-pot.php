<?php
/**
 * Writes languages/causeway-claude-bridge.pot from the plugin's PHP (docs/13 M10).
 *
 * A small stand-in for `wp i18n make-pot` (WP-CLI isn't available in every build environment):
 * reads WordPress gettext calls with PHP's tokenizer, keeps `translators:` comments, contexts and
 * plurals, and only takes strings in the plugin's text domain. No creation date, so the file only
 * changes when strings change (CI checks it is up to date).
 *
 * Usage: php bin/make-pot.php [--check]
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.YodaConditions.NotYoda -- CLI build script, runs without WordPress.

const CCB_POT_DOMAIN = 'causeway-claude-bridge';

/**
 * Argument positions per function: text, plural, context, domain (null when absent).
 */
const CCB_POT_FUNCTIONS = array(
	'__'         => array( 0, null, null, 1 ),
	'_e'         => array( 0, null, null, 1 ),
	'esc_html__' => array( 0, null, null, 1 ),
	'esc_html_e' => array( 0, null, null, 1 ),
	'esc_attr__' => array( 0, null, null, 1 ),
	'esc_attr_e' => array( 0, null, null, 1 ),
	'_x'         => array( 0, null, 1, 2 ),
	'_ex'        => array( 0, null, 1, 2 ),
	'esc_html_x' => array( 0, null, 1, 2 ),
	'esc_attr_x' => array( 0, null, 1, 2 ),
	'_n'         => array( 0, 1, null, 3 ),
	'_nx'        => array( 0, 1, 3, 4 ),
	'_n_noop'    => array( 0, 1, null, 2 ),
	'_nx_noop'   => array( 0, 1, 2, 3 ),
);

$root  = dirname( __DIR__ );
$check = in_array( '--check', $argv, true );

$files = array( $root . '/causeway-claude-bridge.php', $root . '/uninstall.php' );
foreach ( array( 'src', 'templates' ) as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}
sort( $files );

/**
 * Entries keyed by context + msgid.
 *
 * @var array<string, array{msgid: string, plural: ?string, context: ?string, refs: list<string>, comments: list<string>}>
 */
$entries = array();

// Plugin header strings.
$header = (string) file_get_contents( $root . '/causeway-claude-bridge.php' );
foreach ( array( 'Plugin Name', 'Description' ) as $field ) {
	if ( preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/m', $header, $m ) ) {
		$text             = trim( $m[1] );
		$entries[ $text ] = array(
			'msgid'    => $text,
			'plural'   => null,
			'context'  => null,
			'refs'     => array( 'causeway-claude-bridge.php' ),
			'comments' => array( $field . ' of the plugin' ),
		);
	}
}

/**
 * Value of a PHP string literal token.
 *
 * @param string $literal Literal including quotes.
 */
function ccb_pot_literal( string $literal ): string {
	$quote = $literal[0];
	$body  = substr( $literal, 1, -1 );
	if ( "'" === $quote ) {
		return strtr(
			$body,
			array(
				"\\'"  => "'",
				'\\\\' => '\\',
			)
		);
	}
	return stripcslashes( $body );
}

/**
 * PO string, quoted and escaped.
 *
 * @param string $text Text.
 */
function ccb_pot_quote( string $text ): string {
	return '"' . strtr(
		$text,
		array(
			'\\' => '\\\\',
			'"'  => '\\"',
			"\n" => '\\n',
			"\t" => '\\t',
		)
	) . '"';
}

foreach ( $files as $path ) {
	$relative     = substr( $path, strlen( $root ) + 1 );
	$tokens       = token_get_all( (string) file_get_contents( $path ) );
	$count        = count( $tokens );
	$comment      = null;
	$comment_line = 0;

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && T_COMMENT === $token[0] && preg_match( '/translators:/i', $token[1] ) ) {
			$comment      = trim( (string) preg_replace( array( '#^/\*+|\*+/$#', '#^\s*(//|\*)\s?#m' ), '', $token[1] ) );
			$comment      = (string) preg_replace( '/\s+/', ' ', $comment );
			$comment_line = $token[2] + substr_count( $token[1], "\n" );
			continue;
		}
		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( CCB_POT_FUNCTIONS[ $token[1] ] ) ) {
			continue;
		}
		// Not a method or a definition: previous meaningful token must not be ->, ::, function or \ namespace part.
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			--$prev;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}

		// Collect top-level arguments: a literal string, or null for anything else.
		$args    = array();
		$current = array();
		$depth   = 0;
		for ( $k = $j + 1; $k < $count; $k++ ) {
			$t = $tokens[ $k ];
			if ( in_array( $t, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $t, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
			} elseif ( ',' === $t && 0 === $depth ) {
				$args[]  = $current;
				$current = array();
				continue;
			}
			if ( ! ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) ) {
				$current[] = $t;
			}
		}
		$args[] = $current;
		$values = array_map(
			static fn ( array $arg ): ?string => 1 === count( $arg ) && is_array( $arg[0] ) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0] ? ccb_pot_literal( $arg[0][1] ) : null,
			$args
		);

		list( $text_at, $plural_at, $context_at, $domain_at ) = CCB_POT_FUNCTIONS[ $token[1] ];
		if ( ( $values[ $domain_at ] ?? null ) !== CCB_POT_DOMAIN || null === ( $values[ $text_at ] ?? null ) ) {
			continue;
		}
		$msgid   = (string) $values[ $text_at ];
		$plural  = null === $plural_at ? null : ( $values[ $plural_at ] ?? null );
		$context = null === $context_at ? null : ( $values[ $context_at ] ?? null );
		$key     = ( $context ?? '' ) . "\x04" . $msgid;
		$line    = $token[2];

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'    => $msgid,
				'plural'   => $plural,
				'context'  => $context,
				'refs'     => array(),
				'comments' => array(),
			);
		}
		$entries[ $key ]['refs'][] = $relative; // File only: line numbers would make every edit look like a string change.
		if ( null !== $comment && $line - $comment_line <= 3 && ! in_array( $comment, $entries[ $key ]['comments'], true ) ) {
			$entries[ $key ]['comments'][] = $comment;
		}
		$comment = null;
	}
}

$out  = "# Copyright (C) Claude Publishing Bridge contributors\n";
$out .= "# This file is distributed under the GPL-2.0-or-later.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: Claude Publishing Bridge\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= '"X-Domain: ' . CCB_POT_DOMAIN . "\\n\"\n";

foreach ( $entries as $entry ) {
	$out .= "\n";
	foreach ( $entry['comments'] as $note ) {
		$out .= '#. ' . $note . "\n";
	}
	$out .= '#: ' . implode( "\n#: ", array_unique( $entry['refs'] ) ) . "\n";
	if ( str_contains( $entry['msgid'], '%' ) ) {
		$out .= "#, php-format\n";
	}
	if ( null !== $entry['context'] ) {
		$out .= 'msgctxt ' . ccb_pot_quote( $entry['context'] ) . "\n";
	}
	$out .= 'msgid ' . ccb_pot_quote( $entry['msgid'] ) . "\n";
	if ( null !== $entry['plural'] ) {
		$out .= 'msgid_plural ' . ccb_pot_quote( $entry['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
	} else {
		$out .= "msgstr \"\"\n";
	}
}

$target = $root . '/languages/' . CCB_POT_DOMAIN . '.pot';
if ( $check ) {
	if ( ! is_readable( $target ) || file_get_contents( $target ) !== $out ) {
		fwrite( STDERR, "languages/causeway-claude-bridge.pot is out of date: run php bin/make-pot.php\n" );
		exit( 1 );
	}
	echo 'POT up to date (' . count( $entries ) . " strings)\n";
	exit( 0 );
}
if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0755, true );
}
file_put_contents( $target, $out );
echo 'Wrote ' . count( $entries ) . " strings to languages/causeway-claude-bridge.pot\n";
