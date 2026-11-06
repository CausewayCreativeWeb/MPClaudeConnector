<?php
/**
 * Setup pack tab (docs/08 "8. Setup pack"). The download itself arrives with the setup pack (M9).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$ccb_profile = admin_url( 'profile.php' );
?>
<h2 class="ccb:mb-4"><?php esc_html_e( 'Setup pack', 'causeway-claude-bridge' ); ?></h2>
<div class="ccb:grid ccb:gap-4">
	<div class="ccb-card ccb:grid ccb:gap-3">
		<p><?php esc_html_e( 'The setup pack connects Claude Desktop to this site: an extension and a skill with this site\'s rules. It contains no passwords.', 'causeway-claude-bridge' ); ?></p>
		<p class="ccb-help" x-text="t( 'packVersion' ).replace( '%s', boot.setupPack.version )"></p>
		<div x-show="boot.setupPack.download">
			<a class="ccb-btn ccb-btn-primary" :href="boot.setupPack.download"><?php esc_html_e( 'Download setup pack', 'causeway-claude-bridge' ); ?></a>
		</div>
	</div>
	<div class="ccb-card">
		<h3 class="ccb:mb-3"><?php esc_html_e( 'Instructions for a new starter', 'causeway-claude-bridge' ); ?></h3>
		<ol id="ccb-starter" class="ccb:list-decimal ccb:pl-5 ccb:grid ccb:gap-2">
			<li><?php esc_html_e( 'Install Claude Desktop and sign in with your Claude account.', 'causeway-claude-bridge' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: link to the WordPress profile */
					esc_html__( 'In WordPress, open %s, scroll to Application Passwords and create one named after this computer (for example "Sam – office laptop"). Copy it: it is only shown once.', 'causeway-claude-bridge' ),
					'<a class="ccb-link" href="' . esc_url( $ccb_profile ) . '">' . esc_html__( 'your profile', 'causeway-claude-bridge' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'On the same profile page, download the setup pack.', 'causeway-claude-bridge' ); ?></li>
			<li><?php esc_html_e( 'Double-click the extension file to add it to Claude Desktop, and paste your username and Application Password when asked.', 'causeway-claude-bridge' ); ?></li>
			<li><?php esc_html_e( 'In Claude, add the skill from the pack (Settings → Capabilities → Skills).', 'causeway-claude-bridge' ); ?></li>
			<li><?php esc_html_e( 'Ask Claude: "Check the connection to the website."', 'causeway-claude-bridge' ); ?></li>
		</ol>
		<button type="button" class="ccb-btn ccb-btn-small ccb:mt-3" @click="copy( document.getElementById( 'ccb-starter' ).innerText )"><?php esc_html_e( 'Copy instructions', 'causeway-claude-bridge' ); ?></button>
	</div>
</div>
