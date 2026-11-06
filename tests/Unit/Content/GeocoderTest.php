<?php
/**
 * Postcode handling (docs/05 "Visits location").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Content;

use Causeway\ClaudeBridge\Content\Geocoder;
use Causeway\ClaudeBridge\Tests\Unit\UnitTestCase;

/**
 * @covers \Causeway\ClaudeBridge\Content\Geocoder
 */
final class GeocoderTest extends UnitTestCase {

	/**
	 * Normalisation: uppercase, single space before the inward code.
	 *
	 * @dataProvider postcodes
	 *
	 * @param string $typed      As typed.
	 * @param string $normalised Expected.
	 * @param bool   $valid      Whether it's a valid shape.
	 */
	public function test_normalise_and_validate( string $typed, string $normalised, bool $valid ): void {
		$this->assertSame( $normalised, Geocoder::normalise( $typed ) );
		$this->assertSame( $valid, Geocoder::is_valid( Geocoder::normalise( $typed ) ) );
	}

	/**
	 * [typed, normalised, valid].
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function postcodes(): array {
		return array(
			'lower case, no space'   => array( 'wa11aa', 'WA1 1AA', true ),
			'extra spaces'           => array( ' WA1   1AA ', 'WA1 1AA', true ),
			'A9A format'             => array( 'W1A 1AA', 'W1A 1AA', true ),
			'AA9A format'            => array( 'SW1A1AA', 'SW1A 1AA', true ),
			'A99 format'             => array( 'M60 1NW', 'M60 1NW', true ),
			'Northern Ireland'       => array( 'BT1 5GS', 'BT1 5GS', true ),
			'too short'              => array( 'WA1', 'WA1', false ),
			'letters only'           => array( 'ABCDEF', 'ABC DEF', false ),
			'bad inward letters'     => array( 'WA1 1CI', 'WA1 1CI', false ),
			'words'                  => array( 'NOT A CODE', 'NOTAC ODE', false ),
		);
	}

	public function test_meta_value_matches_the_mp_visits_map_format(): void {
		\Brain\Monkey\Functions\when( 'wp_json_encode' )->alias( static fn ( $v, $f = 0 ) => json_encode( $v, $f ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertSame( '{"lat":53.390012,"lng":-2.597034}', Geocoder::to_meta( array( 'lat' => 53.3900123, 'lng' => -2.5970341 ) ) );
		$this->assertSame( '{"lat":53.0,"lng":-2.0}', Geocoder::to_meta( array( 'lat' => 53.0, 'lng' => -2.0 ) ), 'Always numbers, never strings' );
	}
}
