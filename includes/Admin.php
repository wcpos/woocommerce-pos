<?php

/**
 * WP Admin Class
 * conditionally loads classes for WP Admin.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Admin {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init();
		add_action( 'current_screen', array( $this, 'conditional_init' ) );
	}

	/**
	 * Conditionally load subclasses.
	 *
	 * @param $current_screen
	 */
	public function conditional_init( $current_screen ): void {
		// Add setting to permalink page
		if ( 'options-permalink' == $current_screen->id ) {
			new Admin\Permalink();
		}

		// Edit products page
		if ( 'product' == $current_screen->id ) {
			new Admin\Products();
		}

		// Add POS settings to orders pages
		//		if ( $current_screen->id == 'shop_order' || $current_screen->id == 'edit-shop_order' ) {
		//			new Admin\Orders();
		//		}

		// Customise plugins page
		if ( 'plugins' == $current_screen->id ) {
			new Admin\Plugins();
		}
	}

	/**
	 * Load admin subclasses.
	 */
	private function init(): void {
		new Admin\Notices();
		new Admin\Menu();
		new Admin\Settings();
		//		new Admin\Status();
		//		new Admin\Gateways();
	}
}
