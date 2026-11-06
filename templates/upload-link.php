<?php
/**
 * Upload-link page. Neutral design, no external assets (non-negotiables 9 and 10).
 *
 * Variables: $view (array) from UploadPage.
 *
 * @package Causeway\ClaudeBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template data.
 *
 * @var array<string, mixed> $view
 */
$ccb_view  = $view;
$ccb_nonce = (string) $ccb_view['csp_nonce'];
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $ccb_view['title'] . ' · ' . $ccb_view['site'] ); ?></title>
<style nonce="<?php echo esc_attr( $ccb_nonce ); ?>">
	:root { --ink: #1d2327; --muted: #646970; --line: #dcdcde; --canvas: #f0f0f1; --accent: #2271b1; --good: #00a32a; --bad: #d63638; }
	* { box-sizing: border-box; }
	body { margin: 0; background: var(--canvas); color: var(--ink); font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
	main { max-width: 560px; margin: 48px auto; padding: 0 16px; }
	.card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 24px; }
	h1 { font-size: 20px; margin: 0 0 4px; }
	.site, .hint { color: var(--muted); font-size: 14px; margin: 0 0 16px; }
	.post { font-weight: 600; margin: 0 0 8px; }
	label { display: block; font-weight: 600; margin: 16px 0 6px; font-size: 14px; }
	.drop { border: 2px dashed var(--line); border-radius: 8px; padding: 24px; text-align: center; }
	.drop.over { border-color: var(--accent); background: #f6f7f7; }
	input[type=text] { width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; font: inherit; }
	button { margin-top: 20px; background: var(--accent); color: #fff; border: 0; border-radius: 6px; padding: 10px 18px; font: inherit; font-weight: 600; cursor: pointer; }
	button:focus-visible, input:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
	.error { border-left: 4px solid var(--bad); background: #fcf0f1; padding: 10px 12px; margin: 0 0 16px; }
	.done { border-left: 4px solid var(--good); background: #edfaef; padding: 10px 12px; }
	progress { width: 100%; margin-top: 12px; }
	[hidden] { display: none !important; }
	@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>
<main>
	<div class="card">
		<h1><?php echo esc_html( $ccb_view['title'] ); ?></h1>
		<p class="site"><?php echo esc_html( $ccb_view['site'] ); ?></p>

		<?php if ( isset( $ccb_view['message'] ) ) : ?>
			<p class="<?php echo $ccb_view['done'] ? 'done' : 'error'; ?>" role="status"><?php echo esc_html( $ccb_view['message'] ); ?></p>
		<?php else : ?>
			<p class="post"><?php echo esc_html( $ccb_view['post_title'] ); ?></p>
			<p class="hint" id="ccb-expected"><?php echo esc_html( $ccb_view['expected'] ); ?></p>

			<?php if ( ! empty( $ccb_view['error'] ) ) : ?>
				<p class="error" role="alert"><?php echo esc_html( $ccb_view['error'] ); ?></p>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $ccb_view['action'] ); ?>" id="ccb-form">
				<input type="hidden" name="_ccb_nonce" value="<?php echo esc_attr( $ccb_view['nonce'] ); ?>">
				<label for="ccb-file"><?php esc_html_e( 'File', 'causeway-claude-bridge' ); ?></label>
				<div class="drop" id="ccb-drop">
					<input type="file" name="file" id="ccb-file" accept="<?php echo esc_attr( $ccb_view['accept'] ); ?>" aria-describedby="ccb-expected" required>
				</div>
				<?php if ( $ccb_view['is_image'] ) : ?>
					<label for="ccb-alt"><?php esc_html_e( 'Describe the image for people who can\'t see it', 'causeway-claude-bridge' ); ?></label>
					<input type="text" name="alt" id="ccb-alt" maxlength="125" required>
				<?php endif; ?>
				<progress id="ccb-progress" max="100" value="0" hidden></progress>
				<button type="submit"><?php esc_html_e( 'Upload', 'causeway-claude-bridge' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</main>
<?php if ( ! isset( $ccb_view['message'] ) ) : ?>
<script nonce="<?php echo esc_attr( $ccb_nonce ); ?>">
( function () {
	var form = document.getElementById( 'ccb-form' ), drop = document.getElementById( 'ccb-drop' ), input = document.getElementById( 'ccb-file' ), bar = document.getElementById( 'ccb-progress' );
	if ( ! form || ! window.FormData || ! window.XMLHttpRequest ) { return; }
	[ 'dragenter', 'dragover' ].forEach( function ( type ) { drop.addEventListener( type, function ( e ) { e.preventDefault(); drop.classList.add( 'over' ); } ); } );
	[ 'dragleave', 'drop' ].forEach( function ( type ) { drop.addEventListener( type, function () { drop.classList.remove( 'over' ); } ); } );
	drop.addEventListener( 'drop', function ( e ) { e.preventDefault(); if ( e.dataTransfer && e.dataTransfer.files.length ) { input.files = e.dataTransfer.files; } } );
	form.addEventListener( 'submit', function ( e ) {
		if ( ! form.checkValidity() ) { return; }
		e.preventDefault();
		var xhr = new XMLHttpRequest();
		bar.hidden = false;
		xhr.upload.addEventListener( 'progress', function ( p ) { if ( p.lengthComputable ) { bar.value = Math.round( p.loaded / p.total * 100 ); } } );
		xhr.addEventListener( 'load', function () {
			// Swap in the result card; the page's styles stay. A returned form submits normally.
			var next = new DOMParser().parseFromString( xhr.responseText, 'text/html' ).querySelector( '.card' );
			if ( next ) { document.querySelector( '.card' ).replaceWith( document.importNode( next, true ) ); } else { form.submit(); }
		} );
		xhr.addEventListener( 'error', function () { form.submit(); } );
		xhr.open( 'POST', form.action );
		xhr.send( new FormData( form ) );
	} );
}() );
</script>
<?php endif; ?>
</body>
</html>
