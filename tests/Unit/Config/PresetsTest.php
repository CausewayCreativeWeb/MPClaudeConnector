<?php
/**
 * Presets (docs/04 "Presets").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Config;

use Causeway\ClaudeBridge\Config\DefaultConfig;
use Causeway\ClaudeBridge\Config\Presets;
use Causeway\ClaudeBridge\Config\SchemaValidator;
use Causeway\ClaudeBridge\Config\SiteChecks;
use Causeway\ClaudeBridge\Tests\Unit\UnitTestCase;

/**
 * @covers \Causeway\ClaudeBridge\Config\Presets
 * @covers \Causeway\ClaudeBridge\Config\ConfigDiff
 */
final class PresetsTest extends UnitTestCase {

	public function test_mp_site_preset_is_listed(): void {
		$names = array_column( ( new Presets() )->all(), 'name' );
		$this->assertContains( 'mp-site', $names );
	}

	public function test_unknown_or_unsafe_names_return_null(): void {
		$presets = new Presets();
		$this->assertNull( $presets->read( 'nope' ) );
		$this->assertNull( $presets->read( '../composer' ) );
		$this->assertNull( $presets->preview( 'nope', DefaultConfig::get( 'a@example.org' ), SiteFixture::structure() ) );
	}

	public function test_mp_preset_maps_all_three_sections_and_resolves_term_slugs(): void {
		$preview = ( new Presets() )->preview( 'mp-site', DefaultConfig::get( 'support@example.org' ), SiteFixture::structure() );
		$config  = $preview['config'];

		$this->assertSame( array( 'news', 'insights', 'visits' ), array_keys( $config['sections'] ) );
		$this->assertSame( array( 'category' => array( 5 ) ), $config['sections']['news']['default_terms'] );
		$this->assertSame( array(), $preview['skipped_sections'] );
		$this->assertSame( array(), $preview['missing_terms'] );

		// Values the client confirmed.
		$this->assertSame( array( 'min_words' => 150, 'max_words' => 1200, 'excerpt_max_chars' => 160, 'max_images' => 5 ), $config['sections']['news']['limits'] );
		$this->assertSame( 150, $config['sections']['visits']['limits']['max_words'] );
		$this->assertSame( 1, $config['sections']['visits']['limits']['max_images'] );
		$this->assertSame( 'required', $config['sections']['insights']['field_rules']['authors'] );

		// Defaults fill everything the preset leaves out.
		$this->assertSame( '', $config['sections']['visits']['notes'] );
		$this->assertSame( 160, $config['sections']['visits']['limits']['excerpt_max_chars'] );
		$this->assertSame( 'en-GB', $config['writing']['language'] );
		$this->assertTrue( $config['writing']['strip_inline_styles'], 'Writing keys not in the preset are kept' );
	}

	public function test_preset_result_passes_schema_and_site_checks(): void {
		$config = SiteFixture::mp_config();
		$result = ( new SchemaValidator() )->validate( $config );
		SiteChecks::check( $config, SiteFixture::structure(), $result );

		$this->assertTrue( $result->is_valid(), (string) json_encode( $result->errors() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in unit tests.
		$this->assertSame( array(), $result->warnings() );
	}

	public function test_missing_slug_is_reported_and_dropped(): void {
		$structure                                    = SiteFixture::structure();
		$structure['taxonomies']['category']['terms'] = array( array( 'id' => 6, 'slug' => 'events', 'name' => 'Events', 'parent' => 0 ) );

		$preview = ( new Presets() )->preview( 'mp-site', DefaultConfig::get( 'a@example.org' ), $structure );

		$this->assertSame( array( array( 'section' => 'news', 'taxonomy' => 'category', 'slug' => 'news' ) ), $preview['missing_terms'] );
		$this->assertSame( array(), $preview['config']['sections']['news']['default_terms'] );
	}

	public function test_sections_whose_post_type_is_missing_are_skipped(): void {
		$structure               = SiteFixture::structure();
		$structure['post_types'] = array_values( array_filter( $structure['post_types'], static fn ( array $t ): bool => 'visits' !== $t['name'] ) );

		$preview = ( new Presets() )->preview( 'mp-site', DefaultConfig::get( 'a@example.org' ), $structure );

		$this->assertSame( array( array( 'key' => 'visits', 'post_type' => 'visits' ) ), $preview['skipped_sections'] );
		$this->assertArrayNotHasKey( 'visits', $preview['config']['sections'] );
	}

	public function test_existing_section_settings_not_in_the_preset_are_kept(): void {
		$current                              = DefaultConfig::get( 'a@example.org' );
		$current['sections']['news']          = DefaultConfig::section( 'post', 'Latest news' );
		$current['sections']['news']['notes'] = 'Always mention the constituency.';

		$preview = ( new Presets() )->preview( 'mp-site', $current, SiteFixture::structure() );
		$news    = $preview['config']['sections']['news'];

		$this->assertSame( 'Always mention the constituency.', $news['notes'] );
		$this->assertSame( 'News', $news['label'], 'Preset values win' );
		$this->assertContains( '/sections/news/label', array_column( $preview['changes'], 'path' ) );
	}

	public function test_preview_lists_changes_against_the_current_config(): void {
		$preview = ( new Presets() )->preview( 'mp-site', DefaultConfig::get( 'a@example.org' ), SiteFixture::structure() );
		$paths   = array_column( $preview['changes'], 'path' );

		$this->assertContains( '/sections/news', $paths );
		$this->assertContains( '/sections/visits', $paths );
		$this->assertNotContains( '/version', $paths );
	}
}
