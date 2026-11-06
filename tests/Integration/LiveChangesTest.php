<?php
/**
 * Live changes and confirmation tokens (docs/02, docs/03 "Live-change abilities", docs/11).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * @covers \Causeway\ClaudeBridge\Live\LiveChanges
 * @covers \Causeway\ClaudeBridge\Security\ConfirmationTokens
 * @covers \Causeway\ClaudeBridge\Abilities\AbstractLiveAbility
 * @covers \Causeway\ClaudeBridge\Abilities\RestorePost
 */
final class LiveChangesTest extends McpTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/London' );
		$this->configure_mp_site();
	}

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * A News post that meets every requirement.
	 *
	 * @param string $status Status.
	 */
	private function ready_post( string $status = 'pending' ): int {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Crossing reopens',
				'post_status'  => $status,
				'post_content' => '<p>' . trim( str_repeat( 'word ', 200 ) ) . '</p>',
			)
		);
		wp_set_object_terms( $post, array( get_term_by( 'slug', 'news', 'category' )->term_id ), 'category' );
		$file  = get_temp_dir() . 'ccb-live-' . wp_generate_password( 6, false ) . '.jpg';
		$image = imagecreatetruecolor( 1600, 900 );
		imagejpeg( $image, $file );
		imagedestroy( $image );
		$attachment = self::factory()->attachment->create_upload_object( $file, $post );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'The crossing' );
		set_post_thumbnail( $post, $attachment );
		return $post;
	}

	/**
	 * Error code in a failed result.
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	private static function code( array $result ): string {
		return preg_match( '/code: (ccb_[a-z_]+)/', self::text( $result ), $m ) ? $m[1] : '';
	}

	/**
	 * Runs a prepare tool and returns its data.
	 *
	 * @param string               $tool  Tool name without the ccb- prefix.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	private function prepare( string $tool, array $input ): array {
		return $this->data( $this->call_tool( 'ccb-' . $tool, $input ) );
	}

	public function test_publisher_publishes_after_a_confirmed_summary(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$modified_before = get_post( $post )->post_modified_gmt;
		$fired           = array();
		add_action(
			'ccb_live_change',
			static function ( string $action, int $id ) use ( &$fired ): void {
				$fired[] = array( $action, $id );
			},
			10,
			2
		);

		$prepared = $this->prepare( 'prepare-publish', array( 'post_id' => $post ) );

		$summary = $prepared['summary'];
		$this->assertSame( 'Publish now', $summary['action'] );
		$this->assertSame( 'Crossing reopens', $summary['title'] );
		$this->assertSame( 'News', $summary['section'] );
		$this->assertSame( 'pending', $summary['from_status'] );
		$this->assertSame( 'publish', $summary['to_status'] );
		$this->assertContains( 'Publishers will be emailed.', $summary['notes'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $prepared['token'] );
		$this->assertSame( 'pending', get_post_status( $post ), 'Preparing changes nothing' );
		$this->assertSame( $modified_before, get_post( $post )->post_modified_gmt );
		$this->assertSame( 'prepare', $this->activity( 1 )[0]['action'] );

		$done = $this->data( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $prepared['token'] ) ) );

		$this->assertSame( 'publish', get_post_status( $post ) );
		$this->assertSame( 'publish', $done['status'] );
		$this->assertSame( get_permalink( $post ), $done['url'] );
		$this->assertTrue( $done['verification']['passed'], (string) wp_json_encode( $done['verification'] ) );
		$this->assertEqualsWithDelta( time(), strtotime( get_post( $post )->post_date_gmt . ' UTC' ), 120, 'Published now' );
		$this->assertSame( array( array( 'publish', $post ) ), $fired );
		$row = $this->activity( 1 )[0];
		$this->assertSame( 'publish', $row['action'] );
		$this->assertSame( 'pending', $row['status_from'] );
		$this->assertSame( 'publish', $row['status_to'] );
	}

	public function test_contributors_can_never_publish_or_schedule(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		foreach ( array( 'prepare-publish' => array(), 'prepare-schedule' => array( 'publish_at' => gmdate( 'c', time() + DAY_IN_SECONDS ) ) ) as $tool => $extra ) {
			$result = $this->call_tool( 'ccb-' . $tool, array_merge( array( 'post_id' => $post ), $extra ) );
			$this->assertSame( 'ccb_forbidden', self::code( $result ), $tool );
		}
		$this->assertSame( 'ccb_forbidden', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => str_repeat( 'a', 43 ) ) ) ) );
		$this->assertSame( 'pending', get_post_status( $post ) );
	}

	public function test_publishing_needs_every_requirement(): void {
		$post = $this->ready_post();
		delete_post_thumbnail( $post );
		$this->login_as( RoleManager::ROLE_PUBLISHER );

		$result = $this->call_tool( 'ccb-prepare-publish', array( 'post_id' => $post ) );

		$this->assertSame( 'ccb_required_missing', self::code( $result ) );
		$this->assertStringContainsString( 'Featured image missing', self::text( $result ) );
	}

	public function test_actions_need_a_token(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );

		$this->assertSame( 'ccb_invalid_input', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post ) ) ) );
		$this->assertSame( 'ccb_token_invalid', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => str_repeat( 'x', 43 ) ) ) ) );
		$this->assertSame( 'pending', get_post_status( $post ) );
	}

	public function test_tokens_expire_after_ten_minutes(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$token = $this->prepare( 'prepare-publish', array( 'post_id' => $post ) )['token'];
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( Schema::TOKENS ), array( 'expires_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'token_hash' => hash( 'sha256', $token ) ) );

		$this->assertSame( 'ccb_token_invalid', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $token ) ) ) );
		$this->assertSame( 'pending', get_post_status( $post ) );
	}

	public function test_tokens_work_once(): void {
		$post = $this->ready_post( 'draft' );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$token = $this->prepare( 'prepare-trash', array( 'post_id' => $post ) )['token'];
		$this->data( $this->call_tool( 'ccb-trash-post', array( 'post_id' => $post, 'token' => $token ) ) );
		$this->data( $this->call_tool( 'ccb-restore-post', array( 'post_id' => $post ) ) );

		$this->assertSame( 'ccb_token_invalid', self::code( $this->call_tool( 'ccb-trash-post', array( 'post_id' => $post, 'token' => $token ) ) ) );
		$this->assertSame( 'draft', get_post_status( $post ) );
	}

	public function test_tokens_are_bound_to_the_user_and_the_post(): void {
		$post  = $this->ready_post();
		$other = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$mine = $this->prepare( 'prepare-publish', array( 'post_id' => $post ) )['token'];

		$this->assertSame( 'ccb_token_invalid', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $other, 'token' => $mine ) ) ), 'Other post' );

		$theirs = $this->prepare( 'prepare-publish', array( 'post_id' => $post ) )['token'];
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$this->assertSame( 'ccb_token_invalid', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $theirs ) ) ), 'Other user' );

		$this->assertSame( 'pending', get_post_status( $post ) );
		$this->assertSame( 'pending', get_post_status( $other ) );
	}

	public function test_tokens_are_bound_to_the_action(): void {
		$post = $this->ready_post( 'publish' );
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$token = $this->prepare( 'prepare-unpublish', array( 'post_id' => $post ) )['token'];

		$this->assertSame( 'ccb_token_invalid', self::code( $this->call_tool( 'ccb-trash-post', array( 'post_id' => $post, 'token' => $token ) ) ) );
		$this->assertSame( 'publish', get_post_status( $post ) );
	}

	public function test_a_changed_payload_is_refused(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$when  = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );
		$token = $this->prepare( 'prepare-schedule', array( 'post_id' => $post, 'publish_at' => $when ) )['token'];

		$result = $this->call_tool( 'ccb-schedule-post', array( 'post_id' => $post, 'publish_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * DAY_IN_SECONDS ), 'token' => $token ) );

		$this->assertSame( 'ccb_token_mismatch', self::code( $result ) );
		$this->assertSame( 'pending', get_post_status( $post ) );
	}

	public function test_a_post_changed_after_prepare_is_refused_and_the_token_is_spent(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$token = $this->prepare( 'prepare-publish', array( 'post_id' => $post ) )['token'];
		sleep( 1 );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Edited in WP Admin meanwhile' ) );

		$this->assertSame( 'ccb_post_changed', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $token ) ) ) );
		$this->assertSame( 'ccb_token_invalid', self::code( $this->call_tool( 'ccb-publish-post', array( 'post_id' => $post, 'token' => $token ) ) ), 'Used up even though the action failed' );
		$this->assertSame( 'pending', get_post_status( $post ) );
	}

	public function test_only_hashes_of_tokens_are_stored(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$token = $this->prepare( 'prepare-publish', array( 'post_id' => $post ) )['token'];
		global $wpdb;
		$table = Schema::table( Schema::TOKENS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) );

		$this->assertStringNotContainsString( $token, $rows );
		$this->assertStringContainsString( hash( 'sha256', $token ), $rows );
		foreach ( $this->activity( 5 ) as $row ) {
			$this->assertStringNotContainsString( $token, (string) wp_json_encode( $row ) );
		}
	}

	public function test_scheduling_in_uk_time(): void {
		$post = $this->ready_post();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$day = gmdate( 'Y-m-d', time() + 3 * DAY_IN_SECONDS );

		$past = $this->call_tool( 'ccb-prepare-schedule', array( 'post_id' => $post, 'publish_at' => gmdate( 'c', time() + 60 ) ) );
		$this->assertSame( 'ccb_schedule_in_past', self::code( $past ) );

		$prepared = $this->prepare( 'prepare-schedule', array( 'post_id' => $post, 'publish_at' => $day . 'T09:00:00' ) );
		$this->assertStringContainsString( '09:00 UK time', $prepared['summary']['action'] );
		$this->assertSame( 'future', $prepared['summary']['to_status'] );

		$this->data( $this->call_tool( 'ccb-schedule-post', array( 'post_id' => $post, 'publish_at' => $day . 'T09:00:00', 'token' => $prepared['token'] ) ) );

		$this->assertSame( 'future', get_post_status( $post ) );
		$this->assertSame( $day . ' 09:00:00', get_post( $post )->post_date, 'Site (UK) time' );
		$expected = ( new \DateTimeImmutable( $day . ' 09:00:00', new \DateTimeZone( 'Europe/London' ) ) )->getTimestamp();
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $expected ), get_post( $post )->post_date_gmt );
	}

	public function test_update_live_lists_changes_and_saves_a_revision_first(): void {
		$post = $this->ready_post( 'publish' );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$changes = array(
			'title'   => 'Crossing reopens on Monday',
			'content' => '<p>' . trim( str_repeat( 'word ', 250 ) ) . '</p>',
		);

		$prepared = $this->prepare( 'prepare-update-live', array( 'post_id' => $post, 'changes' => $changes ) );
		$listed   = array_column( $prepared['summary']['changes'], null, 'field' );
		$this->assertSame( array( 'Crossing reopens', 'Crossing reopens on Monday' ), array( $listed['Title']['before'], $listed['Title']['after'] ) );
		$this->assertSame( array( '200 words', '250 words' ), array( $listed['Body']['before'], $listed['Body']['after'] ) );
		$this->assertSame( 'Crossing reopens', get_the_title( $post ), 'Preparing changes nothing' );

		$altered = $this->call_tool( 'ccb-update-live-post', array( 'post_id' => $post, 'changes' => array( 'title' => 'Something else' ), 'token' => $prepared['token'] ) );
		$this->assertSame( 'ccb_token_mismatch', self::code( $altered ) );

		$prepared = $this->prepare( 'prepare-update-live', array( 'post_id' => $post, 'changes' => $changes ) );
		$this->data( $this->call_tool( 'ccb-update-live-post', array( 'post_id' => $post, 'changes' => $changes, 'token' => $prepared['token'] ) ) );

		$this->assertSame( 'Crossing reopens on Monday', get_the_title( $post ) );
		$this->assertSame( 'publish', get_post_status( $post ), 'Status unchanged' );
		$titles = array_map( static fn ( \WP_Post $r ): string => $r->post_title, wp_get_post_revisions( $post ) );
		$this->assertContains( 'Crossing reopens', $titles, 'The live version was kept as a revision' );
	}

	public function test_update_live_only_for_live_posts_and_not_elementor_content(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$draft = $this->ready_post( 'draft' );
		$this->assertSame( 'ccb_wrong_status', self::code( $this->call_tool( 'ccb-prepare-update-live', array( 'post_id' => $draft, 'changes' => array( 'title' => 'x' ) ) ) ) );

		$built = $this->ready_post( 'publish' );
		update_post_meta( $built, '_elementor_edit_mode', 'builder' );
		$this->assertSame( 'ccb_elementor_post', self::code( $this->call_tool( 'ccb-prepare-update-live', array( 'post_id' => $built, 'changes' => array( 'content' => '<p>x</p>' ) ) ) ) );
		$this->prepare( 'prepare-update-live', array( 'post_id' => $built, 'changes' => array( 'title' => 'Title only' ) ) );
	}

	public function test_contributor_unpublishes_with_confirmation(): void {
		$post = $this->ready_post( 'publish' );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$prepared = $this->prepare( 'prepare-unpublish', array( 'post_id' => $post ) );
		$this->assertSame( 'Unpublish (back to draft)', $prepared['summary']['action'] );
		$this->data( $this->call_tool( 'ccb-unpublish-post', array( 'post_id' => $post, 'token' => $prepared['token'] ) ) );

		$this->assertSame( 'draft', get_post_status( $post ) );
		$this->assertSame( 'ccb_wrong_status', self::code( $this->call_tool( 'ccb-prepare-unpublish', array( 'post_id' => $post ) ) ) );
	}

	public function test_bin_and_restore_always_as_draft(): void {
		$post = $this->ready_post( 'publish' );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$prepared = $this->prepare( 'prepare-trash', array( 'post_id' => $post ) );
		$this->assertSame( 'Move to the Bin', $prepared['summary']['action'] );
		$this->assertContains( 'Can be restored for 30 days.', $prepared['summary']['notes'] );
		$this->data( $this->call_tool( 'ccb-trash-post', array( 'post_id' => $post, 'token' => $prepared['token'] ) ) );
		$this->assertSame( 'trash', get_post_status( $post ) );
		$this->assertNotNull( get_post( $post ), 'In the Bin, not deleted' );

		$restored = $this->data( $this->call_tool( 'ccb-restore-post', array( 'post_id' => $post ) ) );
		$this->assertSame( 'draft', $restored['status'] );
		$this->assertSame( 'draft', get_post_status( $post ), 'Never straight back to live' );
		$this->assertSame( 'restore', $this->activity( 1 )[0]['action'] );

		$this->assertSame( 'ccb_wrong_status', self::code( $this->call_tool( 'ccb-restore-post', array( 'post_id' => $post ) ) ) );
	}

	public function test_daily_cap_on_live_changes(): void {
		$repository                               = new ConfigRepository();
		$config                                   = $repository->get();
		$config['access']['live_changes_per_day'] = 1;
		$this->assertIsArray( $repository->save( $config, 1, 'ui' ) );
		$first  = $this->ready_post( 'publish' );
		$second = $this->ready_post( 'publish' );
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );

		$token = $this->prepare( 'prepare-unpublish', array( 'post_id' => $first ) )['token'];
		$this->data( $this->call_tool( 'ccb-unpublish-post', array( 'post_id' => $first, 'token' => $token ) ) );

		$this->assertSame( 'ccb_rate_limited', self::code( $this->call_tool( 'ccb-prepare-unpublish', array( 'post_id' => $second ) ) ) );
		$this->assertSame( 'publish', get_post_status( $second ) );
	}

	public function test_live_tools_are_marked_destructive_and_prepare_tools_read_only(): void {
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$this->initialize();
		[ , $body ] = $this->rpc( 'tools/list' );
		$tools      = array_column( json_decode( (string) wp_json_encode( $body ), true )['result']['tools'], 'annotations', 'name' );

		foreach ( array( 'publish-post', 'schedule-post', 'update-live-post', 'unpublish-post', 'trash-post' ) as $action ) {
			$this->assertTrue( $tools[ 'ccb-' . $action ]['destructiveHint'] ?? false, $action );
		}
		foreach ( array( 'prepare-publish', 'prepare-schedule', 'prepare-update-live', 'prepare-unpublish', 'prepare-trash' ) as $prepare ) {
			$this->assertTrue( $tools[ 'ccb-' . $prepare ]['readOnlyHint'] ?? false, $prepare );
		}
		$this->assertArrayNotHasKey( 'ccb-delete-post', $tools, 'There is no permanent delete' );
	}
}
