<?php
/**
 * WooCommerce status tool for copied-store account identities.
 *
 * @package WCPOS\WooCommercePOS\Admin
 */

namespace WCPOS\WooCommercePOS\Admin;

use WCPOS\WooCommercePOS\Sync\Pos_Uuid;

/** Offers a manual identity reset on single sites only. */
class User_Identity_Tool {
	/** Register with WooCommerce's existing tools interface. */
	public function __construct() {
		if ( ! is_multisite() ) {
			add_filter( 'woocommerce_debug_tools', array( $this, 'register_tool' ) );
		}
	}

	/**
	 * Append the account identity reset without changing other tools.
	 *
	 * @param array $tools Existing WooCommerce tools.
	 * @return array Tools including the WCPOS identity reset.
	 */
	public function register_tool( array $tools ): array {
		$tools['wcpos_reset_user_identities'] = array(
			'name'     => __( 'Reset WCPOS account identities', 'woocommerce-pos' ),
			'button'   => __( 'Reset identities', 'woocommerce-pos' ),
			'desc'     => __( 'Use this on a store that was copied from another store, when the POS app reports AUTH342. Each user gets a new POS identity the next time it is needed. Sync any waiting sales from the tills first; afterwards, remove this store from each till and connect it again.', 'woocommerce-pos' ),
			'callback' => array( $this, 'reset' ),
		);

		return $tools;
	}

	/** Reset identities and return WooCommerce's tool result message. */
	public function reset(): string {
		return \sprintf(
			/* translators: %d: Number of users whose WCPOS account identity was reset. */
			__( 'WCPOS account identities reset. Users affected: %d.', 'woocommerce-pos' ),
			Pos_Uuid::reset_user_uuids()
		);
	}
}
