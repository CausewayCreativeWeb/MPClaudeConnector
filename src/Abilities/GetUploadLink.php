<?php
/**
 * `ccb/get-upload-link` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Config\PublishingGuide;
use Causeway\ClaudeBridge\Media\UploadLinks;
use WP_Error;

/**
 * A one-time link the user opens to upload a large image or a PDF straight to the post.
 */
final class GetUploadLink extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/get-upload-link';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Get upload link', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Creates a one-time link for the user to upload a file straight to a post: a featured image, an image for the body, or a PDF for a file field (PDFs always use a link). Give the user the link; it works once and expires in minutes. Afterwards call get-post to confirm the file arrived.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'upload_link_created';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'purpose' ),
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'purpose' => array(
					'type' => 'string',
					'enum' => UploadLinks::PURPOSES,
				),
				'field'   => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,64}$',
					'description' => __( 'The file field\'s name, for purpose field_file.', 'causeway-claude-bridge' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'url', 'expires_at_gmt', 'accepts', 'max_mb' ),
			'properties' => array(
				'url'            => array( 'type' => 'string' ),
				'expires_at_gmt' => array( 'type' => 'string' ),
				'accepts'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'max_mb'         => array( 'type' => 'integer' ),
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
		$sections = new Sections();
		$found    = $sections->post( (int) $input['post_id'] );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		[ $post, $key ] = $found;
		$context->about_post( $post, $key );
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return AbilityError::make( 'ccb_forbidden', __( 'You can\'t edit this post.', 'causeway-claude-bridge' ), 403, 'post_id' );
		}

		$purpose   = (string) $input['purpose'];
		$field_key = null;
		if ( 'field_file' === $purpose ) {
			$field_key = $this->file_field_key( $sections, $key, (string) ( $input['field'] ?? '' ) );
			if ( $field_key instanceof WP_Error ) {
				return $field_key;
			}
		}

		if ( UploadLinks::count_recent( get_current_user_id() ) >= UploadLinks::PER_HOUR ) {
			return AbilityError::make(
				'ccb_rate_limited',
				/* translators: %d: links per hour */
				sprintf( __( 'You can create up to %d upload links an hour.', 'causeway-claude-bridge' ), UploadLinks::PER_HOUR ),
				429,
				null,
				__( 'Wait a while, or reuse a link that hasn\'t been used yet.', 'causeway-claude-bridge' ),
				null,
				array( 'retry_after' => HOUR_IN_SECONDS )
			);
		}

		$media   = $sections->config()['media'];
		$link    = UploadLinks::create( get_current_user_id(), $post->ID, $purpose, $field_key, (int) $media['upload_link_minutes'] );
		$accepts = UploadLinks::accepts( $purpose, $media );

		$context->details = array(
			'purpose' => $purpose,
			'field'   => $input['field'] ?? null,
		);

		return array(
			'url'            => $link['url'],
			'expires_at_gmt' => $link['expires_at_gmt'],
			'accepts'        => $accepts['accepts'],
			'max_mb'         => $accepts['max_mb'],
		);
	}

	/**
	 * ACF key of a writable file field in the section.
	 *
	 * @param Sections $sections Sections.
	 * @param string   $key      Section key.
	 * @param string   $field    Field name.
	 * @return string|WP_Error
	 */
	private function file_field_key( Sections $sections, string $key, string $field ): string|WP_Error {
		$guide   = PublishingGuide::build( $sections->config(), $sections->structure(), $key )['sections'][0];
		$fields  = array_column( $guide['fields'], null, 'name' );
		$section = $sections->all()[ $key ];
		$acf     = array_column( $sections->structure()['acf']['fields_by_post_type'][ $section['post_type'] ] ?? array(), null, 'name' );

		if ( '' === $field || ! isset( $fields[ $field ], $acf[ $field ] ) || 'file' !== $fields[ $field ]['type'] ) {
			$files = array_keys( array_filter( $fields, static fn ( array $f ): bool => 'file' === $f['type'] ) );
			return AbilityError::make(
				'ccb_invalid_input',
				__( 'A PDF upload link needs the name of a file field in this section.', 'causeway-claude-bridge' ),
				400,
				'field',
				array() === $files
					? __( 'This section has no file fields.', 'causeway-claude-bridge' )
					/* translators: %s: field names */
					: sprintf( __( 'Use: %s.', 'causeway-claude-bridge' ), implode( ', ', $files ) )
			);
		}
		return (string) $acf[ $field ]['key'];
	}
}
