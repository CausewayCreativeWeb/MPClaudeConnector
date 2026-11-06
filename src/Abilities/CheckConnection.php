<?php
/**
 * `ccb/check-connection` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Audit\RequestContext;
use Causeway\ClaudeBridge\Checks\PreFlight;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Vendor\WP\MCP\Core\McpAdapter;

/**
 * Run at the start of every session: who you are, which sections you can use, and anything wrong.
 */
final class CheckConnection extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/check-connection';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Check connection', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Call this first in every session, passing the skill version. Returns who you are logged in as, whether you can publish, the sections you can post to, and any problems. If "ok" is false, show the issues and the support contact to the user and stop.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'check';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'skill_version' => array(
					'type'        => 'string',
					'description' => __( 'Version of the Claude skill in use.', 'causeway-claude-bridge' ),
					'maxLength'   => 20,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'ok', 'user', 'versions', 'sections', 'issues' ),
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'user'       => array( 'type' => 'object' ),
				'credential' => array( 'type' => array( 'object', 'null' ) ),
				'versions'   => array( 'type' => 'object' ),
				'sections'   => array( 'type' => 'array' ),
				'issues'     => array( 'type' => 'array' ),
				'support'    => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param AbilityContext       $context Request context.
	 * @return array<string, mixed>
	 */
	protected function run( array $input, AbilityContext $context ): array {
		$user     = wp_get_current_user();
		$sections = new Sections();
		$config   = $sections->config();
		$issues   = ( new PreFlight() )->run( isset( $input['skill_version'] ) ? (string) $input['skill_version'] : null );
		$app      = RequestContext::app_password();

		$roles = (array) $user->roles;
		$role  = RoleManager::is_administrator( $user ) ? 'administrator' : (string) ( array_values( array_intersect( array( RoleManager::ROLE_PUBLISHER, RoleManager::ROLE_CONTRIBUTOR ), $roles ) )[0] ?? ( $roles[0] ?? '' ) );

		$context->details = array(
			'skill_version' => $input['skill_version'] ?? null,
			'issues'        => array_column( $issues, 'code' ),
		);

		return array(
			'ok'         => ! PreFlight::has_errors( $issues ),
			'user'       => array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'role'         => $role,
				'can_publish'  => current_user_can( RoleManager::CAP_PUBLISH ),
			),
			'credential' => null === $app ? null : array( 'name' => $app['name'] ),
			'versions'   => array(
				'plugin'       => CCB_VERSION,
				'adapter'      => McpAdapter::VERSION,
				'config'       => ( new ConfigRepository() )->version(),
				'latest_skill' => PreFlight::latest_skill_version(),
			),
			'sections'   => array_map(
				static fn ( string $key, array $section ): array => array(
					'key'   => $key,
					'label' => $section['label'],
				),
				array_keys( $sections->all() ),
				$sections->all()
			),
			'issues'     => $issues,
			'support'    => $config['support'],
		);
	}
}
