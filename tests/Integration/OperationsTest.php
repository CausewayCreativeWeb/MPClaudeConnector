<?php
/**
 * Notifications, health check, error alerts, clean-up and live URL checks (docs/07).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Checks\HealthCheck;
use Causeway\ClaudeBridge\Checks\Pruner;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Notify\Mailer;
use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * @covers \Causeway\ClaudeBridge\Notify\Mailer
 * @covers \Causeway\ClaudeBridge\Notify\Notifications
 * @covers \Causeway\ClaudeBridge\Checks\HealthCheck
 * @covers \Causeway\ClaudeBridge\Checks\Pruner
 * @covers \Causeway\ClaudeBridge\Checks\LiveUrlCheck
 */
final class OperationsTest extends McpTestCase {

	private int $admin;

	private int $publisher;

	/**
	 * Answer the health probe gets.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $probe_response = null;

	public function set_up(): void {
		parent::set_up();
		$this->configure_mp_site();
		reset_phpmailer_instance();
		Mailer::reset();
		delete_transient( ActivityLog::BURST_TRANSIENT );
		delete_option( HealthCheck::OPTION_ALERTS );
		$this->admin     = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'site-admin@example.org' ) );
		$this->publisher = self::factory()->user->create( array( 'role' => RoleManager::ROLE_PUBLISHER, 'user_email' => 'mike@example.org', 'display_name' => 'Mike' ) );
		add_filter( 'pre_http_request', array( $this, 'probe_double' ), 50, 3 );
	}

	/**
	 * Answers requests to the MCP endpoint with $probe_response.
	 *
	 * @param mixed  $pre  Earlier answer.
	 * @param mixed  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function probe_double( mixed $pre, mixed $args, string $url ): mixed {
		unset( $args );
		if ( false === $pre && null !== $this->probe_response && str_contains( $url, 'ccb_health=' ) ) {
			return $this->probe_response;
		}
		return $pre;
	}

	/**
	 * Saves a config change.
	 *
	 * @param callable $change Receives the config by reference.
	 */
	private function change_config( callable $change ): void {
		$repository = new ConfigRepository();
		$config     = $repository->get();
		$change( $config );
		$this->assertIsArray( $repository->save( $config, 1, 'ui', null, null, true ) );
	}

	/**
	 * Recipients of every email sent.
	 *
	 * @return list<list<string>>
	 */
	private static function sent_to(): array {
		return array_map(
			static fn ( array $mail ): array => array_map( static fn ( array $to ): string => $to[0], $mail['to'] ),
			tests_retrieve_phpmailer_instance()->mock_sent
		);
	}

	/**
	 * A News post ready to publish.
	 */
	private function ready_post(): int {
		$post  = self::factory()->post->create(
			array(
				'post_title'   => 'Crossing reopens',
				'post_status'  => 'pending',
				'post_content' => '<p>' . trim( str_repeat( 'word ', 200 ) ) . '</p>',
			)
		);
		$file  = get_temp_dir() . 'ccb-ops-' . wp_generate_password( 6, false ) . '.jpg';
		$image = imagecreatetruecolor( 1600, 900 );
		imagejpeg( $image, $file );
		imagedestroy( $image );
		$attachment = self::factory()->attachment->create_upload_object( $file, $post );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'Crossing' );
		set_post_thumbnail( $post, $attachment );
		return $post;
	}

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	public function test_pending_post_emails_publishers_but_not_the_author(): void {
		$this->change_config(
			static function ( array &$c ): void {
				$c['notifications']['recipients']['extra'] = array( 'office@example.org' );
			}
		);
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'For review', 'content' => '<p>' . str_repeat( 'w ', 200 ) . '</p>' ) ) );
		Mailer::flush();

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $mail );
		$to = array_map( static fn ( array $r ): string => $r[0], $mail->to );
		$this->assertContains( 'mike@example.org', $to );
		$this->assertContains( 'site-admin@example.org', $to );
		$this->assertContains( 'office@example.org', $to );
		$this->assertNotContains( wp_get_current_user()->user_email, $to );
		$this->assertStringContainsString( 'Waiting for review: For review', $mail->subject );
		$this->assertStringContainsString( '[Test Blog]', $mail->subject );
		$this->assertStringContainsString( 'Review in WP Admin', $mail->body );
	}

	public function test_publishers_are_not_emailed_about_their_own_changes(): void {
		wp_set_current_user( $this->publisher );
		\Causeway\ClaudeBridge\Notify\Notifications::on_created( self::factory()->post->create( array( 'post_status' => 'pending' ) ), 'pending' );
		Mailer::flush();

		foreach ( self::sent_to() as $to ) {
			$this->assertNotContains( 'mike@example.org', $to );
		}
	}

	public function test_switched_off_events_send_nothing(): void {
		$this->change_config(
			static function ( array &$c ): void {
				$c['notifications']['events']['pending_post'] = false;
			}
		);
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Quiet', 'content' => '<p>' . str_repeat( 'w ', 200 ) . '</p>' ) ) );
		Mailer::flush();

		$this->assertSame( array(), self::sent_to() );
	}

	public function test_live_change_email_says_who_what_and_where(): void {
		$post = $this->ready_post();
		wp_set_current_user( $this->admin );
		$this->session = null;
		$token         = $this->data( $this->call_tool( 'ccb-prepare-publish', array( 'post_id' => $post ) ) )['token'];
		$this->data( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $token ) ) );
		Mailer::flush();

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'Publish now: Crossing reopens', $mail->subject );
		$this->assertStringContainsString( get_permalink( $post ), $mail->body );
		$this->assertStringContainsString( 'View the post', $mail->body );
		$this->assertNotSame( '', tests_retrieve_phpmailer_instance()->AltBody, 'Plain-text alternative' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertNotContains( 'site-admin@example.org', array_map( static fn ( array $r ): string => $r[0], $mail->to ) );
	}

	public function test_bin_email_links_to_the_bin_without_a_nonce_and_restore_is_reported(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Old news' ) );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$token = $this->data( $this->call_tool( 'ccb-prepare-trash', array( 'post_id' => $post ) ) )['token'];
		$this->data( $this->call_tool( 'ccb-trash-post', array( 'post_id' => $post, 'token' => $token ) ) );
		$this->data( $this->call_tool( 'ccb-restore-post', array( 'post_id' => $post ) ) );
		Mailer::flush();

		$mails = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 2, $mails );
		$this->assertStringContainsString( 'Moved to the Bin: Old news', $mails[0]['subject'] );
		$this->assertStringContainsString( 'edit.php?post_status=trash&#038;post_type=post', $mails[0]['body'] );
		$this->assertStringNotContainsString( '_wpnonce', $mails[0]['body'] );
		$this->assertStringContainsString( 'Restored from the Bin: Old news', $mails[1]['subject'] );
	}

	public function test_emails_are_sent_at_the_end_of_the_request(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Queued', 'content' => '<p>' . str_repeat( 'w ', 200 ) . '</p>' ) ) );

		$this->assertCount( 1, Mailer::queued() );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'Nothing sent before shutdown' );
		$this->assertNotFalse( has_action( 'shutdown', array( Mailer::class, 'flush' ) ) );
	}

	public function test_live_url_is_checked_after_publish_and_unpublish(): void {
		$post = $this->ready_post();
		wp_set_current_user( $this->admin );
		$this->session = null;

		$token     = $this->data( $this->call_tool( 'ccb-prepare-publish', array( 'post_id' => $post ) ) )['token'];
		$published = $this->data( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $token ) ) );
		$checks    = array_column( $published['verification']['checks'], null, 'name' );
		$this->assertTrue( $checks['live_url']['passed'] );
		$this->assertStringContainsString( 'Cached copies may take a few minutes', $checks['live_url']['message'], 'No cache plugin to purge' );

		$token = $this->data( $this->call_tool( 'ccb-prepare-unpublish', array( 'post_id' => $post ) ) )['token'];
		$gone  = $this->data( $this->call_tool( 'ccb-unpublish-post', array( 'post_id' => $post, 'token' => $token ) ) );
		$this->assertTrue( array_column( $gone['verification']['checks'], null, 'name' )['live_url']['passed'] );
	}

	public function test_cache_purge_hook_is_offered(): void {
		$post   = $this->ready_post();
		$purged = array();
		add_filter(
			'ccb_purge_post_cache',
			static function ( bool $done, int $id ) use ( &$purged ): bool {
				$purged[] = $id;
				return true;
			},
			10,
			2
		);
		wp_set_current_user( $this->admin );
		$this->session = null;
		$token         = $this->data( $this->call_tool( 'ccb-prepare-publish', array( 'post_id' => $post ) ) )['token'];
		$done          = $this->data( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $token ) ) );

		$this->assertSame( array( $post ), $purged );
		$this->assertStringNotContainsString( 'Cached copies', array_column( $done['verification']['checks'], null, 'name' )['live_url']['message'] );
	}

	public function test_health_check_passes_when_the_endpoint_needs_a_login(): void {
		$this->probe_response = array(
			'response' => array( 'code' => 401 ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => '{"code":"rest_forbidden"}',
			'cookies'  => array(),
		);

		$result = ( new HealthCheck() )->run();

		$this->assertNotSame( 'problem', $result['status'], (string) wp_json_encode( $result['issues'] ) );
		$this->assertSame( $result, HealthCheck::last() );
		$this->assertSame( 'health_check', $this->activity( 1 )[0]['action'] );
	}

	/**
	 * Each bad endpoint answer is a problem with its own code.
	 *
	 * @dataProvider bad_endpoints
	 *
	 * @param array<string, mixed> $response Fake response.
	 * @param string               $code     Expected issue code.
	 */
	public function test_health_check_flags_bad_endpoint_answers( array $response, string $code ): void {
		$this->probe_response = $response + array(
			'body'    => '',
			'cookies' => array(),
		);

		$result = ( new HealthCheck() )->run();

		$this->assertSame( 'problem', $result['status'] );
		$this->assertContains( $code, array_column( $result['issues'], 'code' ) );
	}

	/**
	 * [response, code].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function bad_endpoints(): array {
		return array(
			'firewall challenge' => array(
				array(
					'response' => array( 'code' => 403 ),
					'headers'  => array( 'content-type' => 'text/html' ),
				),
				'endpoint_blocked',
			),
			'cached response'    => array(
				array(
					'response' => array( 'code' => 401 ),
					'headers'  => array(
						'content-type'    => 'application/json',
						'cf-cache-status' => 'HIT',
					),
				),
				'endpoint_cached',
			),
			'open without login' => array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
				),
				'endpoint_open',
			),
		);
	}

	public function test_health_problems_email_support_and_admins_once_a_day(): void {
		$this->probe_response = array(
			'response' => array( 'code' => 403 ),
			'headers'  => array( 'content-type' => 'text/html' ),
			'body'     => '<html>Challenge</html>',
			'cookies'  => array(),
		);

		( new HealthCheck() )->run();
		Mailer::flush();
		( new HealthCheck() )->run();
		Mailer::flush();

		$sent = self::sent_to();
		$this->assertCount( 1, $sent, 'Same problem: one email per 24 hours' );
		$this->assertContains( ( new ConfigRepository() )->get()['support']['email'], $sent[0] );
		$this->assertContains( 'site-admin@example.org', $sent[0] );
	}

	public function test_check_connection_warns_after_a_failed_or_stale_health_check(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		update_option( HealthCheck::OPTION, array( 'checked_at_gmt' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'status' => 'problem', 'issues' => array() ) );
		$this->assertContains( 'health_failed', array_column( $this->data( $this->call_tool( 'ccb-check-connection' ) )['issues'], 'code' ) );

		update_option( HealthCheck::OPTION, array( 'checked_at_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS ), 'status' => 'good', 'issues' => array() ) );
		$this->assertContains( 'health_stale', array_column( $this->data( $this->call_tool( 'ccb-check-connection' ) )['issues'], 'code' ) );
	}

	public function test_updates_queue_a_health_check_five_minutes_later(): void {
		wp_clear_scheduled_hook( \Causeway\ClaudeBridge\Activation::CRON_HEALTH );
		$this->assertNotFalse( has_action( 'upgrader_process_complete', array( HealthCheck::class, 'queue_after_update' ) ) );
		HealthCheck::queue_after_update();

		$next = wp_next_scheduled( \Causeway\ClaudeBridge\Activation::CRON_HEALTH );
		$this->assertNotFalse( $next );
		$this->assertEqualsWithDelta( time() + 300, $next, 5 );
	}

	public function test_five_errors_in_an_hour_alert_the_support_contact_once(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			ActivityLog::record(
				array(
					'action'     => 'create',
					'result'     => 'error',
					'error_code' => 'ccb_internal',
				)
			);
		}
		Mailer::flush();

		$sent = self::sent_to();
		$this->assertCount( 1, $sent );
		$this->assertSame( array( ( new ConfigRepository() )->get()['support']['email'] ), $sent[0] );
	}

	public function test_daily_prune_removes_old_rows_and_keeps_recent_ones(): void {
		global $wpdb;
		ActivityLog::record( array( 'action' => 'get', 'result' => 'success' ) );
		$old = ActivityLog::record( array( 'action' => 'get', 'result' => 'success' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( Schema::ACTIVITY ), array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-13 months' ) ) ), array( 'id' => $old ) );
		$wpdb->insert(
			Schema::table( Schema::TOKENS ),
			array(
				'token_hash'        => str_repeat( 'd', 64 ),
				'user_id'           => 1,
				'post_id'           => 1,
				'action'            => 'publish',
				'payload_hash'      => str_repeat( 'e', 64 ),
				'post_modified_gmt' => '2026-01-01 00:00:00',
				'expires_at_gmt'    => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		// phpcs:enable

		$counts = Pruner::run();

		$this->assertGreaterThanOrEqual( 1, $counts['activity'] );
		$this->assertGreaterThanOrEqual( 1, $counts['tokens'] );
		$this->assertNotEmpty( $this->activity( 1 ), 'Recent rows kept' );
	}
}
