<?php
/**
 * Email Helper for testing email functionality.
 *
 * Provides utilities to capture, inspect, and assert email sending behavior.
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use ReflectionClass;
use WC_Email;

/**
 * EmailHelper class.
 */
class EmailHelper {
	/**
	 * Captured emails.
	 *
	 * @var array
	 */
	private static $captured_emails = array();

	/**
	 * Initialize email capturing.
	 *
	 * Call this in setUp() to start capturing emails.
	 */
	public static function init(): void {
		self::$captured_emails = array();

		// Hook into wp_mail to capture emails
		add_filter( 'wp_mail', array( __CLASS__, 'capture_email' ), 999, 1 );

		// Also hook into WooCommerce email sending
		add_action( 'woocommerce_email_sent', array( __CLASS__, 'on_email_sent' ), 10, 3 );
	}

	/**
	 * Clean up email capturing.
	 *
	 * Call this in tearDown() to stop capturing emails.
	 */
	public static function cleanup(): void {
		remove_filter( 'wp_mail', array( __CLASS__, 'capture_email' ), 999 );
		remove_action( 'woocommerce_email_sent', array( __CLASS__, 'on_email_sent' ), 10 );
		self::$captured_emails = array();
	}

	/**
	 * Capture an email from wp_mail filter.
	 *
	 * @param array $args Email arguments.
	 *
	 * @return array The same email arguments (pass-through).
	 */
	public static function capture_email( array $args ): array {
		self::$captured_emails[] = array(
			'to'          => $args['to']          ?? '',
			'subject'     => $args['subject']     ?? '',
			'message'     => $args['message']     ?? '',
			'headers'     => $args['headers']     ?? '',
			'attachments' => $args['attachments'] ?? array(),
			'timestamp'   => time(),
			'source'      => 'wp_mail',
		);

		return $args;
	}

	/**
	 * Hook into WooCommerce email sent action.
	 *
	 * @param bool     $return   Whether the email was sent successfully.
	 * @param string   $email_id The email ID.
	 * @param WC_Email $email    The email object.
	 */
	public static function on_email_sent( bool $return, string $email_id, $email = null ): void {
		// Find the last captured email and add WC email info
		$count = \count( self::$captured_emails );
		if ( $count > 0 && null !== $email ) {
			self::$captured_emails[ $count - 1 ]['wc_email_id']   = $email_id;
			self::$captured_emails[ $count - 1 ]['wc_email_type'] = \get_class( $email );
			self::$captured_emails[ $count - 1 ]['wc_success']    = $return;
		}
	}

	/**
	 * Get all captured emails.
	 *
	 * @return array Array of captured emails.
	 */
	public static function get_captured_emails(): array {
		return self::$captured_emails;
	}

	/**
	 * Get the last captured email.
	 *
	 * @return null|array The last captured email or null if none.
	 */
	public static function get_last_email(): ?array {
		$count = \count( self::$captured_emails );

		return $count > 0 ? self::$captured_emails[ $count - 1 ] : null;
	}

	/**
	 * Get the count of captured emails.
	 *
	 * @return int Number of captured emails.
	 */
	public static function get_email_count(): int {
		return \count( self::$captured_emails );
	}

	/**
	 * Clear all captured emails.
	 */
	public static function clear(): void {
		self::$captured_emails = array();
	}

	/**
	 * Check if any emails were sent.
	 *
	 * @return bool True if at least one email was captured.
	 */
	public static function has_emails(): bool {
		return \count( self::$captured_emails ) > 0;
	}

	/**
	 * Check if an email was sent to a specific recipient.
	 *
	 * @param string $to The recipient email address.
	 *
	 * @return bool True if an email was sent to the recipient.
	 */
	public static function email_sent_to( string $to ): bool {
		foreach ( self::$captured_emails as $email ) {
			if ( \is_array( $email['to'] ) ) {
				if ( \in_array( $to, $email['to'], true ) ) {
					return true;
				}
			} elseif ( $email['to'] === $to ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get emails sent to a specific recipient.
	 *
	 * @param string $to The recipient email address.
	 *
	 * @return array Array of emails sent to the recipient.
	 */
	public static function get_emails_to( string $to ): array {
		$emails = array();
		foreach ( self::$captured_emails as $email ) {
			if ( \is_array( $email['to'] ) ) {
				if ( \in_array( $to, $email['to'], true ) ) {
					$emails[] = $email;
				}
			} elseif ( $email['to'] === $to ) {
				$emails[] = $email;
			}
		}

		return $emails;
	}

	/**
	 * Get emails by WooCommerce email ID.
	 *
	 * @param string $email_id The WooCommerce email ID (e.g., 'new_order', 'customer_processing_order').
	 *
	 * @return array Array of emails with the given WC email ID.
	 */
	public static function get_emails_by_wc_id( string $email_id ): array {
		$emails = array();
		foreach ( self::$captured_emails as $email ) {
			if ( isset( $email['wc_email_id'] ) && $email['wc_email_id'] === $email_id ) {
				$emails[] = $email;
			}
		}

		return $emails;
	}

	/**
	 * Check if a WooCommerce email type was sent.
	 *
	 * @param string $email_id The WooCommerce email ID.
	 *
	 * @return bool True if the email type was sent.
	 */
	public static function wc_email_sent( string $email_id ): bool {
		return \count( self::get_emails_by_wc_id( $email_id ) ) > 0;
	}

	/**
	 * Get emails with subject containing a string.
	 *
	 * @param string $subject_contains The string to search for in subjects.
	 *
	 * @return array Array of matching emails.
	 */
	public static function get_emails_by_subject( string $subject_contains ): array {
		$emails = array();
		foreach ( self::$captured_emails as $email ) {
			if ( false !== strpos( $email['subject'], $subject_contains ) ) {
				$emails[] = $email;
			}
		}

		return $emails;
	}

	/**
	 * Reset the WooCommerce mailer instance.
	 *
	 * This forces WooCommerce to recreate email instances, which is useful
	 * when testing different settings configurations.
	 */
	public static function reset_mailer(): void {
		// Clear the mailer singleton
		if ( \function_exists( 'WC' ) && WC()->mailer() ) {
			// Force reinitialization by clearing emails array
			$mailer     = WC()->mailer();
			$reflection = new ReflectionClass( $mailer );
			if ( $reflection->hasProperty( 'emails' ) ) {
				$property = $reflection->getProperty( 'emails' );
				$property->setAccessible( true );
				$property->setValue( $mailer, array() );
			}
		}
	}

	/**
	 * Prevent actual email sending during tests.
	 *
	 * This hooks into pre_wp_mail to prevent actual sending while still
	 * allowing capture of email data.
	 */
	public static function prevent_sending(): void {
		add_filter( 'pre_wp_mail', '__return_true', 999 );
	}

	/**
	 * Allow actual email sending during tests.
	 *
	 * Removes the filter that prevents email sending.
	 */
	public static function allow_sending(): void {
		remove_filter( 'pre_wp_mail', '__return_true', 999 );
	}
}
