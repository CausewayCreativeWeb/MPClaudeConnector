<?php
/**
 * Security review checklist items (docs/09) not already covered by the feature tests.
 * See docs/security-report.md for where every checklist item is tested.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Abilities\AbilityRegistrar;
use Causeway\ClaudeBridge\Abilities\AbstractAbility;
use Causeway\ClaudeBridge\Audit\DebugLog;
use Causeway\ClaudeBridge\Audit\RequestContext;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Notify\Mailer;
use Causeway\ClaudeBridge\Notify\Notifications;
use Causeway\ClaudeBridge\Roles\RoleManager;
use WP_Error;

/**
 * @coversNothing
 */
final class SecurityChecklistTest extends McpTestCase {

	/**
	 * Debug lines captured.
	 *
	 * @var list<string>
	 */
	private array $lines = array();

	public function set_up(): void {
		parent::set_up();
		$this->configure_mp_site();
		reset_phpmailer_instance();
		Mailer::reset();
		add_filter( 'ccb_debug_write', '__return_false' );
		add_action(
			'ccb_debug_line',
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);
	}

	/**
	 * Every registered ccb ability.
	 *
	 * @return list<\WP_Ability>
	 */
	private static function abilities(): array {
		return array_values( array_filter( wp_get_abilities(), static fn ( $a ): bool => str_starts_with( $a->get_name(), 'ccb/' ) ) );
	}

	public function test_every_ability_refuses_logged_out_and_roleless_users(): void {
		$abilities = self::abilities();
		$this->assertCount( count( AbilityRegistrar::abilities() ), $abilities );

		$_SERVER['HTTPS'] = 'on';
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'subscriber' ) ), self::factory()->user->create( array( 'role' => 'editor' ) ) ) as $user ) {
			wp_set_current_user( $user );
			AbstractAbility::reset_request_state();
			foreach ( $abilities as $ability ) {
				$result = $ability->check_permissions( array() );
				$this->assertInstanceOf( WP_Error::class, $result, $ability->get_name() . ' for user ' . $user );
				$this->assertStringContainsString( 0 === $user ? 'ccb_not_authenticated' : 'ccb_forbidden', $result->get_error_message(), $ability->get_name() );
			}
		}
	}

	public function test_contributors_are_refused_every_publish_only_ability(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$publish_only = array( 'ccb/prepare-publish', 'ccb/publish-post', 'ccb/prepare-schedule', 'ccb/schedule-post' );
		foreach ( self::abilities() as $ability ) {
			$allowed = $ability->check_permissions( array() );
			if ( in_array( $ability->get_name(), $publish_only, true ) ) {
				$this->assertInstanceOf( WP_Error::class, $allowed, $ability->get_name() );
			} else {
				$this->assertTrue( $allowed, $ability->get_name() );
			}
		}
	}

	public function test_plain_http_is_refused(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		unset( $_SERVER['HTTPS'] );
		$result = wp_get_ability( 'ccb/check-connection' )->check_permissions( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'ccb_https_required', $result->get_error_message() );
	}

	public function test_xss_in_title_excerpt_fields_and_alt_text_is_neutralised(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$attack = '<script>alert(1)</script><img src=x onerror=alert(2)>';
		$data   = $this->data(
			$this->call_tool(
				'ccb-create-post',
				array(
					'section' => 'news',
					'title'   => 'Hello ' . $attack,
					'excerpt' => 'Summary ' . $attack,
					'content' => '<p>Body <a href="javascript:alert(3)">link</a> <span onclick="x()">text</span></p>' . $attack . '<p>' . str_repeat( 'word ', 200 ) . '</p>',
				)
			)
		);
		$post   = get_post( $data['post_id'] );
		foreach ( array( $post->post_title, $post->post_excerpt, $post->post_content ) as $stored ) {
			$this->assertDoesNotMatchRegularExpression( '/<script|onerror|onclick|javascript:/i', $stored );
		}

		if ( function_exists( 'update_field' ) ) {
			$type     = self::factory()->term->create( array( 'taxonomy' => 'content-type', 'name' => 'Briefing' ) );
			$category = self::factory()->term->create( array( 'taxonomy' => 'resource_category', 'name' => 'Reports' ) );
			$insight  = $this->data(
				$this->call_tool(
					'ccb-create-post',
					array(
						'section' => 'insights',
						'title'   => 'Report',
						'terms'   => array(
							'resource_category' => array( $category ),
							'content-type'      => array( $type ),
						),
						'fields'  => array( 'authors' => 'Jane ' . $attack ),
					)
				)
			);
			$this->assertDoesNotMatchRegularExpression( '/<script|onerror/i', (string) get_post_meta( $insight['post_id'], 'authors', true ) );
		}

		$image = get_temp_dir() . 'ccb-xss-' . wp_generate_password( 6, false ) . '.png';
		$gd    = imagecreatetruecolor( 1200, 800 );
		imagepng( $gd, $image );
		$upload = $this->data(
			$this->call_tool(
				'ccb-upload-image',
				array(
					'filename'    => 'photo.png',
					'data_base64' => base64_encode( (string) file_get_contents( $image ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					'alt'         => 'Alt ' . $attack,
					'caption'     => 'Caption ' . $attack,
				)
			)
		);
		wp_delete_file( $image );
		$this->assertDoesNotMatchRegularExpression( '/<script|onerror|</i', (string) get_post_meta( $upload['attachment_id'], '_wp_attachment_image_alt', true ) );
		$this->assertDoesNotMatchRegularExpression( '/<script|onerror/i', (string) get_post( $upload['attachment_id'] )->post_excerpt );
	}

	public function test_config_strings_are_escaped_in_emails(): void {
		$repo                      = new ConfigRepository();
		$config                    = $repo->get();
		$config['support']['name'] = '<img src=x onerror=alert(1)>Support';
		$this->assertIsArray( $repo->save( $config, 1, 'ui', null, null, true ) );

		Mailer::send( array( 'someone@example.org' ), 'Subject', array( 'heading' => '<b>Heading</b>', 'intro' => 'Intro', 'footer' => Notifications::footer() ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		// The HTML part (the plain-text alternative carries the text as typed, which is safe as text/plain).
		$html = substr( $mail->body, (int) strpos( $mail->body, 'text/html' ) );
		$this->assertStringContainsString( '&lt;img src=x', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b>Heading', $html );
	}

	public function test_activity_log_holds_no_tokens_passwords_or_content(): void {
		global $wpdb;
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$secret_text = 'Unique body sentence ' . wp_generate_password( 12, false );
		$created     = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Log check', 'content' => '<p>' . $secret_text . ' ' . str_repeat( 'word ', 200 ) . '</p>' ) ) );
		$link        = $this->data( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $created['post_id'], 'purpose' => \Causeway\ClaudeBridge\Media\ImagePipeline::PURPOSE_CONTENT ) ) );
		$published   = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Old' ) );
		$token       = $this->data( $this->call_tool( 'ccb-prepare-trash', array( 'post_id' => $published ) ) )['token'];
		$this->data( $this->call_tool( 'ccb-trash-post', array( 'post_id' => $published, 'token' => $token ) ) );
		$app_password = \WP_Application_Passwords::create_new_application_password( get_current_user_id(), array( 'name' => 'Laptop' ) )[0];

		$table = Schema::table( Schema::ACTIVITY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dump = (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) );
		$this->assertStringNotContainsString( $token, $dump );
		$this->assertStringNotContainsString( $secret_text, $dump );
		preg_match( '#(?:/ccb-upload/|[?&]ccb_upload=)([A-Za-z0-9_-]+)#', (string) $link['url'], $m );
		$this->assertNotEmpty( $m[1] ?? '' );
		$this->assertStringNotContainsString( $m[1], $dump );
		$this->assertStringNotContainsString( $app_password, $dump );

		// Tokens and upload links are stored as hashes only.
		foreach ( array( Schema::TOKENS, Schema::UPLOAD_LINKS ) as $name ) {
			$t = Schema::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM `{$t}`", ARRAY_A ) );
			$this->assertStringNotContainsString( $token, $rows );
			$this->assertStringNotContainsString( $m[1], $rows );
		}
	}

	public function test_debug_mode_logs_calls_without_secrets_and_switches_itself_off(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$body = 'Private paragraph ' . wp_generate_password( 10, false );

		$this->call_tool( 'ccb-check-connection' );
		$this->assertSame( array(), $this->lines, 'Off by default' );

		DebugLog::set( true );
		$created   = $this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Debugged', 'content' => '<p>' . $body . '</p>' ) ) );
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$token     = $this->data( $this->call_tool( 'ccb-prepare-trash', array( 'post_id' => $published ) ) )['token'];

		$log = implode( "\n", $this->lines );
		$this->assertStringContainsString( 'ccb/create-post input', $log );
		$this->assertStringContainsString( 'ccb/create-post result', $log );
		$this->assertStringContainsString( '"post_id":' . $created['post_id'], $log );
		$this->assertStringContainsString( RequestContext::request_ref(), $log );
		$this->assertStringNotContainsString( $body, $log );
		$this->assertStringNotContainsString( $token, $log );
		$this->assertStringContainsString( '[redacted', $log );

		update_option( DebugLog::OPTION, time() - 1, false );
		$this->assertFalse( DebugLog::enabled() );
		$this->assertFalse( get_option( DebugLog::OPTION ), 'Expired debug mode is cleared' );
	}

	public function test_no_ability_or_route_reaches_users_settings_plugins_or_permanent_deletion(): void {
		$names = array_map( static fn ( $a ): string => $a->get_name(), self::abilities() );
		foreach ( $names as $name ) {
			$this->assertDoesNotMatchRegularExpression( '/user|setting|plugin|theme|page|menu|delete|elementor|term-create|create-term/', $name );
		}
		$this->assertContains( 'ccb/trash-post', $names, 'Removal is the Bin only' );

		foreach ( rest_get_server()->get_routes( 'ccb/v1' ) as $path => $handlers ) {
			if ( '/ccb/v1' === $path ) {
				continue; // The namespace index.
			}
			foreach ( $handlers as $handler ) {
				$this->assertIsCallable( $handler['permission_callback'], $path );
				if ( array_intersect_key( (array) $handler['methods'], array_flip( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) ) ) ) {
					wp_set_current_user( self::factory()->user->create( array( 'role' => RoleManager::ROLE_PUBLISHER ) ) );
					$this->assertFalse( call_user_func( $handler['permission_callback'] ), 'Writes need manage_options: ' . $path );
				}
			}
		}
	}
}
