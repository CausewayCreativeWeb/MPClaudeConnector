<?php
/**
 * One-time upload links (docs/05 "One-time upload links", "PDFs"; docs/06 `ccb_upload_links`).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Media;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use Causeway\ClaudeBridge\Abilities\Sections;
use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Config\PublishingGuide;
use Causeway\ClaudeBridge\Db\Schema;
use WP_Error;
use WP_Post;

/**
 * Links Claude hands to the user for large images and PDFs. Each is bound to one user, one post and
 * one purpose (and field), valid for `media.upload_link_minutes`, and used once: a failed upload
 * leaves it usable until it expires. Only the SHA-256 of the token is stored.
 */
final class UploadLinks {

	public const PURPOSES      = array( ImagePipeline::PURPOSE_FEATURED, ImagePipeline::PURPOSE_CONTENT, 'field_file' );
	public const PER_HOUR      = 10;
	public const QUERY_VAR     = 'ccb_upload';
	public const PATH          = 'ccb-upload';
	public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

	/**
	 * Registers the pretty URL `/ccb-upload/<token>` (and the `?ccb_upload=` fallback).
	 */
	public static function register_rewrite(): void {
		add_rewrite_rule( '^' . self::PATH . '/(' . self::TOKEN_PATTERN . ')/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_filter(
			'query_vars',
			static function ( array $vars ): array {
				$vars[] = self::QUERY_VAR;
				return $vars;
			}
		);
	}

	/**
	 * Links created by a user in the last hour.
	 *
	 * @param int $user_id User.
	 */
	public static function count_recent( int $user_id ): int {
		global $wpdb;
		$table = Schema::table( Schema::UPLOAD_LINKS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND created_at_gmt >= %s", $user_id, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
	}

	/**
	 * Creates a link.
	 *
	 * @param int         $user_id   User.
	 * @param int         $post_id   Post.
	 * @param string      $purpose   One of PURPOSES.
	 * @param string|null $field_key ACF field key for `field_file`.
	 * @param int         $minutes   Lifetime.
	 * @return array{token: string, url: string, expires_at_gmt: string}
	 */
	public static function create( int $user_id, int $post_id, string $purpose, ?string $field_key, int $minutes ): array {
		global $wpdb;
		$token   = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url token.
		$expires = time() + $minutes * MINUTE_IN_SECONDS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			Schema::table( Schema::UPLOAD_LINKS ),
			array(
				'token_hash'     => hash( 'sha256', $token ),
				'user_id'        => $user_id,
				'post_id'        => $post_id,
				'purpose'        => $purpose,
				'field_key'      => $field_key,
				'expires_at_gmt' => gmdate( 'Y-m-d H:i:s', $expires ),
				'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return array(
			'token'          => $token,
			'url'            => self::url( $token ),
			'expires_at_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
		);
	}

	/**
	 * Public URL for a token: pretty when permalinks are on, a query string otherwise. Always HTTPS.
	 *
	 * @param string $token Token.
	 */
	public static function url( string $token ): string {
		$url = get_option( 'permalink_structure' )
			? home_url( '/' . self::PATH . '/' . $token . '/' )
			: add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
		return set_url_scheme( $url, 'https' );
	}

	/**
	 * The link for a token if it exists, is unused and hasn't expired.
	 *
	 * @param string $token Token from the URL.
	 * @return array<string, mixed>|null
	 */
	public static function find_valid( string $token ): ?array {
		if ( 1 !== preg_match( '/^' . self::TOKEN_PATTERN . '$/', $token ) ) {
			return null;
		}
		global $wpdb;
		$table = Schema::table( Schema::UPLOAD_LINKS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_hash = %s", hash( 'sha256', $token ) ), ARRAY_A );
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], hash( 'sha256', $token ) ) ) {
			return null;
		}
		if ( null !== $row['used_at_gmt'] || strtotime( $row['expires_at_gmt'] . ' UTC' ) < time() ) {
			return null;
		}
		return $row;
	}

	/**
	 * What a link accepts, for the page and the ability output.
	 *
	 * @param string               $purpose Purpose.
	 * @param array<string, mixed> $media   Config `media` block.
	 * @return array{accepts: list<string>, max_mb: int}
	 */
	public static function accepts( string $purpose, array $media ): array {
		if ( 'field_file' === $purpose ) {
			return array(
				'accepts' => array( 'application/pdf' ),
				'max_mb'  => (int) $media['pdf_max_mb'],
			);
		}
		return array(
			'accepts' => array_values( (array) $media['image_types'] ),
			'max_mb'  => (int) floor( ( new ImagePipeline( $media ) )->max_link_bytes() / MB_IN_BYTES ),
		);
	}

	/**
	 * Processes a file uploaded through a link. On success the link is used up; on failure it stays
	 * usable. Acts as the link's owner for the duration, so the attachment and the activity row are theirs.
	 *
	 * @param string $token Token.
	 * @param string $path  Local path of the uploaded file (it is deleted).
	 * @param string $alt   Image description from the page (images only).
	 * @return array{attachment_id: int, url: string, post_id: int, purpose: string}|WP_Error
	 */
	public static function handle( string $token, string $path, string $alt = '' ): array|WP_Error {
		$link = self::find_valid( $token );
		if ( null === $link ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			return AbilityError::make( 'ccb_token_invalid', __( 'This upload link has expired or has already been used.', 'causeway-claude-bridge' ), 403, null, __( 'Ask Claude for a new link.', 'causeway-claude-bridge' ) );
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( (int) $link['user_id'] );
		try {
			$result = self::process( $link, $path, $alt );
		} finally {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		ActivityLog::record(
			array(
				'action'        => 'upload_link_used',
				'post_id'       => (int) $link['post_id'],
				'attachment_id' => $result instanceof WP_Error ? null : $result['attachment_id'],
				'result'        => $result instanceof WP_Error ? 'rejected' : 'success',
				'error_code'    => $result instanceof WP_Error ? (string) $result->get_error_code() : null,
				'message'       => $result instanceof WP_Error ? AbilityError::plain( $result ) : null,
			),
			array( 'purpose' => $link['purpose'] )
		);
		wp_set_current_user( $previous_user );

		if ( ! $result instanceof WP_Error ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->update(
				Schema::table( Schema::UPLOAD_LINKS ),
				array(
					'used_at_gmt'   => gmdate( 'Y-m-d H:i:s' ),
					'attachment_id' => $result['attachment_id'],
				),
				array( 'token_hash' => $link['token_hash'] ),
				array( '%s', '%d' ),
				array( '%s' )
			);
		}
		return $result;
	}

	/**
	 * Checks the post is still one the user may work on, then runs the image pipeline or the PDF checks.
	 *
	 * @param array<string, mixed> $link Link row.
	 * @param string               $path File.
	 * @param string               $alt  Image description.
	 * @return array{attachment_id: int, url: string, post_id: int, purpose: string}|WP_Error
	 */
	private static function process( array $link, string $path, string $alt ): array|WP_Error {
		$sections = new Sections();
		$found    = $sections->post( (int) $link['post_id'] );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		[ $post, $key ] = $found;
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return AbilityError::make( 'ccb_forbidden', __( 'You can no longer edit this post.', 'causeway-claude-bridge' ), 403 );
		}

		$config  = $sections->config();
		$section = $sections->all()[ $key ];
		$purpose = (string) $link['purpose'];

		if ( 'field_file' === $purpose ) {
			$id = self::import_pdf( $path, $post, (int) $config['media']['pdf_max_mb'] );
			if ( $id instanceof WP_Error ) {
				return $id;
			}
			$guide = PublishingGuide::build( $config, $sections->structure(), $key )['sections'][0];
			$names = array_column( $guide['fields'], 'name' );
			$acf   = array_column( $sections->structure()['acf']['fields_by_post_type'][ $section['post_type'] ] ?? array(), 'name', 'key' );
			$name  = $acf[ (string) $link['field_key'] ] ?? '';
			if ( ! in_array( $name, $names, true ) || ! function_exists( 'update_field' ) ) {
				return AbilityError::make( 'ccb_field_hidden', __( 'That file field is no longer available.', 'causeway-claude-bridge' ), 400 );
			}
			update_field( (string) $link['field_key'], $id, $post->ID );
		} else {
			$alt = trim( sanitize_text_field( $alt ) );
			if ( '' === $alt || mb_strlen( $alt ) > 125 ) {
				return AbilityError::make( 'ccb_invalid_input', __( 'Add a short description of the image (up to 125 characters) for people who can\'t see it.', 'causeway-claude-bridge' ), 400, 'alt' );
			}
			$pipeline = new ImagePipeline( $config['media'] );
			$image    = $pipeline->process( $path, $pipeline->max_link_bytes(), $purpose, $section, 'file' );
			if ( $image instanceof WP_Error ) {
				return $image;
			}
			$id = $pipeline->import( $image, $post->ID, $alt, '', 'ccb_upload_link' );
			if ( $id instanceof WP_Error ) {
				return $id;
			}
			if ( ImagePipeline::PURPOSE_FEATURED === $purpose ) {
				set_post_thumbnail( $post->ID, $id );
			}
		}

		return array(
			'attachment_id' => $id,
			'url'           => (string) wp_get_attachment_url( $id ),
			'post_id'       => $post->ID,
			'purpose'       => $purpose,
		);
	}

	/**
	 * Checks and imports a PDF: real PDF content, `%PDF-` header, size limit. Stored as uploaded,
	 * named after the post.
	 *
	 * @param string  $path   File.
	 * @param WP_Post $post   Post.
	 * @param int     $max_mb Limit in MB.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function import_pdf( string $path, WP_Post $post, int $max_mb ): int|WP_Error {
		$size = (int) filesize( $path );
		if ( $size > $max_mb * MB_IN_BYTES ) {
			/* translators: 1: size in MB, 2: limit in MB */
			return AbilityError::make( 'ccb_file_too_large', sprintf( __( 'The PDF is %1$s MB; the limit is %2$d MB.', 'causeway-claude-bridge' ), number_format_i18n( $size / MB_IN_BYTES, 1 ), $max_mb ), 413, null, __( 'Ask for a smaller PDF.', 'causeway-claude-bridge' ) );
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = false === $finfo ? false : finfo_file( $finfo, $path );
		if ( false !== $finfo ) {
			finfo_close( $finfo );
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading 5 bytes of a temp file.
		$header = false === $handle ? '' : (string) fread( $handle, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( false !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( 'application/pdf' !== $mime || '%PDF-' !== $header ) {
			return AbilityError::make( 'ccb_file_type', __( 'That file isn\'t a PDF.', 'causeway-claude-bridge' ), 415, null, __( 'Choose the PDF version of the document.', 'causeway-claude-bridge' ) );
		}

		ImagePipeline::load_media_functions();
		$base = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		$id   = media_handle_sideload(
			array(
				'name'     => ( '' === $base ? 'document-' . $post->ID : $base ) . '.pdf',
				'tmp_name' => $path,
				'size'     => (string) $size,
			),
			$post->ID
		);
		if ( $id instanceof WP_Error ) {
			return AbilityError::make( 'ccb_internal', __( 'WordPress couldn\'t add the PDF to the Media Library.', 'causeway-claude-bridge' ), 500, null, $id->get_error_message() );
		}
		update_post_meta( $id, '_ccb_source', 'ccb_upload_link' );
		return $id;
	}
}
