<?php
/**
 * Images, upload links and PDFs (docs/05 media sections, docs/09 "Uploads", docs/11).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Media\ImagePipeline;
use Causeway\ClaudeBridge\Media\UploadLinks;
use Causeway\ClaudeBridge\Media\UploadPage;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Tests\Fixtures\ExifFixture;

require_once dirname( __DIR__ ) . '/fixtures/ExifFixture.php';

/**
 * @covers \Causeway\ClaudeBridge\Abilities\UploadImage
 * @covers \Causeway\ClaudeBridge\Abilities\GetUploadLink
 * @covers \Causeway\ClaudeBridge\Media\ImagePipeline
 * @covers \Causeway\ClaudeBridge\Media\UploadLinks
 * @covers \Causeway\ClaudeBridge\Media\UploadPage
 */
final class MediaTest extends McpTestCase {

	private int $news_post;

	public function set_up(): void {
		parent::set_up();
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->news_post = self::factory()->post->create(
			array(
				'post_title'  => 'Crossing reopens',
				'post_name'   => 'crossing-reopens',
				'post_status' => 'draft',
			)
		);
	}

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * A generated image as a temp file.
	 *
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param string $type   jpeg or png.
	 * @param bool   $noisy  Add random pixels, like a photo.
	 */
	private static function generated( int $width, int $height, string $type = 'jpeg', bool $noisy = false ): string {
		$path  = get_temp_dir() . 'ccb-media-' . wp_generate_password( 8, false ) . '.' . $type;
		$image = imagecreatetruecolor( $width, $height );
		for ( $x = 0; $x < $width; $x += 40 ) {
			imagefilledrectangle( $image, $x, 0, $x + 20, $height, (int) imagecolorallocate( $image, $x % 255, 90, 160 ) );
		}
		if ( $noisy ) {
			for ( $i = 0; $i < $width * $height / 4; $i++ ) {
				imagesetpixel( $image, wp_rand( 0, $width - 1 ), wp_rand( 0, $height - 1 ), (int) imagecolorallocate( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
			}
		}
		'png' === $type ? imagepng( $image, $path ) : imagejpeg( $image, $path, 90 );
		imagedestroy( $image );
		return $path;
	}

	/**
	 * Base64 of a file.
	 *
	 * @param string $path File.
	 */
	private static function b64( string $path ): string {
		return base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Error code in a failed result.
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	private static function code( array $result ): string {
		return preg_match( '/code: (ccb_[a-z_]+)/', self::text( $result ), $m ) ? $m[1] : '';
	}

	/**
	 * Uploads through upload-image.
	 *
	 * @param string               $path  File.
	 * @param array<string, mixed> $extra Extra input.
	 * @return array<string, mixed> Tool result.
	 */
	private function upload( string $path, array $extra = array() ): array {
		return $this->call_tool(
			'ccb-upload-image',
			array_merge(
				array(
					'filename'    => basename( $path ),
					'data_base64' => self::b64( $path ),
					'alt'         => 'Pupils at Riverside Primary',
				),
				$extra
			)
		);
	}

	public function test_photo_with_gps_becomes_webp_without_metadata_and_is_featured(): void {
		$source = ExifFixture::jpeg_with_gps( get_temp_dir() . 'ccb-gps.jpg', 1600, 900 );
		$this->assertArrayHasKey( 'GPS', (array) exif_read_data( $source, null, true ), 'Fixture has GPS' );

		$data = $this->data(
			$this->upload(
				$source,
				array(
					'post_id'      => $this->news_post,
					'set_featured' => true,
					'caption'      => 'Riverside Primary',
				)
			)
		);

		$file = (string) get_attached_file( $data['attachment_id'] );
		$this->assertSame( 'webp', $data['format'] );
		$this->assertSame( 'image/webp', get_post_mime_type( $data['attachment_id'] ) );
		$this->assertMatchesRegularExpression( '/^crossing-reopens-01(-\d+)?\.webp$/', basename( $file ), 'Named from the post slug, never from the uploaded file name' );
		$this->assertSame( array( 1600, 900 ), array( $data['width'], $data['height'] ) );
		$this->assertFalse( ImagePipeline::has_metadata( $file, 'image/webp' ) );
		$this->assertStringNotContainsString( 'TestCam', (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( $data['attachment_id'], (int) get_post_thumbnail_id( $this->news_post ) );
		$this->assertSame( $this->news_post, wp_get_post_parent_id( $data['attachment_id'] ) );
		$this->assertSame( 'Pupils at Riverside Primary', get_post_meta( $data['attachment_id'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Riverside Primary', get_post_field( 'post_excerpt', $data['attachment_id'] ) );
		$this->assertSame( 'ccb_upload', get_post_meta( $data['attachment_id'], '_ccb_source', true ) );

		$row = $this->activity( 1 )[0];
		$this->assertSame( 'upload_image', $row['action'] );
		$this->assertSame( (string) $data['attachment_id'], (string) $row['attachment_id'] );
		$this->assertStringNotContainsString( 'data_base64', (string) $row['details'] );
	}

	public function test_without_webp_support_jpeg_is_stripped_too(): void {
		$source = ExifFixture::jpeg_with_gps( get_temp_dir() . 'ccb-gps2.jpg', 1000, 750 );
		add_filter( 'ccb_webp_supported', '__return_false' );

		$pipeline = new ImagePipeline( ( new ConfigRepository() )->get()['media'] );
		$copy     = get_temp_dir() . 'ccb-gps2-copy.jpg';
		copy( $source, $copy );
		$image = $pipeline->process( $copy, $pipeline->max_bytes(), ImagePipeline::PURPOSE_CONTENT, array(), 'file' );
		remove_filter( 'ccb_webp_supported', '__return_false' );

		$this->assertIsArray( $image );
		$this->assertSame( 'image/jpeg', $image['mime'] );
		$exif = @exif_read_data( $image['path'], null, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->assertFalse( isset( $exif['GPS'] ) || isset( $exif['IFD0'] ), 'No GPS or camera data left' );
		$this->assertFalse( file_exists( $copy ), 'The input temp file is deleted' );
		wp_delete_file( $image['path'] );
	}

	public function test_png_is_accepted_and_large_images_are_reduced(): void {
		$photo = self::generated( 1000, 600, 'png', true );
		$this->assertSame( 'webp', $this->data( $this->upload( $photo ) )['format'], 'Photo-like PNG: WebP is smaller' );

		$flat = $this->data( $this->upload( self::generated( 1000, 600, 'png' ) ) );
		$this->assertSame( 'png', $flat['format'], 'Flat graphic: kept as PNG because WebP would be bigger' );

		$large = $this->data( $this->upload( DIR_TESTDATA . '/images/test-image-large.jpg' ) );
		$this->assertSame( array( 2000, 1500 ), array( $large['width'], $large['height'] ), 'Longest edge 2000, never enlarged' );
	}

	public function test_portrait_phone_photos_are_turned_upright_before_stripping(): void {
		$data = $this->data( $this->upload( DIR_TESTDATA . '/images/test-image-rotated-90ccw.jpg' ) );

		$this->assertSame( array( 1800, 1200 ), array( $data['width'], $data['height'] ) );
	}

	/**
	 * Files that aren't accepted images are refused by content, whatever their name.
	 *
	 * @dataProvider rejected_files
	 *
	 * @param callable $make Returns a file path.
	 */
	public function test_unaccepted_files_are_refused_by_content( callable $make ): void {
		$result = $this->upload( $make() );

		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'ccb_file_type', self::code( $result ) );
	}

	/**
	 * Files to reject.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function rejected_files(): array {
		$write = static function ( string $name, string $contents ): string {
			$path = get_temp_dir() . $name;
			file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return $path;
		};
		return array(
			'SVG'                => array( static fn (): string => $write( 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="900" height="900"/></svg>' ) ),
			'PHP renamed to jpg' => array( static fn (): string => $write( 'shell.jpg', '<?php echo "pwned"; ?>' ) ),
			'GIF'                => array( static fn (): string => DIR_TESTDATA . '/images/test-image.gif' ),
			'HEIC'               => array( static fn (): string => DIR_TESTDATA . '/images/test-image.heic' ),
			'PDF as an image'    => array( static fn (): string => DIR_TESTDATA . '/images/test-alpha.pdf' ),
		);
	}

	public function test_heic_gets_an_iphone_hint(): void {
		$this->assertStringContainsString( 'Most Compatible', self::text( $this->upload( DIR_TESTDATA . '/images/test-image.heic' ) ) );
	}

	public function test_polyglot_jpeg_php_is_re_encoded_without_the_code(): void {
		$path = self::generated( 1000, 600 );
		file_put_contents( $path, '<?php system($_GET["c"]); ?>', FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$data = $this->data( $this->upload( $path ) );

		$stored = (string) file_get_contents( (string) get_attached_file( $data['attachment_id'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( '<?php', $stored );
		$this->assertStringEndsNotWith( '.php', (string) get_attached_file( $data['attachment_id'] ) );
	}

	public function test_size_and_shape_rules(): void {
		$square = $this->upload( self::generated( 1200, 1200 ), array( 'post_id' => $this->news_post, 'set_featured' => true ) );
		$this->assertSame( 'ccb_field_invalid', self::code( $square ), 'News needs 16:9' );

		$narrow = $this->upload( self::generated( 1000, 563 ), array( 'post_id' => $this->news_post, 'set_featured' => true ) );
		$this->assertSame( 'ccb_field_invalid', self::code( $narrow ), 'News needs 1200px wide' );

		$small = $this->upload( self::generated( 640, 480 ) );
		$this->assertSame( 'ccb_field_invalid', self::code( $small ), 'Body images need 800px' );

		$orphan = $this->upload( self::generated( 1600, 900 ), array( 'set_featured' => true ) );
		$this->assertSame( 'ccb_invalid_input', self::code( $orphan ) );
	}

	public function test_too_large_and_invalid_data(): void {
		$repository                       = new ConfigRepository();
		$config                           = $repository->get();
		$config['media']['max_upload_mb'] = 1;
		$this->assertIsArray( $repository->save( $config, 1, 'ui' ) );

		$big = get_temp_dir() . 'ccb-big.jpg';
		file_put_contents( $big, str_repeat( 'x', 1100000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = $this->upload( $big, array( 'post_id' => $this->news_post ) );
		$this->assertSame( 'ccb_file_too_large', self::code( $result ) );
		$this->assertStringContainsString( 'get-upload-link', self::text( $result ), 'An upload link is offered' );

		$bad = $this->call_tool( 'ccb-upload-image', array( 'filename' => 'x.jpg', 'data_base64' => 'not*base64!', 'alt' => 'x' ) );
		$this->assertSame( 'ccb_invalid_input', self::code( $bad ) );
	}

	public function test_get_upload_link_for_a_featured_image(): void {
		$data = $this->data( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $this->news_post, 'purpose' => 'featured_image' ) ) );

		$this->assertMatchesRegularExpression( '#^https://[^/]+/\?ccb_upload=[A-Za-z0-9_-]{43}$#', $data['url'] );
		$this->assertSame( array( 'image/jpeg', 'image/png', 'image/webp' ), $data['accepts'] );
		$this->assertSame( 12, $data['max_mb'], 'Three times the 4 MB limit' );
		$this->assertMatchesRegularExpression( '/Z$/', $data['expires_at_gmt'] );

		parse_str( (string) wp_parse_url( $data['url'], PHP_URL_QUERY ), $query );
		$token = (string) $query['ccb_upload'];
		global $wpdb;
		$table = Schema::table( Schema::UPLOAD_LINKS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM `{$table}` ORDER BY created_at_gmt DESC LIMIT 1", ARRAY_A );
		$this->assertSame( hash( 'sha256', $token ), $row['token_hash'], 'Only the hash is stored' );
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( $row ) );
		$this->assertSame( 'upload_link_created', $this->activity( 1 )[0]['action'] );
	}

	public function test_pretty_link_when_permalinks_are_on(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$data = $this->data( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $this->news_post, 'purpose' => 'content_image' ) ) );
		$this->assertMatchesRegularExpression( '#^https://[^/]+/ccb-upload/[A-Za-z0-9_-]{43}/$#', $data['url'] );
	}

	public function test_file_field_links_need_a_file_field(): void {
		$resource = self::factory()->post->create( array( 'post_type' => 'resource', 'post_status' => 'draft' ) );

		if ( function_exists( 'acf_get_field' ) ) {
			$pdf = $this->data( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $resource, 'purpose' => 'field_file', 'field' => 'downloadable_file' ) ) );
			$this->assertSame( array( 'application/pdf' ), $pdf['accepts'] );
			$this->assertSame( 20, $pdf['max_mb'] );

			$this->assertSame( 'ccb_invalid_input', self::code( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $resource, 'purpose' => 'field_file', 'field' => 'authors' ) ) ) );
		}
		$this->assertSame( 'ccb_invalid_input', self::code( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $this->news_post, 'purpose' => 'field_file', 'field' => 'downloadable_file' ) ) ) );
	}

	public function test_upload_links_are_limited_to_ten_an_hour(): void {
		for ( $i = 0; $i < UploadLinks::PER_HOUR; $i++ ) {
			$this->data( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $this->news_post, 'purpose' => 'content_image' ) ) );
		}
		$this->assertSame( 'ccb_rate_limited', self::code( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $this->news_post, 'purpose' => 'content_image' ) ) ) );
	}

	public function test_links_only_for_posts_in_sections(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 'ccb_post_type_not_allowed', self::code( $this->call_tool( 'ccb-get-upload-link', array( 'post_id' => $page, 'purpose' => 'content_image' ) ) ) );
	}

	/**
	 * Creates a link as the current user and returns its token.
	 *
	 * @param int         $post_id   Post.
	 * @param string      $purpose   Purpose.
	 * @param string|null $field_key Field key.
	 */
	private function link( int $post_id, string $purpose, ?string $field_key = null ): string {
		return UploadLinks::create( get_current_user_id(), $post_id, $purpose, $field_key, 15 )['token'];
	}

	/**
	 * A copy of a file in the temp dir (handle() deletes its input).
	 *
	 * @param string $path File.
	 */
	private static function copy_of( string $path ): string {
		$copy = get_temp_dir() . 'ccb-copy-' . wp_generate_password( 8, false ) . '-' . basename( $path );
		copy( $path, $copy );
		return $copy;
	}

	public function test_pdf_through_a_link_sets_the_insights_file_field(): void {
		if ( ! function_exists( 'get_field' ) ) {
			$this->markTestSkipped( 'ACF is not installed.' );
		}
		$resource = self::factory()->post->create( array( 'post_type' => 'resource', 'post_status' => 'draft', 'post_title' => 'Rail report', 'post_name' => 'rail-report' ) );
		$token    = $this->link( $resource, 'field_file', 'field_655493153192f' );
		$user     = get_current_user_id();
		wp_set_current_user( 0 ); // The page works without a login.

		$wrong = UploadLinks::handle( $token, self::copy_of( DIR_TESTDATA . '/images/canola.jpg' ) );
		$this->assertWPError( $wrong );
		$this->assertSame( 'ccb_file_type', $wrong->get_error_code() );
		$this->assertNotNull( UploadLinks::find_valid( $token ), 'A failed upload leaves the link usable' );

		$result = UploadLinks::handle( $token, self::copy_of( DIR_TESTDATA . '/images/test-alpha.pdf' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 0, get_current_user_id(), 'The link owner is only used during the upload' );
		$this->assertSame( $result['attachment_id'], (int) get_field( 'field_655493153192f', $resource, false ) );
		$this->assertMatchesRegularExpression( '/^rail-report(-\d+)?\.pdf$/', basename( (string) get_attached_file( $result['attachment_id'] ) ) );
		$this->assertSame( $user, (int) get_post_field( 'post_author', $result['attachment_id'] ) );
		$this->assertSame( 'upload_link_used', $this->activity( 1 )[0]['action'] );
		$this->assertSame( (string) $user, (string) $this->activity( 1 )[0]['user_id'] );

		$again = UploadLinks::handle( $token, self::copy_of( DIR_TESTDATA . '/images/test-alpha.pdf' ) );
		$this->assertSame( 'ccb_token_invalid', $again->get_error_code(), 'Single use' );
	}

	public function test_fake_pdf_is_refused(): void {
		if ( ! function_exists( 'get_field' ) ) {
			$this->markTestSkipped( 'ACF is not installed.' );
		}
		$resource = self::factory()->post->create( array( 'post_type' => 'resource', 'post_status' => 'draft' ) );
		$token    = $this->link( $resource, 'field_file', 'field_655493153192f' );
		$fake     = get_temp_dir() . 'fake.pdf';
		file_put_contents( $fake, "Not really a PDF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertSame( 'ccb_file_type', UploadLinks::handle( $token, $fake )->get_error_code() );
	}

	public function test_featured_image_through_a_link_needs_alt_text(): void {
		$token = $this->link( $this->news_post, 'featured_image' );

		$no_alt = UploadLinks::handle( $token, self::generated( 1600, 900 ) );
		$this->assertSame( 'ccb_invalid_input', $no_alt->get_error_code() );

		$result = UploadLinks::handle( $token, self::generated( 1600, 900 ), 'The reopened crossing' );
		$this->assertIsArray( $result );
		$this->assertSame( $result['attachment_id'], (int) get_post_thumbnail_id( $this->news_post ) );
		$this->assertSame( 'The reopened crossing', get_post_meta( $result['attachment_id'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'ccb_upload_link', get_post_meta( $result['attachment_id'], '_ccb_source', true ) );
	}

	public function test_expired_and_unknown_links_are_refused(): void {
		$token = $this->link( $this->news_post, 'content_image' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( Schema::UPLOAD_LINKS ), array( 'expires_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'token_hash' => hash( 'sha256', $token ) ) );

		$this->assertNull( UploadLinks::find_valid( $token ) );
		$this->assertNull( UploadLinks::find_valid( str_repeat( 'a', 43 ) ) );
		$this->assertNull( UploadLinks::find_valid( '../../etc/passwd' ) );
	}

	public function test_upload_page_responses(): void {
		$token = $this->link( $this->news_post, 'featured_image' );
		wp_set_current_user( 0 );

		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 403, UploadPage::respond( $token, false )['status'], 'HTTPS only' );
		$_SERVER['HTTPS'] = 'on';

		$page = UploadPage::respond( $token, false );
		$this->assertSame( 200, $page['status'] );
		$this->assertSame( 'Crossing reopens', $page['view']['post_title'] );
		$this->assertStringContainsString( 'landscape 16:9, at least 1200px wide', $page['view']['expected'] );
		$this->assertTrue( $page['view']['is_image'] );
		$this->assertNotSame( '', $page['view']['nonce'] );

		$_POST = array( '_ccb_nonce' => 'wrong' );
		$bad   = UploadPage::respond( $token, true );
		$_POST = array();
		$this->assertSame( 400, $bad['status'] );
		$this->assertNotEmpty( $bad['view']['error'] );
		$this->assertNotNull( UploadLinks::find_valid( $token ), 'Still usable' );

		$this->assertSame( 410, UploadPage::respond( str_repeat( 'b', 43 ), false )['status'] );
	}

	public function test_temp_folder_is_protected(): void {
		$dir = \Causeway\ClaudeBridge\Media\TempDir::path();

		$this->assertFileExists( $dir . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertFileExists( $dir . '/web.config' );
	}
}
