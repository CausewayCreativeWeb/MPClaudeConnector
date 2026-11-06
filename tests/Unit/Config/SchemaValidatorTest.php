<?php
/**
 * Config validation rules from docs/04 that don't need the live site.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Config;

use Causeway\ClaudeBridge\Config\DefaultConfig;
use Causeway\ClaudeBridge\Config\SchemaValidator;
use Causeway\ClaudeBridge\Tests\Unit\UnitTestCase;

/**
 * @covers \Causeway\ClaudeBridge\Config\SchemaValidator
 */
final class SchemaValidatorTest extends UnitTestCase {

	/**
	 * A valid config shaped like the MP site's.
	 *
	 * @return array<string, mixed>
	 */
	private static function valid_config(): array {
		$config = DefaultConfig::get( 'support@example.org' );

		$news                                      = DefaultConfig::section( 'post', 'News' );
		$news['enabled']                           = true;
		$news['default_terms']                     = array( 'category' => array( 5 ) );
		$news['required_taxonomies']               = array( 'category' );
		$news['featured_image']                    = array(
			'required'  => true,
			'ratio'     => '16:9',
			'min_width' => 1200,
		);
		$news['limits']['min_words']               = 150;
		$news['limits']['max_words']               = 1200;
		$insights                                  = DefaultConfig::section( 'resource', 'Insights' );
		$insights['enabled']                       = true;
		$insights['required_taxonomies']           = array( 'resource_category', 'content-type' );
		$insights['field_rules']                   = array(
			'authors'           => 'required',
			'downloadable_file' => 'optional',
			'online_reader_url' => 'only_if_given',
		);
		$visits                                    = DefaultConfig::section( 'visits', 'Visits' );
		$visits['enabled']                         = true;
		$visits['limits']['max_words']             = 150;
		$visits['limits']['max_images']            = 1;
		$visits['extra_fields']['visits_location'] = array(
			'label'        => 'Location',
			'type'         => 'uk_postcode_to_latlng',
			'required'     => true,
			'instructions' => 'Ask for the UK postcode of the place visited.',
		);
		$config['sections']                        = compact( 'news', 'insights', 'visits' );
		$config['notifications']['recipients']['extra'] = array( 'office@example.org' );

		return $config;
	}

	/**
	 * Sets a value at a slash-separated path, or removes it when $value is the REMOVE marker.
	 *
	 * @param array<string, mixed> $config Config.
	 * @param string               $path   Path such as `media/webp_quality`.
	 * @param mixed                $value  New value.
	 * @return array<string, mixed>
	 */
	private static function with( array $config, string $path, mixed $value ): array {
		$keys = explode( '/', $path );
		$last = array_pop( $keys );
		$ref  = &$config;
		foreach ( $keys as $key ) {
			$ref = &$ref[ $key ];
		}
		if ( '__remove__' === $value ) {
			unset( $ref[ $last ] );
		} else {
			$ref[ $last ] = $value;
		}
		return $config;
	}

	public function test_default_config_is_valid(): void {
		$result = ( new SchemaValidator() )->validate( DefaultConfig::get( 'support@example.org' ) );
		$this->assertTrue( $result->is_valid(), self::json( $result->errors() ) );
	}

	public function test_mp_shaped_config_is_valid(): void {
		$result = ( new SchemaValidator() )->validate( self::valid_config() );
		$this->assertTrue( $result->is_valid(), self::json( $result->errors() ) );
	}

	public function test_zero_limits_mean_no_limit(): void {
		$config = self::with( self::valid_config(), 'sections/news/limits/max_words', 0 );
		$config = self::with( $config, 'sections/news/limits/min_words', 500 );
		$this->assertTrue( ( new SchemaValidator() )->validate( $config )->is_valid() );
	}

	public function test_select_extra_field_with_choices_is_valid(): void {
		$config = self::with(
			self::valid_config(),
			'sections/visits/extra_fields/visit_type',
			array(
				'label'    => 'Type',
				'type'     => 'select',
				'required' => false,
				'choices'  => array( 'School', 'Business' ),
			)
		);
		$this->assertTrue( ( new SchemaValidator() )->validate( $config )->is_valid() );
	}

	/**
	 * Every invalid case reports an error at the expected path.
	 *
	 * @dataProvider invalid_cases
	 *
	 * @param string $path          Value to change.
	 * @param mixed  $value         Invalid value.
	 * @param string $expected_path Path the error should point at.
	 */
	public function test_invalid_value_is_rejected_at_its_path( string $path, mixed $value, string $expected_path ): void {
		$result = ( new SchemaValidator() )->validate( self::with( self::valid_config(), $path, $value ) );

		$this->assertFalse( $result->is_valid(), 'Expected an error for ' . $path );
		$this->assertContains( $expected_path, $result->error_paths(), self::json( $result->errors() ) );
		foreach ( $result->errors() as $error ) {
			$this->assertNotSame( '', $error['message'] );
		}
	}

	/**
	 * Cases: [path to change, invalid value, expected error path].
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string}>
	 */
	public static function invalid_cases(): array {
		return array(
			// Top level.
			'schema version not supported'     => array( 'schema_version', 2, '/schema_version' ),
			'unknown top-level setting'        => array( 'colour', 'red', '/colour' ),
			'missing writing block'            => array( 'writing', '__remove__', '/writing' ),

			// Section keys and fields.
			'section key with capitals'        => array( 'sections/News', DefaultConfig::section( 'page', 'News' ), '/sections/News' ),
			'section key too short'            => array( 'sections/n', DefaultConfig::section( 'page', 'N' ), '/sections/n' ),
			'section key starting with digit'  => array( 'sections/1news', DefaultConfig::section( 'page', 'N' ), '/sections/1news' ),
			'empty label'                      => array( 'sections/news/label', '', '/sections/news/label' ),
			'label over 40 chars'              => array( 'sections/news/label', str_repeat( 'a', 41 ), '/sections/news/label' ),
			'notes over 1000 chars'            => array( 'sections/news/notes', str_repeat( 'a', 1001 ), '/sections/news/notes' ),
			'missing post type'                => array( 'sections/news/post_type', '__remove__', '/sections/news/post_type' ),
			'default term id not an integer'   => array( 'sections/news/default_terms/category', array( 'news' ), '/sections/news/default_terms/category/0' ),
			'default term id zero'             => array( 'sections/news/default_terms/category', array( 0 ), '/sections/news/default_terms/category/0' ),
			'ratio not allowed'                => array( 'sections/news/featured_image/ratio', '3:2', '/sections/news/featured_image/ratio' ),
			'negative min width'               => array( 'sections/news/featured_image/min_width', -1, '/sections/news/featured_image/min_width' ),
			'negative max words'               => array( 'sections/news/limits/max_words', -5, '/sections/news/limits/max_words' ),
			'unknown field rule'               => array( 'sections/insights/field_rules/authors', 'mandatory', '/sections/insights/field_rules/authors' ),
			'extra field key starting with _'  => array( 'sections/visits/extra_fields/_secret', array( 'label' => 'S', 'type' => 'text', 'required' => false ), '/sections/visits/extra_fields/_secret' ),
			'extra field key with capitals'    => array( 'sections/visits/extra_fields/Location', array( 'label' => 'S', 'type' => 'text', 'required' => false ), '/sections/visits/extra_fields/Location' ),
			'extra field unknown type'         => array( 'sections/visits/extra_fields/visits_location/type', 'map', '/sections/visits/extra_fields/visits_location/type' ),
			'select extra field without choices' => array( 'sections/visits/extra_fields/kind', array( 'label' => 'Kind', 'type' => 'select', 'required' => false ), '/sections/visits/extra_fields/kind/choices' ),

			// Writing.
			'h1 as allowed heading'            => array( 'writing/headings', array( 'h1', 'h2' ), '/writing/headings/0' ),
			'no headings'                      => array( 'writing/headings', array(), '/writing/headings' ),
			'script in allowed html'           => array( 'writing/allowed_html', array( 'p', 'script' ), '/writing/allowed_html/1' ),
			'language not a locale'            => array( 'writing/language', 'english', '/writing/language' ),
			'house style over 4000 chars'      => array( 'writing/house_style', str_repeat( 'a', 4001 ), '/writing/house_style' ),
			'sign-off over 500 chars'          => array( 'writing/sign_off', str_repeat( 'a', 501 ), '/writing/sign_off' ),
			'unknown SEO plugin'               => array( 'writing/seo/plugin', 'allinone', '/writing/seo/plugin' ),

			// Media ranges.
			'webp quality below 60'            => array( 'media/webp_quality', 59, '/media/webp_quality' ),
			'webp quality above 95'            => array( 'media/webp_quality', 96, '/media/webp_quality' ),
			'max upload below 1 MB'            => array( 'media/max_upload_mb', 0, '/media/max_upload_mb' ),
			'max upload above 10 MB'           => array( 'media/max_upload_mb', 11, '/media/max_upload_mb' ),
			'PDF max below 1 MB'               => array( 'media/pdf_max_mb', 0, '/media/pdf_max_mb' ),
			'PDF max above 50 MB'              => array( 'media/pdf_max_mb', 51, '/media/pdf_max_mb' ),
			'upload link below 5 minutes'      => array( 'media/upload_link_minutes', 4, '/media/upload_link_minutes' ),
			'upload link above 60 minutes'     => array( 'media/upload_link_minutes', 61, '/media/upload_link_minutes' ),
			'strip metadata switched off'      => array( 'media/strip_metadata', false, '/media/strip_metadata' ),
			'HEIC as image type'               => array( 'media/image_types', array( 'image/heic' ), '/media/image_types/0' ),
			'quality as a string'              => array( 'media/webp_quality', '82', '/media/webp_quality' ),

			// Access ranges.
			'requests per minute below 5'      => array( 'access/requests_per_minute', 4, '/access/requests_per_minute' ),
			'requests per minute above 120'    => array( 'access/requests_per_minute', 121, '/access/requests_per_minute' ),
			'live changes per day below 1'     => array( 'access/live_changes_per_day', 0, '/access/live_changes_per_day' ),
			'live changes per day above 200'   => array( 'access/live_changes_per_day', 201, '/access/live_changes_per_day' ),

			// Notifications, support, activity.
			'invalid extra recipient'          => array( 'notifications/recipients/extra', array( 'not-an-email' ), '/notifications/recipients/extra/0' ),
			'missing support email'            => array( 'support/email', '__remove__', '/support/email' ),
			'empty support email'              => array( 'support/email', '', '/support/email' ),
			'invalid support email'            => array( 'support/email', 'support', '/support/email' ),
			'support url not http'             => array( 'support/url', 'javascript:alert(1)', '/support/url' ),
			'retention below 1 month'          => array( 'activity/retention_months', 0, '/activity/retention_months' ),
			'retention above 36 months'        => array( 'activity/retention_months', 37, '/activity/retention_months' ),
		);
	}

	public function test_two_sections_cannot_share_a_post_type(): void {
		$config = self::with( self::valid_config(), 'sections/visits/post_type', 'post' );
		$result = ( new SchemaValidator() )->validate( $config );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( array( '/sections/visits/post_type' ), $result->error_paths() );
		$this->assertSame( 'post_type_shared', $result->errors()[0]['code'] );
	}

	public function test_min_words_cannot_exceed_max_words(): void {
		$config = self::with( self::valid_config(), 'sections/news/limits/min_words', 1300 );
		$result = ( new SchemaValidator() )->validate( $config );

		$this->assertSame( array( '/sections/news/limits/min_words' ), $result->error_paths() );
	}

	public function test_at_least_one_notification_recipient_is_needed(): void {
		$config = self::with( self::valid_config(), 'notifications/recipients/publishers', false );
		$config = self::with( $config, 'notifications/recipients/extra', array() );
		$result = ( new SchemaValidator() )->validate( $config );

		$this->assertSame( array( '/notifications/recipients' ), $result->error_paths() );
		$this->assertSame( 'no_recipients', $result->errors()[0]['code'] );
	}

	public function test_extra_address_alone_is_enough_recipients(): void {
		$config = self::with( self::valid_config(), 'notifications/recipients/publishers', false );
		$this->assertTrue( ( new SchemaValidator() )->validate( $config )->is_valid() );
	}

	public function test_bad_key_names_report_only_the_key(): void {
		$config = self::with( self::valid_config(), 'sections/1news', DefaultConfig::section( 'page', 'N' ) );
		$config = self::with( $config, 'sections/visits/extra_fields/_secret', array( 'label' => 'S', 'type' => 'text', 'required' => false ) );
		$config = self::with( $config, 'colour', 'red' );
		$result = ( new SchemaValidator() )->validate( $config );

		$paths = $result->error_paths();
		sort( $paths );
		$this->assertSame( array( '/colour', '/sections/1news', '/sections/visits/extra_fields/_secret' ), $paths, self::json( $result->errors() ) );
		$this->assertCount( 3, $result->errors() );
	}

	public function test_all_errors_are_reported_not_just_the_first(): void {
		$config = self::with( self::valid_config(), 'media/webp_quality', 10 );
		$config = self::with( $config, 'access/requests_per_minute', 1000 );
		$result = ( new SchemaValidator() )->validate( $config );

		$this->assertContains( '/media/webp_quality', $result->error_paths() );
		$this->assertContains( '/access/requests_per_minute', $result->error_paths() );
	}

	/**
	 * JSON for assertion messages.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( mixed $value ): string {
		return (string) json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
