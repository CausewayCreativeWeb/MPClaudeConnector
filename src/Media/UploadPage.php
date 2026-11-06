<?php
/**
 * The public page behind an upload link (docs/05 "One-time upload links").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Media;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use Causeway\ClaudeBridge\Abilities\Sections;
use WP_Error;

/**
 * Serves `/ccb-upload/<token>`. Works without a login: the secret, single-use, short-lived token is
 * the authorisation. The form POST also carries a nonce. Never cached or indexed, refused over plain HTTP.
 */
final class UploadPage {

	/**
	 * Hooks the page.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( UploadLinks::class, 'register_rewrite' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_serve' ), 0 );
	}

	/**
	 * Serves the page when the request is for an upload link.
	 */
	public static function maybe_serve(): void {
		$token = (string) get_query_var( UploadLinks::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}
		$response = self::respond( $token, isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] );
		self::send( $response['status'], $response['view'] );
		exit;
	}

	/**
	 * Works out what to show. Separate from output so it can be tested.
	 *
	 * @param string $token   Token from the URL.
	 * @param bool   $is_post Whether this is the form submission.
	 * @return array{status: int, view: array<string, mixed>}
	 */
	public static function respond( string $token, bool $is_post ): array {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache convention.
		}

		if ( ! is_ssl() ) {
			return self::message( 403, __( 'This link must be opened over a secure (https) connection.', 'causeway-claude-bridge' ) );
		}

		$link = UploadLinks::find_valid( $token );
		if ( null === $link ) {
			return self::message( 410, __( 'This upload link has expired or has already been used. Ask Claude for a new one.', 'causeway-claude-bridge' ) );
		}

		$view = self::form_view( $token, $link );
		if ( null === $view ) {
			return self::message( 410, __( 'This post can no longer receive uploads.', 'causeway-claude-bridge' ) );
		}
		if ( ! $is_post ) {
			return array(
				'status' => 200,
				'view'   => $view,
			);
		}

		$error = self::handle_post( $token, $link );
		if ( null === $error ) {
			return self::message( 200, __( 'Done. Go back to Claude and say it\'s uploaded.', 'causeway-claude-bridge' ), true );
		}
		// A failed upload doesn't use up the link.
		$view['error'] = AbilityError::plain( $error );
		$data          = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['fix'] ) ) {
			$view['error'] .= ' ' . $data['fix'];
		}
		return array(
			'status' => 400,
			'view'   => $view,
		);
	}

	/**
	 * Checks the nonce and the uploaded file, then hands it to UploadLinks.
	 *
	 * @param string               $token Token.
	 * @param array<string, mixed> $link  Link row.
	 */
	private static function handle_post( string $token, array $link ): ?WP_Error {
		$nonce = isset( $_POST['_ccb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ccb_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $link ) ) ) {
			return AbilityError::make( 'ccb_token_invalid', __( 'The page has expired. Reload it and try again.', 'causeway-claude-bridge' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file array; the path is checked with is_uploaded_file().
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( null === $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return AbilityError::make( 'ccb_invalid_input', __( 'Choose a file to upload.', 'causeway-claude-bridge' ), 400 );
		}
		if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return AbilityError::make( 'ccb_file_too_large', __( 'The file is bigger than this server accepts.', 'causeway-claude-bridge' ), 413, null, __( 'Use a smaller file.', 'causeway-claude-bridge' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return AbilityError::make( 'ccb_internal', __( 'The upload didn\'t arrive completely.', 'causeway-claude-bridge' ), 500, null, __( 'Try again.', 'causeway-claude-bridge' ) );
		}

		$path = TempDir::file();
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $path ) ) {
			return AbilityError::make( 'ccb_internal', __( 'The upload couldn\'t be saved.', 'causeway-claude-bridge' ), 500 );
		}
		$alt    = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$result = UploadLinks::handle( $token, $path, $alt );
		return $result instanceof WP_Error ? $result : null;
	}

	/**
	 * Data for the upload form.
	 *
	 * @param string               $token Token.
	 * @param array<string, mixed> $link  Link row.
	 * @return array<string, mixed>|null
	 */
	private static function form_view( string $token, array $link ): ?array {
		$sections = new Sections();
		$found    = $sections->post( (int) $link['post_id'] );
		if ( $found instanceof WP_Error ) {
			return null;
		}
		[ $post, $key ] = $found;
		$section        = $sections->all()[ $key ];
		$media          = $sections->config()['media'];
		$purpose        = (string) $link['purpose'];
		$accepts        = UploadLinks::accepts( $purpose, $media );

		if ( 'field_file' === $purpose ) {
			/* translators: %d: size limit in MB */
			$expected = sprintf( __( 'PDF up to %d MB.', 'causeway-claude-bridge' ), $accepts['max_mb'] );
		} elseif ( ImagePipeline::PURPOSE_FEATURED === $purpose ) {
			$rules    = $section['featured_image'];
			$shape    = 'any' === $rules['ratio'] ? __( 'any shape', 'causeway-claude-bridge' ) : ( '1:1' === $rules['ratio'] ? __( 'square', 'causeway-claude-bridge' ) : sprintf( /* translators: %s: ratio */ __( 'landscape %s', 'causeway-claude-bridge' ), $rules['ratio'] ) );
			$expected = $rules['min_width'] > 0
				/* translators: 1: shape, 2: minimum width, 3: size limit */
				? sprintf( __( 'Featured image: %1$s, at least %2$dpx wide, up to %3$d MB.', 'causeway-claude-bridge' ), $shape, $rules['min_width'], $accepts['max_mb'] )
				/* translators: 1: shape, 2: size limit */
				: sprintf( __( 'Featured image: %1$s, up to %2$d MB.', 'causeway-claude-bridge' ), $shape, $accepts['max_mb'] );
		} else {
			/* translators: 1: minimum width, 2: size limit */
			$expected = sprintf( __( 'Image for the post: at least %1$dpx wide, up to %2$d MB.', 'causeway-claude-bridge' ), ImageRules::CONTENT_MIN_WIDTH, $accepts['max_mb'] );
		}

		return array(
			'title'      => __( 'Upload a file', 'causeway-claude-bridge' ),
			'site'       => get_bloginfo( 'name' ),
			'post_title' => get_the_title( $post ),
			'expected'   => $expected,
			'accept'     => implode( ',', $accepts['accepts'] ),
			'is_image'   => 'field_file' !== $purpose,
			'nonce'      => wp_create_nonce( self::nonce_action( $link ) ),
			'action'     => UploadLinks::url( $token ),
			'error'      => null,
			'done'       => false,
		);
	}

	/**
	 * A page with just a message.
	 *
	 * @param int    $status HTTP status.
	 * @param string $text   Message.
	 * @param bool   $done   Whether it's the success message.
	 * @return array{status: int, view: array<string, mixed>}
	 */
	private static function message( int $status, string $text, bool $done = false ): array {
		return array(
			'status' => $status,
			'view'   => array(
				'title'   => $done ? __( 'Uploaded', 'causeway-claude-bridge' ) : __( 'Upload link', 'causeway-claude-bridge' ),
				'site'    => get_bloginfo( 'name' ),
				'message' => $text,
				'done'    => $done,
			),
		);
	}

	/**
	 * Nonce action tied to the link.
	 *
	 * @param array<string, mixed> $link Link row.
	 */
	private static function nonce_action( array $link ): string {
		return 'ccb-upload-' . substr( (string) $link['token_hash'], 0, 16 );
	}

	/**
	 * Sends headers and renders the template.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $view   Template data.
	 */
	private static function send( int $status, array $view ): void {
		$csp_nonce = bin2hex( random_bytes( 16 ) );
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'nonce-{$csp_nonce}'; script-src 'nonce-{$csp_nonce}'; form-action 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'" );

		$view['csp_nonce'] = $csp_nonce;
		require dirname( __DIR__, 2 ) . '/templates/upload-link.php';
	}
}
