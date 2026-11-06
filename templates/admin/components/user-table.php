<?php
/**
 * Publishers or Contributors with their device passwords (docs/08 "Access").
 *
 * @package Causeway\ClaudeBridge
 *
 * @var string $ccb_list Alpine expression for the list.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="ccb-table-wrap" x-show="<?php echo esc_attr( $ccb_list ); ?>.length">
	<table class="ccb-table">
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Name', 'causeway-claude-bridge' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Devices', 'causeway-claude-bridge' ); ?></th>
			<th scope="col"><span class="ccb-sr-only"><?php esc_html_e( 'Actions', 'causeway-claude-bridge' ); ?></span></th>
		</tr></thead>
		<tbody>
			<template x-for="u in <?php echo esc_attr( $ccb_list ); ?>" :key="u.id">
				<tr>
					<td>
						<a class="ccb-link" :href="u.edit_link" x-text="u.name"></a>
						<span class="ccb-help ccb:block" x-text="u.email"></span>
						<span x-show="u.locked" class="ccb-help ccb:block"><?php esc_html_e( 'Admin, always a Publisher', 'causeway-claude-bridge' ); ?></span>
					</td>
					<td>
						<button type="button" class="ccb-link" @click="passwords[ u.id ] ? delete passwords[ u.id ] : loadPasswords( u.id )" :aria-expanded="passwords[ u.id ] ? 'true' : 'false'"
							x-text="t( u.app_passwords === 1 ? 'devicesOne' : 'devicesMany' ).replace( '%d', u.app_passwords )"></button>
						<template x-if="passwords[ u.id ]">
							<ul class="ccb:mt-2 ccb:grid ccb:gap-2 ccb:list-none ccb:m-0 ccb:p-0">
								<template x-for="p in passwords[ u.id ]" :key="p.uuid">
									<li class="ccb:flex ccb:flex-wrap ccb:items-center ccb:gap-2">
										<span class="ccb:font-semibold" x-text="p.name"></span>
										<span class="ccb-help" x-text="t( 'deviceMeta' ).replace( '%1$s', p.created || '—' ).replace( '%2$s', p.last_used || t( 'never' ) ).replace( '%3$s', p.last_ip || '—' )"></span>
										<button type="button" class="ccb-btn ccb-btn-small ccb-btn-danger" @click="revoke( u, p )"><?php esc_html_e( 'Revoke', 'causeway-claude-bridge' ); ?></button>
									</li>
								</template>
								<li x-show="passwords[ u.id ].length === 0" class="ccb-help"><?php esc_html_e( 'No devices connected.', 'causeway-claude-bridge' ); ?></li>
							</ul>
						</template>
					</td>
					<td class="ccb:text-right">
						<button type="button" class="ccb-btn ccb-btn-small" x-show="! u.locked && u.role === 'publisher'" @click="setRole( u.id, 'contributor' )"><?php esc_html_e( 'Make Contributor', 'causeway-claude-bridge' ); ?></button>
						<button type="button" class="ccb-btn ccb-btn-small" x-show="u.role === 'contributor'" @click="setRole( u.id, 'publisher' )"><?php esc_html_e( 'Make Publisher', 'causeway-claude-bridge' ); ?></button>
					</td>
				</tr>
			</template>
		</tbody>
	</table>
</div>
