<?php
/**
 * HTML output adapter.
 *
 * @package WCPOS\WooCommercePOS\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Templates\Adapters;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;

/**
 * Html_Output_Adapter class.
 */
class Html_Output_Adapter implements Receipt_Output_Adapter_Interface {
	/**
	 * Transform receipt payload to HTML representation.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		if ( isset( $context['html'] ) && \is_string( $context['html'] ) ) {
			return $context['html'];
		}

		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;

		return sprintf(
			'<div class="wcpos-receipt" data-order="%s"><strong>%s</strong>: %s</div>',
			esc_attr( $order_number ),
			esc_html__( 'Order', 'woocommerce-pos' ),
			wp_kses_post( wc_price( $total ) )
		);
	}
}
