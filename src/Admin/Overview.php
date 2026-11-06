<?php
/**
 * Data for the Overview tab and the header status pill (docs/08 "Overview").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Admin;

use Causeway\ClaudeBridge\Checks\HealthCheck;
use Causeway\ClaudeBridge\Checks\PreFlight;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Mcp\ServerRegistrar;
use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * Status cards, this week's numbers, posts waiting for review and setup progress.
 */
final class Overview {

	/**
	 * Option holding onboarding steps the plugin can't infer (test email sent, setup pack downloaded).
	 */
	public const OPTION_ONBOARDING = 'ccb_onboarding';

	/**
	 * Everything the Overview tab shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$repo   = new ConfigRepository();
		$config = $repo->get();
		$issues = ( new PreFlight() )->run( null, true );
		$health = HealthCheck::last();

		$status = self::status( $issues );
		if ( 'good' === $status && null === $health ) {
			$status = 'warning'; // Never checked: can't say it's connected.
		}

		return array(
			'status'   => $status,
			'issues'   => $issues,
			'cards'    => self::cards( $config, $health, $issues ),
			'health'   => $health,
			'week'     => self::week(),
			'pending'  => self::pending( $config ),
			'setup'    => self::setup( $config ),
			'endpoint' => ServerRegistrar::url(),
		);
	}

	/**
	 * Overall status: good, warning or problem.
	 *
	 * @param list<array<string, mixed>> $issues Pre-flight issues.
	 */
	public static function status( array $issues ): string {
		$severities = array_column( $issues, 'severity' );
		if ( in_array( 'error', $severities, true ) ) {
			return 'problem';
		}
		return array() === $issues ? 'good' : 'warning';
	}

	/**
	 * Status cards.
	 *
	 * @param array<string, mixed>       $config Config.
	 * @param array<string, mixed>|null  $health Last health check.
	 * @param list<array<string, mixed>> $issues Pre-flight issues.
	 * @return list<array{key: string, label: string, value: string, state: string, note: string}>
	 */
	private static function cards( array $config, ?array $health, array $issues ): array {
		$endpoint_issue = null;
		foreach ( (array) ( $health['issues'] ?? array() ) as $issue ) {
			if ( str_starts_with( (string) ( $issue['code'] ?? '' ), 'endpoint_' ) ) {
				$endpoint_issue = $issue;
			}
		}
		$saved_by = get_userdata( (int) ( $config['saved_by'] ?? 0 ) );
		$adapter  = '\Causeway\ClaudeBridge\Vendor\WP\MCP\Core\McpAdapter';
		$codes    = array_column( $issues, 'code' );

		$cards = array(
			array(
				'key'   => 'endpoint',
				'label' => __( 'Claude connection', 'causeway-claude-bridge' ),
				'value' => null === $health ? __( 'Not checked yet', 'causeway-claude-bridge' ) : ( null === $endpoint_issue ? __( 'Reachable', 'causeway-claude-bridge' ) : (string) $endpoint_issue['message'] ),
				'state' => null === $health ? 'warning' : ( null === $endpoint_issue ? 'good' : 'problem' ),
				'note'  => null === $endpoint_issue ? '' : (string) ( $endpoint_issue['fix'] ?? '' ),
			),
			array(
				'key'   => 'plugin',
				'label' => __( 'Plugin version', 'causeway-claude-bridge' ),
				'value' => defined( 'CCB_VERSION' ) ? (string) CCB_VERSION : '',
				'state' => 'good',
				'note'  => '',
			),
			array(
				'key'   => 'adapter',
				'label' => __( 'MCP Adapter version', 'causeway-claude-bridge' ),
				'value' => class_exists( $adapter ) ? (string) constant( $adapter . '::VERSION' ) : ( defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : __( 'Not loaded', 'causeway-claude-bridge' ) ),
				'state' => class_exists( $adapter ) || defined( 'WP_MCP_VERSION' ) ? 'good' : 'problem',
				'note'  => '',
			),
			array(
				'key'   => 'acf',
				'label' => __( 'Advanced Custom Fields', 'causeway-claude-bridge' ),
				'value' => function_exists( 'acf_get_field_groups' ) ? __( 'Active', 'causeway-claude-bridge' ) : __( 'Not active', 'causeway-claude-bridge' ),
				'state' => function_exists( 'acf_get_field_groups' ) ? 'good' : 'warning',
				'note'  => function_exists( 'acf_get_field_groups' ) ? '' : __( 'Sections can\'t use ACF fields until ACF is active.', 'causeway-claude-bridge' ),
			),
			array(
				'key'   => 'config',
				'label' => __( 'Settings version', 'causeway-claude-bridge' ),
				/* translators: 1: version number, 2: user name */
				'value' => sprintf( __( 'Version %1$d by %2$s', 'causeway-claude-bridge' ), (int) $config['version'], false === $saved_by ? __( 'the installer', 'causeway-claude-bridge' ) : $saved_by->display_name ),
				'state' => in_array( 'ccb_config_invalid', $codes, true ) ? 'problem' : ( in_array( 'config_stale', $codes, true ) ? 'warning' : 'good' ),
				'note'  => in_array( 'config_stale', $codes, true ) ? __( 'Some settings refer to terms or fields that no longer exist. Open Sections to fix them.', 'causeway-claude-bridge' ) : '',
			),
			array(
				'key'   => 'health',
				'label' => __( 'Last daily check', 'causeway-claude-bridge' ),
				'value' => null === $health ? __( 'Not run yet', 'causeway-claude-bridge' ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $health['checked_at_gmt'] ) ), 'j M Y H:i' ),
				'state' => null === $health ? 'warning' : ( 'good' === ( $health['status'] ?? '' ) ? 'good' : ( 'problem' === ( $health['status'] ?? '' ) ? 'problem' : 'warning' ) ),
				'note'  => in_array( 'health_stale', $codes, true ) ? __( 'The daily check hasn\'t run for more than two days. Check that WP-Cron runs.', 'causeway-claude-bridge' ) : '',
			),
		);
		return $cards;
	}

	/**
	 * Successful Claude actions in the last 7 days.
	 *
	 * @return array{created: int, published: int, scheduled: int, binned: int}
	 */
	private static function week(): array {
		global $wpdb;
		$table = Schema::table( Schema::ACTIVITY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- custom table, name from constants; "create" is an action name, not DDL.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT action, COUNT(*) AS n FROM `{$table}` WHERE result = 'success' AND action IN ('create','publish','schedule','trash') AND created_at_gmt >= %s GROUP BY action", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ), ARRAY_A );
		$counts = array_column( is_array( $rows ) ? $rows : array(), 'n', 'action' );
		return array(
			'created'   => (int) ( $counts['create'] ?? 0 ),
			'published' => (int) ( $counts['publish'] ?? 0 ),
			'scheduled' => (int) ( $counts['schedule'] ?? 0 ),
			'binned'    => (int) ( $counts['trash'] ?? 0 ),
		);
	}

	/**
	 * Posts waiting in Pending Review, per enabled section.
	 *
	 * @param array<string, mixed> $config Config.
	 * @return list<array{section: string, label: string, count: int, link: string}>
	 */
	private static function pending( array $config ): array {
		$list = array();
		foreach ( $config['sections'] as $key => $section ) {
			if ( empty( $section['enabled'] ) || ! post_type_exists( (string) $section['post_type'] ) ) {
				continue;
			}
			$counts = wp_count_posts( (string) $section['post_type'] );
			$list[] = array(
				'section' => (string) $key,
				'label'   => (string) $section['label'],
				'count'   => (int) ( $counts->pending ?? 0 ),
				'link'    => admin_url( 'edit.php?post_status=pending&post_type=' . rawurlencode( (string) $section['post_type'] ) ),
			);
		}
		return $list;
	}

	/**
	 * Setup progress: Sections → Access → Notifications → Setup pack.
	 *
	 * @param array<string, mixed> $config Config.
	 * @return array{steps: list<array{key: string, label: string, done: bool}>, complete: bool}
	 */
	public static function setup( array $config ): array {
		$flags   = get_option( self::OPTION_ONBOARDING, array() );
		$flags   = is_array( $flags ) ? $flags : array();
		$enabled = array_filter( $config['sections'], static fn ( array $s ): bool => ! empty( $s['enabled'] ) );
		$users   = get_users(
			array(
				'role__in' => array( RoleManager::ROLE_CONTRIBUTOR, RoleManager::ROLE_PUBLISHER ),
				'number'   => 1,
				'fields'   => 'ID',
			)
		);

		$steps = array(
			array(
				'key'   => 'sections',
				'label' => __( 'Turn on at least one section', 'causeway-claude-bridge' ),
				'done'  => array() !== $enabled,
			),
			array(
				'key'   => 'access',
				'label' => __( 'Give staff the AI Contributor or AI Publisher role', 'causeway-claude-bridge' ),
				'done'  => array() !== $users,
			),
			array(
				'key'   => 'notifications',
				'label' => __( 'Send a test email', 'causeway-claude-bridge' ),
				'done'  => ! empty( $flags['test_email'] ),
			),
			array(
				'key'   => 'setup-pack',
				'label' => __( 'Download the setup pack', 'causeway-claude-bridge' ),
				'done'  => ! empty( $flags['setup_pack'] ),
			),
		);
		return array(
			'steps'    => $steps,
			'complete' => array() === array_filter( $steps, static fn ( array $s ): bool => ! $s['done'] ),
		);
	}

	/**
	 * Marks an onboarding step as done.
	 *
	 * @param string $step `test_email` or `setup_pack`.
	 */
	public static function mark( string $step ): void {
		$flags          = get_option( self::OPTION_ONBOARDING, array() );
		$flags          = is_array( $flags ) ? $flags : array();
		$flags[ $step ] = time();
		update_option( self::OPTION_ONBOARDING, $flags, false );
	}
}
