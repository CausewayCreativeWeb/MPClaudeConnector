<?php
/**
 * Content sanitising (docs/05 "Post content").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\DefaultConfig;
use Causeway\ClaudeBridge\Content\ContentSanitiser;

/**
 * @covers \Causeway\ClaudeBridge\Content\ContentSanitiser
 */
final class ContentSanitiserTest extends IntegrationTestCase {

	/**
	 * Default writing rules with changes.
	 *
	 * @param array<string, mixed> $changes Changes.
	 */
	private static function sanitiser( array $changes = array() ): ContentSanitiser {
		return new ContentSanitiser( array_merge( DefaultConfig::get( 'a@example.org' )['writing'], $changes ) );
	}

	/**
	 * Sanitises for the classic-editor Visits post type (no block markup).
	 *
	 * @param string               $html    HTML.
	 * @param array<string, mixed> $changes Writing changes.
	 * @return array{html: string, warnings: list<string>, word_count: int, image_ids: list<int>}
	 */
	private static function clean( string $html, array $changes = array() ): array {
		return self::sanitiser( $changes )->sanitise( $html, 'visits' );
	}

	public function test_disallowed_tags_are_removed_but_text_kept(): void {
		$result = self::clean( '<p>Hello <script>alert(1)</script><span onclick="x()">world</span></p><div>Inside a div</div><iframe src="https://x"></iframe>' );

		$this->assertSame( '<p>Hello alert(1)world</p>Inside a div', $result['html'] );
	}

	public function test_headings_move_to_the_nearest_allowed_level(): void {
		$result = self::clean( '<h1>Title</h1><h2>Two</h2><h3>Three</h3><h4>Four</h4><h6>Six</h6>' );

		$this->assertSame( '<h2>Title</h2><h2>Two</h2><h3>Three</h3><h3>Four</h3><h3>Six</h3>', $result['html'] );
	}

	public function test_inline_styles_classes_and_empty_paragraphs_are_stripped(): void {
		$result = self::clean( '<p style="color:red" class="big">Text&nbsp;&nbsp;&nbsp;here</p><p>&nbsp;</p><p> </p><blockquote cite="https://example.org/s">Quote</blockquote>' );

		$this->assertSame( '<p>Text here</p><blockquote cite="https://example.org/s">Quote</blockquote>', $result['html'] );
	}

	public function test_styles_are_kept_when_stripping_is_off_but_unsafe_attributes_never_are(): void {
		$result = self::clean( '<p>One&nbsp;&nbsp;two</p><a href="https://example.org" onclick="x()" style="color:red">Link</a>', array( 'strip_inline_styles' => false ) );

		$this->assertStringNotContainsString( 'onclick', $result['html'] );
		$this->assertStringNotContainsString( 'style=', $result['html'], 'style is not on the attribute allow-list' );
		$this->assertStringContainsString( "One\u{00A0}\u{00A0}two", $result['html'], 'Non-breaking spaces are kept' );
	}

	public function test_images_must_be_attachments_on_this_site(): void {
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$url        = (string) wp_get_attachment_url( $attachment );

		$result = self::clean( '<figure><img src="' . esc_url( $url ) . '" alt="Canola"><figcaption>Field</figcaption></figure><p><img src="https://elsewhere.example/x.jpg" alt="x"></p>' );

		$this->assertSame( array( $attachment ), $result['image_ids'] );
		$this->assertStringContainsString( 'class="wp-image-' . $attachment . '"', $result['html'] );
		$this->assertStringNotContainsString( 'elsewhere.example', $result['html'] );
		$this->assertCount( 1, $result['warnings'] );
	}

	public function test_resized_image_urls_resolve_to_their_attachment(): void {
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$url        = (string) wp_get_attachment_url( $attachment );
		$resized    = (string) preg_replace( '/(\.jpg)$/', '-300x225$1', $url );

		$this->assertSame( $attachment, ContentSanitiser::attachment_id( $resized ) );
	}

	public function test_unsafe_links_are_unwrapped_and_link_rules_applied(): void {
		$result = self::clean(
			'<p><a href="javascript:alert(1)">bad</a> <a href="https://elsewhere.example/page" target="_self" rel="author">external</a> <a href="/about">relative</a> <a href="mailto:office@example.org">mail</a></p>',
			array(
				'link_rules' => array(
					'external_new_tab'  => true,
					'nofollow_external' => true,
				),
			)
		);

		$this->assertStringContainsString( '<p>bad <a', $result['html'], 'Unsafe link unwrapped, text kept' );
		$this->assertStringNotContainsString( 'javascript', $result['html'] );
		$this->assertMatchesRegularExpression( '#<a href="https://elsewhere\.example/page"(?=[^>]*target="_blank")(?=[^>]*rel="noopener nofollow")[^>]*>external</a>#', $result['html'] );
		$this->assertStringNotContainsString( 'alert', $result['html'] );
		$this->assertStringContainsString( '<a href="/about">relative</a>', $result['html'] );
		$this->assertStringContainsString( '<a href="mailto:office@example.org">mail</a>', $result['html'] );
	}

	public function test_words_to_avoid_are_warnings_not_errors(): void {
		$result = self::clean( '<p>This is a game-changer and a Synergy.</p>', array( 'words_to_avoid' => array( 'synergy', 'leverage' ) ) );

		$this->assertSame( '<p>This is a game-changer and a Synergy.</p>', $result['html'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'synergy', $result['warnings'][0] );
	}

	public function test_entity_obfuscated_javascript_links_are_unwrapped(): void {
		$result = self::clean( '<p><a href="java&#x09;script:alert(1)">one</a> <a href=" JAVASCRIPT:alert(1)">two</a> <a href="data:text/html,x">three</a></p>' );

		$this->assertSame( '<p>one two three</p>', $result['html'] );
	}

	public function test_word_count_ignores_markup(): void {
		$this->assertSame( 5, self::clean( '<h2>Two words</h2><p>and <strong>three</strong> more</p>' )['word_count'] );
	}

	public function test_block_editor_post_types_get_block_markup(): void {
		$html = self::sanitiser()->sanitise( '<h2>Heading</h2><p>Para</p><h3>Sub</h3><ul><li>One</li><li>Two</li></ul><blockquote><p>Quote</p></blockquote>', 'post' )['html'];

		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Heading</h2>\n<!-- /wp:heading -->", $html );
		$this->assertStringContainsString( "<!-- wp:paragraph -->\n<p>Para</p>\n<!-- /wp:paragraph -->", $html );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $html );
		$this->assertStringContainsString( '<!-- wp:list-item -->', $html );
		$this->assertStringContainsString( '<!-- wp:quote -->', $html );

		$blocks = parse_blocks( $html );
		$names  = array_values( array_filter( array_column( $blocks, 'blockName' ) ) );
		$this->assertSame( array( 'core/heading', 'core/paragraph', 'core/heading', 'core/list', 'core/quote' ), $names );
	}

	public function test_classic_editor_post_types_keep_plain_html(): void {
		$this->assertStringNotContainsString( '<!-- wp:', self::clean( '<p>Para</p>' )['html'] );
	}

	public function test_utf8_text_survives(): void {
		$this->assertSame( '<p>Café – “quotes” £5 🎉</p>', self::clean( '<p>Café – “quotes” £5 🎉</p>' )['html'] );
	}
}
