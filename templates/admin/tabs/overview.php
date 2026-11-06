<?php
/**
 * Overview tab (docs/08 "1. Overview").
 *
 * @package Causeway\ClaudeBridge
 *
 * @var bool $can_manage Whether the user is an administrator.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-3 ccb:mb-4">
	<h2><?php esc_html_e( 'Overview', 'causeway-claude-bridge' ); ?></h2>
	<?php if ( $can_manage ) : ?>
		<button type="button" class="ccb-btn" @click="loadStatus( true )" :disabled="statusLoading" :aria-busy="statusLoading ? 'true' : 'false'">
			<span x-text="statusLoading ? t( 'checking' ) : t( 'runChecks' )"></span>
		</button>
	<?php endif; ?>
</div>

<p x-show="! status" class="ccb-help"><?php esc_html_e( 'Loading…', 'causeway-claude-bridge' ); ?></p>

<template x-if="status">
	<div class="ccb:grid ccb:gap-4">
		<template x-if="! status.setup.complete && canManage">
			<div class="ccb-card">
				<h3 class="ccb:mb-3"><?php esc_html_e( 'Setup progress', 'causeway-claude-bridge' ); ?></h3>
				<ol class="ccb:grid ccb:gap-2 ccb:sm:grid-cols-2 ccb:lg:grid-cols-4 ccb:list-none ccb:m-0 ccb:p-0">
					<template x-for="( step, i ) in status.setup.steps" :key="step.key">
						<li class="ccb:flex ccb:items-start ccb:gap-2">
							<span class="ccb-pill" :class="step.done ? 'ccb-pill-good' : 'ccb-pill-neutral'" x-text="step.done ? t( 'done' ) : ( i + 1 )"></span>
							<a :href="'#' + step.key" class="ccb-link" @click.prevent="show( step.key === 'setup-pack' ? 'setup-pack' : step.key )" x-text="step.label"></a>
						</li>
					</template>
				</ol>
			</div>
		</template>

		<div class="ccb:grid ccb:gap-4 ccb:sm:grid-cols-2 ccb:xl:grid-cols-3">
			<template x-for="card in status.cards" :key="card.key">
				<div class="ccb-card">
					<div class="ccb:flex ccb:items-start ccb:justify-between ccb:gap-2">
						<h3 class="ccb:text-label" x-text="card.label"></h3>
						<span class="ccb-pill" :class="'ccb-pill-' + card.state" x-text="statusLabel( card.state )"></span>
					</div>
					<p class="ccb:mt-2 ccb:text-card" x-text="card.value"></p>
					<p class="ccb-help" x-show="card.note" x-text="card.note"></p>
				</div>
			</template>
		</div>

		<template x-if="status.issues.length">
			<div class="ccb-card">
				<h3 class="ccb:mb-3"><?php esc_html_e( 'Things to look at', 'causeway-claude-bridge' ); ?></h3>
				<ul class="ccb:grid ccb:gap-3 ccb:list-none ccb:m-0 ccb:p-0">
					<template x-for="issue in status.issues" :key="issue.code + issue.message">
						<li class="ccb:flex ccb:items-start ccb:gap-3">
							<span class="ccb-pill" :class="issue.severity === 'error' ? 'ccb-pill-problem' : 'ccb-pill-warning'" x-text="issue.severity === 'error' ? t( 'state_problem' ) : t( 'state_warning' )"></span>
							<div>
								<p x-text="issue.message"></p>
								<p class="ccb-help" x-show="issue.fix" x-text="issue.fix"></p>
							</div>
						</li>
					</template>
				</ul>
			</div>
		</template>

		<div class="ccb:grid ccb:gap-4 ccb:lg:grid-cols-2">
			<div class="ccb-card">
				<h3 class="ccb:mb-3"><?php esc_html_e( 'This week through Claude', 'causeway-claude-bridge' ); ?></h3>
				<dl class="ccb:grid ccb:grid-cols-2 ccb:gap-3 ccb:m-0">
					<div><dt class="ccb-help"><?php esc_html_e( 'Created', 'causeway-claude-bridge' ); ?></dt><dd class="ccb:m-0 ccb:text-tab ccb:font-semibold" x-text="status.week.created"></dd></div>
					<div><dt class="ccb-help"><?php esc_html_e( 'Published', 'causeway-claude-bridge' ); ?></dt><dd class="ccb:m-0 ccb:text-tab ccb:font-semibold" x-text="status.week.published"></dd></div>
					<div><dt class="ccb-help"><?php esc_html_e( 'Scheduled', 'causeway-claude-bridge' ); ?></dt><dd class="ccb:m-0 ccb:text-tab ccb:font-semibold" x-text="status.week.scheduled"></dd></div>
					<div><dt class="ccb-help"><?php esc_html_e( 'Moved to the Bin', 'causeway-claude-bridge' ); ?></dt><dd class="ccb:m-0 ccb:text-tab ccb:font-semibold" x-text="status.week.binned"></dd></div>
				</dl>
			</div>
			<div class="ccb-card">
				<h3 class="ccb:mb-3"><?php esc_html_e( 'Waiting for review', 'causeway-claude-bridge' ); ?></h3>
				<p x-show="status.pending.length === 0" class="ccb-help"><?php esc_html_e( 'No sections are switched on yet.', 'causeway-claude-bridge' ); ?></p>
				<ul class="ccb:grid ccb:gap-2 ccb:list-none ccb:m-0 ccb:p-0">
					<template x-for="p in status.pending" :key="p.section">
						<li class="ccb:flex ccb:justify-between ccb:gap-3">
							<a class="ccb-link" :href="p.link" x-text="p.label"></a>
							<span x-text="t( p.count === 1 ? 'postsOne' : 'postsMany' ).replace( '%d', p.count )"></span>
						</li>
					</template>
				</ul>
			</div>
		</div>

		<p class="ccb-help">
			<?php esc_html_e( 'Claude connects to:', 'causeway-claude-bridge' ); ?> <code x-text="status.endpoint"></code>
		</p>
	</div>
</template>
