<?php
/**
 * Wires the plugin's services into WordPress.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge;

use Causeway\ClaudeBridge\Abilities\AbilityRegistrar;
use Causeway\ClaudeBridge\Config\SiteStructure;
use Causeway\ClaudeBridge\Mcp\ServerRegistrar;
use Causeway\ClaudeBridge\Roles\RoleManager;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Entry point once requirements are met. Later milestones add the MCP server, abilities, admin UI and cron handlers here.
 */
final class Plugin {

	public const UPDATE_METADATA_URL = 'https://repo.causewaycreative.co.uk/?action=get_metadata&slug=causeway-claude-bridge';

	/**
	 * Single instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The shared instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Registers hooks. Called once on `plugins_loaded`.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		RoleManager::register_hooks();
		SiteStructure::register_hooks();
		AbilityRegistrar::register_hooks();
		ServerRegistrar::register();

		add_action( 'init', array( Activation::class, 'maybe_upgrade' ), 1 );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_update_checker();
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'causeway-claude-bridge', false, dirname( plugin_basename( CCB_FILE ) ) . '/languages' );
	}

	/**
	 * Self-hosted updates, the same way as the site's other Causeway plugins.
	 */
	private function register_update_checker(): void {
		if ( ! class_exists( PucFactory::class ) || ! is_admin() ) {
			return;
		}
		PucFactory::buildUpdateChecker( self::UPDATE_METADATA_URL, CCB_FILE, 'causeway-claude-bridge' );
	}
}
