<?php
/**
 * Config storage and versioning (docs/04-configuration.md, docs/06-data-model.md).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

use Causeway\ClaudeBridge\Db\Schema;
use WP_Error;

/**
 * Loads and saves the live config. Every save is validated, bumps the version and writes a
 * row to `ccb_config_versions`; only the latest 20 versions are kept.
 */
final class ConfigRepository {

	public const OPTION       = 'ccb_config';
	public const MAX_VERSIONS = 20;
	public const SOURCES      = array( 'ui', 'import', 'restore', 'preset', 'activation' );

	/**
	 * Validator.
	 *
	 * @var SchemaValidator
	 */
	private SchemaValidator $validator;

	/**
	 * Sets up the repository.
	 *
	 * @param SchemaValidator|null $validator Validator; a default one is created when null.
	 */
	public function __construct( ?SchemaValidator $validator = null ) {
		$this->validator = $validator ?? new SchemaValidator();
	}

	/**
	 * Whether a config has been stored.
	 */
	public function exists(): bool {
		return false !== get_option( self::OPTION, false );
	}

	/**
	 * The live config. Falls back to the defaults if nothing is stored or the stored value is unreadable.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, false );
		if ( is_string( $stored ) ) {
			$config = ConfigJson::decode( $stored );
			if ( null !== $config ) {
				return $this->migrate( $config );
			}
		}
		return DefaultConfig::get();
	}

	/**
	 * The live config's version number (0 when nothing is stored).
	 */
	public function version(): int {
		return $this->exists() ? (int) ( $this->get()['version'] ?? 0 ) : 0;
	}

	/**
	 * Validates the stored config. Pre-flight uses this to report `ccb_config_invalid`.
	 */
	public function validate_stored(): ValidationResult {
		return $this->validator->validate( $this->get() );
	}

	/**
	 * Validates and saves a config as a new version.
	 *
	 * @param array<string, mixed> $config       Config to save. `version`, `saved_by`, `saved_at_gmt` and `schema_version` are set here.
	 * @param int                  $user_id      User saving.
	 * @param string               $source       One of self::SOURCES.
	 * @param string|null          $note         Optional note for the version history.
	 * @param int|null             $base_version Version the editor started from; a newer live version means someone else saved first.
	 * @return array<string, mixed>|WP_Error Saved config, or an error with `errors`/`warnings` in its data.
	 */
	public function save( array $config, int $user_id, string $source, ?string $note = null, ?int $base_version = null ): array|WP_Error {
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return new WP_Error( 'ccb_invalid_input', __( 'Unknown config source.', 'causeway-claude-bridge' ), array( 'status' => 400 ) );
		}

		$current = $this->version();
		if ( null !== $base_version && $base_version !== $current ) {
			return new WP_Error(
				'ccb_config_conflict',
				__( 'Someone else saved changes since you opened these settings.', 'causeway-claude-bridge' ),
				array(
					'status'       => 409,
					'live_version' => $current,
				)
			);
		}

		$config['schema_version'] = DefaultConfig::SCHEMA_VERSION;
		// Continue from the history too, in case the option was deleted while version rows remain.
		$config['version']      = max( $current, $this->latest_history_version() ) + 1;
		$config['saved_by']     = $user_id;
		$config['saved_at_gmt'] = gmdate( 'Y-m-d\TH:i:s\Z' );

		$result = $this->validator->validate( $config );
		if ( ! $result->is_valid() ) {
			return new WP_Error(
				'ccb_config_invalid',
				__( 'The settings could not be saved because some values are invalid.', 'causeway-claude-bridge' ),
				array(
					'status'   => 400,
					'errors'   => $result->errors(),
					'warnings' => $result->warnings(),
				)
			);
		}

		$json = ConfigJson::encode( $config );

		// The version row goes in first: its unique key stops two simultaneous saves both becoming version N.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$inserted = $wpdb->insert(
			Schema::table( Schema::CONFIG_VERSIONS ),
			array(
				'version'        => $config['version'],
				'config'         => $json,
				'user_id'        => $user_id,
				'note'           => null === $note ? null : mb_substr( sanitize_text_field( $note ), 0, 255 ),
				'source'         => $source,
				'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error(
				'ccb_config_conflict',
				__( 'Someone else saved changes at the same time. Reload and try again.', 'causeway-claude-bridge' ),
				array(
					'status'       => 409,
					'live_version' => $this->version(),
				)
			);
		}

		update_option( self::OPTION, $json, false );
		$this->prune_versions( (int) $config['version'] );

		/**
		 * Fires after a config version is saved.
		 *
		 * @param array<string, mixed> $config Saved config.
		 * @param string               $source Where the save came from.
		 */
		do_action( 'ccb_config_saved', $config, $source );

		return $config;
	}

	/**
	 * Stores the default config as version 1 if no config exists yet.
	 *
	 * @param int $user_id User performing the activation.
	 */
	public function ensure_default( int $user_id ): void {
		if ( $this->exists() ) {
			return;
		}
		$saved = $this->save( DefaultConfig::get(), $user_id, 'activation' );
		if ( is_wp_error( $saved ) ) {
			// Can only happen if the admin email is unusable; store the defaults anyway so the plugin loads,
			// and pre-flight will report the config as invalid until an admin fixes the support contact.
			update_option( self::OPTION, ConfigJson::encode( DefaultConfig::get() ), false );
		}
	}

	/**
	 * Version history, newest first.
	 *
	 * @return list<array{id: int, version: int, user_id: int, note: ?string, source: string, created_at_gmt: string}>
	 */
	public function versions(): array {
		global $wpdb;
		$table = Schema::table( Schema::CONFIG_VERSIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants, no user input.
		$rows = $wpdb->get_results( "SELECT id, version, user_id, note, source, created_at_gmt FROM `{$table}` ORDER BY version DESC", ARRAY_A );

		return array_map(
			static fn ( array $row ): array => array(
				'id'             => (int) $row['id'],
				'version'        => (int) $row['version'],
				'user_id'        => (int) $row['user_id'],
				'note'           => null === $row['note'] ? null : (string) $row['note'],
				'source'         => (string) $row['source'],
				'created_at_gmt' => (string) $row['created_at_gmt'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * One stored version's config.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public function get_version_config( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( Schema::CONFIG_VERSIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT config FROM `{$table}` WHERE id = %d", $id ) );
		return is_string( $json ) ? ConfigJson::decode( $json ) : null;
	}

	/**
	 * Highest version number in the history table (0 when empty).
	 */
	private function latest_history_version(): int {
		global $wpdb;
		$table = Schema::table( Schema::CONFIG_VERSIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		return (int) $wpdb->get_var( "SELECT MAX(version) FROM `{$table}`" );
	}

	/**
	 * Deletes version rows beyond the newest MAX_VERSIONS.
	 *
	 * @param int $latest Latest version number.
	 */
	private function prune_versions( int $latest ): void {
		global $wpdb;
		$table = Schema::table( Schema::CONFIG_VERSIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE version <= %d", $latest - self::MAX_VERSIONS ) );
	}

	/**
	 * Brings an older stored config up to the current schema version. Version 1 is the first, so nothing to do yet.
	 *
	 * @param array<string, mixed> $config Stored config.
	 * @return array<string, mixed>
	 */
	private function migrate( array $config ): array {
		return $config;
	}
}
