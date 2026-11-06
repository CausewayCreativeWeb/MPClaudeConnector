<?php
/**
 * Config checks against the live site (docs/04 "Validation rules").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Config;

use Causeway\ClaudeBridge\Config\SiteChecks;
use Causeway\ClaudeBridge\Config\ValidationResult;
use Causeway\ClaudeBridge\Tests\Unit\UnitTestCase;

/**
 * @covers \Causeway\ClaudeBridge\Config\SiteChecks
 */
final class SiteChecksTest extends UnitTestCase {

	/**
	 * Runs the checks.
	 *
	 * @param array<string, mixed> $config    Config.
	 * @param array<string, mixed> $structure Structure.
	 * @param string               $mode      Mode.
	 */
	private static function run_checks( array $config, ?array $structure = null, string $mode = SiteChecks::MODE_SAVE ): ValidationResult {
		$result = new ValidationResult();
		SiteChecks::check( $config, $structure ?? SiteFixture::structure(), $result, $mode );
		return $result;
	}

	/**
	 * Codes of the problems found.
	 *
	 * @param list<array{path: string, code: string, message: string}> $problems Problems.
	 * @return array<string, string> path => code
	 */
	private static function codes( array $problems ): array {
		return array_column( $problems, 'code', 'path' );
	}

	public function test_mp_config_matches_the_site(): void {
		$result = self::run_checks( SiteFixture::mp_config() );
		$this->assertSame( array(), $result->errors() );
		$this->assertSame( array(), $result->warnings() );
	}

	/**
	 * Each broken reference is an error on save.
	 *
	 * @dataProvider broken_references
	 *
	 * @param callable $mutate        Changes the config.
	 * @param string   $expected_path Error path.
	 * @param string   $expected_code Error code.
	 */
	public function test_missing_site_objects_are_errors_on_save( callable $mutate, string $expected_path, string $expected_code ): void {
		$config = SiteFixture::mp_config();
		$mutate( $config );

		$result = self::run_checks( $config );

		$this->assertSame( array( $expected_path => $expected_code ), self::codes( $result->errors() ) );
	}

	/**
	 * The same broken references are warnings for a stored config.
	 *
	 * @dataProvider broken_references
	 *
	 * @param callable $mutate        Changes the config.
	 * @param string   $expected_path Warning path.
	 */
	public function test_missing_site_objects_are_stale_warnings_when_stored( callable $mutate, string $expected_path ): void {
		$config = SiteFixture::mp_config();
		$mutate( $config );

		$result = self::run_checks( $config, null, SiteChecks::MODE_STORED );

		$this->assertSame( array(), $result->errors() );
		$this->assertSame( array( $expected_path => 'config_stale' ), self::codes( $result->warnings() ) );
	}

	/**
	 * [change, path, code].
	 *
	 * @return array<string, array{0: callable, 1: string, 2: string}>
	 */
	public static function broken_references(): array {
		return array(
			'post type not registered'           => array(
				static function ( array &$c ): void {
										$c['sections']['news']['post_type'] = 'article'; },
				'/sections/news/post_type',
				'post_type_missing',
			),
			'default term deleted'               => array(
				static function ( array &$c ): void {
										$c['sections']['news']['default_terms']['category'] = array( 99 ); },
				'/sections/news/default_terms/category/0',
				'term_missing',
			),
			'default term from another taxonomy' => array(
				static function ( array &$c ): void {
										$c['sections']['news']['default_terms']['category'] = array( 7 ); },
				'/sections/news/default_terms/category/0',
				'term_missing',
			),
			'default terms for foreign taxonomy' => array(
				static function ( array &$c ): void {
										$c['sections']['news']['default_terms']['resource_category'] = array( 7 ); },
				'/sections/news/default_terms/resource_category',
				'taxonomy_not_on_post_type',
			),
			'required taxonomy not on type'      => array(
				static function ( array &$c ): void {
										$c['sections']['visits']['required_taxonomies'] = array( 'category' ); },
				'/sections/visits/required_taxonomies/0',
				'taxonomy_not_on_post_type',
			),
			'rule for an unknown ACF field'      => array(
				static function ( array &$c ): void {
										$c['sections']['insights']['field_rules']['editor_notes'] = 'optional'; },
				'/sections/insights/field_rules/editor_notes',
				'acf_field_missing',
			),
		);
	}

	public function test_field_rules_need_acf(): void {
		$structure                     = SiteFixture::structure();
		$structure['acf']['available'] = false;

		$result = self::run_checks( SiteFixture::mp_config(), $structure );

		$this->assertSame( array( '/sections/insights/field_rules' => 'acf_inactive' ), self::codes( $result->errors() ) );
	}

	public function test_unwritable_acf_fields_must_be_hidden(): void {
		$structure = SiteFixture::structure();
		$structure['acf']['fields_by_post_type']['resource'][] = array( 'key' => 'field_x', 'name' => 'gallery', 'label' => 'Gallery', 'type' => 'gallery', 'required' => false, 'group' => 'G', 'writable' => false, 'instructions' => '' );
		$config = SiteFixture::mp_config();

		$config['sections']['insights']['field_rules']['gallery'] = 'optional';
		$this->assertSame( array( '/sections/insights/field_rules/gallery' => 'acf_field_unsupported' ), self::codes( self::run_checks( $config, $structure )->errors() ) );

		$config['sections']['insights']['field_rules']['gallery'] = 'hidden';
		$this->assertTrue( self::run_checks( $config, $structure )->is_valid() );
	}

	public function test_disabled_sections_are_not_checked(): void {
		$config                                  = SiteFixture::mp_config();
		$config['sections']['news']['enabled']   = false;
		$config['sections']['news']['post_type'] = 'gone';

		$this->assertTrue( self::run_checks( $config )->is_valid() );
	}

	public function test_post_type_with_its_own_capabilities_gets_a_warning(): void {
		$structure                                     = SiteFixture::structure();
		$structure['post_types'][2]['capability_type'] = 'visit';

		$result = self::run_checks( SiteFixture::mp_config(), $structure );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( array( '/sections/visits/post_type' => 'post_type_capabilities' ), self::codes( $result->warnings() ) );
	}

	public function test_extra_field_cannot_shadow_an_acf_field(): void {
		$config = SiteFixture::mp_config();
		$config['sections']['insights']['extra_fields']['authors'] = array( 'label' => 'Authors', 'type' => 'text', 'required' => false );

		$this->assertSame( array( '/sections/insights/extra_fields/authors' => 'extra_field_is_acf' ), self::codes( self::run_checks( $config )->errors() ) );
	}
}
