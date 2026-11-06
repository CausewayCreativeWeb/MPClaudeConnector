<?php
/**
 * Writing style tab (docs/08 "3. Writing style").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$ccb_tags = array( 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'strong', 'em', 'blockquote', 'figure', 'figcaption', 'img', 'br' );
?>
<h2 class="ccb:mb-4"><?php esc_html_e( 'Writing style', 'causeway-claude-bridge' ); ?></h2>
<div class="ccb:grid ccb:gap-4">
	<div class="ccb-card ccb:grid ccb:gap-5">
		<div class="ccb:grid ccb:gap-4 ccb:md:grid-cols-2">
			<div>
				<label class="ccb-label" for="ccb-language"><?php esc_html_e( 'Language', 'causeway-claude-bridge' ); ?></label>
				<select class="ccb-select" id="ccb-language" x-model="config.writing.language" data-path="/writing/language">
					<option value="en-GB"><?php esc_html_e( 'English (UK)', 'causeway-claude-bridge' ); ?></option>
					<option value="en-US"><?php esc_html_e( 'English (US)', 'causeway-claude-bridge' ); ?></option>
					<option value="en"><?php esc_html_e( 'English', 'causeway-claude-bridge' ); ?></option>
					<option value="cy-GB"><?php esc_html_e( 'Welsh', 'causeway-claude-bridge' ); ?></option>
					<option value="ga-IE"><?php esc_html_e( 'Irish', 'causeway-claude-bridge' ); ?></option>
					<option value="gd-GB"><?php esc_html_e( 'Scottish Gaelic', 'causeway-claude-bridge' ); ?></option>
				</select>
			</div>
			<fieldset class="ccb:border-0 ccb:p-0 ccb:m-0" data-path="/writing/headings" tabindex="-1">
				<legend class="ccb-label"><?php esc_html_e( 'Headings Claude may use', 'causeway-claude-bridge' ); ?></legend>
				<div class="ccb:flex ccb:flex-wrap ccb:gap-4">
					<?php foreach ( array( 'h2', 'h3', 'h4' ) as $ccb_h ) : ?>
						<label class="ccb:flex ccb:items-center ccb:gap-2"><input type="checkbox" class="ccb-check" value="<?php echo esc_attr( $ccb_h ); ?>" x-model="config.writing.headings"> <?php echo esc_html( strtoupper( $ccb_h ) ); ?></label>
					<?php endforeach; ?>
				</div>
				<p class="ccb-error-text" x-show="err( '/writing/headings' )" x-text="errText( '/writing/headings' )"></p>
			</fieldset>
		</div>

		<fieldset class="ccb:border-0 ccb:p-0 ccb:m-0" data-path="/writing/allowed_html" tabindex="-1">
			<legend class="ccb-label"><?php esc_html_e( 'Allowed HTML', 'causeway-claude-bridge' ); ?></legend>
			<div class="ccb:grid ccb:grid-cols-3 ccb:sm:grid-cols-6 ccb:gap-2">
				<?php foreach ( $ccb_tags as $ccb_tag ) : ?>
					<label class="ccb:flex ccb:items-center ccb:gap-2"><input type="checkbox" class="ccb-check" value="<?php echo esc_attr( $ccb_tag ); ?>" x-model="config.writing.allowed_html"> <code>&lt;<?php echo esc_html( $ccb_tag ); ?>&gt;</code></label>
				<?php endforeach; ?>
			</div>
			<p class="ccb-help"><?php esc_html_e( 'Anything else is removed from what Claude sends. Scripts, styles and embeds are never allowed.', 'causeway-claude-bridge' ); ?></p>
			<p class="ccb-error-text" x-show="err( '/writing/allowed_html' )" x-text="errText( '/writing/allowed_html' )"></p>
		</fieldset>

		<div class="ccb:grid ccb:gap-3">
			<label class="ccb:flex ccb:items-center ccb:gap-3"><input type="checkbox" role="switch" class="ccb-toggle" x-model="config.writing.strip_inline_styles"> <span><?php esc_html_e( 'Remove inline styles', 'causeway-claude-bridge' ); ?></span></label>
			<label class="ccb:flex ccb:items-center ccb:gap-3"><input type="checkbox" role="switch" class="ccb-toggle" x-model="config.writing.link_rules.external_new_tab"> <span><?php esc_html_e( 'Open links to other sites in a new tab', 'causeway-claude-bridge' ); ?></span></label>
			<label class="ccb:flex ccb:items-center ccb:gap-3"><input type="checkbox" role="switch" class="ccb-toggle" x-model="config.writing.link_rules.nofollow_external"> <span><?php esc_html_e( 'Add nofollow to links to other sites', 'causeway-claude-bridge' ); ?></span></label>
		</div>
	</div>

	<div class="ccb-card ccb:grid ccb:gap-4">
		<h3><?php esc_html_e( 'Search engines', 'causeway-claude-bridge' ); ?></h3>
		<p class="ccb-help">
			<?php esc_html_e( 'SEO plugin found on this site:', 'causeway-claude-bridge' ); ?>
			<strong x-text="{ yoast: 'Yoast SEO', rankmath: 'Rank Math', seopress: 'SEOPress', none: t( 'none' ) }[ boot.seoPlugin ] || boot.seoPlugin"></strong>
		</p>
		<div class="ccb:grid ccb:gap-4 ccb:md:grid-cols-3">
			<div>
				<label class="ccb-label" for="ccb-seo-plugin"><?php esc_html_e( 'Write SEO fields for', 'causeway-claude-bridge' ); ?></label>
				<select class="ccb-select" id="ccb-seo-plugin" x-model="config.writing.seo.plugin">
					<option value="auto"><?php esc_html_e( 'Whichever is active', 'causeway-claude-bridge' ); ?></option>
					<option value="seopress">SEOPress</option>
					<option value="yoast">Yoast SEO</option>
					<option value="rankmath">Rank Math</option>
					<option value="none"><?php esc_html_e( 'None', 'causeway-claude-bridge' ); ?></option>
				</select>
			</div>
			<div>
				<label class="ccb-label" for="ccb-seo-title"><?php esc_html_e( 'SEO title length', 'causeway-claude-bridge' ); ?></label>
				<input class="ccb-input" id="ccb-seo-title" type="number" min="10" max="200" x-model.number="config.writing.seo.title_max" data-path="/writing/seo/title_max" :aria-invalid="err( '/writing/seo/title_max' )">
				<p class="ccb-error-text" x-show="err( '/writing/seo/title_max' )" x-text="errText( '/writing/seo/title_max' )"></p>
			</div>
			<div>
				<label class="ccb-label" for="ccb-seo-desc"><?php esc_html_e( 'Meta description length', 'causeway-claude-bridge' ); ?></label>
				<input class="ccb-input" id="ccb-seo-desc" type="number" min="50" max="400" x-model.number="config.writing.seo.description_max" data-path="/writing/seo/description_max" :aria-invalid="err( '/writing/seo/description_max' )">
				<p class="ccb-error-text" x-show="err( '/writing/seo/description_max' )" x-text="errText( '/writing/seo/description_max' )"></p>
			</div>
		</div>
	</div>

	<div class="ccb-card ccb:grid ccb:gap-4">
		<div>
			<label class="ccb-label" for="ccb-house-style"><?php esc_html_e( 'House style', 'causeway-claude-bridge' ); ?></label>
			<textarea class="ccb-textarea" id="ccb-house-style" rows="8" maxlength="4000" x-model="config.writing.house_style" aria-describedby="ccb-house-style-help" data-path="/writing/house_style"></textarea>
			<p class="ccb-help" id="ccb-house-style-help"><?php esc_html_e( 'Plain English or simple Markdown (lists, bold). Claude follows it for every post.', 'causeway-claude-bridge' ); ?></p>
		</div>
		<div>
			<label class="ccb-label" for="ccb-avoid"><?php esc_html_e( 'Words to avoid', 'causeway-claude-bridge' ); ?></label>
			<div class="ccb:flex ccb:flex-wrap ccb:gap-1 ccb:mb-2">
				<template x-for="( word, i ) in config.writing.words_to_avoid" :key="word">
					<span class="ccb-chip"><span x-text="word"></span><button type="button" @click="config.writing.words_to_avoid.splice( i, 1 )" :aria-label="t( 'removeItem' ).replace( '%s', word )">&times;</button></span>
				</template>
			</div>
			<input class="ccb-input" id="ccb-avoid" type="text" maxlength="60" x-model="tagInput" aria-describedby="ccb-avoid-help"
				@keydown.enter.prevent="addTag( config.writing.words_to_avoid, tagInput ); tagInput = ''" @keydown.,.prevent="addTag( config.writing.words_to_avoid, tagInput ); tagInput = ''">
			<p class="ccb-help" id="ccb-avoid-help"><?php esc_html_e( 'Press Enter after each word or phrase.', 'causeway-claude-bridge' ); ?></p>
		</div>
		<div>
			<label class="ccb-label" for="ccb-signoff"><?php esc_html_e( 'Sign-off', 'causeway-claude-bridge' ); ?></label>
			<textarea class="ccb-textarea" id="ccb-signoff" rows="3" maxlength="500" x-model="config.writing.sign_off" aria-describedby="ccb-signoff-help"></textarea>
			<p class="ccb-help" id="ccb-signoff-help"><?php esc_html_e( 'Added to the end of posts where it fits, if set.', 'causeway-claude-bridge' ); ?></p>
		</div>
	</div>
</div>
