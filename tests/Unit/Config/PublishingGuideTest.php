<?php
/**
 * Publishing guide (docs/04 "Publishing guide", docs/03 `ccb/get-publishing-guide`).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Config;

use Brain\Monkey\Functions;
use Causeway\ClaudeBridge\Config\PublishingGuide;
use Causeway\ClaudeBridge\Tests\Unit\UnitTestCase;

/**
 * @covers \Causeway\ClaudeBridge\Config\PublishingGuide
 */
final class PublishingGuideTest extends UnitTestCase {

	private const SNAPSHOT = __DIR__ . '/../../fixtures/guide/mp-site.md';

	protected function set_up(): void {
		parent::set_up();
		Functions\when( '_n' )->alias( static fn ( string $single, string $plural, int $number ): string => 1 === $number ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ): string => number_format( (float) $n ) );
	}

	/**
	 * The guide markdown for the MP site matches the reviewed snapshot.
	 * Run with CCB_UPDATE_SNAPSHOTS=1 to rewrite it after an intended change, then review the diff.
	 */
	public function test_mp_site_guide_matches_snapshot(): void {
		$guide    = PublishingGuide::build( SiteFixture::mp_config(), SiteFixture::structure() );
		$markdown = implode( "\n", array_column( $guide['sections'], 'guide' ) );

		if ( getenv( 'CCB_UPDATE_SNAPSHOTS' ) ) {
			file_put_contents( self::SNAPSHOT, $markdown ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$this->assertSame( (string) file_get_contents( self::SNAPSHOT ), $markdown ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_structured_sections_follow_the_documented_shape(): void {
		$guide = PublishingGuide::build( SiteFixture::mp_config(), SiteFixture::structure() );

		$this->assertSame( 1, $guide['config_version'] );
		$this->assertSame( array( 'news', 'insights', 'visits' ), array_column( $guide['sections'], 'key' ) );

		$news = $guide['sections'][0];
		$this->assertSame( array( 'key', 'label', 'post_type', 'post_type_label', 'guide', 'taxonomies', 'fields', 'featured_image', 'limits' ), array_keys( $news ) );
		$this->assertSame( 'Posts', $news['post_type_label'] );
		$this->assertSame(
			array(
				'label'    => 'Category',
				'required' => true,
				'multiple' => true,
				'default'  => array( 5 ),
				'terms'    => SiteFixture::structure()['taxonomies']['category']['terms'],
			),
			$news['taxonomies']['category']
		);
		$this->assertFalse( $news['taxonomies']['post_tag']['required'] );

		$general = $guide['general'];
		$this->assertSame( 'en-GB', $general['language'] );
		$this->assertSame( array( 'h2', 'h3' ), $general['headings'] );
		$this->assertSame( 4, $general['uploads']['max_upload_mb'] );
		$this->assertSame( 'support@example.org', $general['support_contact']['email'] );
	}

	public function test_insights_fields_carry_their_rules(): void {
		$insights = PublishingGuide::build( SiteFixture::mp_config(), SiteFixture::structure(), 'insights' )['sections'][0];

		$this->assertSame(
			array( 'downloadable_file' => 'optional', 'online_reader_url' => 'only_if_given', 'authors' => 'required' ),
			array_column( $insights['fields'], 'rule', 'name' )
		);
	}

	public function test_visits_location_is_listed_as_a_required_extra_field(): void {
		$visits = PublishingGuide::build( SiteFixture::mp_config(), SiteFixture::structure(), 'visits' )['sections'][0];

		$this->assertSame(
			array(
				array(
					'name'         => 'visits_location',
					'label'        => 'Location',
					'type'         => 'uk_postcode_to_latlng',
					'rule'         => 'required',
					'source'       => 'meta',
					'instructions' => 'Ask for the UK postcode of the place visited.',
				),
			),
			$visits['fields']
		);
	}

	public function test_hidden_fields_are_left_out(): void {
		$config = SiteFixture::mp_config();
		$config['sections']['insights']['field_rules']['online_reader_url'] = 'hidden';

		$insights = PublishingGuide::build( $config, SiteFixture::structure(), 'insights' )['sections'][0];

		$this->assertNotContains( 'online_reader_url', array_column( $insights['fields'], 'name' ) );
		$this->assertStringNotContainsString( 'online_reader_url', $insights['guide'] );
	}

	public function test_acf_required_fields_default_to_required_and_unwritable_ones_are_left_out(): void {
		$structure = SiteFixture::structure();
		$structure['acf']['fields_by_post_type']['resource'][] = array( 'key' => 'field_a', 'name' => 'summary', 'label' => 'Summary', 'type' => 'text', 'required' => true, 'group' => 'G', 'writable' => true, 'instructions' => '' );
		$structure['acf']['fields_by_post_type']['resource'][] = array( 'key' => 'field_b', 'name' => 'gallery', 'label' => 'Gallery', 'type' => 'gallery', 'required' => false, 'group' => 'G', 'writable' => false, 'instructions' => '' );

		$fields = PublishingGuide::build( SiteFixture::mp_config(), $structure, 'insights' )['sections'][0]['fields'];
		$rules  = array_column( $fields, 'rule', 'name' );

		$this->assertSame( 'required', $rules['summary'] );
		$this->assertArrayNotHasKey( 'gallery', $rules );
	}

	public function test_deleted_default_terms_are_left_out(): void {
		$structure                                    = SiteFixture::structure();
		$structure['taxonomies']['category']['terms'] = array( array( 'id' => 6, 'slug' => 'events', 'name' => 'Events', 'parent' => 0 ) );

		$news = PublishingGuide::build( SiteFixture::mp_config(), $structure, 'news' )['sections'][0];

		$this->assertSame( array(), $news['taxonomies']['category']['default'] );
		$this->assertStringNotContainsString( 'defaults to', $news['guide'] );
	}

	public function test_disabled_and_orphaned_sections_are_left_out(): void {
		$config                                    = SiteFixture::mp_config();
		$config['sections']['news']['enabled']     = false;
		$config['sections']['visits']['post_type'] = 'gone';

		$keys = array_column( PublishingGuide::build( $config, SiteFixture::structure() )['sections'], 'key' );

		$this->assertSame( array( 'insights' ), $keys );
	}

	public function test_notes_and_select_choices_appear_in_the_guide(): void {
		$config                                = SiteFixture::mp_config();
		$config['sections']['visits']['notes'] = 'Mention who Mike met.';
		$config['sections']['visits']['extra_fields']['visit_type'] = array( 'label' => 'Type of visit', 'type' => 'select', 'required' => false, 'choices' => array( 'School', 'Business' ) );

		$guide = PublishingGuide::build( $config, SiteFixture::structure(), 'visits' )['sections'][0]['guide'];

		$this->assertStringContainsString( '- **Type of visit** is optional (field `visit_type`, one of the allowed values). Allowed values: `School`, `Business`.', $guide );
		$this->assertStringContainsString( '- Extra notes: Mention who Mike met.', $guide );
	}
}
