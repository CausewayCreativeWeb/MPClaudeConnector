<?php
/**
 * Images and files tab (docs/08 "4. Images and files").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$ccb_numbers = array(
	'max_upload_mb'       => array( __( 'Largest image Claude can upload (MB)', 'causeway-claude-bridge' ), 1, 10 ),
	'max_edge_px'         => array( __( 'Longest edge after resizing (px)', 'causeway-claude-bridge' ), 800, 6000 ),
	'pdf_max_mb'          => array( __( 'Largest PDF (MB)', 'causeway-claude-bridge' ), 1, 50 ),
	'upload_link_minutes' => array( __( 'Upload links expire after (minutes)', 'causeway-claude-bridge' ), 5, 60 ),
);
?>
<h2 class="ccb:mb-4"><?php esc_html_e( 'Images and files', 'causeway-claude-bridge' ); ?></h2>
<div class="ccb-card ccb:grid ccb:gap-5">
	<fieldset class="ccb:border-0 ccb:p-0 ccb:m-0" data-path="/media/image_types" tabindex="-1">
		<legend class="ccb-label"><?php esc_html_e( 'Accepted image types', 'causeway-claude-bridge' ); ?></legend>
		<div class="ccb:flex ccb:flex-wrap ccb:gap-4">
			<?php
			foreach ( array(
				'image/jpeg' => 'JPEG',
				'image/png'  => 'PNG',
				'image/webp' => 'WebP',
			) as $ccb_mime => $ccb_name ) :
				?>
				<label class="ccb:flex ccb:items-center ccb:gap-2"><input type="checkbox" class="ccb-check" value="<?php echo esc_attr( $ccb_mime ); ?>" x-model="config.media.image_types"> <?php echo esc_html( $ccb_name ); ?></label>
			<?php endforeach; ?>
		</div>
		<p class="ccb-help"><?php esc_html_e( 'GIF, SVG and HEIC are never accepted.', 'causeway-claude-bridge' ); ?></p>
		<p class="ccb-error-text" x-show="err( '/media/image_types' )" x-text="errText( '/media/image_types' )"></p>
	</fieldset>

	<div class="ccb:grid ccb:gap-4 ccb:md:grid-cols-2">
		<?php foreach ( $ccb_numbers as $ccb_key => $ccb_field ) : ?>
			<div>
				<label class="ccb-label" for="ccb-media-<?php echo esc_attr( $ccb_key ); ?>"><?php echo esc_html( $ccb_field[0] ); ?></label>
				<input class="ccb-input" type="number" id="ccb-media-<?php echo esc_attr( $ccb_key ); ?>" min="<?php echo (int) $ccb_field[1]; ?>" max="<?php echo (int) $ccb_field[2]; ?>"
					x-model.number="config.media.<?php echo esc_attr( $ccb_key ); ?>" data-path="/media/<?php echo esc_attr( $ccb_key ); ?>"
					:aria-invalid="err( '/media/<?php echo esc_attr( $ccb_key ); ?>' )" aria-describedby="ccb-media-<?php echo esc_attr( $ccb_key ); ?>-err">
				<p class="ccb-error-text" id="ccb-media-<?php echo esc_attr( $ccb_key ); ?>-err" x-show="err( '/media/<?php echo esc_attr( $ccb_key ); ?>' )" x-text="errText( '/media/<?php echo esc_attr( $ccb_key ); ?>' )"></p>
			</div>
		<?php endforeach; ?>
	</div>

	<div>
		<label class="ccb-label" for="ccb-webp"><?php esc_html_e( 'WebP quality', 'causeway-claude-bridge' ); ?> <span x-text="config.media.webp_quality"></span></label>
		<input id="ccb-webp" type="range" min="60" max="95" step="1" x-model.number="config.media.webp_quality" data-path="/media/webp_quality" class="ccb:w-full ccb:max-w-md" aria-describedby="ccb-webp-help">
		<p class="ccb-help" id="ccb-webp-help"><?php esc_html_e( 'Higher looks better but makes bigger files. 82 suits most photos.', 'causeway-claude-bridge' ); ?></p>
		<p class="ccb-error-text" x-show="err( '/media/webp_quality' )" x-text="errText( '/media/webp_quality' )"></p>
	</div>

	<div>
		<label class="ccb:flex ccb:items-center ccb:gap-3">
			<input type="checkbox" role="switch" class="ccb-toggle" checked disabled aria-describedby="ccb-strip-help">
			<span><?php esc_html_e( 'Remove location and camera data from photos', 'causeway-claude-bridge' ); ?></span>
		</label>
		<p class="ccb-help" id="ccb-strip-help"><?php esc_html_e( 'Always on: photos taken on phones can reveal where someone lives or works.', 'causeway-claude-bridge' ); ?></p>
	</div>
</div>
