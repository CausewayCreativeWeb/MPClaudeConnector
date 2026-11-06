<?php
/**
 * Sticky save bar (docs/08 "App shell", "Save flow").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="ccb-savebar ccb:mt-6" x-show="dirtyCount() > 0 || errors.length > 0" x-transition.opacity>
	<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-3">
		<p class="ccb:font-semibold" x-text="t( dirtyCount() === 1 ? 'unsavedOne' : 'unsavedMany' ).replace( '%d', dirtyCount() )"></p>
		<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:gap-2">
			<label class="ccb-sr-only" for="ccb-save-note"><?php esc_html_e( 'Note for the version history (optional)', 'causeway-claude-bridge' ); ?></label>
			<input id="ccb-save-note" class="ccb-input" style="width: 240px" type="text" maxlength="255" x-model="note" placeholder="<?php esc_attr_e( 'Note for the history (optional)', 'causeway-claude-bridge' ); ?>">
			<button type="button" class="ccb-btn" @click="discard()" :disabled="saving"><?php esc_html_e( 'Discard', 'causeway-claude-bridge' ); ?></button>
			<button type="button" class="ccb-btn ccb-btn-primary" @click="save()" :disabled="saving" :aria-busy="saving ? 'true' : 'false'">
				<span x-text="saving ? t( 'saving' ) : t( 'saveChanges' )"></span>
			</button>
		</div>
	</div>
	<div id="ccb-error-summary" tabindex="-1" x-show="errors.length > 0" class="ccb:mt-3">
		<p class="ccb-error-text" x-text="t( 'invalid' ).replace( '%d', errors.length )"></p>
		<ul class="ccb:mt-1 ccb:list-disc ccb:pl-5 ccb:text-label">
			<template x-for="e in errors" :key="e.path + e.code">
				<li><button type="button" class="ccb-link" @click="goToError( e )" x-text="e.message"></button> <code x-text="e.path"></code></li>
			</template>
		</ul>
	</div>
</div>
