<?php
/**
 * Setup pack (docs/10).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- test fixtures; ZipArchive's own property.

use Causeway\ClaudeBridge\Checks\PreFlight;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Mcp\ServerRegistrar;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\SetupPack\SetupPackBuilder;
use Causeway\ClaudeBridge\SetupPack\SetupPackDownload;
use Causeway\ClaudeBridge\SetupPack\SimplePdf;
use ZipArchive;

/**
 * @covers \Causeway\ClaudeBridge\SetupPack\SetupPackBuilder
 * @covers \Causeway\ClaudeBridge\SetupPack\SetupPackDownload
 * @covers \Causeway\ClaudeBridge\SetupPack\SimplePdf
 */
final class SetupPackTest extends McpTestCase {

	private string $source;

	public function set_up(): void {
		parent::set_up();
		$this->configure_mp_site();
		update_option( 'blogname', 'Jane Smith MP: Office' );

		// The real skill template with a stand-in for the bundled proxy (built by npm, not in PHP-only CI jobs).
		$this->source = get_temp_dir() . 'ccb-pack-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->source . '/extension/server' );
		wp_mkdir_p( $this->source . '/skill' );
		copy( dirname( __DIR__, 2 ) . '/setup-pack/skill/SKILL.md', $this->source . '/skill/SKILL.md' );
		file_put_contents( $this->source . '/extension/server/index.js', "import '@automattic/mcp-wordpress-remote';\n" );
		file_put_contents( $this->source . '/extension/server/THIRD-PARTY-LICENSES.txt', "MIT\n" );
	}

	public function tear_down(): void {
		foreach ( array( 'extension/server/index.js', 'extension/server/THIRD-PARTY-LICENSES.txt', 'skill/SKILL.md' ) as $file ) {
			@unlink( $this->source . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( array( 'extension/server', 'extension', 'skill', '' ) as $dir ) {
			@rmdir( $this->source . '/' . $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		remove_all_filters( 'ccb_setup_pack_send' );
		parent::tear_down();
	}

	/**
	 * Unzips a zip held in memory.
	 *
	 * @param string $data Zip bytes.
	 * @return array<string, string>
	 */
	private static function unzip( string $data ): array {
		$tmp = wp_tempnam( 'ccb-test' );
		file_put_contents( $tmp, $data );
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $tmp ) );
		$files = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name           = (string) $zip->getNameIndex( $i );
			$files[ $name ] = (string) $zip->getFromIndex( $i );
		}
		$zip->close();
		unlink( $tmp );
		return $files;
	}

	private function build(): array {
		$path  = ( new SetupPackBuilder( $this->source ) )->build();
		$files = self::unzip( (string) file_get_contents( $path ) );
		unlink( $path );
		return $files;
	}

	public function test_pack_has_extension_skill_and_how_to(): void {
		$builder = new SetupPackBuilder( $this->source );
		$this->assertSame( 'jane-smith-mp-office', $builder->slug() );
		$this->assertSame( 'setup-pack-jane-smith-mp-office-' . SetupPackBuilder::VERSION . '.zip', $builder->filename() );

		$files = $this->build();
		$this->assertSame( array( 'jane-smith-mp-office-publishing.mcpb', 'jane-smith-mp-office-publishing-skill.zip', 'how-to.pdf' ), array_keys( $files ) );

		$mcpb = self::unzip( $files['jane-smith-mp-office-publishing.mcpb'] );
		$this->assertEqualsCanonicalizing( array( 'manifest.json', 'server/index.js', 'server/THIRD-PARTY-LICENSES.txt' ), array_keys( $mcpb ) );

		$skill = self::unzip( $files['jane-smith-mp-office-publishing-skill.zip'] );
		$this->assertSame( array( 'jane-smith-mp-office-publishing/SKILL.md' ), array_keys( $skill ) );

		$this->assertStringStartsWith( '%PDF-1.4', $files['how-to.pdf'] );
		$this->assertStringEndsWith( "%%EOF\n", $files['how-to.pdf'] );
	}

	public function test_manifest_points_at_the_ccb_server_and_holds_no_credentials(): void {
		$manifest = ( new SetupPackBuilder( $this->source ) )->manifest();

		$this->assertSame( SetupPackBuilder::MANIFEST_VERSION, $manifest['manifest_version'] );
		$this->assertSame( 'jane-smith-mp-office-publishing', $manifest['name'] );
		$this->assertSame( 'Jane Smith MP: Office publishing', $manifest['display_name'] );
		$env = $manifest['server']['mcp_config']['env'];
		$this->assertSame( ServerRegistrar::url(), $env['WP_API_URL'] );
		$this->assertStringEndsWith( '/mcp/ccb', $env['WP_API_URL'] );
		$this->assertSame( '${user_config.username}', $env['WP_API_USERNAME'] );
		$this->assertSame( '${user_config.app_password}', $env['WP_API_PASSWORD'] );
		$this->assertSame( 'false', $env['OAUTH_ENABLED'], 'Application Password only, no OAuth discovery' );
		$this->assertTrue( $manifest['user_config']['app_password']['sensitive'] );

		$json = (string) wp_json_encode( $manifest );
		foreach ( get_users() as $user ) {
			$this->assertStringNotContainsString( '"' . $user->user_login . '"', $json );
		}
		$this->assertStringNotContainsString( 'mcp-adapter-default-server', $json );
	}

	public function test_manifest_matches_the_mcpb_schema(): void {
		$schema = dirname( __DIR__, 2 ) . '/node_modules/@anthropic-ai/mcpb/dist/mcpb-manifest-v' . SetupPackBuilder::MANIFEST_VERSION . '.schema.json';
		if ( ! is_readable( $schema ) ) {
			$this->markTestSkipped( 'Run npm install for the MCPB schema (CI validates with the mcpb CLI).' );
		}
		$validator = new \Causeway\ClaudeBridge\Vendor\Opis\JsonSchema\Validator();
		$data      = json_decode( (string) wp_json_encode( ( new SetupPackBuilder( $this->source ) )->manifest() ) );
		$result    = $validator->validate( $data, (string) file_get_contents( $schema ) );
		$this->assertTrue( $result->isValid(), $result->hasError() ? (string) wp_json_encode( ( new \Causeway\ClaudeBridge\Vendor\Opis\JsonSchema\Errors\ErrorFormatter() )->format( $result->error() ) ) : '' );
	}

	public function test_every_tool_named_in_the_skill_exists_on_the_ccb_server(): void {
		$skill = ( new SetupPackBuilder( $this->source ) )->skill();
		preg_match_all( '/`(ccb-[a-z-]+)`/', $skill, $matches );
		$named = array_values( array_unique( $matches[1] ) );
		$this->assertNotEmpty( $named );

		$this->login_as( RoleManager::ROLE_PUBLISHER );
		$this->initialize();
		list( , $body ) = $this->rpc( 'tools/list' );
		$tools          = array_column( json_decode( (string) wp_json_encode( $body ), true )['result']['tools'], 'name' );

		$this->assertSame( array(), array_values( array_diff( $named, $tools ) ), 'Tools named in the skill but missing on the server' );
		$this->assertSame( array(), array_values( array_diff( $tools, $named ) ), 'Tools on the server the skill never mentions' );
	}

	public function test_skill_has_site_values_and_valid_front_matter(): void {
		$skill = ( new SetupPackBuilder( $this->source ) )->skill();
		$this->assertStringNotContainsString( '{{', $skill );
		$this->assertMatchesRegularExpression( '/^---\nname: jane-smith-mp-office-publishing\ndescription: "[^"\n]+"\n---\n/', $skill );
		$this->assertStringContainsString( 'Jane Smith MP: Office website', $skill );
		$this->assertStringContainsString( 'News, Insights, Visits', $skill );
		$this->assertStringContainsString( 'skill_version: "' . SetupPackBuilder::VERSION . '"', $skill );
		$this->assertStringContainsString( ( new ConfigRepository() )->get()['support']['email'], $skill );
		$this->assertSame( SetupPackBuilder::VERSION, PreFlight::latest_skill_version() );
	}

	public function test_outdated_skill_is_reported_by_check_connection(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$codes = array_column( $this->data( $this->call_tool( 'ccb-check-connection', array( 'skill_version' => '0.0.1' ) ) )['issues'], 'code' );
		$this->assertContains( 'skill_outdated', $codes );
		$codes = array_column( $this->data( $this->call_tool( 'ccb-check-connection', array( 'skill_version' => SetupPackBuilder::VERSION ) ) )['issues'], 'code' );
		$this->assertNotContains( 'skill_outdated', $codes );
	}

	public function test_download_needs_the_nonce_and_the_capability_and_is_logged(): void {
		$sent = null;
		add_filter(
			'ccb_setup_pack_send',
			static function ( bool $handled, string $path, string $name ) use ( &$sent ): bool {
				$sent = array( $path, $name );
				return true;
			},
			10,
			3
		);
		add_filter( 'ccb_setup_pack_source', fn () => $this->source );

		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$_REQUEST['_wpnonce'] = wp_create_nonce( SetupPackDownload::ACTION );
		SetupPackDownload::handle();
		unset( $_REQUEST['_wpnonce'] );

		$this->assertNotNull( $sent );
		$this->assertFileExists( $sent[0] );
		unlink( $sent[0] );
		$row = $this->activity( 1 )[0];
		$this->assertSame( 'setup_pack_download', $row['action'] );
		$this->assertSame( 'success', $row['result'] );

		$this->expectException( \WPDieException::class );
		$_REQUEST['_wpnonce'] = 'wrong';
		try {
			SetupPackDownload::handle();
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	public function test_profile_shows_the_download_to_the_user_only(): void {
		add_filter( 'ccb_setup_pack_source', fn () => $this->source );
		$user = $this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		ob_start();
		do_action( 'show_user_profile', get_userdata( $user ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		$this->assertStringContainsString( 'action=ccb_setup_pack', (string) ob_get_clean() );

		$other = self::factory()->user->create( array( 'role' => RoleManager::ROLE_CONTRIBUTOR ) );
		$this->login_as( 'administrator' );
		ob_start();
		do_action( 'edit_user_profile', get_userdata( $other ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		$this->assertStringNotContainsString( 'action=ccb_setup_pack', (string) ob_get_clean(), 'Each person downloads their own' );
	}

	public function test_pdf_wraps_and_encodes_text(): void {
		$pdf = ( new SimplePdf() )->title( 'Café – “quotes” (and) back\\slash' )->paragraph( str_repeat( 'word ', 4000 ) )->output( 'T' );
		$this->assertStringContainsString( "Caf\xE9 \x96 \x93quotes\x94 \\(and\\) back\\\\slash", $pdf );
		$this->assertGreaterThanOrEqual( 2, substr_count( $pdf, '/Type /Page ' ), 'Long text flows onto a second page' );
		preg_match( '/startxref\n(\d+)/', $pdf, $m );
		$this->assertSame( 'xref', substr( $pdf, (int) $m[1], 4 ), 'xref offset is right' );
		$this->assertLessThan( 483.28, SimplePdf::measure( 'word word word word word word word word word word word word word word word', 10 ) + 1 );
	}
}
