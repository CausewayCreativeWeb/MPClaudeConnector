<?php
/**
 * UK postcode to latitude/longitude (docs/05 "Visits location"; docs/reference/mp-visits-findings.md).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use WP_Error;

/**
 * Looks a postcode up with postcodes.io, the same service and request form the MP Visits admin map
 * uses, and returns coordinates in the shape its map reads. This is the plugin's only outbound HTTP call.
 */
final class Geocoder {

	public const ENDPOINT = 'https://api.postcodes.io/postcodes/';
	public const CACHE    = 30 * DAY_IN_SECONDS;

	/**
	 * Full UK postcode: outward code (A9, A9A, A99, AA9, AA9A, AA99), space, inward code (9AA).
	 */
	private const PATTERN = '/^(GIR 0AA|[A-PR-UWYZ](?:[0-9][0-9A-HJKPS-UW]?|[A-HK-Y][0-9][0-9ABEHMNPRV-Y]?) [0-9][ABD-HJLNP-UW-Z]{2})$/';

	/**
	 * Mainland UK, Northern Ireland and islands.
	 */
	private const BOUNDS = array(
		'lat' => array( 49.8, 60.9 ),
		'lng' => array( -8.7, 1.8 ),
	);

	/**
	 * Uppercase with a single space before the inward code.
	 *
	 * @param string $postcode Postcode as typed.
	 */
	public static function normalise( string $postcode ): string {
		$compact = strtoupper( (string) preg_replace( '/\s+/', '', $postcode ) );
		if ( strlen( $compact ) < 5 ) {
			return $compact;
		}
		return substr( $compact, 0, -3 ) . ' ' . substr( $compact, -3 );
	}

	/**
	 * Whether a normalised postcode has the shape of a UK postcode.
	 *
	 * @param string $postcode Normalised postcode.
	 */
	public static function is_valid( string $postcode ): bool {
		return 1 === preg_match( self::PATTERN, $postcode );
	}

	/**
	 * Coordinates for a postcode.
	 *
	 * @param string $postcode Postcode as typed.
	 * @param string $field    Input field name, for errors.
	 * @return array{lat: float, lng: float}|WP_Error
	 */
	public function locate( string $postcode, string $field ): array|WP_Error {
		$normalised = self::normalise( $postcode );
		if ( ! self::is_valid( $normalised ) ) {
			return self::failed( __( 'That isn\'t a valid UK postcode.', 'causeway-claude-bridge' ), $field );
		}
		$compact = str_replace( ' ', '', $normalised );

		$cached = get_transient( 'ccb_geo_' . $compact );
		if ( is_array( $cached ) && isset( $cached['lat'], $cached['lng'] ) ) {
			return array(
				'lat' => (float) $cached['lat'],
				'lng' => (float) $cached['lng'],
			);
		}

		$response = wp_remote_get(
			self::ENDPOINT . rawurlencode( $compact ),
			array(
				'timeout'    => 5,
				'user-agent' => 'Claude Publishing Bridge/' . CCB_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::failed( __( 'The postcode lookup service couldn\'t be reached.', 'causeway-claude-bridge' ), $field, __( 'Try again in a minute.', 'causeway-claude-bridge' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['result']['latitude'], $body['result']['longitude'] ) ) {
			/* translators: %s: postcode */
			return self::failed( sprintf( __( 'Postcode %s wasn\'t found.', 'causeway-claude-bridge' ), $normalised ), $field );
		}

		$lat = round( (float) $body['result']['latitude'], 6 );
		$lng = round( (float) $body['result']['longitude'], 6 );
		if ( $lat < self::BOUNDS['lat'][0] || $lat > self::BOUNDS['lat'][1] || $lng < self::BOUNDS['lng'][0] || $lng > self::BOUNDS['lng'][1] ) {
			/* translators: %s: postcode */
			return self::failed( sprintf( __( 'Postcode %s is outside the UK.', 'causeway-claude-bridge' ), $normalised ), $field );
		}

		$location = array(
			'lat' => $lat,
			'lng' => $lng,
		);
		set_transient( 'ccb_geo_' . $compact, $location, self::CACHE );
		return $location;
	}

	/**
	 * The stored meta value MP Visits' map reads: `{"lat":<number>,"lng":<number>}`.
	 *
	 * @param array{lat: float, lng: float} $location Coordinates.
	 */
	public static function to_meta( array $location ): string {
		return (string) wp_json_encode(
			array(
				'lat' => round( $location['lat'], 6 ),
				'lng' => round( $location['lng'], 6 ),
			),
			JSON_PRESERVE_ZERO_FRACTION
		);
	}

	/**
	 * Geocoding error.
	 *
	 * @param string      $message Message.
	 * @param string      $field   Field.
	 * @param string|null $fix     Fix.
	 */
	private static function failed( string $message, string $field, ?string $fix = null ): WP_Error {
		return AbilityError::make( 'ccb_geocode_failed', $message, 422, $field, $fix ?? __( 'Ask the user to check the postcode of the place visited.', 'causeway-claude-bridge' ) );
	}
}
