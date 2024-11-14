<?php

namespace WCPOS\WooCommercePOS\Integrations;

use const WCPOS\WooCommercePOS\PLUGIN_FILE;

/**
 * wePOS Integration
 *
 * wePOS alters the WC REST API response for variable products, it includes the full list of variations
 * instead of just the variation IDs. This breaks variations for WooCommerce POS (and anyone else).
 */
class WePOS {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'check_conflict' ) );
	}

	/**
	 * Check for wePOS plugin conflict.
	 */
	public function check_conflict(): void {
		// Check if wePOS is active
		if ( is_plugin_active( 'wepos/wepos.php' ) ) {
			deactivate_plugins( PLUGIN_FILE );
			add_action( 'admin_notices', array( $this, 'render_conflict_notice' ) );
		}
	}

	/**
	 * Render conflict admin notice.
	 */
	public function render_conflict_notice(): void {
		echo '<div class="notice notice-error is-dismissible">
            <p>' . esc_html__( 'WooCommerce POS cannot run alongside the wePOS plugin due to compatibility issues. WooCommerce POS has been deactivated.', 'woocommerce-pos' ) . '</p>
        </div>';
	}
}
