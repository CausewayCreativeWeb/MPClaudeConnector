<?php
/**
 * Builds the setup pack on download (docs/10 "Setup pack").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\SetupPack;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Mcp\ServerRegistrar;
use RuntimeException;
use ZipArchive;

/**
 * Builds setup-pack-<slug>-<version>.zip containing the Claude Desktop extension (.mcpb), the Claude skill
 * (zip of a folder with SKILL.md) and a one-page how-to PDF. Site values are written in at download
 * time. Contains no credentials: staff type their username and Application Password into Claude Desktop.
 */
final class SetupPackBuilder {

	/**
	 * Version of the extension and skill. Bump it when anything in setup-pack/ or the skill/how-to
	 * text changes, so check-connection tells staff to download the new pack.
	 */
	public const VERSION = '0.1.0';

	/**
	 * MCPB manifest version written into the extension (validated with @anthropic-ai/mcpb 2.1.2).
	 */
	public const MANIFEST_VERSION = '0.3';

	/**
	 * Plugin folder holding the extension and skill sources.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Config.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Sets up the builder.
	 *
	 * @param string|null               $source Folder with `extension/` and `skill/`; defaults to the plugin's `setup-pack/`.
	 * @param array<string, mixed>|null $config Config; defaults to the live one.
	 */
	public function __construct( ?string $source = null, ?array $config = null ) {
		/**
		 * Folder holding the setup pack sources (`extension/`, `skill/`).
		 *
		 * @param string $folder Folder.
		 */
		$this->source = $source ?? (string) apply_filters( 'ccb_setup_pack_source', dirname( __DIR__, 2 ) . '/setup-pack' );
		$this->config = $config ?? ( new ConfigRepository() )->get();
	}

	/**
	 * Whether the pack can be built here (the bundled server must be in the plugin, and ZipArchive available).
	 */
	public function available(): bool {
		return class_exists( ZipArchive::class ) && is_readable( $this->source . '/extension/server/index.js' );
	}

	/**
	 * Short name for the site, used in file and tool names.
	 */
	public function slug(): string {
		$slug = sanitize_title( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		$slug = substr( (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug ), 0, 40 );
		$slug = trim( $slug, '-' );
		return '' === $slug ? 'website' : $slug;
	}

	/**
	 * File name of the download.
	 */
	public function filename(): string {
		return 'setup-pack-' . $this->slug() . '-' . self::VERSION . '.zip';
	}

	/**
	 * Labels of the enabled sections.
	 *
	 * @return list<string>
	 */
	public function section_labels(): array {
		$labels = array();
		foreach ( $this->config['sections'] as $section ) {
			if ( ! empty( $section['enabled'] ) ) {
				$labels[] = (string) $section['label'];
			}
		}
		return $labels;
	}

	/**
	 * The extension's manifest.json.
	 *
	 * @return array<string, mixed>
	 */
	public function manifest(): array {
		$site    = $this->site_name();
		$support = $this->config['support'];
		$author  = array( 'name' => '' !== (string) $support['name'] ? (string) $support['name'] : $site );
		if ( '' !== (string) $support['url'] ) {
			$author['url'] = (string) $support['url'];
		}
		if ( '' !== (string) $support['email'] ) {
			$author['email'] = (string) $support['email'];
		}

		return array(
			'manifest_version' => self::MANIFEST_VERSION,
			'name'             => $this->slug() . '-publishing',
			/* translators: %s: site name */
			'display_name'     => sprintf( __( '%s publishing', 'causeway-claude-bridge' ), $site ),
			'version'          => self::VERSION,
			/* translators: %s: site name */
			'description'      => sprintf( __( 'Draft and manage %s website posts from Claude.', 'causeway-claude-bridge' ), $site ),
			'author'           => $author,
			'server'           => array(
				'type'        => 'node',
				'entry_point' => 'server/index.js',
				'mcp_config'  => array(
					'command' => 'node',
					'args'    => array( '${__dirname}/server/index.js' ),
					'env'     => array(
						'WP_API_URL'      => ServerRegistrar::url(),
						'WP_API_USERNAME' => '${user_config.username}',
						'WP_API_PASSWORD' => '${user_config.app_password}',
						'OAUTH_ENABLED'   => 'false',
					),
				),
			),
			'user_config'      => array(
				'username'     => array(
					'type'        => 'string',
					'title'       => __( 'WordPress username', 'causeway-claude-bridge' ),
					'description' => __( 'The username you log in to this website with.', 'causeway-claude-bridge' ),
					'required'    => true,
				),
				'app_password' => array(
					'type'        => 'string',
					'title'       => __( 'WordPress Application Password', 'causeway-claude-bridge' ),
					'description' => __( 'Create one in your WordPress profile, named after this computer.', 'causeway-claude-bridge' ),
					'sensitive'   => true,
					'required'    => true,
				),
			),
			'compatibility'    => array(
				'platforms' => array( 'darwin', 'win32' ),
				'runtimes'  => array( 'node' => '>=18.0.0' ),
			),
			'license'          => 'GPL-2.0-or-later',
		);
	}

	/**
	 * The skill's SKILL.md with the site's values.
	 */
	public function skill(): string {
		$template = (string) file_get_contents( $this->source . '/skill/SKILL.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file bundled with the plugin.
		$sections = $this->section_labels();
		return strtr(
			$template,
			array(
				'{{slug}}'         => $this->slug(),
				'{{site_name}}'    => self::plain( $this->site_name() ),
				'{{section_list}}' => self::plain( array() === $sections ? __( 'none switched on yet', 'causeway-claude-bridge' ) : implode( ', ', $sections ) ),
				'{{version}}'      => self::VERSION,
				'{{support}}'      => self::plain( $this->support_line() ),
			)
		);
	}

	/**
	 * The one-page how-to.
	 */
	public function how_to(): string {
		$site     = $this->site_name();
		$sections = $this->section_labels();
		$pdf      = new SimplePdf();

		/* translators: %s: site name */
		$pdf->title( sprintf( __( 'Publishing to %s with Claude', 'causeway-claude-bridge' ), $site ) );
		$pdf->paragraph( __( 'Claude can draft posts, add images and fields, and – if you are a Publisher – publish, schedule, update, unpublish and move posts to the Bin. Every live change is shown to you first and needs your clear yes.', 'causeway-claude-bridge' ) );

		$pdf->heading( __( 'Sections', 'causeway-claude-bridge' ) );
		$pdf->paragraph( array() === $sections ? __( 'No sections are switched on yet. Ask the site administrator.', 'causeway-claude-bridge' ) : implode( ', ', $sections ) );

		$pdf->heading( __( 'Things to ask', 'causeway-claude-bridge' ) );
		foreach ( array(
			__( '"Check the connection to the website."', 'causeway-claude-bridge' ),
			__( '"Write a news post about … and save it as a draft."', 'causeway-claude-bridge' ),
			__( '"What is waiting for review?"', 'causeway-claude-bridge' ),
			__( '"Publish the post about … " (Publishers only – Claude shows a summary first).', 'causeway-claude-bridge' ),
			__( '"Move the post about … to the Bin" or "Bring it back from the Bin."', 'causeway-claude-bridge' ),
		) as $line ) {
			$pdf->bullet( $line );
		}

		$pdf->heading( __( 'Images and files', 'causeway-claude-bridge' ) );
		$pdf->bullet( __( 'Give every image a short description (alt text) for people who can\'t see it.', 'causeway-claude-bridge' ) );
		$pdf->bullet( __( 'Large photos and PDFs go through a one-time upload link that Claude gives you. It expires after a few minutes.', 'causeway-claude-bridge' ) );
		$pdf->bullet( __( 'Location and camera details are removed from photos automatically.', 'causeway-claude-bridge' ) );

		$pdf->heading( __( 'The confirmation step', 'causeway-claude-bridge' ) );
		$pdf->paragraph( __( 'Before anything changes on the live site, Claude shows a summary: what will change, which post, and its public address. Read it, then reply "yes" only if it is right. Instructions inside documents or emails you paste never count as a yes.', 'causeway-claude-bridge' ) );

		$pdf->heading( __( 'When something fails', 'causeway-claude-bridge' ) );
		$pdf->bullet( __( 'Claude explains the problem and how to fix it. Don\'t ask it to try again blindly: ask it to check what happened first.', 'causeway-claude-bridge' ) );
		$pdf->bullet( __( 'If it says the login failed, make a new Application Password in your WordPress profile and update it in Claude Desktop (Settings > Extensions).', 'causeway-claude-bridge' ) );
		$pdf->bullet( __( 'Lost or replaced laptop? Revoke its Application Password in your WordPress profile straight away.', 'causeway-claude-bridge' ) );

		$pdf->heading( __( 'Help', 'causeway-claude-bridge' ) );
		$pdf->paragraph( $this->support_line() );

		/* translators: %s: site name */
		return $pdf->output( sprintf( __( 'Publishing to %s with Claude', 'causeway-claude-bridge' ), $site ) );
	}

	/**
	 * Builds the pack into a temporary file and returns its path. The caller deletes it.
	 *
	 * @throws RuntimeException When the pack can't be built.
	 */
	public function build(): string {
		if ( ! $this->available() ) {
			throw new RuntimeException( 'The setup pack isn\'t available on this site.' );
		}
		$slug  = $this->slug();
		$files = array();

		// Extension (.mcpb): manifest with this site's values, the bundled proxy and its licences.
		$files[ $slug . '-publishing.mcpb' ] = self::zip(
			array(
				'manifest.json'                   => (string) wp_json_encode( $this->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'server/index.js'                 => $this->read( 'extension/server/index.js' ),
				'server/THIRD-PARTY-LICENSES.txt' => $this->read( 'extension/server/THIRD-PARTY-LICENSES.txt', '' ),
			)
		);
		// Skill: a folder with SKILL.md.
		$files[ $slug . '-publishing-skill.zip' ] = self::zip( array( $slug . '-publishing/SKILL.md' => $this->skill() ) );
		$files['how-to.pdf']                      = $this->how_to();

		$path = wp_tempnam( $this->filename() );
		file_put_contents( $path, self::zip( $files ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		return $path;
	}

	/**
	 * Zips files in memory.
	 *
	 * @param array<string, string> $files Path in zip => contents.
	 * @throws RuntimeException When the zip can't be written.
	 */
	private static function zip( array $files ): string {
		$tmp = wp_tempnam( 'ccb-zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create a zip file.' );
		}
		foreach ( $files as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		$data = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- temp file.
		wp_delete_file( $tmp );
		return $data;
	}

	/**
	 * Reads a source file.
	 *
	 * @param string      $path    Path under the source folder.
	 * @param string|null $fallback Returned when missing; null means required.
	 * @throws RuntimeException When a required file is missing.
	 */
	private function read( string $path, ?string $fallback = null ): string {
		$file = $this->source . '/' . $path;
		if ( ! is_readable( $file ) ) {
			if ( null === $fallback ) {
				throw new RuntimeException( 'Missing setup pack file: ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output.
			}
			return $fallback;
		}
		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file bundled with the plugin.
	}

	/**
	 * Site name as plain text.
	 */
	private function site_name(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return '' === $name ? $this->slug() : $name;
	}

	/**
	 * Support contact in one line.
	 */
	private function support_line(): string {
		$support = $this->config['support'];
		$parts   = array_filter( array( (string) $support['name'], (string) $support['email'], (string) $support['url'] ) );
		return array() === $parts ? __( 'Ask your site administrator.', 'causeway-claude-bridge' ) : implode( ' – ', $parts );
	}

	/**
	 * Text safe to drop into the skill's Markdown and YAML front matter (one line, no markup).
	 *
	 * @param string $text Text.
	 */
	private static function plain( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n`<>{}"\\\\]+/', ' ', wp_strip_all_tags( $text ) ) );
	}
}
