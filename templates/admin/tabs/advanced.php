<?php
/**
 * Advanced tab (docs/08 "9. Advanced").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<h2 class="ccb:mb-4"><?php esc_html_e( 'Advanced', 'causeway-claude-bridge' ); ?></h2>
<div class="ccb:grid ccb:gap-4">
	<div class="ccb-card ccb:grid ccb:gap-4">
		<h3><?php esc_html_e( 'Support contact', 'causeway-claude-bridge' ); ?></h3>
		<p class="ccb-help"><?php esc_html_e( 'Shown to staff in emails and error messages, and sent alerts when something breaks.', 'causeway-claude-bridge' ); ?></p>
		<div class="ccb:grid ccb:gap-4 ccb:md:grid-cols-3">
			<div>
				<label class="ccb-label" for="ccb-support-name"><?php esc_html_e( 'Name', 'causeway-claude-bridge' ); ?></label>
				<input class="ccb-input" id="ccb-support-name" type="text" maxlength="100" x-model="config.support.name" data-path="/support/name">
			</div>
			<div>
				<label class="ccb-label" for="ccb-support-email"><?php esc_html_e( 'Email', 'causeway-claude-bridge' ); ?></label>
				<input class="ccb-input" id="ccb-support-email" type="email" required maxlength="254" x-model="config.support.email" data-path="/support/email" :aria-invalid="err( '/support/email' )" aria-describedby="ccb-support-email-err">
				<p class="ccb-error-text" id="ccb-support-email-err" x-show="err( '/support/email' )" x-text="errText( '/support/email' )"></p>
			</div>
			<div>
				<label class="ccb-label" for="ccb-support-url"><?php esc_html_e( 'Help page (optional)', 'causeway-claude-bridge' ); ?></label>
				<input class="ccb-input" id="ccb-support-url" type="url" maxlength="2000" x-model="config.support.url" data-path="/support/url" :aria-invalid="err( '/support/url' )" aria-describedby="ccb-support-url-err">
				<p class="ccb-error-text" id="ccb-support-url-err" x-show="err( '/support/url' )" x-text="errText( '/support/url' )"></p>
			</div>
		</div>
	</div>

	<div class="ccb-card">
		<h3 class="ccb:mb-3"><?php esc_html_e( 'Presets', 'causeway-claude-bridge' ); ?></h3>
		<p class="ccb-help ccb:mb-3"><?php esc_html_e( 'A starting point for a type of site. You see every change before it\'s applied.', 'causeway-claude-bridge' ); ?></p>
		<ul class="ccb:grid ccb:gap-3 ccb:list-none ccb:m-0 ccb:p-0">
			<template x-for="p in ( presets || [] )" :key="p.name">
				<li class="ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-3">
					<div><p class="ccb:font-semibold" x-text="p.label"></p><p class="ccb-help" x-text="p.description"></p></div>
					<button type="button" class="ccb-btn ccb-btn-small" @click="previewPreset( p.name )"><?php esc_html_e( 'Preview and apply', 'causeway-claude-bridge' ); ?></button>
				</li>
			</template>
		</ul>
	</div>

	<div class="ccb-card">
		<h3 class="ccb:mb-3"><?php esc_html_e( 'Version history', 'causeway-claude-bridge' ); ?></h3>
		<div class="ccb-table-wrap">
			<table class="ccb-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Version', 'causeway-claude-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Saved', 'causeway-claude-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'By', 'causeway-claude-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Note', 'causeway-claude-bridge' ); ?></th>
					<th scope="col"><span class="ccb-sr-only"><?php esc_html_e( 'Actions', 'causeway-claude-bridge' ); ?></span></th>
				</tr></thead>
				<tbody>
					<template x-for="v in ( versions || [] )" :key="v.id">
						<tr>
							<td x-text="v.version + ( v.version === version ? ' ' + t( 'current' ) : '' )"></td>
							<td x-text="v.time"></td>
							<td x-text="v.user || '—'"></td>
							<td x-text="v.note || v.source"></td>
							<td class="ccb:text-right"><button type="button" class="ccb-btn ccb-btn-small" x-show="v.version !== version" @click="viewVersion( v )"><?php esc_html_e( 'Compare and restore', 'causeway-claude-bridge' ); ?></button></td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</div>

	<div class="ccb-card ccb:grid ccb:gap-3">
		<h3><?php esc_html_e( 'Export and import', 'causeway-claude-bridge' ); ?></h3>
		<p class="ccb-help"><?php esc_html_e( 'Move settings between sites. An import is checked and shown to you before anything changes.', 'causeway-claude-bridge' ); ?></p>
		<div class="ccb:flex ccb:flex-wrap ccb:gap-2">
			<button type="button" class="ccb-btn" @click="exportConfig()"><?php esc_html_e( 'Export settings', 'causeway-claude-bridge' ); ?></button>
			<label class="ccb-btn" for="ccb-import" tabindex="0" @keydown.enter.prevent="$refs.importInput.click()" @keydown.space.prevent="$refs.importInput.click()"><?php esc_html_e( 'Import settings…', 'causeway-claude-bridge' ); ?></label>
			<input id="ccb-import" x-ref="importInput" class="ccb-sr-only" type="file" accept="application/json,.json" @change="importFile( $event )" tabindex="-1">
		</div>
	</div>

	<div class="ccb-card ccb:grid ccb:gap-4">
		<h3><?php esc_html_e( 'Maintenance', 'causeway-claude-bridge' ); ?></h3>
		<div class="ccb:flex ccb:items-start ccb:gap-3">
			<input type="checkbox" role="switch" class="ccb-toggle" id="ccb-debug" :checked="settings && settings.debug" @change="saveSetting( 'debug', $event.target.checked )" aria-describedby="ccb-debug-help">
			<div>
				<label for="ccb-debug" class="ccb:font-semibold"><?php esc_html_e( 'Debug mode', 'causeway-claude-bridge' ); ?></label>
				<p class="ccb-help" id="ccb-debug-help" x-text="settings && settings.debug ? t( 'debugUntil' ).replace( '%s', settings.debug_until ) : t( 'debugOff' )"></p>
			</div>
		</div>
		<div class="ccb:flex ccb:items-start ccb:gap-3">
			<input type="checkbox" role="switch" class="ccb-toggle" id="ccb-delete-data" :checked="settings && settings.delete_data_on_uninstall" @change="saveSetting( 'delete_data_on_uninstall', $event.target.checked )" aria-describedby="ccb-delete-data-help">
			<div>
				<label for="ccb-delete-data" class="ccb:font-semibold"><?php esc_html_e( 'Delete all data when the plugin is deleted', 'causeway-claude-bridge' ); ?></label>
				<p class="ccb-help" id="ccb-delete-data-help"><?php esc_html_e( 'Settings, history and the activity log. Posts and images are never deleted.', 'causeway-claude-bridge' ); ?></p>
			</div>
		</div>
		<div><button type="button" class="ccb-btn ccb-btn-danger" @click="resetDefaults()"><?php esc_html_e( 'Reset to defaults…', 'causeway-claude-bridge' ); ?></button></div>
	</div>
</div>
