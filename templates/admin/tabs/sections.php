<?php
/**
 * Sections tab (docs/08 "2. Sections").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-3 ccb:mb-4">
	<div>
		<h2><?php esc_html_e( 'Sections', 'causeway-claude-bridge' ); ?></h2>
		<p class="ccb-help"><?php esc_html_e( 'Each section is a kind of post Claude can write, such as News. Claude only sees switched-on sections.', 'causeway-claude-bridge' ); ?></p>
	</div>
	<button type="button" class="ccb-btn" @click="refreshStructure()"><?php esc_html_e( 'Re-read the site', 'causeway-claude-bridge' ); ?></button>
</div>

<div class="ccb-sections">
	<div class="ccb:grid ccb:gap-4">
		<template x-for="key in sectionKeys()" :key="key">
			<div class="ccb-card" :data-path="'/sections/' + key" tabindex="-1">
				<div class="ccb:flex ccb:flex-wrap ccb:items-center ccb:justify-between ccb:gap-3">
					<div class="ccb:flex ccb:items-center ccb:gap-3 ccb:min-w-0">
						<input type="checkbox" role="switch" class="ccb-toggle" :id="'ccb-section-' + key + '-enabled'"
							x-model="config.sections[ key ].enabled" :aria-label="t( 'enableSection' ).replace( '%s', config.sections[ key ].label )">
						<div class="ccb:min-w-0">
							<h3 x-text="config.sections[ key ].label"></h3>
							<p class="ccb-help" x-text="summary( key )"></p>
						</div>
					</div>
					<div class="ccb:flex ccb:items-center ccb:gap-2">
						<span x-show="err( '/sections/' + key )" class="ccb-pill ccb-pill-problem"><?php esc_html_e( 'Needs fixing', 'causeway-claude-bridge' ); ?></span>
						<span x-show="! err( '/sections/' + key ) && ( missingTerms( key ).length || missingRules( key ).length )" class="ccb-pill ccb-pill-warning"><?php esc_html_e( 'Out of date', 'causeway-claude-bridge' ); ?></span>
						<button type="button" class="ccb-btn ccb-btn-small" :aria-expanded="openSection === key ? 'true' : 'false'" :aria-controls="'ccb-section-' + key"
							@click="openSection = openSection === key ? '' : key; queuePreview()" x-text="openSection === key ? t( 'close' ) : t( 'edit' )"></button>
					</div>
				</div>

				<div :id="'ccb-section-' + key" x-show="openSection === key" class="ccb:mt-5 ccb:grid ccb:gap-5">
					<template x-if="missingTerms( key ).length || missingRules( key ).length">
						<div class="ccb:rounded-control ccb:border ccb:border-warn ccb:bg-warn/10 ccb:p-3">
							<p class="ccb:font-semibold"><?php esc_html_e( 'Some settings refer to things that no longer exist on the site.', 'causeway-claude-bridge' ); ?></p>
							<ul class="ccb:mt-2 ccb:grid ccb:gap-1 ccb:list-none ccb:m-0 ccb:p-0">
								<template x-for="m in missingTerms( key )" :key="m.tax + m.id">
									<li class="ccb:flex ccb:items-center ccb:gap-2"><span x-text="t( 'missingTermId' ).replace( '%1$s', m.id ).replace( '%2$s', m.tax )"></span>
										<button type="button" class="ccb-link" @click="removeTerm( key, m.tax, m.id )"><?php esc_html_e( 'Remove', 'causeway-claude-bridge' ); ?></button></li>
								</template>
								<template x-for="name in missingRules( key )" :key="name">
									<li class="ccb:flex ccb:items-center ccb:gap-2"><span x-text="t( 'missingField' ).replace( '%s', name )"></span>
										<button type="button" class="ccb-link" @click="removeRule( key, name )"><?php esc_html_e( 'Remove', 'causeway-claude-bridge' ); ?></button></li>
								</template>
							</ul>
						</div>
					</template>

					<div class="ccb:grid ccb:gap-4 ccb:md:grid-cols-2">
						<div>
							<label class="ccb-label" :for="'ccb-section-' + key + '-label'"><?php esc_html_e( 'Label shown to staff', 'causeway-claude-bridge' ); ?></label>
							<input class="ccb-input" type="text" maxlength="40" required :id="'ccb-section-' + key + '-label'" x-model="config.sections[ key ].label"
								:data-path="'/sections/' + key + '/label'" :aria-invalid="err( '/sections/' + key + '/label' )" :aria-describedby="'ccb-section-' + key + '-label-err'">
							<p class="ccb-error-text" :id="'ccb-section-' + key + '-label-err'" x-show="err( '/sections/' + key + '/label' )" x-text="errText( '/sections/' + key + '/label' )"></p>
						</div>
						<div>
							<label class="ccb-label" :for="'ccb-section-' + key + '-pt'"><?php esc_html_e( 'Post type', 'causeway-claude-bridge' ); ?></label>
							<select class="ccb-select" :id="'ccb-section-' + key + '-pt'" x-model="config.sections[ key ].post_type"
								:data-path="'/sections/' + key + '/post_type'" :aria-invalid="err( '/sections/' + key + '/post_type' )" :aria-describedby="'ccb-section-' + key + '-pt-help'">
								<template x-if="! postType( config.sections[ key ].post_type )">
									<option :value="config.sections[ key ].post_type" x-text="config.sections[ key ].post_type + ' (' + t( 'missing' ) + ')'"></option>
								</template>
								<template x-for="pt in structure.post_types" :key="pt.name">
									<option :value="pt.name" :disabled="usedPostTypes( key ).includes( pt.name )" :selected="pt.name === config.sections[ key ].post_type" x-text="pt.label + ' (' + pt.name + ')'"></option>
								</template>
							</select>
							<p class="ccb-help" :id="'ccb-section-' + key + '-pt-help'"><?php esc_html_e( 'Post types already used by another section are greyed out.', 'causeway-claude-bridge' ); ?></p>
							<p class="ccb-error-text" x-show="err( '/sections/' + key + '/post_type' )" x-text="errText( '/sections/' + key + '/post_type' )"></p>
							<p class="ccb-help" x-show="warnText( '/sections/' + key + '/post_type' )" x-text="warnText( '/sections/' + key + '/post_type' )"></p>
						</div>
					</div>

					<fieldset x-show="taxonomiesFor( config.sections[ key ].post_type ).length" class="ccb:border-0 ccb:p-0 ccb:m-0">
						<legend class="ccb-label"><?php esc_html_e( 'Categories and terms', 'causeway-claude-bridge' ); ?></legend>
						<div class="ccb:grid ccb:gap-4">
							<template x-for="tax in taxonomiesFor( config.sections[ key ].post_type )" :key="tax.slug">
								<div class="ccb:grid ccb:gap-2">
									<label class="ccb:flex ccb:items-center ccb:gap-2">
										<input type="checkbox" class="ccb-check" :checked="config.sections[ key ].required_taxonomies.includes( tax.slug )"
											@change="toggleIn( config.sections[ key ].required_taxonomies, tax.slug )">
										<span x-text="t( 'requireTax' ).replace( '%s', tax.label )"></span>
									</label>
									<div>
										<label class="ccb-help" :for="'ccb-terms-' + key + '-' + tax.slug" x-text="t( 'defaultTerms' ).replace( '%s', tax.label )"></label>
										<div class="ccb:flex ccb:flex-wrap ccb:gap-1 ccb:my-1">
											<template x-for="id in ( config.sections[ key ].default_terms[ tax.slug ] || [] )" :key="id">
												<span class="ccb-chip"><span x-text="termName( tax.slug, id ) || ( '#' + id )"></span>
													<button type="button" @click="removeTerm( key, tax.slug, id )" :aria-label="t( 'removeItem' ).replace( '%s', termName( tax.slug, id ) || id )">&times;</button></span>
											</template>
										</div>
										<input type="search" class="ccb-input" :id="'ccb-terms-' + key + '-' + tax.slug" :placeholder="t( 'searchTerms' )"
											x-model="termSearch[ key + tax.slug ]" :data-path="'/sections/' + key + '/default_terms/' + tax.slug" autocomplete="off">
										<div x-show="termSearch[ key + tax.slug ]" class="ccb:flex ccb:flex-wrap ccb:gap-1 ccb:mt-1">
											<template x-for="term in termMatches( key, tax.slug )" :key="term.id">
												<button type="button" class="ccb-btn ccb-btn-small" @click="addTerm( key, tax.slug, term.id )" x-text="'+ ' + term.name"></button>
											</template>
											<p class="ccb-help" x-show="termMatches( key, tax.slug ).length === 0"><?php esc_html_e( 'No matching terms. Claude can only use terms that already exist.', 'causeway-claude-bridge' ); ?></p>
										</div>
									</div>
								</div>
							</template>
						</div>
					</fieldset>

					<fieldset class="ccb:border-0 ccb:p-0 ccb:m-0">
						<legend class="ccb-label"><?php esc_html_e( 'Featured image', 'causeway-claude-bridge' ); ?></legend>
						<div class="ccb:grid ccb:gap-4 ccb:md:grid-cols-3 ccb:items-end">
							<label class="ccb:flex ccb:items-center ccb:gap-2">
								<input type="checkbox" role="switch" class="ccb-toggle" x-model="config.sections[ key ].featured_image.required">
								<span><?php esc_html_e( 'Required', 'causeway-claude-bridge' ); ?></span>
							</label>
							<div>
								<label class="ccb-help" :for="'ccb-ratio-' + key"><?php esc_html_e( 'Shape', 'causeway-claude-bridge' ); ?></label>
								<select class="ccb-select" :id="'ccb-ratio-' + key" x-model="config.sections[ key ].featured_image.ratio">
									<option value="any"><?php esc_html_e( 'Any shape', 'causeway-claude-bridge' ); ?></option>
									<option value="16:9"><?php esc_html_e( 'Wide (16:9)', 'causeway-claude-bridge' ); ?></option>
									<option value="4:3"><?php esc_html_e( 'Standard (4:3)', 'causeway-claude-bridge' ); ?></option>
									<option value="1:1"><?php esc_html_e( 'Square (1:1)', 'causeway-claude-bridge' ); ?></option>
								</select>
							</div>
							<div>
								<label class="ccb-help" :for="'ccb-minw-' + key"><?php esc_html_e( 'Minimum width (px)', 'causeway-claude-bridge' ); ?></label>
								<input class="ccb-input" type="number" min="0" max="6000" :id="'ccb-minw-' + key" x-model.number="config.sections[ key ].featured_image.min_width"
									:data-path="'/sections/' + key + '/featured_image/min_width'" :aria-invalid="err( '/sections/' + key + '/featured_image/min_width' )">
							</div>
						</div>
					</fieldset>

					<fieldset class="ccb:border-0 ccb:p-0 ccb:m-0">
						<legend class="ccb-label"><?php esc_html_e( 'Limits', 'causeway-claude-bridge' ); ?></legend>
						<div class="ccb:grid ccb:gap-4 ccb:grid-cols-2 ccb:md:grid-cols-4">
							<?php
							$ccb_limits = array(
								'min_words'         => array( __( 'Minimum words', 'causeway-claude-bridge' ), 20000 ),
								'max_words'         => array( __( 'Maximum words', 'causeway-claude-bridge' ), 20000 ),
								'excerpt_max_chars' => array( __( 'Excerpt length (characters)', 'causeway-claude-bridge' ), 1000 ),
								'max_images'        => array( __( 'Images in the text', 'causeway-claude-bridge' ), 50 ),
							);
							foreach ( $ccb_limits as $ccb_field => $ccb_limit ) :
								?>
								<div>
									<label class="ccb-help" :for="'ccb-<?php echo esc_attr( $ccb_field ); ?>-' + key"><?php echo esc_html( $ccb_limit[0] ); ?></label>
									<input class="ccb-input" type="number" min="0" max="<?php echo (int) $ccb_limit[1]; ?>" :id="'ccb-<?php echo esc_attr( $ccb_field ); ?>-' + key"
										x-model.number="config.sections[ key ].limits.<?php echo esc_attr( $ccb_field ); ?>"
										:data-path="'/sections/' + key + '/limits/<?php echo esc_attr( $ccb_field ); ?>'"
										:aria-invalid="err( '/sections/' + key + '/limits/<?php echo esc_attr( $ccb_field ); ?>' )">
									<p class="ccb-error-text" x-show="err( '/sections/' + key + '/limits/<?php echo esc_attr( $ccb_field ); ?>' )" x-text="errText( '/sections/' + key + '/limits/<?php echo esc_attr( $ccb_field ); ?>' )"></p>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="ccb-help"><?php esc_html_e( '0 means no limit. Under the minimum is a warning to the writer; over the maximum can\'t be published.', 'causeway-claude-bridge' ); ?></p>
					</fieldset>

					<div x-show="acfFields( config.sections[ key ].post_type ).length">
						<h4 class="ccb-label"><?php esc_html_e( 'Fields from ACF', 'causeway-claude-bridge' ); ?></h4>
						<div class="ccb-table-wrap">
							<table class="ccb-table">
								<thead><tr><th scope="col"><?php esc_html_e( 'Field', 'causeway-claude-bridge' ); ?></th><th scope="col"><?php esc_html_e( 'Rule', 'causeway-claude-bridge' ); ?></th></tr></thead>
								<tbody>
									<template x-for="field in acfFields( config.sections[ key ].post_type )" :key="field.key">
										<tr>
											<td><span x-text="field.label"></span> <code x-text="field.name"></code> <span class="ccb-help" x-text="field.type"></span>
												<p class="ccb-help" x-show="! field.writable"><?php esc_html_e( 'Claude can\'t fill this kind of field, so it stays hidden.', 'causeway-claude-bridge' ); ?></p></td>
											<td>
												<label class="ccb-sr-only" :for="'ccb-rule-' + key + '-' + field.name" x-text="t( 'ruleFor' ).replace( '%s', field.label )"></label>
												<select class="ccb-select" :id="'ccb-rule-' + key + '-' + field.name" :disabled="! field.writable" :value="fieldRule( key, field )"
													@change="setFieldRule( key, field, $event.target.value )" :data-path="'/sections/' + key + '/field_rules/' + field.name">
													<option value="required"><?php esc_html_e( 'Required', 'causeway-claude-bridge' ); ?></option>
													<option value="optional"><?php esc_html_e( 'Optional', 'causeway-claude-bridge' ); ?></option>
													<option value="only_if_given"><?php esc_html_e( 'Only if given', 'causeway-claude-bridge' ); ?></option>
													<option value="hidden"><?php esc_html_e( 'Hidden', 'causeway-claude-bridge' ); ?></option>
												</select>
											</td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
					</div>

					<div>
						<div class="ccb:flex ccb:items-center ccb:justify-between ccb:gap-2">
							<h4 class="ccb-label"><?php esc_html_e( 'Extra fields', 'causeway-claude-bridge' ); ?></h4>
							<button type="button" class="ccb-btn ccb-btn-small" @click="addExtraField( key )"><?php esc_html_e( 'Add field', 'causeway-claude-bridge' ); ?></button>
						</div>
						<p class="ccb-help"><?php esc_html_e( 'Values stored in post meta that aren\'t ACF fields, such as a postcode turned into a map location.', 'causeway-claude-bridge' ); ?></p>
						<div class="ccb:grid ccb:gap-3 ccb:mt-2">
							<template x-for="( field, name ) in config.sections[ key ].extra_fields" :key="name">
								<div class="ccb:border ccb:border-line ccb:rounded-control ccb:p-3 ccb:grid ccb:gap-3 ccb:md:grid-cols-2" :data-path="'/sections/' + key + '/extra_fields/' + name" tabindex="-1">
									<div>
										<label class="ccb-help" :for="'ccb-xf-key-' + key + name"><?php esc_html_e( 'Meta key', 'causeway-claude-bridge' ); ?></label>
										<input class="ccb-input" type="text" :id="'ccb-xf-key-' + key + name" :value="name" @change="renameExtraField( key, name, $event.target.value )" pattern="[a-z][a-z0-9_]{1,62}">
									</div>
									<div>
										<label class="ccb-help" :for="'ccb-xf-label-' + key + name"><?php esc_html_e( 'Label', 'causeway-claude-bridge' ); ?></label>
										<input class="ccb-input" type="text" maxlength="60" :id="'ccb-xf-label-' + key + name" x-model="field.label">
									</div>
									<div>
										<label class="ccb-help" :for="'ccb-xf-type-' + key + name"><?php esc_html_e( 'Type', 'causeway-claude-bridge' ); ?></label>
										<select class="ccb-select" :id="'ccb-xf-type-' + key + name" x-model="field.type">
											<option value="text"><?php esc_html_e( 'Short text', 'causeway-claude-bridge' ); ?></option>
											<option value="textarea"><?php esc_html_e( 'Long text', 'causeway-claude-bridge' ); ?></option>
											<option value="date"><?php esc_html_e( 'Date', 'causeway-claude-bridge' ); ?></option>
											<option value="select"><?php esc_html_e( 'Choice from a list', 'causeway-claude-bridge' ); ?></option>
											<option value="uk_postcode_to_latlng"><?php esc_html_e( 'UK postcode to map location', 'causeway-claude-bridge' ); ?></option>
										</select>
									</div>
									<label class="ccb:flex ccb:items-center ccb:gap-2 ccb:self-end">
										<input type="checkbox" role="switch" class="ccb-toggle" x-model="field.required"> <span><?php esc_html_e( 'Required', 'causeway-claude-bridge' ); ?></span>
									</label>
									<div x-show="field.type === 'select'" class="ccb:md:col-span-2">
										<label class="ccb-help" :for="'ccb-xf-choices-' + key + name"><?php esc_html_e( 'Choices, one per line', 'causeway-claude-bridge' ); ?></label>
										<textarea class="ccb-textarea" :id="'ccb-xf-choices-' + key + name" :value="( field.choices || [] ).join( '\n' )" @change="setChoices( field, $event.target.value )"></textarea>
									</div>
									<div class="ccb:md:col-span-2">
										<label class="ccb-help" :for="'ccb-xf-instr-' + key + name"><?php esc_html_e( 'Instructions for Claude', 'causeway-claude-bridge' ); ?></label>
										<input class="ccb-input" type="text" maxlength="500" :id="'ccb-xf-instr-' + key + name" x-model="field.instructions">
									</div>
									<p class="ccb-error-text ccb:md:col-span-2" x-show="err( '/sections/' + key + '/extra_fields/' + name )" x-text="errText( '/sections/' + key + '/extra_fields/' + name )"></p>
									<div class="ccb:md:col-span-2"><button type="button" class="ccb-btn ccb-btn-small ccb-btn-danger" @click="removeExtraField( key, name )"><?php esc_html_e( 'Remove field', 'causeway-claude-bridge' ); ?></button></div>
								</div>
							</template>
						</div>
					</div>

					<div>
						<label class="ccb-label" :for="'ccb-notes-' + key"><?php esc_html_e( 'Notes for Claude', 'causeway-claude-bridge' ); ?></label>
						<textarea class="ccb-textarea" maxlength="1000" :id="'ccb-notes-' + key" x-model="config.sections[ key ].notes" :aria-describedby="'ccb-notes-help-' + key" :data-path="'/sections/' + key + '/notes'"></textarea>
						<p class="ccb-help" :id="'ccb-notes-help-' + key"><?php esc_html_e( 'Anything specific to this section, in plain English. Shown to Claude word for word.', 'causeway-claude-bridge' ); ?></p>
					</div>

					<div>
						<h4 class="ccb-label"><?php esc_html_e( 'Elementor template', 'causeway-claude-bridge' ); ?></h4>
						<template x-if="! structure.elementor || ! structure.elementor.active">
							<p class="ccb-help"><?php esc_html_e( 'Elementor Pro isn\'t active, so posts use the theme\'s own template.', 'causeway-claude-bridge' ); ?></p>
						</template>
						<template x-if="structure.elementor && structure.elementor.active">
							<p :class="templates( config.sections[ key ].post_type ).length ? 'ccb-help' : 'ccb-error-text'"
								x-text="templates( config.sections[ key ].post_type ).length ? t( 'templateCovers' ).replace( '%s', templates( config.sections[ key ].post_type ).map( ( x ) => x.title ).join( ', ' ) ) : t( 'noTemplate' )"></p>
						</template>
					</div>

					<div><button type="button" class="ccb-btn ccb-btn-danger ccb-btn-small" @click="removeSection( key )"><?php esc_html_e( 'Remove section', 'causeway-claude-bridge' ); ?></button></div>
				</div>
			</div>
		</template>

		<div class="ccb-card">
			<h3 class="ccb:mb-3"><?php esc_html_e( 'Add section', 'causeway-claude-bridge' ); ?></h3>
			<div class="ccb:grid ccb:gap-3 ccb:md:grid-cols-3 ccb:items-end">
				<div>
					<label class="ccb-help" for="ccb-new-pt"><?php esc_html_e( 'Post type', 'causeway-claude-bridge' ); ?></label>
					<select class="ccb-select" id="ccb-new-pt" x-model="newSection.post_type">
						<option value=""><?php esc_html_e( 'Choose…', 'causeway-claude-bridge' ); ?></option>
						<template x-for="pt in structure.post_types" :key="pt.name">
							<option :value="pt.name" :disabled="usedPostTypes( '' ).includes( pt.name )" x-text="pt.label + ' (' + pt.name + ')'"></option>
						</template>
					</select>
				</div>
				<div>
					<label class="ccb-help" for="ccb-new-label"><?php esc_html_e( 'Label shown to staff', 'causeway-claude-bridge' ); ?></label>
					<input class="ccb-input" id="ccb-new-label" type="text" maxlength="40" x-model="newSection.label">
				</div>
				<div>
					<label class="ccb-help" for="ccb-new-key"><?php esc_html_e( 'Short name (optional)', 'causeway-claude-bridge' ); ?></label>
					<input class="ccb-input" id="ccb-new-key" type="text" maxlength="31" x-model="newSection.key" pattern="[a-z][a-z0-9-]{1,30}" aria-describedby="ccb-new-key-help">
				</div>
			</div>
			<p class="ccb-help" id="ccb-new-key-help"><?php esc_html_e( 'Lower-case letters, numbers and hyphens. Staff and Claude use it to pick the section.', 'causeway-claude-bridge' ); ?></p>
			<button type="button" class="ccb-btn ccb:mt-3" @click="addSection()" :disabled="! newSection.post_type"><?php esc_html_e( 'Add section', 'causeway-claude-bridge' ); ?></button>
		</div>
	</div>

	<aside class="ccb-preview-panel" aria-labelledby="ccb-preview-title">
		<div class="ccb-card">
			<h3 id="ccb-preview-title"><?php esc_html_e( 'What Claude sees', 'causeway-claude-bridge' ); ?></h3>
			<p class="ccb-help ccb:mb-3" x-text="openSection && config.sections[ openSection ] ? config.sections[ openSection ].label + ( preview.loading ? ' · ' + t( 'updating' ) : '' ) : t( 'openSectionHint' )"></p>
			<div class="ccb-preview" x-show="preview.markdown" x-text="preview.markdown" aria-live="polite"></div>
			<p class="ccb-help" x-show="! preview.markdown && preview.note" x-text="preview.note"></p>
		</div>
	</aside>
</div>
