<?php
/**
 * The create-post and update-post abilities through the MCP server (docs/03, docs/05, docs/11).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * @covers \Causeway\ClaudeBridge\Abilities\CreatePost
 * @covers \Causeway\ClaudeBridge\Abilities\UpdatePost
 * @covers \Causeway\ClaudeBridge\Content\PostWriter
 * @covers \Causeway\ClaudeBridge\Content\FieldWriter
 * @covers \Causeway\ClaudeBridge\Content\TermResolver
 * @covers \Causeway\ClaudeBridge\Content\Geocoder
 * @covers \Causeway\ClaudeBridge\Checks\PostVerifier
 */
final class WriteAbilitiesTest extends McpTestCase {

	/**
	 * Postcode lookups made in the current test.
	 *
	 * @var list<string>
	 */
	private array $lookups = array();

	public function set_up(): void {
		parent::set_up();
		$this->configure_mp_site();
		$this->lookups = array();
		add_filter( 'pre_http_request', array( $this, 'fake_postcodes_io' ), 10, 3 );
		$GLOBALS['ccb_fixture_visits_warnings'] = 0;
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_postcodes_io' ) );
		parent::tear_down();
	}

	/**
	 * Stands in for postcodes.io: WA1 1AA is Warrington, JE2 is Jersey (outside the bounds), anything else 404.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param mixed  $args Request args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function fake_postcodes_io( mixed $pre, mixed $args, string $url ): mixed {
		unset( $args );
		if ( ! str_starts_with( $url, 'https://api.postcodes.io/postcodes/' ) ) {
			return $pre;
		}
		$postcode        = rawurldecode( substr( $url, strlen( 'https://api.postcodes.io/postcodes/' ) ) );
		$this->lookups[] = $postcode;
		$places          = array(
			'WA11AA' => array( 53.390012, -2.597034 ),
			'JE23QA' => array( 49.184, -2.104 ),
		);
		if ( ! isset( $places[ $postcode ] ) ) {
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => '{"status":404,"error":"Postcode not found"}',
				'headers'  => array(),
				'cookies'  => array(),
			);
		}
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'status' => 200,
					'result' => array(
						'latitude'  => $places[ $postcode ][0],
						'longitude' => $places[ $postcode ][1],
					),
				)
			),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	/**
	 * Uploads a generated JPEG of the given size.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	private function image( int $width, int $height ): int {
		$file  = get_temp_dir() . 'ccb-test-' . $width . 'x' . $height . '-' . wp_generate_password( 6, false ) . '.jpg';
		$image = imagecreatetruecolor( $width, $height );
		imagejpeg( $image, $file );
		imagedestroy( $image );
		$id = self::factory()->attachment->create_upload_object( $file );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Test image' );
		return $id;
	}

	/**
	 * Words of lorem text.
	 *
	 * @param int $count Words.
	 */
	private static function words( int $count ): string {
		return '<p>' . trim( str_repeat( 'word ', $count ) ) . '</p>';
	}

	/**
	 * Error code in a failed result's text.
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	private static function code( array $result ): string {
		return preg_match( '/code: (ccb_[a-z_]+)/', self::text( $result ), $m ) ? $m[1] : '';
	}

	/**
	 * All meta keys on a post.
	 *
	 * @param int $post_id Post.
	 * @return list<string>
	 */
	private static function meta_keys( int $post_id ): array {
		return array_keys( get_post_meta( $post_id ) );
	}

	public function test_contributor_post_is_pending_even_when_asking_to_publish(): void {
		$user = $this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data(
			$this->call_tool(
				'ccb-create-post',
				array(
					'section' => 'news',
					'title'   => 'Crossing reopens',
					'content' => self::words( 200 ),
					'status'  => 'publish',
				)
			)
		);

		$post = get_post( $data['post_id'] );
		$this->assertSame( 'pending', $post->post_status );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( $user, (int) $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertNotNull( $data['preview_url'] );
		$this->assertStringContainsString( 'post.php?post=' . $data['post_id'], $data['edit_url'] );
		$created = json_decode( (string) get_post_meta( $data['post_id'], '_ccb_created_via', true ), true );
		$this->assertSame( 'claude', $created['via'] );
		$this->assertSame( $user, $created['user_id'] );
	}

	public function test_publisher_chooses_draft_or_pending_but_never_publish(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );

		$default = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Default status', 'content' => self::words( 200 ) ) ) );
		$this->assertSame( 'pending', get_post_status( $default['post_id'] ) );

		$draft = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'A draft', 'content' => self::words( 200 ), 'status' => 'draft' ) ) );
		$this->assertSame( 'draft', get_post_status( $draft['post_id'] ) );

		foreach ( array( 'publish', 'future' ) as $status ) {
			$refused = $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Go live ' . $status, 'content' => self::words( 200 ), 'status' => $status ) );
			$this->assertTrue( $refused['isError'] );
			$this->assertSame( 'ccb_wrong_status', self::code( $refused ) );
		}
	}

	public function test_title_and_content_are_cleaned(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data(
			$this->call_tool(
				'ccb-create-post',
				array(
					'section' => 'visits',
					'title'   => 'Visit <img src=x onerror=alert(1)> to "Riverside"',
					'content' => '<h1>Big</h1><p style="color:red">Text</p><script>bad()</script>',
				)
			)
		);
		$post = get_post( $data['post_id'] );

		$this->assertSame( 'Visit to "Riverside"', $post->post_title, 'Titles are plain text (MP Visits prints them unescaped)' );
		$this->assertSame( '<h2>Big</h2><p>Text</p>bad()', $post->post_content );
	}

	public function test_terms_resolve_by_id_or_slug_and_defaults_apply(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$news   = get_term_by( 'slug', 'news', 'category' )->term_id;
		$events = self::factory()->category->create( array( 'name' => 'Events', 'slug' => 'events' ) );
		$tag    = self::factory()->tag->create( array( 'name' => 'Schools', 'slug' => 'schools' ) );

		$defaulted = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Defaults', 'content' => self::words( 200 ) ) ) );
		$this->assertSame( array( $news ), wp_get_object_terms( $defaulted['post_id'], 'category', array( 'fields' => 'ids' ) ) );

		$chosen = $this->data(
			$this->call_tool(
				'ccb-create-post',
				array(
					'section' => 'news',
					'title'   => 'Chosen terms',
					'content' => self::words( 200 ),
					'terms'   => array(
						'category' => array( 'events' ),
						'post_tag' => array( $tag ),
					),
				)
			)
		);
		$this->assertSame( array( $events ), wp_get_object_terms( $chosen['post_id'], 'category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( array( $tag ), wp_get_object_terms( $chosen['post_id'], 'post_tag', array( 'fields' => 'ids' ) ) );
	}

	public function test_unknown_terms_are_refused_and_never_created(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$before = wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );

		$result = $this->call_tool(
			'ccb-create-post',
			array(
				'section' => 'news',
				'title'   => 'New category please',
				'content' => self::words( 200 ),
				'terms'   => array( 'category' => array( 'brand-new-category' ) ),
			)
		);

		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'ccb_term_unknown', self::code( $result ) );
		$this->assertStringContainsString( 'field: terms.category', self::text( $result ) );
		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) );
		$this->assertNull( term_exists( 'brand-new-category', 'category' ) );

		$foreign = $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Foreign taxonomy', 'content' => self::words( 200 ), 'terms' => array( 'resource_category' => array( 1 ) ) ) );
		$this->assertSame( 'ccb_term_unknown', self::code( $foreign ) );
	}

	public function test_insights_acf_fields_are_written_by_key(): void {
		if ( ! function_exists( 'update_field' ) ) {
			$this->markTestSkipped( 'ACF is not installed.' );
		}
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$category = self::factory()->term->create( array( 'taxonomy' => 'resource_category', 'name' => 'Reports' ) );
		$type     = self::factory()->term->create( array( 'taxonomy' => 'content-type', 'name' => 'Briefing' ) );

		$data = $this->data(
			$this->call_tool(
				'ccb-create-post',
				array(
					'section' => 'insights',
					'title'   => 'Rail report',
					'content' => self::words( 300 ),
					'terms'   => array(
						'resource_category' => array( $category ),
						'content-type'      => array( $type ),
					),
					'fields'  => array( 'authors' => "Jane Smith\nAli Khan" ),
				)
			)
		);

		$this->assertSame( "Jane Smith\nAli Khan", get_post_meta( $data['post_id'], 'authors', true ) );
		$this->assertSame( 'field_68bef927b733c', get_post_meta( $data['post_id'], '_authors', true ), 'Written by field key' );
		$checks = array_column( $data['verification']['checks'], 'passed', 'name' );
		$this->assertTrue( $checks['field_authors'] );
		$this->assertTrue( $checks['terms_resource_category'] );
	}

	public function test_hidden_unknown_and_badly_typed_fields_are_refused(): void {
		if ( ! function_exists( 'update_field' ) ) {
			$this->markTestSkipped( 'ACF is not installed.' );
		}
		$repository = new ConfigRepository();
		$config     = $repository->get();
		$config['sections']['insights']['field_rules']['online_reader_url'] = 'hidden';
		$this->assertIsArray( $repository->save( $config, 1, 'ui' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$hidden = $this->call_tool( 'ccb-create-post', array( 'section' => 'insights', 'title' => 'Hidden', 'fields' => array( 'online_reader_url' => 'https://example.org/reader' ) ) );
		$this->assertSame( 'ccb_field_hidden', self::code( $hidden ) );

		$unknown = $this->call_tool( 'ccb-create-post', array( 'section' => 'insights', 'title' => 'Unknown', 'fields' => array( 'colour' => 'red' ) ) );
		$this->assertSame( 'ccb_invalid_input', self::code( $unknown ) );

		$typed = $this->call_tool( 'ccb-create-post', array( 'section' => 'insights', 'title' => 'Typed', 'fields' => array( 'authors' => array( 'not', 'text' ) ) ) );
		$this->assertSame( 'ccb_field_invalid', self::code( $typed ) );
		$this->assertStringContainsString( 'field: fields.authors', self::text( $typed ) );
	}

	public function test_visit_location_from_a_postcode_in_the_exact_mp_visits_format(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data(
			$this->call_tool(
				'ccb-create-post',
				array(
					'section' => 'visits',
					'title'   => 'Visit to Riverside Primary',
					'content' => '<p>Met pupils and staff.</p>',
					'fields'  => array( 'visits_location' => array( 'postcode' => 'wa1  1aa' ) ),
				)
			)
		);

		$this->assertSame( array( 'WA11AA' ), $this->lookups, 'Compact postcode, as the MP Visits admin map sends it' );
		$this->assertSame( '{"lat":53.390012,"lng":-2.597034}', get_post_meta( $data['post_id'], 'visits_location', true ) );
		$this->assertGreaterThan( 0, $GLOBALS['ccb_fixture_visits_warnings'], 'MP Visits\' save routine ran (and would log PHP warnings on the real site)' );
		$this->assertTrue( array_column( $data['verification']['checks'], 'passed', 'name' )['field_visits_location'] );

		// Once published, the visit is on the map.
		wp_update_post( array( 'ID' => $data['post_id'], 'post_status' => 'publish' ) );
		$map = json_decode( do_shortcode( '[visits_map]' ), true );
		$this->assertContains( 'Visit to Riverside Primary', array_column( $map, 'title' ) );
		$this->assertSame( array( 'lat' => 53.390012, 'lng' => -2.597034 ), array_column( $map, 'location', 'title' )['Visit to Riverside Primary'] );

		// Repeat lookups come from the cache.
		$this->call_tool( 'ccb-create-post', array( 'section' => 'visits', 'title' => 'Second visit', 'fields' => array( 'visits_location' => array( 'postcode' => 'WA1 1AA' ) ) ) );
		$this->assertSame( array( 'WA11AA' ), $this->lookups );
	}

	public function test_bad_postcodes_are_refused_before_anything_is_saved(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$before = (int) wp_count_posts( 'visits' )->pending;

		foreach ( array( 'NOT A CODE', 'ZZ9 9ZZ', 'JE2 3QA' ) as $postcode ) {
			$result = $this->call_tool( 'ccb-create-post', array( 'section' => 'visits', 'title' => 'Visit ' . $postcode, 'fields' => array( 'visits_location' => array( 'postcode' => $postcode ) ) ) );
			$this->assertTrue( $result['isError'], $postcode );
			$this->assertSame( 'ccb_geocode_failed', self::code( $result ), $postcode );
		}
		wp_cache_flush();
		$this->assertSame( $before, (int) wp_count_posts( 'visits' )->pending );
	}

	public function test_missing_required_things_create_the_post_and_fail_verification(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'visits', 'title' => 'Incomplete visit', 'content' => '<p>Short.</p>' ) ) );

		$this->assertGreaterThan( 0, $data['post_id'], 'Work is not lost' );
		$this->assertFalse( $data['verification']['passed'] );
		$failed = array_column( array_filter( $data['verification']['checks'], static fn ( array $c ): bool => ! $c['passed'] ), 'fix', 'name' );
		$this->assertArrayHasKey( 'featured_image', $failed );
		$this->assertArrayHasKey( 'field_visits_location', $failed );
		$this->assertNotSame( '', $failed['featured_image'] );
	}

	public function test_word_and_image_limits(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$long = $this->call_tool( 'ccb-create-post', array( 'section' => 'visits', 'title' => 'Too long', 'content' => self::words( 151 ) ) );
		$this->assertSame( 'ccb_limit_exceeded', self::code( $long ) );

		$short = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Too short', 'content' => self::words( 20 ) ) ) );
		$this->assertNotEmpty( array_filter( $short['warnings'], static fn ( string $w ): bool => str_contains( $w, 'at least 150' ) ) );

		$one  = $this->image( 1600, 900 );
		$two  = $this->image( 1600, 900 );
		$html = '<p>Two pictures.</p><figure><img src="' . esc_url( (string) wp_get_attachment_url( $one ) ) . '" alt="a"></figure><figure><img src="' . esc_url( (string) wp_get_attachment_url( $two ) ) . '" alt="b"></figure>';
		$many = $this->call_tool( 'ccb-create-post', array( 'section' => 'visits', 'title' => 'Too many images', 'content' => $html ) );
		$this->assertSame( 'ccb_limit_exceeded', self::code( $many ) );
	}

	public function test_featured_image_rules(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$wide   = $this->image( 1600, 900 );
		$square = $this->image( 1200, 1200 );
		$small  = $this->image( 800, 450 );

		$ok = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'With image', 'content' => self::words( 200 ), 'featured_image_id' => $wide ) ) );
		$this->assertSame( $wide, (int) get_post_thumbnail_id( $ok['post_id'] ) );
		$checks = array_column( $ok['verification']['checks'], 'passed', 'name' );
		$this->assertTrue( $checks['featured_image'] );
		$this->assertTrue( $checks['featured_image_alt'] );

		$this->assertSame( 'ccb_field_invalid', self::code( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Square', 'featured_image_id' => $square ) ) ) );
		$this->assertSame( 'ccb_field_invalid', self::code( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Small', 'featured_image_id' => $small ) ) ) );
		$this->assertSame( 'ccb_field_invalid', self::code( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Not an image', 'featured_image_id' => $ok['post_id'] ) ) ) );
	}

	public function test_duplicate_titles_need_confirmation(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$first = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Same title', 'content' => self::words( 200 ) ) ) );

		$second = $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Same title', 'content' => self::words( 200 ) ) );
		$this->assertSame( 'ccb_possible_duplicate', self::code( $second ) );
		$this->assertStringContainsString( (string) $first['post_id'], self::text( $second ) );

		$confirmed = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Same title', 'content' => self::words( 200 ), 'confirm_duplicate' => true ) ) );
		$this->assertNotSame( $first['post_id'], $confirmed['post_id'] );

		$other_section = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'visits', 'title' => 'Same title' ) ) );
		$this->assertGreaterThan( 0, $other_section['post_id'], 'Duplicates are per section' );
	}

	public function test_idempotency_key_returns_the_original_post(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$input = array( 'section' => 'news', 'title' => 'Retried', 'content' => self::words( 200 ), 'idempotency_key' => 'b1946ac9-2492-4b5c-a1b7-b0f1d2a3c4d5' );

		$first  = $this->data( $this->call_tool( 'ccb-create-post', $input ) );
		$second = $this->data( $this->call_tool( 'ccb-create-post', $input ) );

		$this->assertSame( $first['post_id'], $second['post_id'] );
		$this->assertCount( 1, get_posts( array( 'title' => 'Retried', 'post_status' => 'any', 'fields' => 'ids' ) ) );
	}

	public function test_seo_fields_go_to_seopress_meta(): void {
		$repository                            = new ConfigRepository();
		$config                                = $repository->get();
		$config['writing']['seo']['plugin']    = 'seopress';
		$config['writing']['seo']['title_max'] = 20;
		$this->assertIsArray( $repository->save( $config, 1, 'ui' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data(
			$this->call_tool(
				'ccb-create-post',
				array(
					'section' => 'news',
					'title'   => 'SEO post',
					'content' => self::words( 200 ),
					'seo'     => array(
						'title'       => 'Station Road crossing reopens today',
						'description' => 'The crossing has reopened.',
					),
				)
			)
		);

		$this->assertSame( 'Station Road', get_post_meta( $data['post_id'], '_seopress_titles_title', true ) );
		$this->assertSame( 'The crossing has reopened.', get_post_meta( $data['post_id'], '_seopress_titles_desc', true ) );
		$this->assertNotEmpty( array_filter( $data['warnings'], static fn ( string $w ): bool => str_contains( $w, 'shortened' ) ) );
	}

	public function test_seo_fields_are_skipped_with_a_warning_when_no_seo_plugin(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'No SEO plugin', 'content' => self::words( 200 ), 'seo' => array( 'title' => 'x' ) ) ) );

		$this->assertNotEmpty( array_filter( $data['warnings'], static fn ( string $w ): bool => str_contains( $w, 'No SEO plugin' ) ) );
	}

	public function test_update_edits_a_draft_and_replaces_terms(): void {
		$user   = $this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$events = self::factory()->category->create( array( 'name' => 'Events', 'slug' => 'events' ) );
		$post   = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $user, 'post_title' => 'Old title' ) );
		wp_set_object_terms( $post, array( get_term_by( 'slug', 'news', 'category' )->term_id ), 'category' );

		$data = $this->data(
			$this->call_tool(
				'ccb-update-post',
				array(
					'post_id' => $post,
					'title'   => 'New title',
					'terms'   => array( 'category' => array( $events ) ),
					'status'  => 'pending',
				)
			)
		);

		$this->assertSame( 'New title', get_the_title( $post ) );
		$this->assertSame( 'draft', get_post_status( $post ), 'Contributors can\'t change status' );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( array( $events ), wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) ), 'Replaced, not appended' );
	}

	public function test_publisher_can_move_a_draft_to_pending_but_not_live(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->data( $this->call_tool( 'ccb-update-post', array( 'post_id' => $post, 'status' => 'pending' ) ) );
		$this->assertSame( 'pending', get_post_status( $post ) );

		$this->assertSame( 'ccb_wrong_status', self::code( $this->call_tool( 'ccb-update-post', array( 'post_id' => $post, 'status' => 'publish' ) ) ) );
	}

	public function test_update_refuses_live_posts_and_stale_reads(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$live  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$live_result = $this->call_tool( 'ccb-update-post', array( 'post_id' => $live, 'title' => 'Changed' ) );
		$this->assertSame( 'ccb_wrong_status', self::code( $live_result ) );
		$this->assertStringContainsString( 'prepare-update-live', self::text( $live_result ) );

		$stale = $this->call_tool( 'ccb-update-post', array( 'post_id' => $draft, 'title' => 'Changed', 'expected_modified_gmt' => '2001-01-01T00:00:00Z' ) );
		$this->assertSame( 'ccb_post_changed', self::code( $stale ) );

		$fresh = get_post( $draft );
		$this->data( $this->call_tool( 'ccb-update-post', array( 'post_id' => $draft, 'title' => 'Changed', 'expected_modified_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $fresh->post_modified_gmt . ' UTC' ) ) ) ) );
		$this->assertSame( 'Changed', get_the_title( $draft ) );
	}

	public function test_elementor_built_posts_keep_their_content(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$post = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => '<p>Elementor body</p>' ) );
		update_post_meta( $post, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post, '_elementor_data', '[{"id":"x"}]' );

		$this->assertSame( 'ccb_elementor_post', self::code( $this->call_tool( 'ccb-update-post', array( 'post_id' => $post, 'content' => '<p>New</p>' ) ) ) );

		$this->data( $this->call_tool( 'ccb-update-post', array( 'post_id' => $post, 'title' => 'New title only' ) ) );
		$this->assertSame( 'New title only', get_the_title( $post ) );
		$this->assertSame( '<p>Elementor body</p>', get_post_field( 'post_content', $post ) );
		$this->assertSame( '[{"id":"x"}]', get_post_meta( $post, '_elementor_data', true ), 'Elementor data untouched' );
	}

	public function test_no_elementor_meta_is_ever_written(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$created = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Plain post', 'content' => self::words( 200 ), 'featured_image_id' => $this->image( 1600, 900 ) ) ) );
		$this->data( $this->call_tool( 'ccb-update-post', array( 'post_id' => $created['post_id'], 'content' => self::words( 210 ), 'excerpt' => 'Short.' ) ) );

		foreach ( self::meta_keys( $created['post_id'] ) as $key ) {
			$this->assertStringStartsNotWith( '_elementor', $key );
		}
	}

	public function test_activity_rows_name_the_post_and_never_store_content(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Logged post', 'content' => '<p>SECRET BODY TEXT</p>' ) ) );

		$row = $this->activity( 1 )[0];
		$this->assertSame( 'create', $row['action'] );
		$this->assertSame( 'success', $row['result'] );
		$this->assertSame( (string) $data['post_id'], (string) $row['post_id'] );
		$this->assertSame( 'news', $row['section'] );
		$this->assertSame( 'Logged post', $row['post_title_snapshot'] );
		$this->assertSame( 'pending', $row['status_to'] );
		$this->assertStringNotContainsString( 'SECRET BODY TEXT', (string) $row['details'] );
		$this->assertContains( 'content', json_decode( (string) $row['details'], true )['fields'] );
	}
}
