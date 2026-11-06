<?php
/**
 * Access tab (docs/08 "5. Access").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<h2 class="ccb:mb-4"><?php esc_html_e( 'Access', 'causeway-claude-bridge' ); ?></h2>
<p x-show="! users" class="ccb-help"><?php esc_html_e( 'Loading…', 'causeway-claude-bridge' ); ?></p>
<template x-if="users">
	<div class="ccb:grid ccb:gap-4">
		<div class="ccb-card">
			<h3><?php esc_html_e( 'Publishers', 'causeway-claude-bridge' ); ?></h3>
			<p class="ccb-help ccb:mb-3"><?php esc_html_e( 'Can publish, schedule, update live posts and move posts to the Bin through Claude, each after a clear yes.', 'causeway-claude-bridge' ); ?></p>
			<div class="ccb:flex ccb:flex-wrap ccb:items-end ccb:gap-2 ccb:mb-4">
				<div>
					<label class="ccb-help" for="ccb-picker"><?php esc_html_e( 'Make an AI Contributor a Publisher', 'causeway-claude-bridge' ); ?></label>
					<select class="ccb-select" id="ccb-picker" x-model="picker" style="min-width: 240px">
						<option value=""><?php esc_html_e( 'Choose a person…', 'causeway-claude-bridge' ); ?></option>
						<template x-for="u in users.candidates" :key="u.id"><option :value="u.id" x-text="u.name + ' (' + u.email + ')'"></option></template>
					</select>
				</div>
				<button type="button" class="ccb-btn" :disabled="! picker" @click="setRole( picker, 'publisher' )"><?php esc_html_e( 'Make Publisher', 'causeway-claude-bridge' ); ?></button>
			</div>
			<?php \Causeway\ClaudeBridge\Admin\AdminPage::component( 'user-table', array( 'ccb_list' => 'users.publishers' ) ); ?>
		</div>

		<div class="ccb-card">
			<div class="ccb:flex ccb:flex-wrap ccb:items-start ccb:justify-between ccb:gap-2">
				<div>
					<h3><?php esc_html_e( 'Contributors', 'causeway-claude-bridge' ); ?></h3>
					<p class="ccb-help ccb:mb-3"><?php esc_html_e( 'Can draft and edit through Claude. Everything they write waits for review.', 'causeway-claude-bridge' ); ?></p>
				</div>
				<a class="ccb-btn ccb-btn-small" :href="users.add_user"><?php esc_html_e( 'Add a new user', 'causeway-claude-bridge' ); ?></a>
			</div>
			<p class="ccb-help" x-show="users.contributors.length === 0"><?php esc_html_e( 'No AI Contributors yet. Add a user with the AI Contributor role.', 'causeway-claude-bridge' ); ?></p>
			<?php \Causeway\ClaudeBridge\Admin\AdminPage::component( 'user-table', array( 'ccb_list' => 'users.contributors' ) ); ?>
		</div>

		<div class="ccb-card">
			<h3 class="ccb:mb-3"><?php esc_html_e( 'Limits', 'causeway-claude-bridge' ); ?></h3>
			<div class="ccb:grid ccb:gap-4 ccb:md:grid-cols-2">
				<div>
					<label class="ccb-label" for="ccb-rpm"><?php esc_html_e( 'Requests per minute, per person', 'causeway-claude-bridge' ); ?></label>
					<input class="ccb-input" id="ccb-rpm" type="number" min="5" max="120" x-model.number="config.access.requests_per_minute" data-path="/access/requests_per_minute" :aria-invalid="err( '/access/requests_per_minute' )">
					<p class="ccb-error-text" x-show="err( '/access/requests_per_minute' )" x-text="errText( '/access/requests_per_minute' )"></p>
				</div>
				<div>
					<label class="ccb-label" for="ccb-lpd"><?php esc_html_e( 'Live changes per day, per person', 'causeway-claude-bridge' ); ?></label>
					<input class="ccb-input" id="ccb-lpd" type="number" min="1" max="200" x-model.number="config.access.live_changes_per_day" data-path="/access/live_changes_per_day" :aria-invalid="err( '/access/live_changes_per_day' )">
					<p class="ccb-error-text" x-show="err( '/access/live_changes_per_day' )" x-text="errText( '/access/live_changes_per_day' )"></p>
				</div>
			</div>
		</div>
	</div>
</template>
