<?php
/**
 * Private temporary folder for uploads (docs/05 step 4, docs/09 "Uploads").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Media;

/**
 * `uploads/ccb-tmp/`, with an index file and deny rules so nothing in it can be browsed or run.
 * Files get random names and are deleted after processing; stale ones are pruned daily (M7).
 */
final class TempDir {

	public const NAME = 'ccb-tmp';

	/**
	 * Folder path, created on first use.
	 */
	public static function path(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . self::NAME;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::protect( $dir );
		return $dir;
	}

	/**
	 * A new random file path in the folder.
	 *
	 * @param string $extension Extension without the dot.
	 */
	public static function file( string $extension = 'tmp' ): string {
		return self::path() . '/' . bin2hex( random_bytes( 16 ) ) . '.' . preg_replace( '/[^a-z0-9]/', '', strtolower( $extension ) );
	}

	/**
	 * Deletes files older than the given age (daily prune).
	 *
	 * @param int $max_age Seconds.
	 */
	public static function prune( int $max_age = HOUR_IN_SECONDS ): int {
		$deleted = 0;
		$files   = glob( self::path() . '/*' );
		foreach ( false === $files ? array() : $files as $file ) {
			if ( in_array( basename( $file ), array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
				continue;
			}
			if ( is_file( $file ) && filemtime( $file ) < time() - $max_age && wp_delete_file_from_directory( $file, self::path() ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Writes the index and deny files if missing.
	 *
	 * @param string $dir Folder.
	 */
	private static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Claude Publishing temporary uploads: never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $contents ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small protective files in our own folder.
			}
		}
	}
}
