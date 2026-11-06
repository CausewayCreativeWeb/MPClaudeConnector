<?php
/**
 * Settings screen REST routes (docs/08 "REST endpoints", "Save flow").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Admin\ActivityQuery;
use Causeway\ClaudeBridge\Admin\AdminPage;
use Causeway\ClaudeBridge\Admin\Overview;
use Causeway\ClaudeBridge\Admin\RestController;
use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Uninstaller;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \Causeway\ClaudeBridge\Admin\RestController
 * @covers \Causeway\ClaudeBridge\Admin\ActivityQuery
 * @covers \Causeway\ClaudeBridge\Admin\UserAccess
 * @covers \Causeway\ClaudeBridge\Admin\Overview
 * @covers \Causeway\ClaudeBridge\Admin\AdminPage
 * @covers \Causeway\ClaudeBridge\Admin\ProfileFields
 */
final class AdminRestTest extends McpTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->configure_mp_site();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Ada Admin' ) );
		wp_set_current_user( $this->admin );
		reset_phpmailer_instance();
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route under ccb/v1.
	 * @param array<string, mixed> $body   JSON body or query.
	 */
	private function rest( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestController::NAMESPACE . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_every_route_has_a_permission_callback_and_refuses_contributors(): void {
		$routes = rest_get_server()->get_routes( RestController::NAMESPACE );
		$this->assertGreaterThanOrEqual( 19, count( $routes ) - 1 );
		foreach ( $routes as $path => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( '/' . RestController::NAMESPACE === $path ) {
					continue;
				}
				$this->assertArrayHasKey( 'permission_callback', $handler, $path );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $path );
			}
		}

		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		foreach ( array( array( 'GET', '/config' ), array( 'GET', '/status' ), array( 'GET', '/activity' ), array( 'GET', '/users' ), array( 'POST', '/notifications/test' ) ) as $call ) {
			$this->assertSame( 403, $this->rest( $call[0], $call[1] )->get_status(), $call[1] );
		}
	}

	public function test_publishers_see_status_and_activity_but_not_settings(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$this->assertSame( 200, $this->rest( 'GET', '/status' )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', '/activity' )->get_status() );
		foreach ( array( array( 'GET', '/config' ), array( 'GET', '/site-structure' ), array( 'POST', '/status/run' ), array( 'GET', '/users' ), array( 'GET', '/presets' ) ) as $call ) {
			$this->assertSame( 403, $this->rest( $call[0], $call[1] )->get_status(), $call[1] );
		}
		$this->assertSame( array( 'overview', 'activity' ), array_keys( AdminPage::tabs() ) );
	}

	public function test_save_validates_bumps_the_version_and_logs(): void {
		$current                                 = $this->rest( 'GET', '/config' )->get_data();
		$config                                  = $current['config'];
		$config['access']['requests_per_minute'] = 40;

		$response = $this->rest( 'PUT', '/config', array( 'config' => $config, 'base_version' => $current['version'], 'note' => 'More requests' ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $current['version'] + 1, $response->get_data()['version'] );
		$this->assertSame( 'Ada Admin', $response->get_data()['saved_by_name'] );
		$this->assertSame( 40, ( new ConfigRepository() )->get()['access']['requests_per_minute'] );

		$row = $this->activity( 1 )[0];
		$this->assertSame( 'config_save', $row['action'] );
		$this->assertSame( 'success', $row['result'] );
		$this->assertStringContainsString( 'More requests', $row['message'] );

		$versions = $this->rest( 'GET', '/config/versions' )->get_data();
		$this->assertSame( 'More requests', $versions[0]['note'] );
		$this->assertSame( 'Ada Admin', $versions[0]['user'] );
	}

	public function test_invalid_config_is_refused_with_field_paths(): void {
		$current                         = $this->rest( 'GET', '/config' )->get_data();
		$config                          = $current['config'];
		$config['media']['webp_quality'] = 20;

		$check = $this->rest( 'POST', '/config/validate', array( 'config' => $config ) )->get_data();
		$this->assertFalse( $check['valid'] );
		$this->assertContains( '/media/webp_quality', array_column( $check['errors'], 'path' ) );

		$response = $this->rest( 'PUT', '/config', array( 'config' => $config, 'base_version' => $current['version'] ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'ccb_config_invalid', $response->get_data()['code'] );
		$this->assertContains( '/media/webp_quality', array_column( $response->get_data()['data']['errors'], 'path' ) );
		$this->assertSame( $current['version'], ( new ConfigRepository() )->version() );
	}

	public function test_second_admin_gets_a_conflict_with_the_live_config(): void {
		$current                                = $this->rest( 'GET', '/config' )->get_data();
		$first                                  = $current['config'];
		$first['access']['requests_per_minute'] = 41;
		$this->assertSame( 200, $this->rest( 'PUT', '/config', array( 'config' => $first, 'base_version' => $current['version'] ) )->get_status() );

		$second                                   = $current['config'];
		$second['access']['live_changes_per_day'] = 5;
		$response                                 = $this->rest( 'PUT', '/config', array( 'config' => $second, 'base_version' => $current['version'] ) );

		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertSame( $current['version'] + 1, $data['live']['version'] );
		$this->assertContains( '/access/requests_per_minute', array_column( $data['diff'], 'path' ) );

		// Overwrite: resend against the live version.
		$this->assertSame( 200, $this->rest( 'PUT', '/config', array( 'config' => $second, 'base_version' => $data['live']['version'] ) )->get_status() );
	}

	public function test_warnings_need_confirming_and_missing_terms_block_the_save(): void {
		register_post_type( 'ccb_own_caps', array( 'public' => true, 'capability_type' => 'thing', 'map_meta_cap' => true ) );
		\Causeway\ClaudeBridge\Config\SiteStructure::clear_cache();
		$current                      = $this->rest( 'GET', '/config' )->get_data();
		$config                       = $current['config'];
		$config['sections']['things'] = array_replace( \Causeway\ClaudeBridge\Config\DefaultConfig::section( 'ccb_own_caps', 'Things' ), array( 'enabled' => true ) );

		$response = $this->rest( 'PUT', '/config', array( 'config' => $config, 'base_version' => $current['version'] ) );
		$this->assertSame( 409, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'ccb_config_needs_confirm', $response->get_data()['code'] );
		$this->assertContains( 'post_type_capabilities', array_column( $response->get_data()['data']['warnings'], 'code' ) );

		$response = $this->rest( 'PUT', '/config', array( 'config' => $config, 'base_version' => $current['version'], 'accept_warnings' => true ) );
		$this->assertSame( 200, $response->get_status() );

		// A term that no longer exists can't be saved (docs/04: missing site objects on save are errors).
		$config                                      = $response->get_data()['config'];
		$config['sections']['news']['default_terms'] = array( 'category' => array( 987654 ) );
		$response                                    = $this->rest( 'PUT', '/config', array( 'config' => $config, 'base_version' => $config['version'], 'accept_warnings' => true ) );
		$this->assertSame( 400, $response->get_status() );
		unregister_post_type( 'ccb_own_caps' );
	}

	public function test_restore_saves_an_old_version_as_new_and_is_logged(): void {
		$current                       = $this->rest( 'GET', '/config' )->get_data();
		$config                        = $current['config'];
		$config['writing']['sign_off'] = 'Changed';
		$this->rest( 'PUT', '/config', array( 'config' => $config, 'base_version' => $current['version'] ) );

		$versions = $this->rest( 'GET', '/config/versions' )->get_data();
		$old      = $versions[1];
		$view     = $this->rest( 'GET', '/config/versions/' . $old['id'] )->get_data();
		$this->assertContains( '/writing/sign_off', array_column( $view['diff'], 'path' ) );

		$response = $this->rest( 'POST', '/config/versions/' . $old['id'] . '/restore', array( 'base_version' => $current['version'] + 1 ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $current['version'] + 2, $response->get_data()['version'] );
		$this->assertSame( $current['config']['writing']['sign_off'], ( new ConfigRepository() )->get()['writing']['sign_off'] );
		$this->assertSame( 'config_restore', $this->activity( 1 )[0]['action'] );
		$this->assertSame( 404, $this->rest( 'GET', '/config/versions/999999' )->get_status() );
	}

	public function test_import_validates_and_previews_without_saving(): void {
		$current                       = $this->rest( 'GET', '/config' )->get_data();
		$config                        = $current['config'];
		$config['writing']['sign_off'] = 'Imported';

		$preview = $this->rest( 'POST', '/config/import', array( 'json' => (string) wp_json_encode( $config ) ) )->get_data();
		$this->assertTrue( $preview['valid'] );
		$this->assertContains( '/writing/sign_off', array_column( $preview['diff'], 'path' ) );
		$this->assertSame( $current['version'], ( new ConfigRepository() )->version(), 'Preview only' );

		$this->assertSame( 400, $this->rest( 'POST', '/config/import', array( 'json' => 'not json' ) )->get_status() );
		$this->assertSame( 413, $this->rest( 'POST', '/config/import', array( 'json' => str_repeat( ' ', RestController::IMPORT_MAX_BYTES + 1 ) ) )->get_status() );

		$saved = $this->rest( 'PUT', '/config', array( 'config' => $preview['config'], 'base_version' => $current['version'], 'source' => 'import' ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'config_import', $this->activity( 1 )[0]['action'] );
	}

	public function test_presets_list_and_preview(): void {
		$presets = $this->rest( 'GET', '/presets' )->get_data();
		$this->assertContains( 'mp-site', array_column( $presets, 'name' ) );

		$preview = $this->rest( 'POST', '/presets/mp-site/preview' )->get_data();
		$this->assertArrayHasKey( 'config', $preview );
		$this->assertArrayHasKey( 'changes', $preview );
		$this->assertTrue( $preview['valid'], (string) wp_json_encode( $preview['errors'] ) );
		$this->assertSame( 404, $this->rest( 'POST', '/presets/nope/preview' )->get_status() );
	}

	public function test_preview_shows_what_claude_sees_for_an_unsaved_section(): void {
		$config                              = $this->rest( 'GET', '/config' )->get_data()['config'];
		$config['sections']['news']['notes'] = 'Always mention the constituency.';

		$preview = $this->rest( 'POST', '/preview/news', array( 'config' => $config ) )->get_data();
		$this->assertStringContainsString( 'Always mention the constituency.', $preview['markdown'] );

		$config['sections']['news']['post_type'] = 'not-a-type';
		$this->assertSame( '', $this->rest( 'POST', '/preview/news', array( 'config' => $config ) )->get_data()['markdown'] );
	}

	public function test_site_structure_and_status(): void {
		$structure = $this->rest( 'GET', '/site-structure', array( 'refresh' => true ) )->get_data();
		$this->assertContains( 'post', array_column( $structure['post_types'], 'name' ) );

		$status = $this->rest( 'GET', '/status' )->get_data();
		$this->assertContains( $status['status'], array( 'good', 'warning', 'problem' ) );
		$this->assertSame( array( 'endpoint', 'plugin', 'adapter', 'acf', 'config', 'health' ), array_column( $status['cards'], 'key' ) );
		$this->assertSame( '0.7.1', $status['cards'][2]['value'] );
		$this->assertSame( array( 'sections', 'access', 'notifications', 'setup-pack' ), array_column( $status['setup']['steps'], 'key' ) );

		$run = $this->rest( 'POST', '/status/run' )->get_data();
		$this->assertNotNull( $run['health'] );
	}

	public function test_activity_filters_pages_and_hides_reads_by_default(): void {
		ActivityLog::record( array( 'action' => 'check', 'result' => 'success' ) );
		ActivityLog::record( array( 'action' => 'create', 'result' => 'success', 'section' => 'news', 'post_title_snapshot' => 'Bridge reopens' ) );
		ActivityLog::record( array( 'action' => 'publish', 'result' => 'rejected', 'section' => 'news', 'post_title_snapshot' => 'Other' ) );

		$data = $this->rest( 'GET', '/activity' )->get_data();
		$this->assertNotContains( 'check', array_column( $data['rows'], 'action' ) );
		$this->assertSame( 'Ada Admin', $data['rows'][0]['user'] );

		$this->assertContains( 'check', array_column( $this->rest( 'GET', '/activity', array( 'include_reads' => true ) )->get_data()['rows'], 'action' ) );
		$this->assertSame( array( 'publish' ), array_column( $this->rest( 'GET', '/activity', array( 'result' => 'rejected' ) )->get_data()['rows'], 'action' ) );
		$this->assertSame( array( 'create' ), array_column( $this->rest( 'GET', '/activity', array( 'search' => 'bridge' ) )->get_data()['rows'], 'action' ) );
		$this->assertSame( 0, $this->rest( 'GET', '/activity', array( 'from' => '2000-01-01', 'to' => '2000-01-02' ) )->get_data()['total'] );
		$this->assertSame( 400, $this->rest( 'GET', '/activity', array( 'from' => 'yesterday' ) )->get_status() );

		for ( $i = 0; $i < 55; $i++ ) {
			ActivityLog::record( array( 'action' => 'update', 'result' => 'success' ) );
		}
		$page2 = $this->rest( 'GET', '/activity', array( 'page' => 2 ) )->get_data();
		$this->assertSame( 2, $page2['pages'] );
		$this->assertSame( $page2['total'] - 50, count( $page2['rows'] ) );
	}

	public function test_csv_export_neutralises_formulas(): void {
		ActivityLog::record( array( 'action' => 'create', 'result' => 'success', 'post_title_snapshot' => '=HYPERLINK("http://evil")' ) );
		ActivityLog::record( array( 'action' => 'create', 'result' => 'success', 'post_title_snapshot' => '@SUM(1)' ) );

		$csv = ActivityQuery::csv( array() );
		$this->assertStringContainsString( '"\'=HYPERLINK(""http://evil"")"', $csv );
		$this->assertStringContainsString( '"\'@SUM(1)"', $csv );
		$this->assertStringStartsWith( '"time","user"', $csv );

		foreach ( array( '+1', '-1', "\t1", "\r1" ) as $cell ) {
			$this->assertSame( '"\'' . $cell . '"', ActivityQuery::csv_line( array( $cell ) ) );
		}

		$response = $this->rest( 'GET', '/activity', array( 'format' => 'csv' ) );
		$this->assertStringStartsWith( 'text/csv', $response->get_headers()['Content-Type'] );
		$this->assertIsString( $response->get_data() );
	}

	public function test_switching_roles_and_revoking_app_passwords(): void {
		$sam = self::factory()->user->create( array( 'role' => RoleManager::ROLE_CONTRIBUTOR, 'display_name' => 'Sam' ) );

		$users = $this->rest( 'GET', '/users' )->get_data();
		$this->assertContains( $sam, array_column( $users['contributors'], 'id' ) );
		$this->assertContains( $this->admin, array_column( $users['publishers'], 'id' ) );
		$this->assertTrue( $users['publishers'][ array_search( $this->admin, array_column( $users['publishers'], 'id' ), true ) ]['locked'] );

		$response = $this->rest( 'POST', '/users/' . $sam . '/role', array( 'role' => 'publisher' ) );
		$this->assertSame( 'publisher', $response->get_data()['role'] );
		$this->assertTrue( user_can( $sam, RoleManager::CAP_PUBLISH ) );
		$row = $this->activity( 1 )[0];
		$this->assertSame( 'role_change', $row['action'] );
		$this->assertSame( RoleManager::ROLE_PUBLISHER, $row['status_to'] );

		$this->assertSame( 'contributor', $this->rest( 'POST', '/users/' . $sam . '/role', array( 'role' => 'contributor' ) )->get_data()['role'] );
		$this->assertFalse( user_can( $sam, RoleManager::CAP_PUBLISH ) );
		$this->assertSame( 400, $this->rest( 'POST', '/users/' . $this->admin . '/role', array( 'role' => 'contributor' ) )->get_status() );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertSame( 400, $this->rest( 'POST', '/users/' . $editor . '/role', array( 'role' => 'publisher' ) )->get_status() );

		list( , $item ) = WP_Application_Passwords::create_new_application_password( $sam, array( 'name' => 'Sam – laptop' ) );
		$list           = $this->rest( 'GET', '/users/' . $sam . '/app-passwords' )->get_data();
		$this->assertSame( 'Sam – laptop', $list[0]['name'] );
		$this->assertArrayNotHasKey( 'password', $list[0] );

		$this->assertSame( 200, $this->rest( 'DELETE', '/users/' . $sam . '/app-passwords/' . $item['uuid'] )->get_status() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $sam ) );
		$this->assertSame( 'app_password_revoke', $this->activity( 1 )[0]['action'] );
		$this->assertSame( 404, $this->rest( 'DELETE', '/users/' . $sam . '/app-passwords/' . $item['uuid'] )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/users/' . $editor . '/app-passwords' )->get_status() );
	}

	public function test_test_email_goes_to_the_current_admin_and_marks_setup(): void {
		$response = $this->rest( 'POST', '/notifications/test' );
		$this->assertSame( 200, $response->get_status() );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( get_userdata( $this->admin )->user_email, $mail->to[0][0] );
		$this->assertStringContainsString( 'Test email', $mail->subject );
		$steps = array_column( Overview::setup( ( new ConfigRepository() )->get() )['steps'], 'done', 'key' );
		$this->assertTrue( $steps['notifications'] );
	}

	public function test_settings_switch_debug_mode_for_a_day_and_uninstall_data(): void {
		$data = $this->rest( 'POST', '/settings', array( 'debug' => true, 'delete_data_on_uninstall' => true ) )->get_data();
		$this->assertTrue( $data['debug'] );
		$this->assertTrue( $data['delete_data_on_uninstall'] );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, (int) get_option( RestController::OPTION_DEBUG_UNTIL ), 5 );
		$this->assertSame( 1, (int) get_option( Uninstaller::OPTION_DELETE_DATA ) );

		$data = $this->rest( 'POST', '/settings', array( 'debug' => false ) )->get_data();
		$this->assertFalse( $data['debug'] );
		$this->assertTrue( $data['delete_data_on_uninstall'] );
	}

	public function test_profile_toggle_switches_role_after_wordpress_saves_the_profile(): void {
		$sam = self::factory()->user->create( array( 'role' => RoleManager::ROLE_CONTRIBUTOR ) );

		ob_start();
		do_action( 'edit_user_profile', get_userdata( $sam ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="ccb_publisher"', $html );
		$this->assertStringContainsString( 'name="ccb_publisher_was" value="0"', $html );

		$_POST['ccb_publisher']     = '1';
		$_POST['ccb_publisher_was'] = '0';
		do_action( 'edit_user_profile_update', $sam ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		wp_update_user( array( 'ID' => $sam, 'role' => RoleManager::ROLE_CONTRIBUTOR ) );
		unset( $_POST['ccb_publisher'], $_POST['ccb_publisher_was'] );

		$this->assertContains( RoleManager::ROLE_PUBLISHER, get_userdata( $sam )->roles );
		$this->assertNotContains( RoleManager::ROLE_CONTRIBUTOR, get_userdata( $sam )->roles );

		ob_start();
		do_action( 'edit_user_profile', get_userdata( $this->admin ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		$this->assertStringNotContainsString( 'ccb_publisher', (string) ob_get_clean(), 'No toggle for admins' );
	}

	public function test_menu_is_hidden_from_contributors(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->assertFalse( current_user_can( RoleManager::CAP_VIEW_ACTIVITY ) );
		wp_set_current_user( $this->admin );
		$this->assertTrue( current_user_can( RoleManager::CAP_VIEW_ACTIVITY ) );

		$boot = AdminPage::boot_data();
		$this->assertTrue( $boot['canManage'] );
		$this->assertArrayHasKey( 'config', $boot );
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$boot = AdminPage::boot_data();
		$this->assertFalse( $boot['canManage'] );
		$this->assertArrayNotHasKey( 'config', $boot, 'Publishers never get the config' );
	}
}
