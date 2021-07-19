<?php

/**
 * Admin Notices
 * - add notices via static method or filter
 *
 * @package    WCPOS\WooCommercePOS\Admin_Notices
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

class Notices {

	/* @var */
	private static $notices = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add a message for display
	 *
	 * @param string $message
	 * @param string $type (error | warning | success | info)
	 * @param bool $dismissable
	 */
	public static function add( $message = '', $type = 'error', $dismissable = true ) {
		self::$notices[] = array(
			'type'        => $type,
			'message'     => $message,
			'dismissable' => $dismissable,
		);
	}

	/**
	 * Display the admin notices
	 */
	public function admin_notices() {
		$notices = apply_filters( 'woocommerce_pos_admin_notices', self::$notices );
		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) :
			$classes = 'notice notice-' . $notice['type'];
			if ( $notice['dismissable'] ) {
				$classes .= ' is-dismissable';
			}
			if ( $notice['message'] ) {
				echo '<div class="' . $classes . '><p>' .
				     wp_kses( $notice['message'], wp_kses_allowed_html( 'post' ) ) .
				     '</p></div>';
			}
		endforeach;
	}

}
