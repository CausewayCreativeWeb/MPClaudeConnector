<?php
/**
 * Post content sanitising (docs/05 "Post content").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

// DOM properties (nodeName, parentNode, firstChild, textContent) are PHP's own names.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Cleans the HTML Claude sends before every write:
 * 1. wp_kses() with an allow-list from `writing.allowed_html` and fixed attributes.
 * 2. Headings outside `writing.headings` moved to the nearest allowed level (H1 → H2, H4–H6 → H3).
 * 3. With `strip_inline_styles`: no styles, only WordPress image classes, no empty paragraphs or &nbsp; runs.
 * 4. Images must point at an attachment on this site; others are removed with a warning.
 * 5. Links must be http, https, mailto or relative; `link_rules` applied.
 * 6. Words to avoid produce warnings.
 * 7. Block editor post types get core block comments so the editor opens the content cleanly.
 */
final class ContentSanitiser {

	/**
	 * Attributes allowed per tag (docs/05). Nothing else survives.
	 */
	private const ATTRIBUTES = array(
		'a'          => array( 'href', 'title', 'rel', 'target' ),
		'img'        => array( 'src', 'alt', 'width', 'height', 'class' ),
		'figure'     => array( 'class' ),
		'blockquote' => array( 'cite' ),
	);

	private const HEADINGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * WordPress image classes kept when inline styles are stripped.
	 */
	private const IMAGE_CLASS_PATTERN = '/^(align(left|right|center|none)|wp-image-\d+|size-[a-z0-9_-]+|wp-block-image)$/';

	/**
	 * Config `writing` block.
	 *
	 * @var array<string, mixed>
	 */
	private array $writing;

	/**
	 * Sets up the sanitiser.
	 *
	 * @param array<string, mixed> $writing Config `writing` block.
	 */
	public function __construct( array $writing ) {
		$this->writing = $writing;
	}

	/**
	 * Cleans content.
	 *
	 * @param string $html      HTML from Claude.
	 * @param string $post_type Post type the content is for (decides block markup).
	 * @return array{html: string, warnings: list<string>, word_count: int, image_ids: list<int>}
	 */
	public function sanitise( string $html, string $post_type ): array {
		$warnings = array();

		// wp_kses() turns "javascript:x" into a meaningless relative link, so unsafe links are
		// unwrapped (text kept) before it runs.
		$html = self::unwrap_unsafe_links( $html, $warnings );
		$html = wp_kses( $html, $this->allowed_html(), array( 'http', 'https', 'mailto' ) );

		$dom = self::load( $html );
		if ( null === $dom ) {
			return array(
				'html'       => '',
				'warnings'   => array( __( 'The content could not be read and was left empty.', 'causeway-claude-bridge' ) ),
				'word_count' => 0,
				'image_ids'  => array(),
			);
		}
		$xpath = new DOMXPath( $dom );

		$this->fix_headings( $dom, $xpath );
		$image_ids = $this->check_images( $xpath, $warnings );
		$this->apply_link_rules( $xpath );
		if ( ! empty( $this->writing['strip_inline_styles'] ) ) {
			$this->strip_presentation( $xpath );
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		$html = null === $body ? '' : self::inner_html( $body );
		if ( ! empty( $this->writing['strip_inline_styles'] ) ) {
			$html = (string) preg_replace( '/(?:&nbsp;|\x{00A0}){2,}/u', ' ', $html );
		}
		$html = trim( $html );

		$text = wp_strip_all_tags( $html );
		foreach ( $this->writing['words_to_avoid'] ?? array() as $word ) {
			if ( '' !== $word && preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/iu', $text ) ) {
				/* translators: %s: word from the house style's words to avoid */
				$warnings[] = sprintf( __( 'The content uses "%s", which the house style says to avoid.', 'causeway-claude-bridge' ), $word );
			}
		}

		if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type ) ) {
			$html = self::to_blocks( $html );
		}

		return array(
			'html'       => $html,
			'warnings'   => $warnings,
			'word_count' => self::word_count( $html ),
			'image_ids'  => $image_ids,
		);
	}

	/**
	 * Word count: str_word_count of the text without tags (docs/05), with a space added after block
	 * elements first so words either side of a tag boundary aren't counted as one.
	 *
	 * @param string $html HTML.
	 */
	public static function word_count( string $html ): int {
		$spaced = (string) preg_replace( '#</(p|h[1-6]|li|blockquote|figcaption|figure|ul|ol)>|<br\s*/?>#i', '$0 ', $html );
		return str_word_count( wp_strip_all_tags( $spaced ) );
	}

	/**
	 * Replaces links whose address isn't http, https, mailto or relative with their text.
	 *
	 * @param string   $html     HTML.
	 * @param string[] $warnings Collects warnings.
	 */
	private static function unwrap_unsafe_links( string $html, array &$warnings ): string {
		if ( false === stripos( $html, '<a' ) ) {
			return $html;
		}
		$dom = self::load( $html );
		if ( null === $dom ) {
			return $html;
		}
		$changed = false;
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $link ) {
			if ( ! $link->hasAttribute( 'href' ) ) {
				continue;
			}
			// Attribute values are entity-decoded here; drop whitespace and control characters browsers ignore.
			$href = (string) preg_replace( '/[\x00-\x20]+/', '', $link->getAttribute( 'href' ) );
			if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $href, $match ) && ! in_array( strtolower( $match[1] ), array( 'http', 'https', 'mailto' ), true ) ) {
				self::unwrap( $link );
				$changed = true;
			}
		}
		if ( ! $changed ) {
			return $html;
		}
		$warnings[] = __( 'A link with an unsupported address was removed; its text was kept.', 'causeway-claude-bridge' );
		$body       = $dom->getElementsByTagName( 'body' )->item( 0 );
		return null === $body ? '' : self::inner_html( $body );
	}

	/**
	 * Allowed tags and attributes for wp_kses().
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_html(): array {
		$tags = array_diff( (array) $this->writing['allowed_html'], self::HEADINGS );
		// Every heading level is let through here and moved to an allowed level afterwards.
		$tags    = array_merge( $tags, self::HEADINGS );
		$allowed = array();
		foreach ( $tags as $tag ) {
			$allowed[ $tag ] = array_fill_keys( self::ATTRIBUTES[ $tag ] ?? array(), true );
		}
		return $allowed;
	}

	/**
	 * Moves headings to the nearest allowed level; drops heading markup if none is allowed.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function fix_headings( DOMDocument $dom, DOMXPath $xpath ): void {
		$allowed = array_map( static fn ( string $h ): int => (int) substr( $h, 1 ), (array) $this->writing['headings'] );
		sort( $allowed );

		$nodes = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );
		if ( false === $nodes ) {
			return;
		}
		foreach ( iterator_to_array( $nodes ) as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}
			$level  = (int) substr( $heading->nodeName, 1 );
			$target = self::nearest_level( $level, $allowed );
			if ( $target === $level ) {
				continue;
			}
			$replacement = $dom->createElement( null === $target ? 'p' : 'h' . $target );
			while ( $heading->firstChild ) {
				$replacement->appendChild( $heading->firstChild );
			}
			$heading->parentNode?->replaceChild( $replacement, $heading );
		}
	}

	/**
	 * Nearest allowed heading level: a level below the smallest allowed goes up to it (H1 → H2),
	 * one above the largest goes down to it (H4–H6 → H3), otherwise the closest.
	 *
	 * @param int   $level   Heading level.
	 * @param int[] $allowed Allowed levels, sorted.
	 */
	private static function nearest_level( int $level, array $allowed ): ?int {
		if ( array() === $allowed ) {
			return null;
		}
		if ( in_array( $level, $allowed, true ) ) {
			return $level;
		}
		$best = $allowed[0];
		foreach ( $allowed as $candidate ) {
			if ( abs( $candidate - $level ) < abs( $best - $level ) ) {
				$best = $candidate;
			}
		}
		return $best;
	}

	/**
	 * Removes images that aren't attachments on this site.
	 *
	 * @param DOMXPath $xpath    XPath.
	 * @param string[] $warnings Collects warnings.
	 * @return list<int> Attachment IDs of the images kept.
	 */
	private function check_images( DOMXPath $xpath, array &$warnings ): array {
		$ids   = array();
		$nodes = $xpath->query( '//img' );
		if ( false === $nodes ) {
			return $ids;
		}
		foreach ( iterator_to_array( $nodes ) as $image ) {
			if ( ! $image instanceof DOMElement ) {
				continue;
			}
			$id = self::attachment_id( $image->getAttribute( 'src' ) );
			if ( 0 === $id ) {
				$warnings[] = __( 'An image that isn\'t in this site\'s Media Library was removed. Upload it first.', 'causeway-claude-bridge' );
				$parent     = $image->parentNode;
				$parent?->removeChild( $image );
				if ( $parent instanceof DOMElement && 'figure' === $parent->nodeName && '' === trim( $parent->textContent ) && 0 === $parent->getElementsByTagName( 'img' )->length ) {
					$parent->parentNode?->removeChild( $parent );
				}
				continue;
			}
			// Tag the image with its attachment so WordPress (and the block editor) can find it.
			if ( 0 === self::id_from_class( $image->getAttribute( 'class' ) ) ) {
				$image->setAttribute( 'class', trim( $image->getAttribute( 'class' ) . ' wp-image-' . $id ) );
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Attachment ID for an image URL on this site, including resized versions; 0 if none.
	 *
	 * @param string $url Image URL.
	 */
	public static function attachment_id( string $url ): int {
		if ( '' === $url ) {
			return 0;
		}
		$id = attachment_url_to_postid( $url );
		if ( 0 === $id ) {
			// Resized copies end in -{width}x{height} before the extension.
			$original = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
			$id       = $original === $url ? 0 : attachment_url_to_postid( $original );
		}
		return $id > 0 && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * Applies the link rules (unsafe links were already unwrapped before wp_kses()).
	 *
	 * @param DOMXPath $xpath XPath.
	 */
	private function apply_link_rules( DOMXPath $xpath ): void {
		$nodes = $xpath->query( '//a' );
		if ( false === $nodes ) {
			return;
		}
		$rules     = (array) ( $this->writing['link_rules'] ?? array() );
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( iterator_to_array( $nodes ) as $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}
			$href   = trim( $link->getAttribute( 'href' ) );
			$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );

			if ( '' === $href ) {
				self::unwrap( $link );
				continue;
			}

			$host     = (string) wp_parse_url( $href, PHP_URL_HOST );
			$external = in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host && strtolower( $host ) !== strtolower( $site_host );

			$link->removeAttribute( 'target' );
			$rel = array();
			if ( $external && ! empty( $rules['external_new_tab'] ) ) {
				$link->setAttribute( 'target', '_blank' );
				$rel[] = 'noopener';
			}
			if ( $external && ! empty( $rules['nofollow_external'] ) ) {
				$rel[] = 'nofollow';
			}
			if ( array() === $rel ) {
				$link->removeAttribute( 'rel' );
			} else {
				$link->setAttribute( 'rel', implode( ' ', $rel ) );
			}
		}
	}

	/**
	 * Removes styles, non-image classes and empty paragraphs.
	 *
	 * @param DOMXPath $xpath XPath.
	 */
	private function strip_presentation( DOMXPath $xpath ): void {
		$nodes = $xpath->query( '//*[@style]|//*[@class]' );
		if ( false !== $nodes ) {
			foreach ( iterator_to_array( $nodes ) as $element ) {
				if ( ! $element instanceof DOMElement ) {
					continue;
				}
				$element->removeAttribute( 'style' );
				if ( $element->hasAttribute( 'class' ) ) {
					$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
					$keep    = array_filter(
						false === $classes ? array() : $classes,
						static fn ( string $c ): bool => 1 === preg_match( self::IMAGE_CLASS_PATTERN, $c )
					);
					if ( array() === $keep ) {
						$element->removeAttribute( 'class' );
					} else {
						$element->setAttribute( 'class', implode( ' ', $keep ) );
					}
				}
			}
		}

		$paragraphs = $xpath->query( '//p' );
		if ( false !== $paragraphs ) {
			foreach ( iterator_to_array( $paragraphs ) as $paragraph ) {
				if ( $paragraph instanceof DOMElement && '' === trim( str_replace( "\u{00A0}", ' ', $paragraph->textContent ) ) && 0 === $paragraph->getElementsByTagName( 'img' )->length ) {
					$paragraph->parentNode?->removeChild( $paragraph );
				}
			}
		}
	}

	/**
	 * Replaces an element with its children.
	 *
	 * @param DOMElement $element Element.
	 */
	private static function unwrap( DOMElement $element ): void {
		$parent = $element->parentNode;
		if ( null === $parent ) {
			return;
		}
		while ( $element->firstChild ) {
			$parent->insertBefore( $element->firstChild, $element );
		}
		$parent->removeChild( $element );
	}

	/**
	 * Parses an HTML fragment as UTF-8.
	 *
	 * @param string $html Fragment.
	 */
	private static function load( string $html ): ?DOMDocument {
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded ? $dom : null;
	}

	/**
	 * HTML of a node's children.
	 *
	 * @param DOMNode $node Node.
	 */
	private static function inner_html( DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= (string) $node->ownerDocument?->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Wraps top-level elements in core block comments.
	 *
	 * @param string $html Sanitised HTML.
	 */
	public static function to_blocks( string $html ): string {
		$dom = self::load( $html );
		if ( null === $dom ) {
			return $html;
		}
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $body ) {
			return $html;
		}

		$blocks = array();
		foreach ( $body->childNodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$text = trim( (string) $node->textContent );
				if ( '' !== $text ) {
					$blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
				}
				continue;
			}
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$markup   = (string) $dom->saveHTML( $node );
			$blocks[] = self::block_for( $node, $markup, $dom );
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Block comment wrapper for one top-level element.
	 *
	 * @param DOMElement  $node   Element.
	 * @param string      $markup Its HTML.
	 * @param DOMDocument $dom    Document.
	 */
	private static function block_for( DOMElement $node, string $markup, DOMDocument $dom ): string {
		switch ( $node->nodeName ) {
			case 'p':
				return "<!-- wp:paragraph -->\n{$markup}\n<!-- /wp:paragraph -->";
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $node->nodeName, 1 );
				$node->setAttribute( 'class', 'wp-block-heading' );
				$markup = (string) $dom->saveHTML( $node );
				$attrs  = 2 === $level ? '' : ' {"level":' . $level . '}';
				return "<!-- wp:heading{$attrs} -->\n{$markup}\n<!-- /wp:heading -->";
			case 'ul':
			case 'ol':
				$items = '';
				foreach ( $node->childNodes as $child ) {
					if ( $child instanceof DOMElement && 'li' === $child->nodeName ) {
						$items .= "<!-- wp:list-item -->\n" . (string) $dom->saveHTML( $child ) . "\n<!-- /wp:list-item -->\n";
					}
				}
				$attrs = 'ol' === $node->nodeName ? ' {"ordered":true}' : '';
				return "<!-- wp:list{$attrs} -->\n<{$node->nodeName} class=\"wp-block-list\">\n{$items}</{$node->nodeName}>\n<!-- /wp:list -->";
			case 'blockquote':
				$node->setAttribute( 'class', 'wp-block-quote' );
				$inner = '';
				foreach ( $node->childNodes as $child ) {
					if ( $child instanceof DOMElement && 'p' === $child->nodeName ) {
						$inner .= "<!-- wp:paragraph -->\n" . (string) $dom->saveHTML( $child ) . "\n<!-- /wp:paragraph -->\n";
					} elseif ( XML_TEXT_NODE !== $child->nodeType || '' !== trim( (string) $child->textContent ) ) {
						$inner .= (string) $dom->saveHTML( $child );
					}
				}
				$cite = $node->getAttribute( 'cite' );
				$open = '<blockquote class="wp-block-quote"' . ( '' === $cite ? '' : ' cite="' . esc_attr( $cite ) . '"' ) . '>';
				return "<!-- wp:quote -->\n{$open}\n{$inner}</blockquote>\n<!-- /wp:quote -->";
			case 'figure':
				$image = $node->getElementsByTagName( 'img' )->item( 0 );
				$id    = $image instanceof DOMElement ? self::id_from_class( $image->getAttribute( 'class' ) ) : 0;
				$attrs = $id > 0 ? ' {"id":' . $id . '}' : '';
				$node->setAttribute( 'class', trim( 'wp-block-image ' . $node->getAttribute( 'class' ) ) );
				$markup = (string) $dom->saveHTML( $node );
				return "<!-- wp:image{$attrs} -->\n{$markup}\n<!-- /wp:image -->";
			default:
				return "<!-- wp:html -->\n{$markup}\n<!-- /wp:html -->";
		}
	}

	/**
	 * Attachment ID from a `wp-image-N` class.
	 *
	 * @param string $classes Class attribute.
	 */
	private static function id_from_class( string $classes ): int {
		return preg_match( '/\bwp-image-(\d+)\b/', $classes, $match ) ? (int) $match[1] : 0;
	}
}
