<?php
/**
 * Imagick editor that can strip all metadata.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Media;

use WP_Image_Editor_Imagick;

/**
 * Only loaded when the Imagick extension is present (see ImagePipeline::editor()). WordPress's own
 * Imagick editor only strips metadata from thumbnails; the pipeline strips the main image too.
 */
class StrippingImagickEditor extends WP_Image_Editor_Imagick {

	/**
	 * Removes EXIF, IPTC, XMP and comments, keeping the colour profile out too.
	 */
	public function strip(): void {
		$this->image->stripImage();
	}
}
