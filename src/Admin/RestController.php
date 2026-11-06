<?php
/**
 * REST routes for the settings screen (docs/08 "REST endpoints").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Admin;

use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Checks\HealthCheck;
use Causeway\ClaudeBridge\Config\ConfigDiff;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Config\DefaultConfig;
use Causeway\ClaudeBridge\Config\Presets;
use Causeway\ClaudeBridge\Config\PublishingGuide;
use Causeway\ClaudeBridge\Config\SiteStructure;
use Causeway\ClaudeBridge\Notify\Mailer;
use Causeway\ClaudeBridge\Notify\Notifications;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Uninstaller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `ccb/v1`. Every route has a permission callback: `manage_options` for settings, `ccb_view_activity`
 * for status and activity. Cookie requests need the `wp_rest` nonce (WordPress core checks it).
 */
final class RestController {

	public const NAMESPACE = 'ccb/v1';

	public const IMPORT_MAX_BYTES = 262144;

	public const OPTION_DEBUG_UNTIL = 'ccb_debug_until';

	/**
	 * Hooks the routes.
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers every route.
	 */
	public static function register_routes(): void {
		$admin  = array( self::class, 'can_manage' );
		$viewer = array( self::class, 'can_view' );
		$id     = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
		$config = array(
			'type'     => 'object',
			'required' => true,
		);

		$routes = array(
			'/config'                                    => array(
				array( WP_REST_Server::READABLE, 'get_config', $admin, array() ),
				array(
					'PUT',
					'save_config',
					$admin,
					array(
						'config'          => $config,
						'base_version'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'note'            => array(
							'type'      => 'string',
							'maxLength' => 255,
						),
						'accept_warnings' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'source'          => array(
							'type'    => 'string',
							'enum'    => array( 'ui', 'import', 'preset' ),
							'default' => 'ui',
						),
					),
				),
			),
			'/config/validate'                           => array( array( WP_REST_Server::CREATABLE, 'validate_config', $admin, array( 'config' => $config ) ) ),
			'/config/defaults'                           => array( array( WP_REST_Server::READABLE, 'get_defaults', $admin, array() ) ),
			'/config/versions'                           => array( array( WP_REST_Server::READABLE, 'get_versions', $admin, array() ) ),
			'/config/versions/(?P<id>\d+)'               => array( array( WP_REST_Server::READABLE, 'get_version', $admin, array( 'id' => $id ) ) ),
			'/config/versions/(?P<id>\d+)/restore'       => array(
				array(
					WP_REST_Server::CREATABLE,
					'restore_version',
					$admin,
					array(
						'id'              => $id,
						'base_version'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'accept_warnings' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			),
			'/config/import'                             => array(
				array(
					WP_REST_Server::CREATABLE,
					'import_config',
					$admin,
					array(
						'json' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			),
			'/presets'                                   => array( array( WP_REST_Server::READABLE, 'get_presets', $admin, array() ) ),
			'/presets/(?P<name>[a-z0-9-]+)/preview'      => array( array( WP_REST_Server::CREATABLE, 'preview_preset', $admin, array() ) ),
			'/site-structure'                            => array(
				array(
					WP_REST_Server::READABLE,
					'get_site_structure',
					$admin,
					array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			),
			'/preview/(?P<section>[a-z][a-z0-9-]{1,30})' => array( array( WP_REST_Server::CREATABLE, 'preview_section', $admin, array( 'config' => $config ) ) ),
			'/status'                                    => array( array( WP_REST_Server::READABLE, 'get_status', $viewer, array() ) ),
			'/status/run'                                => array( array( WP_REST_Server::CREATABLE, 'run_status', $admin, array() ) ),
			'/activity'                                  => array( array( WP_REST_Server::READABLE, 'get_activity', $viewer, self::activity_args() ) ),
			'/users'                                     => array( array( WP_REST_Server::READABLE, 'get_users', $admin, array() ) ),
			'/users/(?P<id>\d+)/role'                    => array(
				array(
					WP_REST_Server::CREATABLE,
					'set_user_role',
					$admin,
					array(
						'id'   => $id,
						'role' => array(
							'type'     => 'string',
							'enum'     => array( 'publisher', 'contributor' ),
							'required' => true,
						),
					),
				),
			),
			'/users/(?P<id>\d+)/app-passwords'           => array( array( WP_REST_Server::READABLE, 'get_app_passwords', $admin, array( 'id' => $id ) ) ),
			'/users/(?P<id>\d+)/app-passwords/(?P<uuid>[0-9a-f-]{36})' => array( array( WP_REST_Server::DELETABLE, 'revoke_app_password', $admin, array( 'id' => $id ) ) ),
			'/notifications/test'                        => array( array( WP_REST_Server::CREATABLE, 'send_test_email', $admin, array() ) ),
			'/settings'                                  => array(
				array( WP_REST_Server::READABLE, 'get_settings', $admin, array() ),
				array(
					WP_REST_Server::CREATABLE,
					'save_settings',
					$admin,
					array(
						'delete_data_on_uninstall' => array( 'type' => 'boolean' ),
						'debug'                    => array( 'type' => 'boolean' ),
					),
				),
			),
		);

		foreach ( $routes as $route => $endpoints ) {
			$definitions = array();
			foreach ( $endpoints as $endpoint ) {
				$definitions[] = array(
					'methods'             => $endpoint[0],
					'callback'            => array( self::class, $endpoint[1] ),
					'permission_callback' => $endpoint[2],
					'args'                => $endpoint[3],
				);
			}
			register_rest_route( self::NAMESPACE, $route, $definitions );
		}
	}

	/**
	 * Arguments of the activity route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function activity_args(): array {
		return array(
			'user_id'       => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'action'        => array(
				'type'    => 'string',
				'pattern' => '^[a-z_]{0,40}$',
			),
			'section'       => array(
				'type'    => 'string',
				'pattern' => '^[a-z0-9-]{0,31}$',
			),
			'result'        => array(
				'type' => 'string',
				'enum' => array_merge( array( '' ), ActivityLog::RESULTS ),
			),
			'from'          => array(
				'type'    => 'string',
				'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			),
			'to'            => array(
				'type'    => 'string',
				'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			),
			'search'        => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'include_reads' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'page'          => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'format'        => array(
				'type'    => 'string',
				'enum'    => array( 'json', 'csv' ),
				'default' => 'json',
			),
		);
	}

	/**
	 * Administrators only.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Anyone who may view activity (admins and Publishers).
	 */
	public static function can_view(): bool {
		return current_user_can( RoleManager::CAP_VIEW_ACTIVITY );
	}

	/**
	 * GET /config.
	 */
	public static function get_config(): WP_REST_Response {
		$repo = new ConfigRepository();
		return new WP_REST_Response( self::config_payload( $repo->get() ) );
	}

	/**
	 * Config with who saved it.
	 *
	 * @param array<string, mixed> $config Config.
	 * @return array<string, mixed>
	 */
	private static function config_payload( array $config ): array {
		$user = get_userdata( (int) ( $config['saved_by'] ?? 0 ) );
		return array(
			'config'        => $config,
			'version'       => (int) $config['version'],
			'saved_by_name' => false === $user ? '' : $user->display_name,
		);
	}

	/**
	 * GET /config/defaults (for "Reset to defaults").
	 */
	public static function get_defaults(): WP_REST_Response {
		return new WP_REST_Response( array( 'config' => DefaultConfig::get() ) );
	}

	/**
	 * POST /config/validate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function validate_config( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new ConfigRepository() )->validate( self::config_param( $request ) );
		return new WP_REST_Response(
			array(
				'valid'    => $result->is_valid(),
				'errors'   => $result->errors(),
				'warnings' => $result->warnings(),
			)
		);
	}

	/**
	 * PUT /config.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function save_config( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = (string) $request->get_param( 'source' );
		$action = array(
			'ui'     => 'config_save',
			'import' => 'config_import',
			'preset' => 'preset_apply',
		)[ $source ] ?? 'config_save';
		$note   = $request->get_param( 'note' );

		return self::save(
			self::config_param( $request ),
			$source,
			$action,
			is_string( $note ) && '' !== trim( $note ) ? $note : null,
			(int) $request->get_param( 'base_version' ),
			(bool) $request->get_param( 'accept_warnings' )
		);
	}

	/**
	 * Saves, audits and answers; a conflict comes back with the live config and a diff.
	 *
	 * @param array<string, mixed> $config          Config.
	 * @param string               $source          Source.
	 * @param string               $action          Audit action.
	 * @param string|null          $note            Note.
	 * @param int                  $base_version    Version the editor started from.
	 * @param bool                 $accept_warnings Warnings confirmed.
	 */
	private static function save( array $config, string $source, string $action, ?string $note, int $base_version, bool $accept_warnings ): WP_REST_Response|WP_Error {
		$repo  = new ConfigRepository();
		$saved = $repo->save( $config, get_current_user_id(), $source, $note, $base_version, $accept_warnings );

		if ( $saved instanceof WP_Error ) {
			$data = (array) $saved->get_error_data();
			if ( 'ccb_config_conflict' === $saved->get_error_code() ) {
				$live         = $repo->get();
				$data['live'] = self::config_payload( $live );
				$data['diff'] = ConfigDiff::diff( $config, $live );
				$saved->add_data( $data );
			}
			if ( 'ccb_config_needs_confirm' !== $saved->get_error_code() ) {
				ActivityLog::record(
					array(
						'action'     => $action,
						'result'     => 'rejected',
						'error_code' => $saved->get_error_code(),
						'message'    => $saved->get_error_message(),
					)
				);
			}
			return $saved;
		}

		ActivityLog::record(
			array(
				'action'  => $action,
				'result'  => 'success',
				/* translators: %d: settings version */
				'message' => sprintf( __( 'Saved version %d', 'causeway-claude-bridge' ), (int) $saved['version'] ) . ( null === $note ? '' : ': ' . $note ),
			),
			array(
				'version' => (int) $saved['version'],
				'source'  => $source,
			)
		);
		return new WP_REST_Response( self::config_payload( $saved ) );
	}

	/**
	 * GET /config/versions.
	 */
	public static function get_versions(): WP_REST_Response {
		$list = array();
		foreach ( ( new ConfigRepository() )->versions() as $row ) {
			$user   = get_userdata( $row['user_id'] );
			$list[] = $row + array(
				'user' => false === $user ? '' : $user->display_name,
				'time' => get_date_from_gmt( $row['created_at_gmt'], 'j M Y H:i' ),
			);
		}
		return new WP_REST_Response( $list );
	}

	/**
	 * GET /config/versions/{id}: the version and a diff against the live config.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function get_version( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo   = new ConfigRepository();
		$config = $repo->get_version_config( (int) $request->get_param( 'id' ) );
		if ( null === $config ) {
			return self::not_found();
		}
		return new WP_REST_Response(
			array(
				'config' => $config,
				'diff'   => ConfigDiff::diff( $repo->get(), $config ),
			)
		);
	}

	/**
	 * POST /config/versions/{id}/restore: saves the old version as a new one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function restore_version( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$config = ( new ConfigRepository() )->get_version_config( $id );
		if ( null === $config ) {
			return self::not_found();
		}
		/* translators: %d: version number restored */
		$note = sprintf( __( 'Restored version %d', 'causeway-claude-bridge' ), (int) ( $config['version'] ?? 0 ) );
		return self::save( $config, 'restore', 'config_restore', $note, (int) $request->get_param( 'base_version' ), (bool) $request->get_param( 'accept_warnings' ) );
	}

	/**
	 * POST /config/import: validates and diffs an uploaded file; saving is a separate PUT with source `import`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function import_config( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$json = (string) $request->get_param( 'json' );
		if ( strlen( $json ) > self::IMPORT_MAX_BYTES ) {
			return new WP_Error( 'ccb_import_too_large', __( 'That file is larger than 256 KB, so it can\'t be a settings export.', 'causeway-claude-bridge' ), array( 'status' => 413 ) );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return new WP_Error( 'ccb_import_invalid', __( 'That file isn\'t a settings export (it isn\'t valid JSON).', 'causeway-claude-bridge' ), array( 'status' => 400 ) );
		}
		$config = isset( $data['config'] ) && is_array( $data['config'] ) && ! isset( $data['sections'] ) ? $data['config'] : $data;
		$repo   = new ConfigRepository();
		$live   = $repo->get();

		// Keep the live bookkeeping so only real changes show and the schema doesn't trip on them.
		foreach ( array( 'schema_version', 'version', 'saved_by', 'saved_at_gmt' ) as $key ) {
			$config[ $key ] = $live[ $key ];
		}
		$result = $repo->validate( $config );
		return new WP_REST_Response(
			array(
				'config'   => $config,
				'valid'    => $result->is_valid(),
				'errors'   => $result->errors(),
				'warnings' => $result->warnings(),
				'diff'     => ConfigDiff::diff( $live, $config ),
			)
		);
	}

	/**
	 * GET /presets.
	 */
	public static function get_presets(): WP_REST_Response {
		return new WP_REST_Response( ( new Presets() )->all() );
	}

	/**
	 * POST /presets/{name}/preview: the config after applying the preset, and the diff.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function preview_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo    = new ConfigRepository();
		$preview = ( new Presets() )->preview( (string) $request->get_param( 'name' ), $repo->get(), ( new SiteStructure() )->get() );
		if ( null === $preview ) {
			return self::not_found();
		}
		$result = $repo->validate( $preview['config'] );
		return new WP_REST_Response(
			$preview + array(
				'valid'    => $result->is_valid(),
				'errors'   => $result->errors(),
				'warnings' => $result->warnings(),
			)
		);
	}

	/**
	 * GET /site-structure.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function get_site_structure( WP_REST_Request $request ): WP_REST_Response {
		if ( (bool) $request->get_param( 'refresh' ) ) {
			SiteStructure::clear_cache();
		}
		return new WP_REST_Response( ( new SiteStructure() )->get() );
	}

	/**
	 * POST /preview/{section}: the guide Claude would get for a section of an unsaved config.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function preview_section( WP_REST_Request $request ): WP_REST_Response {
		$config  = self::config_param( $request );
		$key     = (string) $request->get_param( 'section' );
		$section = $config['sections'][ $key ] ?? null;
		if ( is_array( $section ) ) {
			// Preview a switched-off section as if it were on, so the admin sees what turning it on would do.
			$config['sections'][ $key ]['enabled'] = true;
		}
		$structure = ( new SiteStructure() )->get();
		try {
			$guide = PublishingGuide::build( self::with_defaults( $config ), $structure, $key );
		} catch ( \Throwable $e ) {
			$guide = array( 'sections' => array() );
		}
		$built = $guide['sections'][0] ?? null;
		return new WP_REST_Response(
			array(
				'section'  => $key,
				'markdown' => is_array( $built ) ? (string) ( $built['guide'] ?? '' ) : '',
				'note'     => is_array( $built ) ? '' : __( 'Nothing to show: pick a post type that exists on this site.', 'causeway-claude-bridge' ),
			)
		);
	}

	/**
	 * Fills top-level keys an in-progress draft might lack, so the preview never fails on a half-edited form.
	 *
	 * @param array<string, mixed> $config Draft.
	 * @return array<string, mixed>
	 */
	private static function with_defaults( array $config ): array {
		$defaults = DefaultConfig::get();
		foreach ( $defaults as $key => $value ) {
			if ( ! isset( $config[ $key ] ) ) {
				$config[ $key ] = $value;
			}
		}
		foreach ( $config['sections'] as $key => $section ) {
			$config['sections'][ $key ] = array_replace( DefaultConfig::section( (string) ( $section['post_type'] ?? '' ), (string) ( $section['label'] ?? '' ) ), (array) $section );
		}
		return $config;
	}

	/**
	 * GET /status.
	 */
	public static function get_status(): WP_REST_Response {
		return new WP_REST_Response( Overview::data() );
	}

	/**
	 * POST /status/run.
	 */
	public static function run_status(): WP_REST_Response {
		( new HealthCheck() )->run();
		return new WP_REST_Response( Overview::data() );
	}

	/**
	 * GET /activity (JSON or CSV).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function get_activity( WP_REST_Request $request ): WP_REST_Response {
		$filters = array();
		foreach ( array( 'user_id', 'action', 'section', 'result', 'from', 'to', 'search', 'include_reads', 'page' ) as $key ) {
			$filters[ $key ] = $request->get_param( $key );
		}
		if ( 'csv' === $request->get_param( 'format' ) ) {
			$response = new WP_REST_Response( ActivityQuery::csv( $filters ) );
			$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
			$response->header( 'Content-Disposition', 'attachment; filename="claude-publishing-activity-' . gmdate( 'Y-m-d' ) . '.csv"' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			add_filter( 'rest_pre_serve_request', array( self::class, 'serve_csv' ), 10, 2 );
			return $response;
		}
		$result            = ActivityQuery::run( $filters );
		$result['users']   = self::activity_users();
		$result['actions'] = array_values( array_diff( self::audit_actions(), empty( $filters['include_reads'] ) ? ActivityQuery::READ_ACTIONS : array() ) );
		return new WP_REST_Response( $result );
	}

	/**
	 * Sends the CSV body as is instead of JSON-encoding it.
	 *
	 * @param bool  $served Already served.
	 * @param mixed $result Response.
	 */
	public static function serve_csv( bool $served, $result ): bool {
		remove_filter( 'rest_pre_serve_request', array( self::class, 'serve_csv' ), 10 );
		if ( $served || ! $result instanceof WP_REST_Response || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells made formula-safe in ActivityQuery::csv_line().
		return true;
	}

	/**
	 * Users for the activity filter.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	private static function activity_users(): array {
		$users = get_users(
			array(
				'capability' => RoleManager::CAP_USE,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		return array_map(
			static fn ( object $u ): array => array(
				'id'   => (int) $u->ID,
				'name' => (string) $u->display_name,
			),
			$users
		);
	}

	/**
	 * Every audit action (docs/06).
	 *
	 * @return list<string>
	 */
	public static function audit_actions(): array {
		return array( 'check', 'guide', 'list', 'get', 'create', 'update', 'upload_image', 'upload_link_created', 'upload_link_used', 'prepare', 'publish', 'schedule', 'update_live', 'unpublish', 'trash', 'restore', 'config_save', 'config_restore', 'config_import', 'preset_apply', 'role_change', 'app_password_revoke', 'health_check' );
	}

	/**
	 * GET /users.
	 */
	public static function get_users(): WP_REST_Response {
		$data               = UserAccess::list();
		$data['add_user']   = admin_url( 'user-new.php' );
		$data['candidates'] = $data['contributors'];
		return new WP_REST_Response( $data );
	}

	/**
	 * POST /users/{id}/role.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function set_user_role( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = UserAccess::set_role( (int) $request->get_param( 'id' ), (string) $request->get_param( 'role' ) );
		return $result instanceof WP_Error ? $result : new WP_REST_Response( $result );
	}

	/**
	 * GET /users/{id}/app-passwords.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function get_app_passwords( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = UserAccess::app_passwords( (int) $request->get_param( 'id' ) );
		return $result instanceof WP_Error ? $result : new WP_REST_Response( $result );
	}

	/**
	 * DELETE /users/{id}/app-passwords/{uuid}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function revoke_app_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = UserAccess::revoke( (int) $request->get_param( 'id' ), (string) $request->get_param( 'uuid' ) );
		return $result instanceof WP_Error ? $result : new WP_REST_Response( array( 'revoked' => true ) );
	}

	/**
	 * POST /notifications/test: sends a test email to the current user.
	 */
	public static function send_test_email(): WP_REST_Response|WP_Error {
		$user = wp_get_current_user();
		$sent = Mailer::send(
			array( $user->user_email ),
			__( 'Test email from Claude Publishing', 'causeway-claude-bridge' ),
			array(
				'heading' => __( 'Emails are working', 'causeway-claude-bridge' ),
				'intro'   => __( 'This is a test from Claude Publishing. Notifications about posts waiting for review, live changes and problems will look like this.', 'causeway-claude-bridge' ),
				'footer'  => Notifications::footer(),
			)
		);
		if ( ! $sent ) {
			return new WP_Error( 'ccb_mail_failed', __( 'WordPress couldn\'t send the email. Check the site\'s email settings or SMTP plugin.', 'causeway-claude-bridge' ), array( 'status' => 500 ) );
		}
		Overview::mark( 'test_email' );
		return new WP_REST_Response( array( 'sent_to' => $user->user_email ) );
	}

	/**
	 * GET /settings: options outside the config.
	 */
	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::settings() );
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		if ( null !== $request->get_param( 'delete_data_on_uninstall' ) ) {
			update_option( Uninstaller::OPTION_DELETE_DATA, (bool) $request->get_param( 'delete_data_on_uninstall' ) ? 1 : 0, false );
		}
		if ( null !== $request->get_param( 'debug' ) ) {
			if ( (bool) $request->get_param( 'debug' ) ) {
				update_option( self::OPTION_DEBUG_UNTIL, time() + DAY_IN_SECONDS, false );
			} else {
				delete_option( self::OPTION_DEBUG_UNTIL );
			}
		}
		return new WP_REST_Response( self::settings() );
	}

	/**
	 * Current options.
	 *
	 * @return array{delete_data_on_uninstall: bool, debug: bool, debug_until: string}
	 */
	private static function settings(): array {
		$until = (int) get_option( self::OPTION_DEBUG_UNTIL, 0 );
		$on    = $until > time();
		return array(
			'delete_data_on_uninstall' => (bool) get_option( Uninstaller::OPTION_DELETE_DATA, false ),
			'debug'                    => $on,
			'debug_until'              => $on ? wp_date( 'j M Y H:i', $until ) : '',
		);
	}

	/**
	 * The `config` body parameter as an array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>
	 */
	private static function config_param( WP_REST_Request $request ): array {
		$config = $request->get_param( 'config' );
		$config = is_array( $config ) ? $config : array();
		if ( ! isset( $config['sections'] ) || ! is_array( $config['sections'] ) ) {
			$config['sections'] = array();
		}
		return $config;
	}

	/**
	 * 404.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'ccb_not_found', __( 'Not found.', 'causeway-claude-bridge' ), array( 'status' => 404 ) );
	}
}
