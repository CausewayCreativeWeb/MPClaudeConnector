<?php
/**
 * Email sending (docs/07 "Notifications").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Notify;

use Causeway\ClaudeBridge\Roles\RoleManager;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Queues emails during a request and sends them on `shutdown`, so an ability's response isn't
 * held up by mail. HTML with a plain-text alternative, neutral branding, site name in the subject.
 */
final class Mailer {

	/**
	 * Emails waiting to be sent.
	 *
	 * @var list<array{to: list<string>, subject: string, message: array<string, mixed>}>
	 */
	private static array $queue = array();

	private static bool $hooked = false;

	/**
	 * Queues an email.
	 *
	 * @param list<string>         $to      Addresses.
	 * @param string               $subject Subject without the site name.
	 * @param array<string, mixed> $message heading, intro, rows (label => value), link (url, label), footer.
	 */
	public static function queue( array $to, string $subject, array $message ): void {
		$to = array_values( array_unique( array_filter( $to, 'is_email' ) ) );
		if ( array() === $to ) {
			return;
		}
		self::$queue[] = compact( 'to', 'subject', 'message' );
		if ( ! self::$hooked || false === has_action( 'shutdown', array( self::class, 'flush' ) ) ) {
			add_action( 'shutdown', array( self::class, 'flush' ) );
			self::$hooked = true;
		}
	}

	/**
	 * Sends everything queued. Runs on shutdown; tests call it directly.
	 */
	public static function flush(): void {
		$queue       = self::$queue;
		self::$queue = array();
		foreach ( $queue as $email ) {
			self::send( $email['to'], $email['subject'], $email['message'] );
		}
	}

	/**
	 * Sends one email now (also used for the test email).
	 *
	 * @param list<string>         $to      Addresses.
	 * @param string               $subject Subject without the site name.
	 * @param array<string, mixed> $message Message parts.
	 */
	public static function send( array $to, string $subject, array $message ): bool {
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$html    = self::render( $message );
		$text    = self::plain_text( $message );
		$alt     = static function ( $mailer ) use ( $text ): void {
			if ( $mailer instanceof PHPMailer ) {
				$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
			}
		};
		$type    = static fn (): string => 'text/html';
		add_action( 'phpmailer_init', $alt );
		add_filter( 'wp_mail_content_type', $type );
		$sent = wp_mail( $to, sprintf( '[%s] %s', $site, $subject ), $html );
		remove_filter( 'wp_mail_content_type', $type );
		remove_action( 'phpmailer_init', $alt );

		if ( ! $sent ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- mail failures must be visible to the host.
			error_log( 'Claude Publishing: email could not be sent: ' . $subject );
		}
		return $sent;
	}

	/**
	 * Email addresses of everyone who can publish through the Bridge (administrators and AI Publishers).
	 *
	 * @param int $exclude User to leave out (the person who made the change).
	 * @return list<string>
	 */
	public static function publisher_emails( int $exclude = 0 ): array {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', RoleManager::ROLE_PUBLISHER ),
				'exclude'  => $exclude > 0 ? array( $exclude ) : array(),
				'fields'   => array( 'ID', 'user_email' ),
			)
		);
		$emails = array();
		foreach ( $users as $user ) {
			if ( user_can( (int) $user->ID, RoleManager::CAP_PUBLISH ) ) {
				$emails[] = (string) $user->user_email;
			}
		}
		return $emails;
	}

	/**
	 * Administrators' email addresses.
	 *
	 * @return list<string>
	 */
	public static function admin_emails(): array {
		return array_values( array_map( 'strval', get_users( array( 'role' => 'administrator', 'fields' => 'user_email' ) ) ) );
	}

	/**
	 * Clears the queue (tests).
	 */
	public static function reset(): void {
		self::$queue = array();
		if ( self::$hooked ) {
			remove_action( 'shutdown', array( self::class, 'flush' ) );
		}
		self::$hooked = false;
	}

	/**
	 * Emails waiting (tests).
	 *
	 * @return list<array{to: list<string>, subject: string, message: array<string, mixed>}>
	 */
	public static function queued(): array {
		return self::$queue;
	}

	/**
	 * HTML body from the template.
	 *
	 * @param array<string, mixed> $message Message parts.
	 */
	private static function render( array $message ): string {
		$email = array_merge(
			array(
				'heading' => '',
				'intro'   => '',
				'rows'    => array(),
				'link'    => null,
				'footer'  => '',
				'site'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			$message
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/templates/emails/notification.php';
		return (string) ob_get_clean();
	}

	/**
	 * Plain-text alternative.
	 *
	 * @param array<string, mixed> $message Message parts.
	 */
	private static function plain_text( array $message ): string {
		$lines = array( (string) ( $message['heading'] ?? '' ), '' );
		if ( ! empty( $message['intro'] ) ) {
			$lines[] = (string) $message['intro'];
			$lines[] = '';
		}
		foreach ( (array) ( $message['rows'] ?? array() ) as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		if ( ! empty( $message['link']['url'] ) ) {
			$lines[] = '';
			$lines[] = $message['link']['label'] . ': ' . $message['link']['url'];
		}
		if ( ! empty( $message['footer'] ) ) {
			$lines[] = '';
			$lines[] = (string) $message['footer'];
		}
		return implode( "\n", $lines ) . "\n";
	}
}
