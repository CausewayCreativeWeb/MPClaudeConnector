<?php
/**
 * Image size and shape rules (docs/05 "Image pipeline" step 6).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Media;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use WP_Error;

/**
 * Featured images must meet the section's minimum width and ratio (±3%; `any` skips the ratio).
 * Images in the body must be at least 800px wide.
 */
final class ImageRules {

	public const CONTENT_MIN_WIDTH = 800;
	public const RATIO_TOLERANCE   = 0.03;

	/**
	 * Checks a featured image's dimensions against the section rules.
	 *
	 * @param int                  $width  Width.
	 * @param int                  $height Height.
	 * @param array<string, mixed> $rules  Section `featured_image` rules.
	 * @param string               $field  Input field for errors.
	 */
	public static function check_featured( int $width, int $height, array $rules, string $field ): ?WP_Error {
		if ( $rules['min_width'] > 0 && $width < $rules['min_width'] ) {
			return AbilityError::make(
				'ccb_field_invalid',
				/* translators: 1: actual width, 2: required width */
				sprintf( __( 'The featured image is %1$dpx wide; this section needs at least %2$dpx.', 'causeway-claude-bridge' ), $width, $rules['min_width'] ),
				400,
				$field,
				__( 'Ask for a larger image.', 'causeway-claude-bridge' )
			);
		}
		if ( 'any' !== $rules['ratio'] && ! self::ratio_matches( $width, $height, (string) $rules['ratio'] ) ) {
			return AbilityError::make(
				'ccb_field_invalid',
				/* translators: 1: width, 2: height, 3: required ratio */
				sprintf( __( 'The featured image is %1$d×%2$d; this section needs a %3$s image.', 'causeway-claude-bridge' ), $width, $height, $rules['ratio'] ),
				400,
				$field,
				__( 'Ask for an image with the right shape, or crop it.', 'causeway-claude-bridge' )
			);
		}
		return null;
	}

	/**
	 * Checks an image for use in the post body.
	 *
	 * @param int    $width Width.
	 * @param string $field Input field for errors.
	 */
	public static function check_content( int $width, string $field ): ?WP_Error {
		if ( $width >= self::CONTENT_MIN_WIDTH ) {
			return null;
		}
		return AbilityError::make(
			'ccb_field_invalid',
			/* translators: 1: actual width, 2: required width */
			sprintf( __( 'The image is %1$dpx wide; images in posts need to be at least %2$dpx.', 'causeway-claude-bridge' ), $width, self::CONTENT_MIN_WIDTH ),
			400,
			$field,
			__( 'Ask for a larger image.', 'causeway-claude-bridge' )
		);
	}

	/**
	 * Whether width/height is within tolerance of a ratio like `16:9`.
	 *
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param string $ratio  Ratio.
	 */
	public static function ratio_matches( int $width, int $height, string $ratio ): bool {
		$parts = array_map( 'intval', explode( ':', $ratio ) );
		if ( 2 !== count( $parts ) || $parts[0] <= 0 || $parts[1] <= 0 || $height <= 0 ) {
			return true;
		}
		$expected = $parts[0] / $parts[1];
		return abs( ( $width / $height ) - $expected ) / $expected <= self::RATIO_TOLERANCE;
	}
}
