<?php
/**
 * Builds JPEGs carrying EXIF camera and GPS data, for the metadata-stripping tests.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Fixtures;

/**
 * Writes a JPEG of a given size with an APP1 Exif segment holding Make, Model and a GPS position.
 */
final class ExifFixture {

	/**
	 * Creates the file and returns its path.
	 *
	 * @param string $path   Where to write.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 */
	public static function jpeg_with_gps( string $path, int $width, int $height ): string {
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate( $image, 30, 120, 200 ) );
		ob_start();
		imagejpeg( $image, null, 90 );
		$jpeg = (string) ob_get_clean();
		imagedestroy( $image );

		// Insert APP1 straight after the SOI marker.
		file_put_contents( $path, substr( $jpeg, 0, 2 ) . self::app1() . substr( $jpeg, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}

	/**
	 * APP1 segment: "Exif\0\0" + little-endian TIFF with IFD0 (Make, Model, GPS pointer) and a GPS IFD.
	 */
	private static function app1(): string {
		$make  = "TestCam\0";
		$model = "Phone 9\0";

		// Layout: header(8) + IFD0 (2 + 3*12 + 4 = 42) + GPS IFD (2 + 4*12 + 4 = 54) + data.
		$ifd0_offset = 8;
		$gps_offset  = $ifd0_offset + 42;
		$data_offset = $gps_offset + 54;

		$make_offset  = $data_offset;
		$model_offset = $make_offset + strlen( $make );
		$lat_offset   = $model_offset + strlen( $model );
		$lng_offset   = $lat_offset + 24;

		$entry = static fn ( int $tag, int $type, int $count, string $value ): string => pack( 'vvV', $tag, $type, $count ) . str_pad( $value, 4, "\0" );

		$tiff  = 'II' . pack( 'vV', 42, $ifd0_offset );
		$tiff .= pack( 'v', 3 );
		$tiff .= $entry( 0x010F, 2, strlen( $make ), pack( 'V', $make_offset ) );
		$tiff .= $entry( 0x0110, 2, strlen( $model ), pack( 'V', $model_offset ) );
		$tiff .= $entry( 0x8825, 4, 1, pack( 'V', $gps_offset ) );
		$tiff .= pack( 'V', 0 );

		$tiff .= pack( 'v', 4 );
		$tiff .= $entry( 0x0001, 2, 2, "N\0" );
		$tiff .= $entry( 0x0002, 5, 3, pack( 'V', $lat_offset ) );
		$tiff .= $entry( 0x0003, 2, 2, "W\0" );
		$tiff .= $entry( 0x0004, 5, 3, pack( 'V', $lng_offset ) );
		$tiff .= pack( 'V', 0 );

		$tiff .= $make . $model;
		$tiff .= pack( 'VVVVVV', 53, 1, 23, 1, 2400, 100 ); // 53° 23' 24".
		$tiff .= pack( 'VVVVVV', 2, 1, 35, 1, 4920, 100 );  // 2° 35' 49.2".

		$payload = "Exif\0\0" . $tiff;
		return "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}
}
