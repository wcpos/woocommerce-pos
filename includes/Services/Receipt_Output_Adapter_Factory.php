<?php
/**
 * Receipt output adapter factory.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use InvalidArgumentException;
use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;
use WCPOS\WooCommercePOS\Templates\Adapters\Escpos_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Html_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Starprnt_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Zpl_Output_Adapter;

/**
 * Receipt_Output_Adapter_Factory class.
 */
class Receipt_Output_Adapter_Factory {
	/**
	 * Create output adapter by type.
	 *
	 * @param string $output_type Output type.
	 *
	 * @throws InvalidArgumentException If output type is unsupported.
	 *
	 * @return Receipt_Output_Adapter_Interface
	 */
	public function create( string $output_type ): Receipt_Output_Adapter_Interface {
		switch ( $output_type ) {
			case 'html':
				return new Html_Output_Adapter();
			case 'escpos':
				return new Escpos_Output_Adapter();
			case 'starprnt':
				return new Starprnt_Output_Adapter();
			case 'zpl':
				return new Zpl_Output_Adapter();
			default:
				throw new InvalidArgumentException( 'Unsupported receipt output type: ' . esc_html( $output_type ) );
		}
	}
}
