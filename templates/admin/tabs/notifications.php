<?php
/**
 * Notifications tab (docs/08 "6. Notifications").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$ccb_events = array(
	'pending_post'   => array( __( 'A post is waiting for review', 'causeway-claude-bridge' ), __( 'When an AI Contributor finishes a post.', 'causeway-claude-bridge' ) ),
	'live_change'    => array( __( 'A live change is made', 'causeway-claude-bridge' ), __( 'Publish, schedule, update a live post, unpublish.', 'causeway-claude-bridge' ) ),
	'removal'        => array( __( 'A post is moved to the Bin or restored', 'causeway-claude-bridge' ), __( 'With a link to the Bin.', 'causeway-claude-bridge' ) ),
	'health_failure' => array( __( 'Something stops working', 'causeway-claude-bridge' ), __( 'Daily check failures and repeated errors, to the support contact and admins.', 'causeway-claude-bridge' ) ),
);
?>
<h2 class="ccb:mb-4"><?php esc_html_e( 'Notifications', 'causeway-claude-bridge' ); ?></h2>
<div class="ccb:grid ccb:gap-4">
	<div class="ccb-card ccb:grid ccb:gap-4">
		<h3><?php esc_html_e( 'Who gets emails', 'causeway-claude-bridge' ); ?></h3>
		<label class="ccb:flex ccb:items-center ccb:gap-3"><input type="checkbox" role="switch" class="ccb-toggle" x-model="config.notifications.recipients.publishers"> <span><?php esc_html_e( 'All Publishers (never about their own changes)', 'causeway-claude-bridge' ); ?></span></label>
		<div data-path="/notifications/recipients/extra" tabindex="-1">
			<label class="ccb-label" for="ccb-extra-email"><?php esc_html_e( 'Also send to', 'causeway-claude-bridge' ); ?></label>
			<div class="ccb:flex ccb:flex-wrap ccb:gap-1 ccb:mb-2">
				<template x-for="( email, i ) in config.notifications.recipients.extra" :key="email">
					<span class="ccb-chip"><span x-text="email"></span><button type="button" @click="config.notifications.recipients.extra.splice( i, 1 )" :aria-label="t( 'removeItem' ).replace( '%s', email )">&times;</button></span>
				</template>
			</div>
			<div class="ccb:flex ccb:gap-2">
				<input class="ccb-input" id="ccb-extra-email" type="email" maxlength="254" x-model="extraInput" @keydown.enter.prevent="addExtraEmail()" style="max-width: 360px">
				<button type="button" class="ccb-btn" @click="addExtraEmail()"><?php esc_html_e( 'Add', 'causeway-claude-bridge' ); ?></button>
			</div>
			<p class="ccb-error-text" x-show="err( '/notifications/recipients/extra' )" x-text="errText( '/notifications/recipients/extra' )"></p>
		</div>
	</div>

	<div class="ccb-card ccb:grid ccb:gap-4">
		<h3><?php esc_html_e( 'What to email about', 'causeway-claude-bridge' ); ?></h3>
		<?php foreach ( $ccb_events as $ccb_key => $ccb_event ) : ?>
			<div class="ccb:flex ccb:items-start ccb:gap-3">
				<input type="checkbox" role="switch" class="ccb-toggle" id="ccb-event-<?php echo esc_attr( $ccb_key ); ?>" x-model="config.notifications.events.<?php echo esc_attr( $ccb_key ); ?>" aria-describedby="ccb-event-<?php echo esc_attr( $ccb_key ); ?>-help">
				<div>
					<label for="ccb-event-<?php echo esc_attr( $ccb_key ); ?>" class="ccb:font-semibold"><?php echo esc_html( $ccb_event[0] ); ?></label>
					<p class="ccb-help" id="ccb-event-<?php echo esc_attr( $ccb_key ); ?>-help"><?php echo esc_html( $ccb_event[1] ); ?></p>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="ccb-card ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-3">
		<p><?php esc_html_e( 'Check that emails from this site arrive.', 'causeway-claude-bridge' ); ?></p>
		<button type="button" class="ccb-btn" @click="testEmail()"><?php esc_html_e( 'Send test email', 'causeway-claude-bridge' ); ?></button>
	</div>
</div>
