<?php
/**
 * ACF and config-defined field writes (docs/05 "Fields").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use DateTimeImmutable;
use WP_Error;

/**
 * Validates field values by type, then writes them: ACF fields with update_field() by field key,
 * extra fields with update_post_meta(). Validation (including the postcode lookup) happens before
 * anything is written, so a bad value never leaves a half-saved post.
 *
 * Required fields aren't enforced here: a draft may be incomplete, and post verification flags it.
 * They are enforced at publish and schedule (M6).
 */
final class FieldWriter {

	/**
	 * Fields Claude may write for the section, from the publishing guide.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Section config.
	 *
	 * @var array<string, mixed>
	 */
	private array $section;

	/**
	 * ACF fields for the post type, from site discovery (including hidden ones).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $acf;

	/**
	 * Config `writing` block, for wysiwyg fields.
	 *
	 * @var array<string, mixed>
	 */
	private array $writing;

	/**
	 * Postcode lookup.
	 *
	 * @var Geocoder
	 */
	private Geocoder $geocoder;

	/**
	 * Sets up the writer.
	 *
	 * @param array<string, mixed>       $guide_section Section entry from the publishing guide.
	 * @param array<string, mixed>       $section       Section config.
	 * @param list<array<string, mixed>> $acf_fields    ACF fields for the post type from site discovery.
	 * @param array<string, mixed>       $writing       Config `writing` block.
	 * @param Geocoder|null              $geocoder      Postcode lookup.
	 */
	public function __construct( array $guide_section, array $section, array $acf_fields, array $writing, ?Geocoder $geocoder = null ) {
		$this->fields   = array_column( $guide_section['fields'], null, 'name' );
		$this->section  = $section;
		$this->acf      = array_column( $acf_fields, null, 'name' );
		$this->writing  = $writing;
		$this->geocoder = $geocoder ?? new Geocoder();
	}

	/**
	 * Validates the given fields. Returns what to write, or the first problem.
	 *
	 * @param array<string, mixed> $input Field name => value. Null clears a field.
	 * @return list<array{name: string, source: string, key: string, value: mixed}>|WP_Error
	 */
	public function prepare( array $input ): array|WP_Error {
		$writes = array();
		foreach ( $input as $name => $value ) {
			$name = (string) $name;
			$path = 'fields.' . $name;

			if ( ! isset( $this->fields[ $name ] ) ) {
				if ( isset( $this->acf[ $name ] ) ) {
					return AbilityError::make(
						'ccb_field_hidden',
						/* translators: %s: field label */
						sprintf( __( 'The "%s" field can only be changed in WP Admin.', 'causeway-claude-bridge' ), $this->acf[ $name ]['label'] ),
						400,
						$path,
						__( 'Leave this field out.', 'causeway-claude-bridge' )
					);
				}
				return AbilityError::make(
					'ccb_invalid_input',
					/* translators: %s: field name */
					sprintf( __( 'This section has no field called "%s".', 'causeway-claude-bridge' ), $name ),
					400,
					$path,
					__( 'Use the field names listed in the publishing guide.', 'causeway-claude-bridge' )
				);
			}

			$field = $this->fields[ $name ];
			if ( 'meta' === $field['source'] ) {
				$prepared = $this->prepare_meta( $name, $value, $path );
				$key      = $name;
			} else {
				$key      = (string) ( $this->acf[ $name ]['key'] ?? '' );
				$prepared = $this->prepare_acf( $field, $key, $value, $path );
			}
			if ( $prepared instanceof WP_Error ) {
				return $prepared;
			}

			$writes[] = array(
				'name'   => $name,
				'source' => (string) $field['source'],
				'key'    => $key,
				'value'  => $prepared['value'],
			);
		}
		return $writes;
	}

	/**
	 * Writes prepared values. Runs after the post has been inserted or updated (MP Visits' own
	 * save routine runs on save_post and must not see these values first).
	 *
	 * @param int                                                                  $post_id Post.
	 * @param list<array{name: string, source: string, key: string, value: mixed}> $writes  From prepare().
	 */
	public static function write( int $post_id, array $writes ): void {
		foreach ( $writes as $write ) {
			if ( 'meta' === $write['source'] ) {
				if ( null === $write['value'] ) {
					delete_post_meta( $post_id, $write['key'] );
				} else {
					update_post_meta( $post_id, $write['key'], wp_slash( $write['value'] ) );
				}
				continue;
			}
			if ( function_exists( 'update_field' ) ) {
				update_field( $write['key'], $write['value'], $post_id );
			}
		}
	}

	/**
	 * Validates a config-defined extra field (docs/05 "Extra fields").
	 *
	 * @param string $name  Meta key.
	 * @param mixed  $value Value.
	 * @param string $path  Input path.
	 * @return array{value: mixed}|WP_Error
	 */
	private function prepare_meta( string $name, mixed $value, string $path ): array|WP_Error {
		if ( null === $value ) {
			return array( 'value' => null );
		}
		$definition = $this->section['extra_fields'][ $name ];

		switch ( $definition['type'] ) {
			case 'text':
			case 'textarea':
				if ( ! is_string( $value ) ) {
					return self::invalid( $path, __( 'Must be text.', 'causeway-claude-bridge' ) );
				}
				$max   = 'text' === $definition['type'] ? 500 : 5000;
				$clean = 'text' === $definition['type'] ? sanitize_text_field( $value ) : sanitize_textarea_field( $value );
				if ( mb_strlen( $clean ) > $max ) {
					/* translators: %d: maximum characters */
					return self::invalid( $path, sprintf( __( 'Must be %d characters or fewer.', 'causeway-claude-bridge' ), $max ) );
				}
				return array( 'value' => $clean );

			case 'date':
				return is_string( $value ) && self::is_date( $value )
					? array( 'value' => $value )
					: self::invalid( $path, __( 'Must be a date as YYYY-MM-DD.', 'causeway-claude-bridge' ) );

			case 'select':
				return is_string( $value ) && in_array( $value, (array) ( $definition['choices'] ?? array() ), true )
					? array( 'value' => $value )
					/* translators: %s: allowed values */
					: self::invalid( $path, sprintf( __( 'Must be one of: %s.', 'causeway-claude-bridge' ), implode( ', ', (array) ( $definition['choices'] ?? array() ) ) ) );

			case 'uk_postcode_to_latlng':
				$postcode = is_array( $value ) ? ( $value['postcode'] ?? null ) : null;
				if ( ! is_string( $postcode ) || '' === trim( $postcode ) ) {
					return self::invalid( $path, __( 'Send the UK postcode as { "postcode": "…" }.', 'causeway-claude-bridge' ) );
				}
				$location = $this->geocoder->locate( $postcode, $path );
				return $location instanceof WP_Error ? $location : array( 'value' => Geocoder::to_meta( $location ) );
		}

		return self::invalid( $path, __( 'This field type isn\'t supported.', 'causeway-claude-bridge' ) );
	}

	/**
	 * Validates an ACF field value (docs/05 table).
	 *
	 * @param array<string, mixed> $field Guide field.
	 * @param string               $key   ACF field key.
	 * @param mixed                $value Value.
	 * @param string               $path  Input path.
	 * @return array{value: mixed}|WP_Error
	 */
	private function prepare_acf( array $field, string $key, mixed $value, string $path ): array|WP_Error {
		if ( null === $value ) {
			return array( 'value' => '' );
		}
		$definition = function_exists( 'acf_get_field' ) ? acf_get_field( $key ) : false;
		$definition = is_array( $definition ) ? $definition : array();
		$choices    = array_map( 'strval', array_keys( (array) ( $definition['choices'] ?? array() ) ) );

		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
				if ( ! is_string( $value ) ) {
					return self::invalid( $path, __( 'Must be text.', 'causeway-claude-bridge' ) );
				}
				$clean = 'text' === $field['type'] ? sanitize_text_field( $value ) : sanitize_textarea_field( $value );
				$max   = (int) ( $definition['maxlength'] ?? 0 );
				if ( $max > 0 && mb_strlen( $clean ) > $max ) {
					/* translators: %d: maximum characters */
					return self::invalid( $path, sprintf( __( 'Must be %d characters or fewer.', 'causeway-claude-bridge' ), $max ) );
				}
				return array( 'value' => $clean );

			case 'email':
				return is_string( $value ) && is_email( $value )
					? array( 'value' => sanitize_email( $value ) )
					: self::invalid( $path, __( 'Must be a valid email address.', 'causeway-claude-bridge' ) );

			case 'url':
				return is_string( $value ) && self::is_web_url( $value )
					? array( 'value' => esc_url_raw( $value ) )
					: self::invalid( $path, __( 'Must be a full web address starting with https:// or http://.', 'causeway-claude-bridge' ) );

			case 'wysiwyg':
				if ( ! is_string( $value ) ) {
					return self::invalid( $path, __( 'Must be HTML text.', 'causeway-claude-bridge' ) );
				}
				return array( 'value' => ( new ContentSanitiser( $this->writing ) )->sanitise( $value, '' )['html'] );

			case 'number':
				if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
					return self::invalid( $path, __( 'Must be a number.', 'causeway-claude-bridge' ) );
				}
				$number = 0 + $value;
				$min    = $definition['min'] ?? '';
				$max    = $definition['max'] ?? '';
				if ( ( '' !== $min && $number < (float) $min ) || ( '' !== $max && $number > (float) $max ) ) {
					/* translators: 1: minimum, 2: maximum */
					return self::invalid( $path, sprintf( __( 'Must be between %1$s and %2$s.', 'causeway-claude-bridge' ), '' === $min ? '…' : $min, '' === $max ? '…' : $max ) );
				}
				return array( 'value' => $number );

			case 'select':
			case 'radio':
				$multiple = 'select' === $field['type'] && ! empty( $definition['multiple'] );
				$values   = $multiple ? (array) $value : array( $value );
				foreach ( $values as $item ) {
					if ( ! is_scalar( $item ) || ! in_array( (string) $item, $choices, true ) ) {
						/* translators: %s: allowed values */
						return self::invalid( $path, sprintf( __( 'Must be one of: %s.', 'causeway-claude-bridge' ), implode( ', ', $choices ) ) );
					}
				}
				return array( 'value' => $multiple ? array_map( 'strval', $values ) : (string) $value );

			case 'checkbox':
				$values = is_array( $value ) ? $value : array( $value );
				foreach ( $values as $item ) {
					if ( ! is_scalar( $item ) || ! in_array( (string) $item, $choices, true ) ) {
						/* translators: %s: allowed values */
						return self::invalid( $path, sprintf( __( 'Each value must be one of: %s.', 'causeway-claude-bridge' ), implode( ', ', $choices ) ) );
					}
				}
				return array( 'value' => array_values( array_map( 'strval', $values ) ) );

			case 'true_false':
				return is_bool( $value )
					? array( 'value' => $value ? 1 : 0 )
					: self::invalid( $path, __( 'Must be true or false.', 'causeway-claude-bridge' ) );

			case 'date_picker':
				return is_string( $value ) && self::is_date( $value )
					? array( 'value' => str_replace( '-', '', $value ) )
					: self::invalid( $path, __( 'Must be a date as YYYY-MM-DD.', 'causeway-claude-bridge' ) );

			case 'image':
			case 'file':
				$id = is_int( $value ) ? $value : ( is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0 );
				if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || ( 'image' === $field['type'] && ! wp_attachment_is_image( $id ) ) ) {
					return self::invalid( $path, __( 'Must be the ID of a file in the Media Library. Upload it first.', 'causeway-claude-bridge' ) );
				}
				return array( 'value' => $id );

			case 'page_link':
			case 'post_object':
				$id = is_int( $value ) ? $value : 0;
				if ( 0 === $id && is_string( $value ) ) {
					$id = ctype_digit( $value ) ? (int) $value : url_to_postid( $value );
				}
				if ( $id > 0 && null !== get_post( $id ) && 'publish' === get_post_status( $id ) ) {
					return array( 'value' => $id );
				}
				if ( 'page_link' === $field['type'] && is_string( $value ) && self::is_web_url( $value ) && self::is_own_host( $value ) ) {
					return array( 'value' => esc_url_raw( $value ) );
				}
				return self::invalid( $path, __( 'Must be the ID or address of a published page or post on this site.', 'causeway-claude-bridge' ) );
		}

		return self::invalid( $path, __( 'This field type isn\'t supported.', 'causeway-claude-bridge' ) );
	}

	/**
	 * Whether a string is a real YYYY-MM-DD date.
	 *
	 * @param string $value Value.
	 */
	private static function is_date( string $value ): bool {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Whether a string is an http(s) URL.
	 *
	 * @param string $value Value.
	 */
	private static function is_web_url( string $value ): bool {
		return false !== filter_var( $value, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
	}

	/**
	 * Whether a URL points at this site.
	 *
	 * @param string $url URL.
	 */
	private static function is_own_host( string $url ): bool {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Invalid field value.
	 *
	 * @param string $path    Input path.
	 * @param string $message Reason.
	 */
	private static function invalid( string $path, string $message ): WP_Error {
		return AbilityError::make( 'ccb_field_invalid', $message, 400, $path );
	}
}
