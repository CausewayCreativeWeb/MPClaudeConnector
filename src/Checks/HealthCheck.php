<?php
/**
 * Daily health check (docs/07 "3. Daily health check").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Checks;

use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Mcp\ServerRegistrar;
use Causeway\ClaudeBridge\Notify\Mailer;
use Causeway\ClaudeBridge\Notify\Notifications;
use WP_Error;

/**
 * Runs daily at 03:15 site time and five minutes after any plugin, theme or core update:
 * the pre-flight checks without a user, plus a request to the plugin's own MCP endpoint, which
 * must answer 401 (not a firewall challenge, a cached page or an open endpoint). The result is
 * kept in `ccb_health`. Problems email the support contact and administrators, once per distinct
 * problem per 24 hours.
 */
final class HealthCheck {

	public const OPTION        = 'ccb_health';
	public const OPTION_ALERTS = 'ccb_health_alerts';
	public const STALE_AFTER   = 2 * DAY_IN_SECONDS;

	/**
	 * Hooks the cron job and the post-update re-check.
	 */
	public static function register_hooks(): void {
		add_action( \Causeway\ClaudeBridge\Activation::CRON_HEALTH, array( self::class, 'cron' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'queue_after_update' ) );
	}

	/**
	 * Cron entry point.
	 */
	public static function cron(): void {
		( new self() )->run();
	}

	/**
	 * Re-checks five minutes after an update (unless a check is already queued that soon).
	 */
	public static function queue_after_update(): void {
		$when = time() + 5 * MINUTE_IN_SECONDS;
		$next = wp_next_scheduled( \Causeway\ClaudeBridge\Activation::CRON_HEALTH );
		if ( false === $next || $next > $when + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( $when, \Causeway\ClaudeBridge\Activation::CRON_HEALTH );
		}
	}

	/**
	 * Runs every check, stores and returns the result, and sends alerts.
	 *
	 * @return array{checked_at_gmt: string, status: string, issues: list<array{code: string, severity: string, message: string, fix: string}>}
	 */
	public function run(): array {
		$issues = ( new PreFlight() )->run( null, false );
		$probe  = self::probe_endpoint();
		if ( null !== $probe ) {
			$issues[] = $probe;
		}

		$severities = array_column( $issues, 'severity' );
		$status     = in_array( 'error', $severities, true ) ? 'problem' : ( array() === $issues ? 'good' : 'warning' );
		$result     = array(
			'checked_at_gmt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'status'         => $status,
			'issues'         => $issues,
		);
		update_option( self::OPTION, $result, false );

		ActivityLog::record(
			array(
				'action'     => 'health_check',
				'result'     => 'problem' === $status ? 'error' : 'success',
				'error_code' => 'problem' === $status ? 'health_failed' : null,
				'message'    => 'problem' === $status ? implode( ', ', array_column( $issues, 'code' ) ) : null,
			),
			array( 'status' => $status )
		);

		if ( 'problem' === $status ) {
			$this->alert( $issues );
		}
		return $result;
	}

	/**
	 * Last stored result, or null if the check has never run.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last(): ?array {
		$result = get_option( self::OPTION );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Requests the MCP endpoint without credentials. Null when it answered 401 as expected.
	 *
	 * @return array{code: string, severity: string, message: string, fix: string}|null
	 */
	public static function probe_endpoint(): ?array {
		$response = wp_remote_post(
			add_query_arg( 'ccb_health', (string) time(), ServerRegistrar::url() ),
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json, text/event-stream',
					'Cache-Control' => 'no-cache',
				),
				'body'      => '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
			)
		);

		if ( $response instanceof WP_Error ) {
			return self::issue( 'endpoint_unreachable', __( 'The site couldn\'t reach its own Claude connection.', 'causeway-claude-bridge' ), __( 'Check the host allows the site to request its own address (loopback requests).', 'causeway-claude-bridge' ) );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$type   = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$cached = strtolower( (string) wp_remote_retrieve_header( $response, 'cf-cache-status' ) ) === 'hit'
			|| str_contains( strtolower( (string) wp_remote_retrieve_header( $response, 'x-cache' ) ), 'hit' );

		if ( $cached ) {
			return self::issue( 'endpoint_cached', __( 'The Claude connection is being served from a cache.', 'causeway-claude-bridge' ), __( 'Exclude /wp-json/mcp/ from page caching and the CDN.', 'causeway-claude-bridge' ) );
		}
		if ( 401 === $code && str_contains( $type, 'json' ) ) {
			return null;
		}
		if ( $code >= 200 && $code < 300 ) {
			return self::issue( 'endpoint_open', __( 'The Claude connection answered without a login.', 'causeway-claude-bridge' ), __( 'Tell the site\'s support contact straight away.', 'causeway-claude-bridge' ) );
		}
		return self::issue(
			'endpoint_blocked',
			/* translators: %d: HTTP status */
			sprintf( __( 'A firewall or security plugin is blocking the Claude connection (HTTP %d).', 'causeway-claude-bridge' ), $code ),
			__( 'Allow /wp-json/mcp/ccb in the firewall, CDN and security plugin, without a challenge page.', 'causeway-claude-bridge' )
		);
	}

	/**
	 * Emails the support contact and administrators, once per distinct problem per day.
	 *
	 * @param list<array{code: string, severity: string, message: string, fix: string}> $issues Issues.
	 */
	private function alert( array $issues ): void {
		$config = ( new ConfigRepository() )->get();
		if ( empty( $config['notifications']['events']['health_failure'] ) ) {
			return;
		}
		$errors = array_values( array_filter( $issues, static fn ( array $i ): bool => 'error' === $i['severity'] ) );
		$key    = md5( implode( '|', array_column( $errors, 'code' ) ) );
		$sent   = get_option( self::OPTION_ALERTS, array() );
		$sent   = is_array( $sent ) ? array_filter( $sent, static fn ( $time ): bool => (int) $time > time() - DAY_IN_SECONDS ) : array();
		if ( isset( $sent[ $key ] ) ) {
			return;
		}
		$sent[ $key ] = time();
		update_option( self::OPTION_ALERTS, $sent, false );

		$rows = array();
		foreach ( $errors as $issue ) {
			$rows[ $issue['message'] ] = $issue['fix'];
		}
		Mailer::queue(
			array_merge( array( (string) $config['support']['email'] ), Mailer::admin_emails() ),
			__( 'Claude Publishing needs attention', 'causeway-claude-bridge' ),
			array(
				'heading' => __( 'The daily check found a problem with the Claude connection', 'causeway-claude-bridge' ),
				'intro'   => __( 'Staff may not be able to use Claude to manage posts until this is fixed.', 'causeway-claude-bridge' ),
				'rows'    => $rows,
				'link'    => array(
					'url'   => admin_url( 'options-general.php?page=claude-publishing' ),
					'label' => __( 'Open Claude Publishing settings', 'causeway-claude-bridge' ),
				),
				'footer'  => Notifications::footer(),
			)
		);
	}

	/**
	 * An error-level issue.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param string $fix     Fix.
	 * @return array{code: string, severity: string, message: string, fix: string}
	 */
	private static function issue( string $code, string $message, string $fix ): array {
		return array(
			'code'     => $code,
			'severity' => 'error',
			'message'  => $message,
			'fix'      => $fix,
		);
	}
}
