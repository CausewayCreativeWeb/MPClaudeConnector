<?php
/**
 * JSON encoding and decoding for the config.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

use stdClass;

/**
 * Converts between the PHP array form used in code and the JSON form that is stored and validated.
 *
 * PHP can't tell an empty list from an empty map, so maps that may be empty (`sections`,
 * `default_terms`, `field_rules`, `extra_fields`) are turned into objects before encoding.
 */
final class ConfigJson {

	/**
	 * Encodes a config array as JSON with maps kept as objects.
	 *
	 * @param array<string, mixed> $config Config.
	 */
	public static function encode( array $config ): string {
		$json = json_encode( self::to_object( $config ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- must work without WordPress (unit tests, validator).
		return false === $json ? '{}' : $json;
	}

	/**
	 * Decodes stored JSON into a config array. Returns null for anything that isn't a JSON object.
	 *
	 * @param string $json JSON text.
	 * @return array<string, mixed>|null
	 */
	public static function decode( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ( array() !== $data && array_is_list( $data ) ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Returns the config as nested stdClass/array values, the form the JSON Schema validator expects.
	 *
	 * @param array<string, mixed> $config Config.
	 */
	public static function to_object( array $config ): mixed {
		$copy = $config;

		if ( isset( $copy['sections'] ) && is_array( $copy['sections'] ) ) {
			foreach ( $copy['sections'] as $key => $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				foreach ( array( 'default_terms', 'field_rules', 'extra_fields' ) as $map ) {
					if ( isset( $section[ $map ] ) && array() === $section[ $map ] ) {
						$section[ $map ] = new stdClass();
					}
				}
				$copy['sections'][ $key ] = $section;
			}
			if ( array() === $copy['sections'] ) {
				$copy['sections'] = new stdClass();
			}
		}

		$json = json_encode( $copy, JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- see encode().
		return false === $json ? new stdClass() : json_decode( $json, false );
	}
}
