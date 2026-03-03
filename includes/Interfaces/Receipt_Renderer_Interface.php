<?php
/**
 * Receipt renderer interface.
 *
 * @package WCPOS\WooCommercePOS\Interfaces
 */

namespace WCPOS\WooCommercePOS\Interfaces;

use WC_Abstract_Order;

/**
 * Receipt_Renderer_Interface interface.
 */
interface Receipt_Renderer_Interface {
	/**
	 * Render receipt output.
	 *
	 * @param array             $template     Template metadata/content.
	 * @param WC_Abstract_Order $order        Order object.
	 * @param array             $receipt_data Canonical receipt payload.
	 *
	 * @return void
	 */
	public function render( array $template, WC_Abstract_Order $order, array $receipt_data ): void;
}
