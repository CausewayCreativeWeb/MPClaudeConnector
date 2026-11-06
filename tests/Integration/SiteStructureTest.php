<?php
/**
 * Live discovery, presets on a real site, and stale-config detection (docs/04).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Config\Presets;
use Causeway\ClaudeBridge\Config\PublishingGuide;
use Causeway\ClaudeBridge\Config\SiteStructure;

/**
 * @covers \Causeway\ClaudeBridge\Config\SiteStructure
 * @covers \Causeway\ClaudeBridge\Config\SiteChecks
 * @covers \Causeway\ClaudeBridge\Config\Presets
 * @covers \Causeway\ClaudeBridge\Config\PublishingGuide
 */
final class SiteStructureTest extends IntegrationTestCase {

	private SiteStructure $site;

	/**
	 * Term IDs created for the MP sections.
	 *
	 * @var array<string, int>
	 */
	private array $terms = array();

	public function set_up(): void {
		parent::set_up();
		$this->site = new SiteStructure();

		$this->terms['news']      = self::factory()->category->create( array( 'name' => 'News', 'slug' => 'news' ) );
		$this->terms['reports']   = self::factory()->term->create( array( 'taxonomy' => 'resource_category', 'name' => 'Reports', 'slug' => 'reports' ) );
		$this->terms['briefings'] = self::factory()->term->create( array( 'taxonomy' => 'content-type', 'name' => 'Briefing', 'slug' => 'briefing' ) );
		SiteStructure::clear_cache();
	}

	/**
	 * Applies the MP preset to the live config.
	 *
	 * @return array<string, mixed>
	 */
	private function mp_config(): array {
		$preview = ( new Presets() )->preview( 'mp-site', ( new ConfigRepository() )->get(), $this->site->get() );
		$this->assertNotNull( $preview );
		return $preview['config'];
	}

	public function test_discovers_the_sections_post_types_and_excludes_the_rest(): void {
		$structure = $this->site->discover();
		$names     = array_column( $structure['post_types'], 'name' );

		foreach ( array( 'post', 'resource', 'visits' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
		foreach ( array( 'page', 'attachment' ) as $excluded ) {
			$this->assertNotContains( $excluded, $names );
		}

		$types = array_column( $structure['post_types'], null, 'name' );
		$this->assertFalse( $types['visits']['show_in_rest'], 'Visits is not in the REST API, and that is fine' );
		$this->assertSame( array(), $types['visits']['taxonomies'] );
		$this->assertEqualsCanonicalizing( array( 'resource_category', 'content-type' ), $types['resource']['taxonomies'] );
		$this->assertEqualsCanonicalizing( array( 'category', 'post_tag' ), $types['post']['taxonomies'] );
		$this->assertSame( 'post', $types['resource']['capability_type'] );
	}

	public function test_discovers_terms_without_hiding_empty_ones(): void {
		$structure = $this->site->discover();

		$slugs = array_column( $structure['taxonomies']['resource_category']['terms'], 'slug' );
		$this->assertContains( 'reports', $slugs, 'Terms with no posts are included' );
		$this->assertTrue( $structure['taxonomies']['resource_category']['hierarchical'] );
	}

	public function test_discovers_acf_fields_by_key_for_resources(): void {
		$structure = $this->site->discover();
		if ( ! $structure['acf']['available'] ) {
			$this->markTestSkipped( 'ACF is not installed in this test environment.' );
		}

		$fields = array_column( $structure['acf']['fields_by_post_type']['resource'], null, 'name' );
		$this->assertSame( 'field_655493153192f', $fields['downloadable_file']['key'] );
		$this->assertSame( 'file', $fields['downloadable_file']['type'] );
		$this->assertSame( 'field_6895f57a6a12e', $fields['online_reader_url']['key'] );
		$this->assertSame( 'field_68bef927b733c', $fields['authors']['key'] );
		$this->assertSame( 'Resource Details', $fields['authors']['group'] );
		$this->assertFalse( $fields['authors']['required'] );
		$this->assertTrue( $fields['authors']['writable'] );
		$this->assertArrayNotHasKey( 'visits', $structure['acf']['fields_by_post_type'] );
	}

	public function test_reports_no_seo_plugin_and_no_elementor_on_a_bare_site(): void {
		$structure = $this->site->discover();
		$this->assertSame( 'none', $structure['seo_plugin'] );
		$this->assertSame( array(), $structure['elementor']['single_templates'] );
	}

	public function test_reads_elementor_single_templates_and_their_conditions(): void {
		register_post_type( 'elementor_library', array( 'public' => false ) );
		$template = self::factory()->post->create(
			array(
				'post_type'   => 'elementor_library',
				'post_title'  => 'Single Visit',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $template, '_elementor_template_type', 'single-post' );
		update_post_meta( $template, '_elementor_conditions', array( 'include/singular/visits' ) );
		$header = self::factory()->post->create( array( 'post_type' => 'elementor_library', 'post_status' => 'publish' ) );
		update_post_meta( $header, '_elementor_template_type', 'header' );

		$structure = $this->site->discover();
		unregister_post_type( 'elementor_library' );

		$this->assertSame(
			array(
				array(
					'id'         => $template,
					'title'      => 'Single Visit',
					'conditions' => array( 'include/singular/visits' ),
				),
			),
			$structure['elementor']['single_templates']
		);
		$this->assertCount( 1, SiteStructure::templates_for( $structure, 'visits' ) );
		$this->assertCount( 0, SiteStructure::templates_for( $structure, 'resource' ) );
	}

	public function test_structure_is_cached_and_cleared_when_terms_change(): void {
		$this->site->get();
		$this->assertIsArray( get_transient( SiteStructure::TRANSIENT ) );

		self::factory()->category->create( array( 'name' => 'Events' ) );

		$this->assertFalse( get_transient( SiteStructure::TRANSIENT ), 'Creating a term clears the cache' );
		$this->assertContains( 'Events', array_column( $this->site->get()['taxonomies']['category']['terms'], 'name' ) );
	}

	public function test_mp_preset_applies_and_saves_on_the_real_site(): void {
		$config = $this->mp_config();
		$this->assertSame( array( $this->terms['news'] ), $config['sections']['news']['default_terms']['category'] );

		$repository = new ConfigRepository();
		$saved      = $repository->save( $config, 1, 'preset', 'MP site preset' );

		$this->assertIsArray( $saved, is_wp_error( $saved ) ? (string) wp_json_encode( $saved->get_error_data() ) : '' );
		$this->assertTrue( $repository->validate_stored()->is_valid() );
		$this->assertSame( array(), $repository->validate_stored()->warnings() );
		$this->assertSame( 'preset', $repository->versions()[0]['source'] );
	}

	public function test_saving_a_reference_to_a_missing_term_is_refused(): void {
		$config = $this->mp_config();
		$config['sections']['news']['default_terms']['category'] = array( 999999 );

		$result = ( new ConfigRepository() )->save( $config, 1, 'ui' );

		$this->assertWPError( $result );
		$this->assertSame( 'ccb_config_invalid', $result->get_error_code() );
		$this->assertSame( '/sections/news/default_terms/category/0', $result->get_error_data()['errors'][0]['path'] );
	}

	public function test_term_deleted_after_saving_makes_the_config_stale_and_leaves_the_guide(): void {
		$repository = new ConfigRepository();
		$this->assertIsArray( $repository->save( $this->mp_config(), 1, 'preset' ) );

		wp_delete_term( $this->terms['news'], 'category' );

		$stored = $repository->validate_stored();
		$this->assertTrue( $stored->is_valid(), 'Still usable' );
		$this->assertSame( array( '/sections/news/default_terms/category/0' => 'config_stale' ), array_column( $stored->warnings(), 'code', 'path' ) );

		$guide = PublishingGuide::build( $repository->get(), $this->site->get(), 'news' );
		$this->assertSame( array(), $guide['sections'][0]['taxonomies']['category']['default'] );
	}

	public function test_warnings_must_be_confirmed_before_saving(): void {
		register_post_type(
			'book',
			array(
				'public'          => true,
				'capability_type' => 'book',
				'label'           => 'Books',
			)
		);
		SiteStructure::clear_cache();

		$repository                             = new ConfigRepository();
		$config                                 = $repository->get();
		$config['sections']['books']            = \Causeway\ClaudeBridge\Config\DefaultConfig::section( 'book', 'Books' );
		$config['sections']['books']['enabled'] = true;

		$first = $repository->save( $config, 1, 'ui' );
		$this->assertWPError( $first );
		$this->assertSame( 'ccb_config_needs_confirm', $first->get_error_code() );
		$this->assertSame( 'post_type_capabilities', $first->get_error_data()['warnings'][0]['code'] );

		$this->assertIsArray( $repository->save( $config, 1, 'ui', null, null, true ) );
		unregister_post_type( 'book' );
	}

	public function test_live_guide_for_the_mp_site(): void {
		$repository = new ConfigRepository();
		$this->assertIsArray( $repository->save( $this->mp_config(), 1, 'preset' ) );

		$guide = PublishingGuide::build( $repository->get(), $this->site->get() );

		$this->assertSame( array( 'news', 'insights', 'visits' ), array_column( $guide['sections'], 'key' ) );
		$this->assertStringContainsString( 'defaults to News', $guide['sections'][0]['guide'] );
		$this->assertStringContainsString( '`visits_location`', $guide['sections'][2]['guide'] );
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$this->assertStringContainsString( '**Author(s)** is required', $guide['sections'][1]['guide'] );
		}
	}
}
