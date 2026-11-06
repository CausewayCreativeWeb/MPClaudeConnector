<?php
/**
 * Config validation (docs/04-configuration.md, "Validation rules").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

use Causeway\ClaudeBridge\Vendor\Opis\JsonSchema\Errors\ValidationError;
use Causeway\ClaudeBridge\Vendor\Opis\JsonSchema\Validator;
use SplObjectStorage;

/**
 * Validates a config against `schema/config.schema.json`, then applies the rules that compare
 * several values. Checks against the live site (post types, terms, ACF fields) are added in M2.
 */
final class SchemaValidator {

	private const SCHEMA_ID = 'https://causewaycreative.co.uk/schemas/causeway-claude-bridge/config/1.json';

	/**
	 * JSON Schema validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Errors raised by a propertyNames rule during the current validation.
	 *
	 * @var SplObjectStorage<ValidationError, null>
	 */
	private SplObjectStorage $property_name_errors;

	/**
	 * Sets up the JSON Schema validator.
	 *
	 * @param string|null $schema_file Path to the schema; defaults to the bundled one.
	 */
	public function __construct( ?string $schema_file = null ) {
		$schema_file   ??= dirname( __DIR__, 2 ) . '/schema/config.schema.json';
		$this->validator = new Validator();
		$this->validator->setStopAtFirstError( false );
		$this->validator->setMaxErrors( 100 );
		$this->validator->resolver()?->registerFile( self::SCHEMA_ID, $schema_file );
		$this->property_name_errors = new SplObjectStorage();
	}

	/**
	 * Validates a config.
	 *
	 * @param array<string, mixed> $config Config in array form.
	 */
	public function validate( array $config ): ValidationResult {
		$result                     = new ValidationResult();
		$this->property_name_errors = new SplObjectStorage();

		$schema_result = $this->validator->validate( ConfigJson::to_object( $config ), self::SCHEMA_ID );
		$error         = $schema_result->error();
		if ( null !== $error ) {
			foreach ( $this->leaf_errors( $error ) as $leaf ) {
				$result->add_error( $this->path( $leaf ), 'schema_' . $leaf->keyword(), $this->message( $leaf ) );
			}
		}

		// Cross-value rules only make sense once the shape is right.
		if ( $result->is_valid() ) {
			$this->check_cross_rules( $config, $result );
		}

		return $result;
	}

	/**
	 * Rules that JSON Schema can't express (docs/04).
	 *
	 * @param array<string, mixed> $config Config that already matches the schema.
	 * @param ValidationResult     $result Collects problems.
	 */
	private function check_cross_rules( array $config, ValidationResult $result ): void {
		$post_types = array();

		foreach ( $config['sections'] as $key => $section ) {
			$base = '/sections/' . $key;

			if ( isset( $post_types[ $section['post_type'] ] ) ) {
				$result->add_error(
					$base . '/post_type',
					'post_type_shared',
					sprintf(
						/* translators: 1: post type, 2: other section key */
						__( 'Post type "%1$s" is already used by the "%2$s" section. Each section needs its own post type.', 'causeway-claude-bridge' ),
						$section['post_type'],
						$post_types[ $section['post_type'] ]
					)
				);
			} else {
				$post_types[ $section['post_type'] ] = (string) $key;
			}

			$limits = $section['limits'];
			if ( $limits['max_words'] > 0 && $limits['min_words'] > $limits['max_words'] ) {
				$result->add_error(
					$base . '/limits/min_words',
					'min_over_max',
					__( 'Minimum words can\'t be more than maximum words.', 'causeway-claude-bridge' )
				);
			}
		}

		$recipients = $config['notifications']['recipients'];
		if ( ! $recipients['publishers'] && array() === $recipients['extra'] ) {
			$result->add_error(
				'/notifications/recipients',
				'no_recipients',
				__( 'Choose at least one notification recipient: Publishers or an extra email address.', 'causeway-claude-bridge' )
			);
		}
	}

	/**
	 * Flattens Opis' error tree to the errors that point at a specific value.
	 *
	 * @param ValidationError $error Root error.
	 * @return list<ValidationError>
	 */
	private function leaf_errors( ValidationError $error ): array {
		$sub = $error->subErrors();
		if ( array() === $sub ) {
			return $this->is_spurious( $error ) ? array() : array( $error );
		}
		$leaves = array();
		foreach ( $sub as $child ) {
			if ( 'propertyNames' === $error->keyword() ) {
				$this->property_name_errors->attach( $child );
			}
			array_push( $leaves, ...$this->leaf_errors( $child ) );
		}
		return $leaves;
	}

	/**
	 * Whether the error is about a key name (it sits directly under a propertyNames error).
	 *
	 * @param ValidationError $error Error.
	 */
	private function is_property_name_error( ValidationError $error ): bool {
		return $this->property_name_errors->contains( $error );
	}

	/**
	 * Opis quirk: when a `properties` check fails it never marks the properties as checked, so
	 * `additionalProperties: false` then reports every declared property as unknown. Keep only
	 * properties the schema doesn't declare.
	 *
	 * @param ValidationError $error Leaf error.
	 */
	private function is_spurious( ValidationError $error ): bool {
		return 'additionalProperties' === $error->keyword() && array() === $this->unknown_properties( $error );
	}

	/**
	 * Properties an additionalProperties error lists that the schema doesn't declare.
	 *
	 * @param ValidationError $error Error.
	 * @return list<string>
	 */
	private function unknown_properties( ValidationError $error ): array {
		$schema   = $error->schema()->info()->data();
		$declared = is_object( $schema ) && isset( $schema->properties ) && is_object( $schema->properties )
			? array_keys( get_object_vars( $schema->properties ) )
			: array();
		$args     = $error->args();
		$listed   = is_array( $args['properties'] ?? null ) ? $args['properties'] : array();

		return array_values( array_map( 'strval', array_diff( $listed, $declared ) ) );
	}

	/**
	 * JSON-pointer-style path for an error. Additional-property errors point at the unknown key.
	 *
	 * @param ValidationError $error Error.
	 */
	private function path( ValidationError $error ): string {
		$segments = array_map( 'strval', $error->data()->fullPath() );
		$args     = $error->args();

		if ( 'additionalProperties' === $error->keyword() ) {
			$unknown = $this->unknown_properties( $error );
			if ( array() !== $unknown ) {
				$segments[] = $unknown[0];
			}
		}
		// A bad key name (propertyNames) is reported against the object; point at the key instead.
		if ( 'string' === $error->data()->type() && is_string( $error->data()->value() ) && $this->is_property_name_error( $error ) ) {
			$segments[] = $error->data()->value();
		}
		if ( 'required' === $error->keyword() && ! empty( $args['missing'] ) && is_array( $args['missing'] ) ) {
			$segments[] = (string) reset( $args['missing'] );
		}

		return '/' . implode( '/', $segments );
	}

	/**
	 * Plain-English message for a schema error.
	 *
	 * @param ValidationError $error Error.
	 */
	private function message( ValidationError $error ): string {
		$args = $error->args();
		$list = static fn ( mixed $value ): string => is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment

		switch ( $error->keyword() ) {
			case 'required':
				/* translators: %s: missing setting names */
				return sprintf( __( 'Missing setting: %s.', 'causeway-claude-bridge' ), $list( $args['missing'] ?? '' ) );
			case 'additionalProperties':
				/* translators: %s: unknown setting names */
				return sprintf( __( 'Unknown setting: %s.', 'causeway-claude-bridge' ), $list( $this->unknown_properties( $error ) ) );
			case 'minimum':
				/* translators: %s: minimum value */
				return sprintf( __( 'Must be at least %s.', 'causeway-claude-bridge' ), $list( $args['min'] ?? '' ) );
			case 'maximum':
				/* translators: %s: maximum value */
				return sprintf( __( 'Must be %s or less.', 'causeway-claude-bridge' ), $list( $args['max'] ?? '' ) );
			case 'minLength':
				/* translators: %s: minimum length */
				return sprintf( __( 'Must be at least %s characters.', 'causeway-claude-bridge' ), $list( $args['min'] ?? '' ) );
			case 'maxLength':
				/* translators: %s: maximum length */
				return sprintf( __( 'Must be %s characters or fewer.', 'causeway-claude-bridge' ), $list( $args['max'] ?? '' ) );
			case 'minItems':
				/* translators: %s: minimum number of items */
				return sprintf( __( 'Choose at least %s.', 'causeway-claude-bridge' ), $list( $args['min'] ?? '' ) );
			case 'maxItems':
				/* translators: %s: maximum number of items */
				return sprintf( __( 'Choose %s or fewer.', 'causeway-claude-bridge' ), $list( $args['max'] ?? '' ) );
			case 'uniqueItems':
				return __( 'Each value can only appear once.', 'causeway-claude-bridge' );
			case 'pattern':
				return __( 'Not in the expected format.', 'causeway-claude-bridge' );
			case 'format':
				if ( 'email' === ( $args['format'] ?? '' ) ) {
					return __( 'Must be a valid email address.', 'causeway-claude-bridge' );
				}
				return __( 'Must be a valid web address.', 'causeway-claude-bridge' );
			case 'enum':
				return __( 'Not one of the allowed values.', 'causeway-claude-bridge' );
			case 'const':
				return __( 'This value can\'t be changed.', 'causeway-claude-bridge' );
			case 'type':
				/* translators: %s: expected type, e.g. integer */
				return sprintf( __( 'Must be a %s.', 'causeway-claude-bridge' ), $list( $args['expected'] ?? '' ) );
			default:
				return $error->message();
		}
	}
}
