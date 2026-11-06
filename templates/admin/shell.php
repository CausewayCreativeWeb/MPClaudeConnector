<?php
/**
 * App shell for Settings → Claude Publishing (docs/08 "App shell").
 *
 * @package Causeway\ClaudeBridge
 *
 * @var array<string, string> $tabs       Tabs the user can see.
 * @var bool                  $can_manage Whether the user is an administrator.
 */

declare(strict_types=1);

use Causeway\ClaudeBridge\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

$ccb_site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
?>
<div class="wrap">
<div id="ccb-app" x-data="ccbApp" x-cloak>
	<div class="ccb-sr-only" role="status" aria-live="polite" x-text="live"></div>

	<header class="ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-4 ccb:py-4 ccb:mb-2">
		<div class="ccb:flex ccb:flex-wrap ccb:items-baseline ccb:gap-x-3 ccb:gap-y-1">
			<h1 class="ccb-page-title"><?php esc_html_e( 'Claude Publishing', 'causeway-claude-bridge' ); ?></h1>
			<p class="ccb:text-body ccb:text-muted"><?php echo esc_html( $ccb_site ); ?></p>
		</div>
		<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:gap-3 ccb:text-label ccb:text-muted">
			<span x-text="t( 'versionBy' ).replace( '%1$s', version ).replace( '%2$s', savedBy || t( 'installer' ) )"></span>
			<span class="ccb-pill" :class="'ccb-pill-' + ( status ? status.status : 'neutral' )" x-text="status ? t( 'status_' + status.status ) : t( 'checking' )"></span>
			<template x-if="boot.support && boot.support.email">
				<a class="ccb-link" :href="boot.support.url || ( 'mailto:' + boot.support.email )"><?php esc_html_e( 'Help', 'causeway-claude-bridge' ); ?></a>
			</template>
		</div>
	</header>

	<div class="ccb-layout">
		<nav aria-label="<?php esc_attr_e( 'Claude Publishing settings', 'causeway-claude-bridge' ); ?>">
			<div class="ccb-nav" role="tablist" aria-orientation="vertical">
				<?php $ccb_index = 0; ?>
				<?php foreach ( $tabs as $ccb_key => $ccb_label ) : ?>
					<a href="#<?php echo esc_attr( $ccb_key ); ?>" id="ccb-tab-<?php echo esc_attr( $ccb_key ); ?>" role="tab"
						aria-controls="ccb-panel-<?php echo esc_attr( $ccb_key ); ?>"
						:aria-selected="tab === '<?php echo esc_js( $ccb_key ); ?>' ? 'true' : 'false'"
						:tabindex="tab === '<?php echo esc_js( $ccb_key ); ?>' ? 0 : -1"
						@click.prevent="show( '<?php echo esc_js( $ccb_key ); ?>' )"
						@keydown="navKey( $event, <?php echo (int) $ccb_index; ?> )">
						<span><?php echo esc_html( $ccb_label ); ?></span>
						<span x-show="errorTab( '<?php echo esc_js( $ccb_key ); ?>' )" class="ccb-dot ccb-dot-error" role="img" aria-label="<?php esc_attr_e( 'has errors', 'causeway-claude-bridge' ); ?>"></span>
						<span x-show="! errorTab( '<?php echo esc_js( $ccb_key ); ?>' ) && dirtyTab( '<?php echo esc_js( $ccb_key ); ?>' )" class="ccb-dot" role="img" aria-label="<?php esc_attr_e( 'unsaved changes', 'causeway-claude-bridge' ); ?>"></span>
					</a>
					<?php ++$ccb_index; ?>
				<?php endforeach; ?>
			</div>
		</nav>

		<main class="ccb-main">
			<?php foreach ( array_keys( $tabs ) as $ccb_key ) : ?>
				<section id="ccb-panel-<?php echo esc_attr( $ccb_key ); ?>" role="tabpanel" aria-labelledby="ccb-tab-<?php echo esc_attr( $ccb_key ); ?>" tabindex="-1"
					x-show="tab === '<?php echo esc_js( $ccb_key ); ?>'">
					<?php include CCB_DIR . 'templates/admin/tabs/' . $ccb_key . '.php'; ?>
				</section>
			<?php endforeach; ?>

			<?php if ( $can_manage ) : ?>
				<?php AdminPage::component( 'save-bar' ); ?>
			<?php endif; ?>
		</main>
	</div>

	<?php AdminPage::component( 'dialog' ); ?>

	<div class="ccb-toast" x-show="toast" x-transition.opacity x-text="toast" aria-hidden="true"></div>
</div>
</div>
