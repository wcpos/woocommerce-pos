<?php
/**
 * Admin Notices
 * - add notices via static method or filter.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin;

/**
 * Notices class.
 */
class Notices {
	/**
	 * Stored notices.
	 *
	 * @var array
	 */
	private static $notices = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add a message for display.
	 *
	 * @param string $message     The notice message.
	 * @param string $type        The notice type.
	 * @param bool   $dismissable Whether the notice is dismissable.
	 */
	public static function add( $message = '', $type = 'error', $dismissable = true ): void {
		self::$notices[] = array(
			'type'        => $type,
			'message'     => $message,
			'dismissable' => $dismissable,
		);
	}

	/**
	 * Display the admin notices.
	 */
	public function admin_notices(): void {
		/**
		 * Filters the POS admin notices.
		 *
		 * @since 1.0.0
		 *
		 * @param array $notices
		 *
		 * @return array $notices
		 *
		 * @hook woocommerce_pos_admin_notices
		 */
		$notices = apply_filters( 'woocommerce_pos_admin_notices', self::$notices );
		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$classes = 'notice notice-' . $notice['type'];
			if ( $notice['dismissable'] ) {
				$classes .= ' is-dismissable';
			}
			if ( $notice['message'] ) {
				echo '<div class="' . esc_attr( $classes ) . '"><p>' .
					 wp_kses( $notice['message'], wp_kses_allowed_html( 'post' ) ) .
					 '</p></div>';
			}
		}
	}
}
