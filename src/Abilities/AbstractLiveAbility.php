<?php
/**
 * Base for the live-change ability pairs (docs/03 "Live-change abilities").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;
use WP_Error;

/**
 * `prepare-*` abilities check and summarise (read-only, return a token); the matching action needs
 * the token and is marked destructive so MCP clients ask before running it.
 */
abstract class AbstractLiveAbility extends AbstractAbility {

	/**
	 * Live action, one of the LiveChanges constants.
	 */
	abstract protected function action(): string;

	/**
	 * Whether this is the `prepare-*` half.
	 */
	abstract protected function is_prepare(): bool;

	/**
	 * Extra input properties for the action (e.g. publish_at, changes).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function extra_properties(): array {
		return array();
	}

	/**
	 * Required extra properties.
	 *
	 * @return list<string>
	 */
	protected function extra_required(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function requires_publish(): bool {
		return LiveChanges::needs_publish_capability( $this->action() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return $this->is_prepare() ? 'prepare' : $this->action();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function annotations(): array {
		return $this->is_prepare()
			? array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => false,
			)
			: array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		$properties = array_merge(
			array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			$this->extra_properties()
		);
		$required   = array_merge( array( 'post_id' ), $this->extra_required() );
		if ( ! $this->is_prepare() ) {
			$properties['token'] = array(
				'type'        => 'string',
				'pattern'     => '^[A-Za-z0-9_-]{43}$',
				'description' => __( 'The token from the matching prepare tool, sent only after the user clearly said yes to its summary.', 'causeway-claude-bridge' ),
			);
			$required[]          = 'token';
		}
		return array(
			'type'                 => 'object',
			'required'             => $required,
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		if ( $this->is_prepare() ) {
			return array(
				'type'       => 'object',
				'required'   => array( 'summary', 'token', 'expires_at_gmt' ),
				'properties' => array(
					'summary'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'      => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
							'section'     => array( 'type' => 'string' ),
							'from_status' => array( 'type' => 'string' ),
							'to_status'   => array( 'type' => 'string' ),
							'changes'     => array( 'type' => 'array' ),
							'notes'       => array( 'type' => 'array' ),
						),
					),
					'token'          => array( 'type' => 'string' ),
					'expires_at_gmt' => array( 'type' => 'string' ),
				),
			);
		}
		return array(
			'type'       => 'object',
			'required'   => array( 'post_id', 'status', 'verification' ),
			'properties' => array(
				'post_id'      => array( 'type' => 'integer' ),
				'status'       => array( 'type' => 'string' ),
				'url'          => array( 'type' => array( 'string', 'null' ) ),
				'verification' => array( 'type' => 'object' ),
				'warnings'     => array( 'type' => 'array' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param AbilityContext       $context Request context.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input, AbilityContext $context ): array|WP_Error {
		$service = new LiveChanges();
		return $this->is_prepare()
			? $service->prepare( $this->action(), $input, $context )
			: $service->apply( $this->action(), $input, $context );
	}
}
