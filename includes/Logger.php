<?php

namespace WCPOS\WooCommercePOS;

class Logger {
	public static $logger;
	const WC_LOG_FILENAME = 'woocommerce-pos';

	/**
	 * Utilize WC logger class
	 */
	public static function log( $message ) {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'woocommerce_pos_logging', true, $message ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}
			$settings = get_option( 'woocommerce_pos_settings' );
			$level    = isset( $settings['debug_level'] ) ? $settings['debug_level'] : 'info';

			if ( ! is_string( $message ) ) {
				$message = print_r( $message, true );
			}

			self::$logger->log( $level, $message, [ 'source' => self::WC_LOG_FILENAME ] );
		}
	}

}
