<?php
/**
 * Activity tab (docs/08 "7. Activity").
 *
 * @package Causeway\ClaudeBridge
 *
 * @var bool $can_manage Whether the user is an administrator.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-3 ccb:mb-4">
	<h2><?php esc_html_e( 'Activity', 'causeway-claude-bridge' ); ?></h2>
	<a class="ccb-btn" :href="csvUrl()" download><?php esc_html_e( 'Export CSV', 'causeway-claude-bridge' ); ?></a>
</div>

<div class="ccb-card ccb:mb-4">
	<form class="ccb:grid ccb:gap-3 ccb:sm:grid-cols-2 ccb:lg:grid-cols-4 ccb:items-end" @submit.prevent="filter()" role="search" aria-label="<?php esc_attr_e( 'Filter activity', 'causeway-claude-bridge' ); ?>">
		<div>
			<label class="ccb-help" for="ccb-f-search"><?php esc_html_e( 'Post title', 'causeway-claude-bridge' ); ?></label>
			<input class="ccb-input" id="ccb-f-search" type="search" maxlength="100" x-model="filters.search">
		</div>
		<div>
			<label class="ccb-help" for="ccb-f-user"><?php esc_html_e( 'Person', 'causeway-claude-bridge' ); ?></label>
			<select class="ccb-select" id="ccb-f-user" x-model="filters.user_id" @change="filter()">
				<option value=""><?php esc_html_e( 'Everyone', 'causeway-claude-bridge' ); ?></option>
				<template x-for="u in activity.users" :key="u.id"><option :value="u.id" x-text="u.name"></option></template>
			</select>
		</div>
		<div>
			<label class="ccb-help" for="ccb-f-action"><?php esc_html_e( 'Action', 'causeway-claude-bridge' ); ?></label>
			<select class="ccb-select" id="ccb-f-action" x-model="filters.action" @change="filter()">
				<option value=""><?php esc_html_e( 'All changes', 'causeway-claude-bridge' ); ?></option>
				<template x-for="a in activity.actions" :key="a"><option :value="a" x-text="a"></option></template>
			</select>
		</div>
		<div>
			<label class="ccb-help" for="ccb-f-result"><?php esc_html_e( 'Result', 'causeway-claude-bridge' ); ?></label>
			<select class="ccb-select" id="ccb-f-result" x-model="filters.result" @change="filter()">
				<option value=""><?php esc_html_e( 'Any result', 'causeway-claude-bridge' ); ?></option>
				<option value="success"><?php esc_html_e( 'Done', 'causeway-claude-bridge' ); ?></option>
				<option value="rejected"><?php esc_html_e( 'Refused', 'causeway-claude-bridge' ); ?></option>
				<option value="error"><?php esc_html_e( 'Error', 'causeway-claude-bridge' ); ?></option>
			</select>
		</div>
		<div>
			<label class="ccb-help" for="ccb-f-section"><?php esc_html_e( 'Section', 'causeway-claude-bridge' ); ?></label>
			<select class="ccb-select" id="ccb-f-section" x-model="filters.section" @change="filter()">
				<option value=""><?php esc_html_e( 'All sections', 'causeway-claude-bridge' ); ?></option>
				<template x-for="key in Object.keys( ( config && config.sections ) || {} )" :key="key"><option :value="key" x-text="config.sections[ key ].label"></option></template>
			</select>
		</div>
		<div>
			<label class="ccb-help" for="ccb-f-from"><?php esc_html_e( 'From', 'causeway-claude-bridge' ); ?></label>
			<input class="ccb-input" id="ccb-f-from" type="date" x-model="filters.from" @change="filter()">
		</div>
		<div>
			<label class="ccb-help" for="ccb-f-to"><?php esc_html_e( 'To', 'causeway-claude-bridge' ); ?></label>
			<input class="ccb-input" id="ccb-f-to" type="date" x-model="filters.to" @change="filter()">
		</div>
		<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:gap-3">
			<label class="ccb:flex ccb:items-center ccb:gap-2"><input type="checkbox" class="ccb-check" x-model="filters.include_reads" @change="filter()"> <?php esc_html_e( 'Include look-ups', 'causeway-claude-bridge' ); ?></label>
			<button type="submit" class="ccb-btn ccb-btn-small"><?php esc_html_e( 'Search', 'causeway-claude-bridge' ); ?></button>
		</div>
	</form>
</div>

<div class="ccb-card">
	<p class="ccb-help ccb:mb-2" aria-live="polite" x-text="activity.loading ? t( 'loading' ) : t( 'entries' ).replace( '%d', activity.total )"></p>
	<p x-show="! activity.loading && activity.rows.length === 0" class="ccb:py-6 ccb:text-center ccb-help"><?php esc_html_e( 'Nothing here yet. Everything Claude does on the site will be listed here.', 'causeway-claude-bridge' ); ?></p>
	<div class="ccb-table-wrap" x-show="activity.rows.length">
		<table class="ccb-table">
			<caption class="ccb-sr-only"><?php esc_html_e( 'Activity log', 'causeway-claude-bridge' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Time', 'causeway-claude-bridge' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Person', 'causeway-claude-bridge' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Device', 'causeway-claude-bridge' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'causeway-claude-bridge' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Post', 'causeway-claude-bridge' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Section', 'causeway-claude-bridge' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'causeway-claude-bridge' ); ?></th>
			</tr></thead>
			<tbody>
				<template x-for="row in activity.rows" :key="row.id">
					<tr>
						<td><time :datetime="row.time_gmt" x-text="row.time"></time></td>
						<td x-text="row.user"></td>
						<td x-text="row.device || '—'"></td>
						<td><span x-text="row.action"></span><span class="ccb-help ccb:block" x-show="row.status_to" x-text="( row.status_from || '' ) + ' → ' + row.status_to"></span></td>
						<td>
							<template x-if="row.post_link"><a class="ccb-link" :href="row.post_link" x-text="row.post_title || ( '#' + row.post_id )"></a></template>
							<span x-show="! row.post_link" x-text="row.post_title || row.message || '—'"></span>
						</td>
						<td x-text="row.section || '—'"></td>
						<td>
							<span class="ccb-pill" :class="'ccb-pill-' + resultState( row.result )" x-text="resultLabel( row.result )"></span>
							<span class="ccb-help ccb:block" x-show="row.error_code" x-text="row.error_code + ' · ' + row.ref"></span>
						</td>
					</tr>
				</template>
			</tbody>
		</table>
	</div>
	<nav class="ccb:flex ccb:items-center ccb:justify-between ccb:gap-3 ccb:mt-3" x-show="activity.pages > 1" aria-label="<?php esc_attr_e( 'Activity pages', 'causeway-claude-bridge' ); ?>">
		<button type="button" class="ccb-btn ccb-btn-small" :disabled="activity.page <= 1" @click="loadActivity( activity.page - 1 )"><?php esc_html_e( 'Newer', 'causeway-claude-bridge' ); ?></button>
		<span class="ccb-help" x-text="t( 'pageOf' ).replace( '%1$s', activity.page ).replace( '%2$s', activity.pages )"></span>
		<button type="button" class="ccb-btn ccb-btn-small" :disabled="activity.page >= activity.pages" @click="loadActivity( activity.page + 1 )"><?php esc_html_e( 'Older', 'causeway-claude-bridge' ); ?></button>
	</nav>
</div>

<?php if ( $can_manage ) : ?>
	<div class="ccb-card ccb:mt-4">
		<label class="ccb-label" for="ccb-retention"><?php esc_html_e( 'Keep activity for (months)', 'causeway-claude-bridge' ); ?></label>
		<input class="ccb-input" id="ccb-retention" type="number" min="1" max="36" style="max-width: 120px" x-model.number="config.activity.retention_months" data-path="/activity/retention_months" :aria-invalid="err( '/activity/retention_months' )">
		<p class="ccb-error-text" x-show="err( '/activity/retention_months' )" x-text="errText( '/activity/retention_months' )"></p>
	</div>
<?php endif; ?>
