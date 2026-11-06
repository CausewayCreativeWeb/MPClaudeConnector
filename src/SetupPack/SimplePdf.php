<?php
/**
 * Minimal text-only PDF writer for the setup pack's how-to (docs/10 "How-to PDF").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\SetupPack;

/**
 * A4 pages, Helvetica and Helvetica-Bold (built into every PDF reader, so nothing is embedded),
 * WinAnsi text, word wrapping. Enough for a one-page guide without a PDF library.
 */
final class SimplePdf {

	private const PAGE_WIDTH  = 595.28;
	private const PAGE_HEIGHT = 841.89;
	private const MARGIN      = 56.0;

	/**
	 * Helvetica advance widths (1/1000 em) for ASCII 32–126, from the standard AFM.
	 */
	private const WIDTHS = array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 );

	/**
	 * Content stream per page.
	 *
	 * @var list<string>
	 */
	private array $pages = array( '' );

	/**
	 * Baseline of the next line (pt from the bottom).
	 *
	 * @var float
	 */
	private float $y = self::PAGE_HEIGHT - self::MARGIN;

	/**
	 * Adds a title.
	 *
	 * @param string $text Text.
	 */
	public function title( string $text ): self {
		return $this->block( $text, 18, true, 8, 0.11 );
	}

	/**
	 * Adds a heading.
	 *
	 * @param string $text Text.
	 */
	public function heading( string $text ): self {
		$this->y -= 6;
		return $this->block( $text, 12, true, 4, 0.11 );
	}

	/**
	 * Adds a paragraph.
	 *
	 * @param string $text Text.
	 */
	public function paragraph( string $text ): self {
		return $this->block( $text, 10, false, 6, 0.2 );
	}

	/**
	 * Adds a bullet point.
	 *
	 * @param string $text Text.
	 */
	public function bullet( string $text ): self {
		return $this->block( $text, 10, false, 3, 0.2, 14.0, '- ' );
	}

	/**
	 * Wraps and writes a block of text.
	 *
	 * @param string $text   Text (UTF-8).
	 * @param float  $size   Font size (pt).
	 * @param bool   $bold   Bold.
	 * @param float  $after  Space after (pt).
	 * @param float  $grey   Text grey level (0 = black).
	 * @param float  $indent Left indent (pt).
	 * @param string $marker Marker printed before the first line, in the indent.
	 */
	private function block( string $text, float $size, bool $bold, float $after, float $grey, float $indent = 0.0, string $marker = '' ): self {
		$width   = self::PAGE_WIDTH - 2 * self::MARGIN - $indent;
		$leading = $size * 1.35;
		$lines   = $this->wrap( self::encode( $text ), $size, $bold, $width );
		$font    = $bold ? 'F2' : 'F1';

		foreach ( $lines as $i => $line ) {
			if ( $this->y - $leading < self::MARGIN ) {
				$this->pages[] = '';
				$this->y       = self::PAGE_HEIGHT - self::MARGIN;
			}
			$this->y -= $leading;
			$page     = count( $this->pages ) - 1;
			$out      = sprintf( "%.2F g\nBT /%s %.1F Tf ", $grey, $font, $size );
			if ( 0 === $i && '' !== $marker ) {
				$out .= sprintf( '%.2F %.2F Td (%s) Tj ET%sBT /%s %.1F Tf ', self::MARGIN + $indent - 10, $this->y, self::escape( self::encode( '•' ) ), "\n", $font, $size );
			}
			$out                  .= sprintf( "%.2F %.2F Td (%s) Tj ET\n", self::MARGIN + $indent, $this->y, self::escape( $line ) );
			$this->pages[ $page ] .= $out;
		}
		$this->y -= $after;
		return $this;
	}

	/**
	 * Splits text into lines that fit the width.
	 *
	 * @param string $text  WinAnsi text.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Bold.
	 * @param float  $width Available width (pt).
	 * @return list<string>
	 */
	private function wrap( string $text, float $size, bool $bold, float $width ): array {
		$lines   = array();
		$current = '';
		foreach ( explode( ' ', $text ) as $word ) {
			$try = '' === $current ? $word : $current . ' ' . $word;
			if ( '' !== $current && self::measure( $try, $size, $bold ) > $width ) {
				$lines[] = $current;
				$current = $word;
			} else {
				$current = $try;
			}
		}
		$lines[] = $current;
		return $lines;
	}

	/**
	 * Width of a string in points.
	 *
	 * @param string $text WinAnsi text.
	 * @param float  $size Font size.
	 * @param bool   $bold Bold (about 5% wider).
	 */
	public static function measure( string $text, float $size, bool $bold = false ): float {
		$units = 0;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$code   = ord( $text[ $i ] );
			$units += ( $code >= 32 && $code <= 126 ) ? self::WIDTHS[ $code - 32 ] : 556;
		}
		return $units * $size / 1000 * ( $bold ? 1.05 : 1.0 );
	}

	/**
	 * UTF-8 to WinAnsi (Windows-1252); characters it lacks become "?".
	 *
	 * @param string $text UTF-8 text.
	 */
	public static function encode( string $text ): string {
		$text = (string) preg_replace( '/\s+/u', ' ', trim( $text ) );
		return (string) mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
	}

	/**
	 * Escapes a PDF string literal.
	 *
	 * @param string $text Text.
	 */
	private static function escape( string $text ): string {
		return strtr(
			$text,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
				"\r" => '',
			)
		);
	}

	/**
	 * The PDF file.
	 *
	 * @param string $title Document title (metadata).
	 */
	public function output( string $title ): string {
		$count   = count( $this->pages );
		$objects = array();

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids       = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$kids[] = ( 6 + 2 * $i ) . ' 0 R';
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $count . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[5] = '<< /Title (' . self::escape( self::encode( $title ) ) . ') /Producer (Claude Publishing) >>';
		foreach ( $this->pages as $i => $stream ) {
			$page_id                 = 6 + 2 * $i;
			$objects[ $page_id ]     = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
				self::PAGE_WIDTH,
				self::PAGE_HEIGHT,
				$page_id + 1
			);
			$objects[ $page_id + 1 ] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . 'endstream';
		}
		ksort( $objects );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$size = count( $objects ) + 1;
		$pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= "trailer\n<< /Size {$size} /Root 1 0 R /Info 5 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
		return $pdf;
	}
}
