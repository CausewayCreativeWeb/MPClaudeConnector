<?php
/**
 * Image pipeline (docs/05 "Image pipeline").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Media;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use WP_Error;
use WP_Image_Editor;

/**
 * Every image from upload-image or an upload link goes through here:
 * 1. strict base64 decoding (upload-image only);
 * 2. size limit;
 * 3. type checked by content (finfo and getimagesize must agree, and be an accepted type);
 * 4. written to the private temp folder under a random name;
 * 5. EXIF orientation applied, longest edge reduced to `max_edge_px` (never enlarged), all metadata
 *    removed by re-encoding, saved as WebP unless that's bigger than the stripped original format;
 * 6. section rules (featured: minimum width and ratio; body images: 800px);
 * 7–9. named from the post slug, imported into the Media Library with alt text and caption, and
 *    set as the featured image if asked.
 *
 * Output is checked for leftover EXIF, XMP or GPS before import; if any remains the image is refused.
 */
final class ImagePipeline {

	public const PURPOSE_FEATURED = 'featured_image';
	public const PURPOSE_CONTENT  = 'content_image';

	/**
	 * Config `media` block.
	 *
	 * @var array<string, mixed>
	 */
	private array $media;

	/**
	 * Sets up the pipeline.
	 *
	 * @param array<string, mixed> $media Config `media` block.
	 */
	public function __construct( array $media ) {
		$this->media = $media;
	}

	/**
	 * Largest upload through Claude, in bytes.
	 */
	public function max_bytes(): int {
		return (int) $this->media['max_upload_mb'] * MB_IN_BYTES;
	}

	/**
	 * Largest image through an upload link: three times the Claude limit, at most 15 MB.
	 */
	public function max_link_bytes(): int {
		return min( 3 * $this->max_bytes(), 15 * MB_IN_BYTES );
	}

	/**
	 * Decodes base64 image data into a temp file.
	 *
	 * @param string $data Base64, optionally as a data: URI.
	 * @return string|WP_Error Temp file path.
	 */
	public function decode( string $data ): string|WP_Error {
		$data    = (string) preg_replace( '/^data:[a-z0-9.+\/-]+;base64,/i', '', trim( $data ) );
		$data    = (string) preg_replace( '/\s+/', '', $data );
		$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding an uploaded image, strictly.
		if ( false === $decoded || '' === $decoded ) {
			return AbilityError::make( 'ccb_invalid_input', __( 'The image data isn\'t valid base64.', 'causeway-claude-bridge' ), 400, 'data_base64', __( 'Send the file again, or use an upload link.', 'causeway-claude-bridge' ) );
		}
		if ( strlen( $decoded ) > $this->max_bytes() ) {
			return self::too_large( strlen( $decoded ), $this->max_bytes(), true );
		}
		$path = TempDir::file();
		file_put_contents( $path, $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private temp folder.
		return $path;
	}

	/**
	 * Checks, cleans and resizes an image file. The input file is deleted.
	 *
	 * @param string               $path      Temp file.
	 * @param int                  $max_bytes Size limit.
	 * @param string               $purpose   PURPOSE_FEATURED or PURPOSE_CONTENT.
	 * @param array<string, mixed> $section   Section config (for featured rules), or empty.
	 * @param string               $field     Input field for errors.
	 * @return array{path: string, mime: string, width: int, height: int, bytes: int, extension: string}|WP_Error
	 */
	public function process( string $path, int $max_bytes, string $purpose, array $section, string $field ): array|WP_Error {
		try {
			$size = (int) filesize( $path );
			if ( $size > $max_bytes ) {
				return self::too_large( $size, $max_bytes, $max_bytes === $this->max_bytes() );
			}

			$mime = $this->detect_type( $path, $field );
			if ( $mime instanceof WP_Error ) {
				return $mime;
			}

			$output = $this->clean( $path, $mime, $field );
			if ( $output instanceof WP_Error ) {
				return $output;
			}

			$problem = self::PURPOSE_FEATURED === $purpose && array() !== $section
				? ImageRules::check_featured( $output['width'], $output['height'], $section['featured_image'], $field )
				: ImageRules::check_content( $output['width'], $field );
			if ( null !== $problem ) {
				wp_delete_file( $output['path'] );
				return $problem;
			}
			return $output;
		} finally {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Imports a processed image into the Media Library. The processed file is moved in.
	 *
	 * @param array{path: string, mime: string, width: int, height: int, bytes: int, extension: string} $image   Processed image.
	 * @param int                                                                                       $post_id Post to attach to, or 0.
	 * @param string                                                                                    $alt     Alt text.
	 * @param string                                                                                    $caption Caption.
	 * @param string                                                                                    $source  `ccb_upload` or `ccb_upload_link`.
	 * @return int|WP_Error Attachment ID.
	 */
	public function import( array $image, int $post_id, string $alt, string $caption, string $source ): int|WP_Error {
		self::load_media_functions();
		$name = self::file_name( $post_id, $image['extension'] );
		$id   = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $image['path'],
				'size'     => (string) $image['bytes'],
			),
			$post_id,
			null,
			array( 'post_excerpt' => $caption )
		);
		if ( file_exists( $image['path'] ) ) {
			wp_delete_file( $image['path'] );
		}
		if ( $id instanceof WP_Error ) {
			return AbilityError::make( 'ccb_internal', __( 'WordPress couldn\'t add the image to the Media Library.', 'causeway-claude-bridge' ), 500, null, $id->get_error_message() );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		update_post_meta( $id, '_ccb_source', $source );
		return $id;
	}

	/**
	 * Loads media_handle_sideload() and friends, which live in wp-admin and aren't loaded on REST or front-end
	 * requests (the MCP endpoint and the upload-link page).
	 */
	public static function load_media_functions(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Works out the real type from the file's content.
	 *
	 * @param string $path  File.
	 * @param string $field Input field for errors.
	 * @return string|WP_Error MIME type.
	 */
	private function detect_type( string $path, string $field ): string|WP_Error {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = false === $finfo ? false : finfo_file( $finfo, $path );
		if ( false !== $finfo ) {
			finfo_close( $finfo );
		}
		$info    = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-images are expected here and handled below.
		$allowed = (array) $this->media['image_types'];

		if ( false === $mime || false === $info || $info['mime'] !== $mime || ! in_array( $mime, $allowed, true ) ) {
			$hint = in_array( $mime, array( 'image/heic', 'image/heif' ), true )
				? __( 'iPhone photos: export as JPEG, or set Camera → Formats to "Most Compatible".', 'causeway-claude-bridge' )
				/* translators: %s: accepted image types */
				: sprintf( __( 'Use one of: %s.', 'causeway-claude-bridge' ), implode( ', ', array_map( static fn ( string $t ): string => strtoupper( substr( $t, 6 ) ), $allowed ) ) );
			return AbilityError::make( 'ccb_file_type', __( 'That file isn\'t an image type the site accepts.', 'causeway-claude-bridge' ), 415, $field, $hint );
		}
		return $mime;
	}

	/**
	 * Rotates, resizes, strips metadata and re-encodes.
	 *
	 * @param string $path  Source file.
	 * @param string $mime  Source type.
	 * @param string $field Input field for errors.
	 * @return array{path: string, mime: string, width: int, height: int, bytes: int, extension: string}|WP_Error
	 */
	private function clean( string $path, string $mime, string $field ): array|WP_Error {
		$editor = self::editor( $path, $mime );
		if ( $editor instanceof WP_Error ) {
			return AbilityError::make( 'ccb_file_type', __( 'The image couldn\'t be opened. It may be damaged.', 'causeway-claude-bridge' ), 415, $field, __( 'Try exporting it again as JPEG or PNG.', 'causeway-claude-bridge' ) );
		}

		// Apply the camera's rotation flag before it's stripped, or portrait photos come out sideways.
		$editor->maybe_exif_rotate();

		$size = $editor->get_size();
		$max  = (int) $this->media['max_edge_px'];
		if ( max( $size['width'], $size['height'] ) > $max ) {
			$editor->resize( $max, $max, false );
		}
		if ( $editor instanceof StrippingImagickEditor ) {
			$editor->strip();
		}

		$extension = self::extension( $mime );
		$original  = $editor->save( TempDir::file( $extension ), $mime );
		if ( $original instanceof WP_Error ) {
			return AbilityError::make( 'ccb_internal', __( 'The image couldn\'t be processed.', 'causeway-claude-bridge' ), 500, $field, $original->get_error_message() );
		}
		$chosen = $original;

		if ( 'image/webp' !== $mime && self::webp_supported() ) {
			$editor->set_quality( (int) $this->media['webp_quality'] );
			$webp = $editor->save( TempDir::file( 'webp' ), 'image/webp' );
			if ( ! $webp instanceof WP_Error ) {
				if ( filesize( $webp['path'] ) <= filesize( $original['path'] ) ) {
					wp_delete_file( $original['path'] );
					$chosen = $webp;
				} else {
					wp_delete_file( $webp['path'] );
				}
			}
		}

		if ( self::has_metadata( $chosen['path'], $chosen['mime-type'] ) ) {
			wp_delete_file( $chosen['path'] );
			return AbilityError::make( 'ccb_internal', __( 'Location and camera details couldn\'t be removed from the image, so it wasn\'t uploaded.', 'causeway-claude-bridge' ), 500, $field, __( 'Tell the site\'s support contact.', 'causeway-claude-bridge' ) );
		}

		clearstatcache( true, $chosen['path'] );
		return array(
			'path'      => $chosen['path'],
			'mime'      => $chosen['mime-type'],
			'width'     => (int) $chosen['width'],
			'height'    => (int) $chosen['height'],
			'bytes'     => (int) filesize( $chosen['path'] ),
			'extension' => self::extension( $chosen['mime-type'] ),
		);
	}

	/**
	 * Whether WebP output is available. Filterable so a host can switch WebP off if it misbehaves.
	 */
	public static function webp_supported(): bool {
		/**
		 * Filters whether the image pipeline saves WebP.
		 *
		 * @param bool $supported Whether an image editor on this server can write WebP.
		 */
		return (bool) apply_filters( 'ccb_webp_supported', wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) );
	}

	/**
	 * Image editor for the pipeline. GD is preferred because re-encoding with it always drops
	 * metadata; Imagick is used through a subclass that strips explicitly.
	 *
	 * @param string $path File.
	 * @param string $mime Type.
	 * @return WP_Image_Editor|WP_Error
	 */
	private static function editor( string $path, string $mime ): WP_Image_Editor|WP_Error {
		$editors = static function (): array {
			$list = array( 'WP_Image_Editor_GD' );
			if ( extension_loaded( 'imagick' ) ) {
				require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
				require_once ABSPATH . 'wp-includes/class-wp-image-editor-imagick.php';
				$list[] = StrippingImagickEditor::class;
			}
			return $list;
		};
		add_filter( 'wp_image_editors', $editors, 100 );
		$editor = wp_get_image_editor( $path, array( 'mime_type' => $mime ) );
		remove_filter( 'wp_image_editors', $editors, 100 );
		return $editor;
	}

	/**
	 * Whether an image file still carries EXIF, XMP or GPS data.
	 *
	 * @param string $path File.
	 * @param string $mime Type.
	 */
	public static function has_metadata( string $path, string $mime ): bool {
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		if ( str_contains( $bytes, 'http://ns.adobe.com/xap/1.0/' ) ) {
			return true;
		}
		switch ( $mime ) {
			case 'image/jpeg':
				if ( str_contains( $bytes, "Exif\0\0" ) ) {
					return true;
				}
				if ( function_exists( 'exif_read_data' ) ) {
					$exif = @exif_read_data( $path, null, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no EXIF is the expected case.
					return is_array( $exif ) && ( isset( $exif['GPS'] ) || isset( $exif['IFD0'] ) || isset( $exif['EXIF'] ) );
				}
				return false;
			case 'image/webp':
				return self::riff_has_chunk( $bytes, array( 'EXIF', 'XMP ' ) );
			case 'image/png':
				return str_contains( $bytes, 'eXIf' ) || str_contains( $bytes, 'iTXtXML:com.adobe.xmp' );
		}
		return false;
	}

	/**
	 * Whether a WebP (RIFF) file has any of the given chunks.
	 *
	 * @param string   $bytes  File contents.
	 * @param string[] $chunks FourCCs.
	 */
	private static function riff_has_chunk( string $bytes, array $chunks ): bool {
		$offset = 12;
		$length = strlen( $bytes );
		while ( $offset + 8 <= $length ) {
			$fourcc = substr( $bytes, $offset, 4 );
			$size   = (int) unpack( 'V', substr( $bytes, $offset + 4, 4 ) )[1];
			if ( in_array( $fourcc, $chunks, true ) ) {
				return true;
			}
			$offset += 8 + $size + ( $size % 2 );
		}
		return false;
	}

	/**
	 * File name from the post slug: `{slug}-{nn}.{ext}`, or `upload-{date}-{nn}.{ext}` without a post.
	 *
	 * @param int    $post_id   Post, or 0.
	 * @param string $extension Extension.
	 */
	private static function file_name( int $post_id, string $extension ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( null !== $post ) {
			$base  = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
			$base  = '' === $base ? 'post-' . $post_id : $base;
			$count = count( get_attached_media( 'image', $post_id ) ) + 1;
		} else {
			$base  = 'upload-' . gmdate( 'Ymd' );
			$count = 1;
		}
		// WordPress adds -1, -2… if the name is already taken.
		return sprintf( '%s-%02d.%s', mb_substr( $base, 0, 80 ), $count, $extension );
	}

	/**
	 * Extension for a type.
	 *
	 * @param string $mime Type.
	 */
	private static function extension( string $mime ): string {
		return match ( $mime ) {
			'image/png'  => 'png',
			'image/webp' => 'webp',
			default      => 'jpg',
		};
	}

	/**
	 * File too large.
	 *
	 * @param int  $bytes       Size.
	 * @param int  $max         Limit.
	 * @param bool $offer_link  Whether to suggest an upload link.
	 */
	private static function too_large( int $bytes, int $max, bool $offer_link ): WP_Error {
		return AbilityError::make(
			'ccb_file_too_large',
			/* translators: 1: file size in MB, 2: limit in MB */
			sprintf( __( 'The file is %1$s MB; the limit is %2$s MB.', 'causeway-claude-bridge' ), number_format_i18n( $bytes / MB_IN_BYTES, 1 ), number_format_i18n( $max / MB_IN_BYTES, 0 ) ),
			413,
			null,
			$offer_link
				? __( 'Call get-upload-link for this post and give the user the link to upload it directly.', 'causeway-claude-bridge' )
				: __( 'Ask for a smaller version of the file.', 'causeway-claude-bridge' )
		);
	}
}
