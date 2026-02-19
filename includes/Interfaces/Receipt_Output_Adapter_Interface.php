<?php
/**
 * Receipt output adapter interface.
 *
 * @package WCPOS\WooCommercePOS\Interfaces
 */

namespace WCPOS\WooCommercePOS\Interfaces;

/**
 * Receipt_Output_Adapter_Interface interface.
 */
interface Receipt_Output_Adapter_Interface {
	/**
	 * Convert canonical receipt payload to target output format.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional adapter context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string;
}
