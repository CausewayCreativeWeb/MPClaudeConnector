<?php
/**
 * Database tables (docs/06-data-model.md).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Db;

/**
 * Creates, upgrades and drops the plugin's tables.
 */
final class Schema {

	/**
	 * Bump when any table definition changes; activation and upgrades re-run dbDelta.
	 */
	public const VERSION = 1;

	public const OPTION_VERSION = 'ccb_db_version';

	public const ACTIVITY        = 'ccb_activity';
	public const CONFIG_VERSIONS = 'ccb_config_versions';
	public const TOKENS          = 'ccb_tokens';
	public const UPLOAD_LINKS    = 'ccb_upload_links';
	public const IDEMPOTENCY     = 'ccb_idempotency';

	/**
	 * Unprefixed table names, in creation order.
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return array( self::ACTIVITY, self::CONFIG_VERSIONS, self::TOKENS, self::UPLOAD_LINKS, self::IDEMPOTENCY );
	}

	/**
	 * Full table name including the site's prefix.
	 *
	 * @param string $table One of the class constants.
	 */
	public static function table( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Whether the stored schema version is behind this code.
	 */
	public static function needs_upgrade(): bool {
		return (int) get_option( self::OPTION_VERSION, 0 ) < self::VERSION;
	}

	/**
	 * Creates or upgrades every table, then records the schema version.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_VERSION, self::VERSION, true );
	}

	/**
	 * Drops every table. Only used by uninstall when the admin asked for data to be deleted.
	 */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$name = self::table( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from constants.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}
		delete_option( self::OPTION_VERSION );
	}

	/**
	 * CREATE TABLE statements in dbDelta format (two spaces before PRIMARY KEY, one key per line).
	 *
	 * @return array<string, string>
	 */
	public static function definitions(): array {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$t       = static fn ( string $name ): string => self::table( $name );

		return array(
			self::ACTIVITY        => "CREATE TABLE {$t( self::ACTIVITY )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at_gmt datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  app_password_uuid char(36) NULL,
  app_password_name varchar(191) NULL,
  action varchar(40) NOT NULL,
  ability varchar(80) NULL,
  post_id bigint(20) unsigned NULL,
  attachment_id bigint(20) unsigned NULL,
  section varchar(40) NULL,
  post_title_snapshot varchar(255) NULL,
  status_from varchar(20) NULL,
  status_to varchar(20) NULL,
  result varchar(10) NOT NULL,
  error_code varchar(60) NULL,
  message varchar(500) NULL,
  config_version int(10) unsigned NULL,
  ip varbinary(16) NULL,
  request_ref char(12) NOT NULL,
  details longtext NULL,
  PRIMARY KEY  (id),
  KEY created (created_at_gmt),
  KEY user_created (user_id,created_at_gmt),
  KEY post (post_id),
  KEY action_result (action,result)
) {$charset};",
			self::CONFIG_VERSIONS => "CREATE TABLE {$t( self::CONFIG_VERSIONS )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version int(10) unsigned NOT NULL,
  config longtext NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  note varchar(255) NULL,
  source varchar(20) NOT NULL,
  created_at_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY version (version)
) {$charset};",
			self::TOKENS          => "CREATE TABLE {$t( self::TOKENS )} (
  token_hash char(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  action varchar(20) NOT NULL,
  payload_hash char(64) NOT NULL,
  post_modified_gmt datetime NOT NULL,
  expires_at_gmt datetime NOT NULL,
  used_at_gmt datetime NULL,
  PRIMARY KEY  (token_hash),
  KEY expires (expires_at_gmt)
) {$charset};",
			self::UPLOAD_LINKS    => "CREATE TABLE {$t( self::UPLOAD_LINKS )} (
  token_hash char(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  purpose varchar(20) NOT NULL,
  field_key varchar(64) NULL,
  expires_at_gmt datetime NOT NULL,
  used_at_gmt datetime NULL,
  attachment_id bigint(20) unsigned NULL,
  created_at_gmt datetime NOT NULL,
  PRIMARY KEY  (token_hash),
  KEY user_created (user_id,created_at_gmt),
  KEY expires (expires_at_gmt)
) {$charset};",
			self::IDEMPOTENCY     => "CREATE TABLE {$t( self::IDEMPOTENCY )} (
  user_id bigint(20) unsigned NOT NULL,
  idem_key varchar(64) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  response longtext NOT NULL,
  created_at_gmt datetime NOT NULL,
  PRIMARY KEY  (user_id,idem_key),
  KEY created (created_at_gmt)
) {$charset};",
		);
	}
}
