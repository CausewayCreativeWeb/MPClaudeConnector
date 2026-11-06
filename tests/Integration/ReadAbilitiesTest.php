<?php
/**
 * Read abilities through the MCP server (docs/03 "Read abilities", docs/02 enforcement).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Vendor\WP\MCP\Core\McpAdapter;
use RuntimeException;

/**
 * @covers \Causeway\ClaudeBridge\Abilities\AbstractAbility
 * @covers \Causeway\ClaudeBridge\Abilities\CheckConnection
 * @covers \Causeway\ClaudeBridge\Abilities\GetPublishingGuide
 * @covers \Causeway\ClaudeBridge\Abilities\ListSections
 * @covers \Causeway\ClaudeBridge\Abilities\ListTerms
 * @covers \Causeway\ClaudeBridge\Abilities\ListPosts
 * @covers \Causeway\ClaudeBridge\Abilities\GetPost
 * @covers \Causeway\ClaudeBridge\Checks\PreFlight
 * @covers \Causeway\ClaudeBridge\Audit\ActivityLog
 */
final class ReadAbilitiesTest extends McpTestCase {

	public function test_check_connection_reports_no_sections_on_a_fresh_install(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data( $this->call_tool( 'ccb-check-connection' ) );

		$this->assertFalse( $data['ok'] );
		$this->assertContains( 'no_sections', array_column( $data['issues'], 'code' ) );
	}

	public function test_check_connection_for_a_contributor_on_the_mp_site(): void {
		$this->configure_mp_site();
		$user = $this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data( $this->call_tool( 'ccb-check-connection', array( 'skill_version' => CCB_VERSION ) ) );

		$this->assertTrue( $data['ok'], (string) wp_json_encode( $data['issues'] ) );
		$this->assertSame( $user, $data['user']['id'] );
		$this->assertSame( 'ccb_contributor', $data['user']['role'] );
		$this->assertFalse( $data['user']['can_publish'] );
		$this->assertSame( array( 'news', 'insights', 'visits' ), array_column( $data['sections'], 'key' ) );
		$this->assertSame( CCB_VERSION, $data['versions']['plugin'] );
		$this->assertSame( McpAdapter::VERSION, $data['versions']['adapter'] );
		$this->assertSame( ( new ConfigRepository() )->version(), $data['versions']['config'] );
		$this->assertNotContains( 'skill_outdated', array_column( $data['issues'], 'code' ) );
		$this->assertArrayHasKey( 'email', $data['support'] );
	}

	public function test_check_connection_for_publishers_and_admins(): void {
		$this->configure_mp_site();

		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$publisher = $this->data( $this->call_tool( 'ccb-check-connection' ) );
		$this->assertSame( 'ccb_publisher', $publisher['user']['role'] );
		$this->assertTrue( $publisher['user']['can_publish'] );

		$this->login_as( 'administrator' );
		$admin = $this->data( $this->call_tool( 'ccb-check-connection' ) );
		$this->assertSame( 'administrator', $admin['user']['role'] );
		$this->assertTrue( $admin['user']['can_publish'] );
	}

	public function test_check_connection_warns_about_an_old_skill(): void {
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data( $this->call_tool( 'ccb-check-connection', array( 'skill_version' => '0.0.1' ) ) );

		$this->assertTrue( $data['ok'], 'A warning does not stop work' );
		$issue = array_column( $data['issues'], null, 'code' )['skill_outdated'];
		$this->assertSame( 'warning', $issue['severity'] );
		$this->assertNotSame( '', $issue['fix'] );
	}

	public function test_plain_http_is_refused_with_a_clear_code(): void {
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->initialize();
		unset( $_SERVER['HTTPS'] );

		$result = $this->call_tool( 'ccb-list-sections' );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'code: ccb_https_required', self::text( $result ) );
		$row = $this->activity( 1 )[0];
		$this->assertSame( 'rejected', $row['result'] );
		$this->assertSame( 'ccb_https_required', $row['error_code'] );
	}

	public function test_invalid_input_is_reported_with_a_ccb_code_and_logged(): void {
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$result = $this->call_tool( 'ccb-list-posts', array( 'per_page' => 50 ) );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'code: ccb_invalid_input', self::text( $result ) );
		$row = $this->activity( 1 )[0];
		$this->assertSame( 'ccb/list-posts', $row['ability'] );
		$this->assertSame( 'list', $row['action'] );
		$this->assertSame( 'ccb_invalid_input', $row['error_code'] );
	}

	public function test_requests_over_the_per_minute_limit_are_refused(): void {
		$this->configure_mp_site();
		$repository                              = new ConfigRepository();
		$config                                  = $repository->get();
		$config['access']['requests_per_minute'] = 5;
		$this->assertIsArray( $repository->save( $config, 1, 'ui' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $this->call_tool( 'ccb-list-sections' )['isError'] ?? false );
		}
		$result = $this->call_tool( 'ccb-list-sections' );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'code: ccb_rate_limited', self::text( $result ) );
		$this->assertMatchesRegularExpression( '/retry_after: \d+/', self::text( $result ) );
	}

	public function test_unexpected_exceptions_become_ccb_internal_with_a_matching_reference(): void {
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		add_filter(
			'get_terms_args',
			static function (): never {
				throw new RuntimeException( 'Simulated failure' );
			}
		);

		$result = $this->call_tool(
			'ccb-list-terms',
			array(
				'section'  => 'news',
				'taxonomy' => 'category',
			)
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'code: ccb_internal', self::text( $result ) );
		$this->assertMatchesRegularExpression( '/ref: ([0-9a-f]{12})/', self::text( $result ) );
		preg_match( '/ref: ([0-9a-f]{12})/', self::text( $result ), $match );
		$row = $this->activity( 1 )[0];
		$this->assertSame( 'error', $row['result'] );
		$this->assertSame( $match[1], $row['request_ref'] );
		$this->assertStringNotContainsString( 'Simulated failure', self::text( $result ), 'Internal details are not shown to the user' );
	}

	public function test_every_call_writes_an_activity_row_with_who_and_where(): void {
		$this->configure_mp_site();
		$user = $this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$this->data( $this->call_tool( 'ccb-get-publishing-guide', array( 'section' => 'visits' ) ) );

		$row = $this->activity( 1 )[0];
		$this->assertSame( 'guide', $row['action'] );
		$this->assertSame( 'ccb/get-publishing-guide', $row['ability'] );
		$this->assertSame( 'success', $row['result'] );
		$this->assertSame( 'visits', $row['section'] );
		$this->assertSame( (string) $user, (string) $row['user_id'] );
		$this->assertSame( '203.0.113.7', inet_ntop( (string) $row['ip'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', (string) $row['request_ref'] );
		$this->assertSame( (string) ( new ConfigRepository() )->version(), (string) $row['config_version'] );
	}

	public function test_get_publishing_guide_for_all_and_one_section(): void {
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$all = $this->data( $this->call_tool( 'ccb-get-publishing-guide' ) );
		$this->assertSame( array( 'news', 'insights', 'visits' ), array_column( $all['sections'], 'key' ) );
		$this->assertSame( 'en-GB', $all['general']['language'] );

		$one = $this->data( $this->call_tool( 'ccb-get-publishing-guide', array( 'section' => 'insights' ) ) );
		$this->assertCount( 1, $one['sections'] );
		$this->assertStringContainsString( '## Insights', $one['sections'][0]['guide'] );

		$unknown = $this->call_tool( 'ccb-get-publishing-guide', array( 'section' => 'events' ) );
		$this->assertTrue( $unknown['isError'] );
		$this->assertStringContainsString( 'code: ccb_section_unknown', self::text( $unknown ) );
	}

	public function test_list_sections_counts_posts_by_status(): void {
		$this->configure_mp_site();
		self::factory()->post->create_many( 2, array( 'post_status' => 'pending' ) );
		self::factory()->post->create( array( 'post_type' => 'visits', 'post_status' => 'publish' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$sections = array_column( $this->data( $this->call_tool( 'ccb-list-sections' ) )['sections'], null, 'key' );

		$this->assertSame( 2, $sections['news']['counts']['pending'] );
		$this->assertSame( 1, $sections['visits']['counts']['publish'] );
		$this->assertSame( 'post', $sections['news']['post_type'] );
	}

	public function test_list_terms_only_for_the_sections_own_taxonomies(): void {
		$this->configure_mp_site();
		self::factory()->term->create( array( 'taxonomy' => 'resource_category', 'name' => 'Reports' ) );
		self::factory()->term->create( array( 'taxonomy' => 'resource_category', 'name' => 'Briefings' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$terms = $this->data( $this->call_tool( 'ccb-list-terms', array( 'section' => 'insights', 'taxonomy' => 'resource_category' ) ) )['terms'];
		$this->assertEqualsCanonicalizing( array( 'Briefings', 'Reports' ), array_column( $terms, 'name' ) );

		$search = $this->data( $this->call_tool( 'ccb-list-terms', array( 'section' => 'insights', 'taxonomy' => 'resource_category', 'search' => 'rep' ) ) )['terms'];
		$this->assertSame( array( 'Reports' ), array_column( $search, 'name' ) );

		$foreign = $this->call_tool( 'ccb-list-terms', array( 'section' => 'news', 'taxonomy' => 'resource_category' ) );
		$this->assertTrue( $foreign['isError'] );
		$this->assertStringContainsString( 'field: taxonomy', self::text( $foreign ) );
	}

	public function test_list_posts_only_returns_section_post_types(): void {
		$this->configure_mp_site();
		$news  = self::factory()->post->create( array( 'post_title' => 'Crossing reopens', 'post_status' => 'pending' ) );
		$visit = self::factory()->post->create( array( 'post_type' => 'visits', 'post_title' => 'Visit to Riverside Primary', 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'About' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$all = $this->data( $this->call_tool( 'ccb-list-posts' ) );
		$ids = array_column( $all['items'], 'id' );
		$this->assertContains( $news, $ids );
		$this->assertContains( $visit, $ids );
		$this->assertNotContains( 'page', array_column( $all['items'], 'section' ) );

		$pending = $this->data( $this->call_tool( 'ccb-list-posts', array( 'section' => 'news', 'status' => array( 'pending' ), 'search' => 'crossing' ) ) );
		$this->assertSame( 1, $pending['total'] );
		$item = $pending['items'][0];
		$this->assertSame( $news, $item['id'] );
		$this->assertSame( 'news', $item['section'] );
		$this->assertSame( 'pending', $item['status'] );
		$this->assertNotNull( $item['preview_url'] );
		$this->assertNull( $item['live_url'] );
		$this->assertMatchesRegularExpression( '/Z$/', (string) $item['date_gmt'] );
	}

	public function test_list_posts_mine_and_paging(): void {
		$this->configure_mp_site();
		self::factory()->post->create_many( 3, array( 'post_status' => 'draft' ) );
		$me = $this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $me ) );

		$mine = $this->data( $this->call_tool( 'ccb-list-posts', array( 'mine' => true ) ) );
		$this->assertSame( 1, $mine['total'] );

		$page = $this->data( $this->call_tool( 'ccb-list-posts', array( 'section' => 'news', 'per_page' => 2, 'page' => 2 ) ) );
		$this->assertSame( 4, $page['total'] );
		$this->assertCount( 2, $page['items'] );
	}

	public function test_get_post_returns_everything_needed_to_edit(): void {
		$this->configure_mp_site();
		$news_term = get_term_by( 'slug', 'news', 'category' );
		$post      = self::factory()->post->create(
			array(
				'post_title'   => 'Crossing reopens',
				'post_content' => '<p>The crossing on Station Road has reopened.</p>',
				'post_excerpt' => 'Station Road crossing reopens.',
				'post_status'  => 'pending',
			)
		);
		wp_set_object_terms( $post, array( $news_term->term_id ), 'category' );
		$image = self::factory()->attachment->create_object( 'crossing.jpg', $post, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $image, '_wp_attachment_image_alt', 'The reopened crossing' );
		set_post_thumbnail( $post, $image );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$data = $this->data( $this->call_tool( 'ccb-get-post', array( 'post_id' => $post ) ) );

		$this->assertSame( $post, $data['id'] );
		$this->assertSame( 'news', $data['section'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( 'Crossing reopens', $data['title'] );
		$this->assertSame( '<p>The crossing on Station Road has reopened.</p>', $data['content'] );
		$this->assertSame( 'Station Road crossing reopens.', $data['excerpt'] );
		$this->assertSame( array( array( 'id' => $news_term->term_id, 'name' => 'News' ) ), $data['terms']['category'] );
		$this->assertSame( $image, $data['featured_image']['id'] );
		$this->assertSame( 'The reopened crossing', $data['featured_image']['alt'] );
		$this->assertContains( $image, array_column( $data['attached_images'], 'id' ) );
		$this->assertFalse( $data['elementor_built'] );
		$this->assertNotNull( $data['date_display'] );
		$this->assertNull( $data['seo'], 'No SEO plugin in the test site' );

		$row = $this->activity( 1 )[0];
		$this->assertSame( (string) $post, (string) $row['post_id'] );
		$this->assertSame( 'Crossing reopens', $row['post_title_snapshot'] );
	}

	public function test_get_post_returns_fields_for_insights_and_visits(): void {
		$this->configure_mp_site();
		$resource = self::factory()->post->create( array( 'post_type' => 'resource', 'post_status' => 'draft' ) );
		$visit    = self::factory()->post->create( array( 'post_type' => 'visits', 'post_status' => 'draft' ) );
		update_post_meta( $visit, 'visits_location', '{"lat":53.39,"lng":-2.597}' );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'field_68bef927b733c', "Jane Smith\nAli Khan", $resource );
		}
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$visit_data = $this->data( $this->call_tool( 'ccb-get-post', array( 'post_id' => $visit ) ) );
		$this->assertSame( array( 'lat' => 53.39, 'lng' => -2.597 ), $visit_data['fields']['visits_location'] );

		if ( function_exists( 'update_field' ) ) {
			$resource_data = $this->data( $this->call_tool( 'ccb-get-post', array( 'post_id' => $resource ) ) );
			$this->assertSame( "Jane Smith\nAli Khan", $resource_data['fields']['authors'] );
			$this->assertArrayHasKey( 'online_reader_url', $resource_data['fields'] );
		}
	}

	public function test_get_post_refuses_posts_outside_sections_and_missing_posts(): void {
		$this->configure_mp_site();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$outside = $this->call_tool( 'ccb-get-post', array( 'post_id' => $page ) );
		$this->assertTrue( $outside['isError'] );
		$this->assertStringContainsString( 'code: ccb_post_type_not_allowed', self::text( $outside ) );

		$missing = $this->call_tool( 'ccb-get-post', array( 'post_id' => 999999 ) );
		$this->assertTrue( $missing['isError'] );
		$this->assertStringContainsString( 'code: ccb_post_not_found', self::text( $missing ) );
	}

	public function test_get_post_flags_elementor_built_posts(): void {
		$this->configure_mp_site();
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_post_meta( $post, '_elementor_edit_mode', 'builder' );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$this->assertTrue( $this->data( $this->call_tool( 'ccb-get-post', array( 'post_id' => $post ) ) )['elementor_built'] );
	}
}
