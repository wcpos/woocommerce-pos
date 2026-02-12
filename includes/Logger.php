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
	 * @var \WC_Logger|null
	 */
	public static $logger;

	/**
	 * Log level.
	 *
	 * @var string|null
	 */
	public static $log_level;

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
	 * @param mixed $message The message to log.
	 * @param mixed $context Optional additional context data to log.
	 */
	public static function log( $message, $context = null ): void {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'woocommerce_pos_logging', true, $message ) ) {
			if ( ! isset( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}

			if ( is_null( self::$log_level ) ) {
				self::$log_level = 'info';
			}

			if ( ! is_string( $message ) ) {
				$message = print_r( $message, true );
			}

			if ( null !== $context ) {
				$message .= ' | Context: ' . ( is_string( $context ) ? $context : print_r( $context, true ) );
			}

			self::$logger->log( self::$log_level, $message, array( 'source' => self::WC_LOG_FILENAME ) );
		}
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
}
