<?php
/**
 * Confirm, warnings, conflict and diff dialog (native <dialog>: focus trapped, Escape closes).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<dialog x-ref="dialog" class="ccb-dialog" aria-labelledby="ccb-dialog-title" @close="dialog = null" @cancel.prevent="closeDialog()">
	<template x-if="dialog">
		<div>
			<h2 id="ccb-dialog-title" class="ccb:mb-3" x-text="dialog.kind === 'warnings' ? t( 'warningsTitle' ) : ( dialog.kind === 'conflict' ? t( 'conflictTitle' ) : dialog.title )"></h2>

			<p x-show="dialog.body" x-text="dialog.body" class="ccb:mb-3"></p>

			<template x-if="dialog.kind === 'warnings'">
				<div>
					<p class="ccb:mb-2"><?php esc_html_e( 'These settings can be saved, but check them first:', 'causeway-claude-bridge' ); ?></p>
					<ul class="ccb:list-disc ccb:pl-5 ccb:mb-3">
						<template x-for="w in dialog.warnings" :key="w.path + w.code"><li x-text="w.message"></li></template>
					</ul>
				</div>
			</template>

			<template x-if="dialog.kind === 'conflict'">
				<p class="ccb:mb-3" x-text="t( 'conflictBody' ).replace( '%1$s', dialog.live.saved_by_name || t( 'installer' ) ).replace( '%2$s', dialog.live.version )"></p>
			</template>

			<template x-if="dialog.notes && dialog.notes.length">
				<ul class="ccb:list-disc ccb:pl-5 ccb:mb-3 ccb:text-label">
					<template x-for="n in dialog.notes" :key="n"><li x-text="n"></li></template>
				</ul>
			</template>

			<template x-if="dialog.diff">
				<div class="ccb-table-wrap ccb:mb-3" style="max-height: 50vh; overflow: auto">
					<p x-show="dialog.diff.length === 0" class="ccb-help"><?php esc_html_e( 'No differences.', 'causeway-claude-bridge' ); ?></p>
					<table class="ccb-table" x-show="dialog.diff.length > 0">
						<thead><tr><th scope="col"><?php esc_html_e( 'Setting', 'causeway-claude-bridge' ); ?></th><th scope="col" x-text="dialog.kind === 'conflict' ? t( 'yours' ) : t( 'before' )"></th><th scope="col" x-text="dialog.kind === 'conflict' ? t( 'theirs' ) : t( 'after' )"></th></tr></thead>
						<tbody>
							<template x-for="d in dialog.diff" :key="d.path">
								<tr>
									<td><code x-text="d.path"></code></td>
									<td class="ccb-diff-before" x-text="diffValue( d.before )"></td>
									<td class="ccb-diff-after" x-text="diffValue( d.after )"></td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</template>

			<template x-if="dialog.typed !== undefined">
				<div class="ccb:mb-3">
					<label class="ccb-label" for="ccb-typed" x-text="t( 'typeToConfirm' ).replace( '%s', dialog.expect )"></label>
					<input id="ccb-typed" class="ccb-input" type="text" x-model="dialog.typed" autocomplete="off">
				</div>
			</template>

			<div class="ccb:flex ccb:flex-wrap ccb:justify-end ccb:gap-2 ccb:mt-4">
				<template x-if="dialog.kind === 'conflict'">
					<button type="button" class="ccb-btn" @click="const l = dialog.live; closeDialog(); reloadFromLive( l )"><?php esc_html_e( 'Reload their version', 'causeway-claude-bridge' ); ?></button>
				</template>
				<button type="button" class="ccb-btn" @click="closeDialog()"><?php esc_html_e( 'Cancel', 'causeway-claude-bridge' ); ?></button>
				<button type="button" class="ccb-btn" :class="dialog.danger ? 'ccb-btn-danger' : 'ccb-btn-primary'"
					x-show="!! dialog.onConfirm" :disabled="dialog.blocked || ( dialog.typed !== undefined && dialog.typed !== dialog.expect )"
					@click="confirmDialog()"
					x-text="dialog.kind === 'warnings' ? t( 'saveAnyway' ) : ( dialog.kind === 'conflict' ? t( 'overwrite' ) : ( dialog.confirmLabel || t( 'confirm' ) ) )"></button>
			</div>
		</div>
	</template>
</dialog>
