<?php
/**
 * Logger.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use function is_string;

/**
 * Class Logger
 *
 * NOTE: do not put any SQL queries in this class, eg: options table lookup
 */
class Logger {
	public const WC_LOG_FILENAME = 'woocommerce-pos';

	/**
	 * Logger instance.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	public static $logger;

	/**
	 * Log level.
	 *
	 * @var string|null
	 */
	public static $log_level;

	/**
	 * Hash of the last logged message (level + message + context) for dedup.
	 *
	 * @var string|null
	 */
	private static ?string $last_message_hash = null;

	/**
	 * Log level of the last deduplicated message, so flush writes at the correct level.
	 *
	 * @var string|null
	 */
	private static ?string $last_message_level = null;

	/**
	 * Number of consecutive duplicate messages suppressed.
	 *
	 * @var int
	 */
	private static int $repeat_count = 0;

	/**
	 * Whether the shutdown flush hook has been registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Set the log level.
	 *
	 * @param string $level The log level.
	 */
	public static function set_log_level( $level ): void {
		self::$log_level = $level;
	}

	/**
	 * Utilize WC logger class.
	 *
	 * Suppresses consecutive duplicate messages within the same request.
	 * When a different message arrives, any suppressed count is flushed
	 * as "Previous message repeated N more times".
	 *
	 * @param mixed $message The message to log.
	 * @param mixed $context Optional additional context data to log.
	 */
	public static function log( $message, $context = null ): void {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( is_null( self::$log_level ) ) {
			self::$log_level = 'info';
		}

		if ( ! is_string( $message ) ) {
			$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		$context_string = '';
		if ( null !== $context ) {
			$context_string = is_string( $context ) ? $context : print_r( $context, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		// Build a hash from level + message + context to detect duplicates.
		$hash = md5( self::$log_level . '|' . $message . '|' . $context_string );

		if ( null !== self::$last_message_hash && $hash === self::$last_message_hash ) {
			++self::$repeat_count;

			return;
		}

		// Different message — flush any pending repeat count first.
		self::flush_repeat_count();

		// Now log the new message.
		self::$last_message_hash  = $hash;
		self::$last_message_level = self::$log_level;
		self::$repeat_count       = 0;

		$full_message = $message;
		if ( '' !== $context_string ) {
			$full_message .= ' | Context: ' . $context_string;
		}

		self::write_log( $full_message );
	}

	/**
	 * Flush any pending repeat count to the log.
	 *
	 * Called when a new (different) message arrives, or at shutdown
	 * to ensure the final repeat count is written.
	 */
	public static function flush_repeat_count(): void {
		if ( self::$repeat_count > 0 ) {
			$saved_level     = self::$log_level;
			self::$log_level = self::$last_message_level ?? 'info';

			self::write_log(
				sprintf( 'Previous message repeated %d more time(s)', self::$repeat_count )
			);

			self::$log_level  = $saved_level;
			self::$repeat_count = 0;
		}
	}

	/**
	 * Reset deduplication state.
	 *
	 * Used by tests to ensure clean state between test methods.
	 */
	public static function reset_dedup_state(): void {
		self::$last_message_hash   = null;
		self::$last_message_level  = null;
		self::$repeat_count        = 0;
		self::$shutdown_registered = false;
	}

	/**
	 * Log a warning message.
	 *
	 * @param mixed $message The message to log.
	 * @param mixed $context Optional additional context data.
	 */
	public static function warning( $message, $context = null ): void {
		$previous_level  = self::$log_level;
		self::$log_level = 'warning';
		self::log( $message, $context );
		self::$log_level = $previous_level;
	}

	/**
	 * Log an error message.
	 *
	 * @param mixed $message The message to log.
	 * @param mixed $context Optional additional context data.
	 */
	public static function error( $message, $context = null ): void {
		$previous_level  = self::$log_level;
		self::$log_level = 'error';
		self::log( $message, $context );
		self::$log_level = $previous_level;
	}

	/**
	 * Write a message to the WC logger, applying the logging filter.
	 *
	 * Registers a shutdown hook on first write to flush any remaining
	 * repeat count at end of request.
	 *
	 * @param string $message The formatted message to write.
	 */
	private static function write_log( string $message ): void {
		if ( ! apply_filters( 'woocommerce_pos_logging', true, $message ) ) {
			return;
		}

		if ( ! isset( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		self::$logger->log( self::$log_level, $message, array( 'source' => self::WC_LOG_FILENAME ) );

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action(
				'shutdown',
				function () {
					Logger::flush_repeat_count();
				}
			);
		}
	}
}
