<?php
/**
 * Notification email. Neutral branding; every value escaped.
 *
 * Variables: $email (heading, intro, rows, link, footer, site).
 *
 * @package Causeway\ClaudeBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email data.
 *
 * @var array<string, mixed> $email
 */
$ccb_email = $email;
?><!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
		<tr><td style="padding:24px;">
			<p style="margin:0 0 4px;font-size:13px;color:#646970;"><?php echo esc_html( $ccb_email['site'] ); ?></p>
			<h1 style="margin:0 0 16px;font-size:20px;"><?php echo esc_html( $ccb_email['heading'] ); ?></h1>
			<?php if ( '' !== $ccb_email['intro'] ) : ?>
				<p style="margin:0 0 16px;font-size:15px;line-height:1.5;"><?php echo esc_html( $ccb_email['intro'] ); ?></p>
			<?php endif; ?>
			<?php if ( array() !== $ccb_email['rows'] ) : ?>
				<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;margin:0 0 16px;">
					<?php foreach ( $ccb_email['rows'] as $ccb_label => $ccb_value ) : ?>
						<tr>
							<td style="padding:6px 12px 6px 0;color:#646970;vertical-align:top;white-space:nowrap;"><?php echo esc_html( (string) $ccb_label ); ?></td>
							<td style="padding:6px 0;vertical-align:top;"><?php echo nl2br( esc_html( (string) $ccb_value ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
			<?php if ( ! empty( $ccb_email['link']['url'] ) ) : ?>
				<p style="margin:0 0 16px;"><a href="<?php echo esc_url( $ccb_email['link']['url'] ); ?>" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-weight:600;"><?php echo esc_html( $ccb_email['link']['label'] ); ?></a></p>
			<?php endif; ?>
			<?php if ( '' !== $ccb_email['footer'] ) : ?>
				<p style="margin:16px 0 0;font-size:12px;color:#646970;"><?php echo esc_html( $ccb_email['footer'] ); ?></p>
			<?php endif; ?>
		</td></tr>
	</table>
</body>
</html>
