<?php
/**
 * Shared enforcement for every ability (docs/02 "Server-side enforcement").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Audit\RequestContext;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Security\RateLimiter;
use Throwable;
use WP_Error;

/**
 * Base class for `ccb/*` abilities.
 *
 * WordPress runs the steps in this order for every call:
 * 1. Input is validated against input_schema() by core (the adapter audits failures, see ServerRegistrar).
 * 2. permission(): authenticated user, HTTPS, `ccb_use_connection` (and `ccb_publish` where needed).
 *    It runs more than once per call (adapter and core both check), so it has no side effects except
 *    one audit row for a refusal.
 * 3. execute(): rate limit, then the ability's own rules in run(), then one audit row.
 *    Exceptions become `ccb_internal` with a reference ID that matches the activity row and the PHP log.
 */
abstract class AbstractAbility {

	/**
	 * Refusals already logged in this request, so repeated permission checks log once.
	 *
	 * @var array<string, true>
	 */
	private static array $logged_refusals = array();

	/**
	 * Ability name, e.g. `ccb/check-connection`.
	 */
	abstract public function name(): string;

	/**
	 * Short label.
	 */
	abstract protected function label(): string;

	/**
	 * What the ability does, written for Claude.
	 */
	abstract protected function description(): string;

	/**
	 * JSON Schema for the input (the subset WordPress' REST validator supports).
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function input_schema(): array;

	/**
	 * JSON Schema for the output.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function output_schema(): array;

	/**
	 * Activity log `action` value (docs/06).
	 */
	abstract public function audit_action(): string;

	/**
	 * Does the work after permission and rate checks pass.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @param AbilityContext       $context Request context; set audit fields on it.
	 * @return array<string, mixed>|WP_Error
	 */
	abstract protected function run( array $input, AbilityContext $context ): array|WP_Error;

	/**
	 * Whether the ability needs `ccb_publish` as well as `ccb_use_connection`.
	 */
	protected function requires_publish(): bool {
		return false;
	}

	/**
	 * MCP behaviour hints (WordPress format; the adapter converts them).
	 *
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Arguments for wp_register_ability().
	 *
	 * @return array<string, mixed>
	 */
	public function registration_args(): array {
		return array(
			'label'               => $this->label(),
			'description'         => $this->description(),
			'category'            => AbilityRegistrar::CATEGORY,
			'input_schema'        => $this->input_schema(),
			'output_schema'       => $this->output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'permission' ),
			'meta'                => array(
				'annotations'  => $this->annotations(),
				// Only on the dedicated ccb server: never on the core REST endpoints or any default MCP server.
				'show_in_rest' => false,
				'mcp'          => array(
					'public' => false,
					'type'   => 'tool',
				),
			),
		);
	}

	/**
	 * Permission check.
	 *
	 * @param mixed $input Input (unused; the checks don't depend on it).
	 * @return true|WP_Error
	 */
	public function permission( mixed $input = null ): bool|WP_Error {
		unset( $input );

		$error = null;
		if ( ! is_user_logged_in() ) {
			$error = AbilityError::make( 'ccb_not_authenticated', __( 'You aren\'t logged in to the website.', 'causeway-claude-bridge' ), 401, null, __( 'Check the WordPress username and Application Password in the Claude Desktop extension, or create a new Application Password in your WordPress profile.', 'causeway-claude-bridge' ) );
		} elseif ( ! is_ssl() ) {
			$error = AbilityError::make( 'ccb_https_required', __( 'The connection must use HTTPS.', 'causeway-claude-bridge' ), 403, null, __( 'Use the https:// address of the site.', 'causeway-claude-bridge' ) );
		} elseif ( ! current_user_can( RoleManager::CAP_USE ) ) {
			$error = AbilityError::make( 'ccb_forbidden', __( 'Your account isn\'t set up to use Claude Publishing.', 'causeway-claude-bridge' ), 403, null, __( 'Ask a site administrator to give you the AI Contributor or AI Publisher role.', 'causeway-claude-bridge' ) );
		} elseif ( $this->requires_publish() && ! current_user_can( RoleManager::CAP_PUBLISH ) ) {
			$error = AbilityError::make( 'ccb_forbidden', __( 'Only AI Publishers can publish or schedule posts.', 'causeway-claude-bridge' ), 403, null, __( 'Submit the post for review instead, so a Publisher can publish it.', 'causeway-claude-bridge' ) );
		}

		if ( null === $error ) {
			return true;
		}

		$error = AbilityError::with_ref( $error, RequestContext::request_ref() );
		$key   = $this->name() . '|' . $error->get_error_code();
		if ( ! isset( self::$logged_refusals[ $key ] ) ) {
			self::$logged_refusals[ $key ] = true;
			$this->audit_error( $error, new AbilityContext() );
		}
		return $error;
	}

	/**
	 * Execute callback.
	 *
	 * @param mixed $input Input validated by core.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$context = new AbilityContext();

		$config = ( new ConfigRepository() )->get();
		$retry  = RateLimiter::hit_request( get_current_user_id(), (int) $config['access']['requests_per_minute'] );
		if ( $retry > 0 ) {
			$error = AbilityError::make(
				'ccb_rate_limited',
				__( 'Too many requests in the last minute.', 'causeway-claude-bridge' ),
				429,
				null,
				/* translators: %d: seconds */
				sprintf( __( 'Wait %d seconds, then try again.', 'causeway-claude-bridge' ), $retry ),
				RequestContext::request_ref(),
				array( 'retry_after' => $retry )
			);
			$this->audit_error( $error, $context );
			return $error;
		}

		try {
			$result = $this->run( $input, $context );
		} catch ( Throwable $exception ) {
			$ref = RequestContext::request_ref();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the reference ID ties this log line to the activity row the user is told about.
			error_log( sprintf( '[Claude Publishing %s] %s in %s:%d', $ref, $exception->getMessage(), $exception->getFile(), $exception->getLine() ) );
			$result = AbilityError::make(
				'ccb_internal',
				__( 'Something went wrong on the website.', 'causeway-claude-bridge' ),
				500,
				null,
				/* translators: %s: reference ID */
				sprintf( __( 'Tell the site\'s support contact, quoting reference %s.', 'causeway-claude-bridge' ), $ref ),
				$ref
			);
		}

		if ( $result instanceof WP_Error ) {
			$result = AbilityError::with_ref( $result, RequestContext::request_ref() );
			$this->audit_error( $result, $context );
			return $result;
		}

		ActivityLog::record(
			array_merge(
				$context->audit_columns(),
				array(
					'action'  => $this->audit_action(),
					'ability' => $this->name(),
					'result'  => 'success',
				)
			),
			$context->details
		);
		return $result;
	}

	/**
	 * Logs a refusal or error.
	 *
	 * @param WP_Error       $error   Error.
	 * @param AbilityContext $context Context.
	 */
	private function audit_error( WP_Error $error, AbilityContext $context ): void {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400;
		ActivityLog::record(
			array_merge(
				$context->audit_columns(),
				array(
					'action'     => $this->audit_action(),
					'ability'    => $this->name(),
					'result'     => $status >= 500 ? 'error' : 'rejected',
					'error_code' => (string) $error->get_error_code(),
					'message'    => AbilityError::plain( $error ),
				)
			),
			$context->details
		);
	}

	/**
	 * Clears the per-request refusal memory (tests).
	 */
	public static function reset_request_state(): void {
		self::$logged_refusals = array();
	}
}
