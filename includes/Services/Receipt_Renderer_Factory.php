<?php
/**
 * Receipt renderer factory.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Renderer_Interface;
use WCPOS\WooCommercePOS\Templates\Renderers\Legacy_Php_Renderer;
use WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer;

/**
 * Receipt_Renderer_Factory class.
 */
class Receipt_Renderer_Factory {
	/**
	 * Create renderer by engine value.
	 *
	 * @param string $engine Template engine.
	 *
	 * @return Receipt_Renderer_Interface
	 */
	public function create( string $engine ): Receipt_Renderer_Interface {
		switch ( $engine ) {
			case 'logicless':
				return new Logicless_Renderer();
			case 'legacy-php':
			default:
				return new Legacy_Php_Renderer();
		}
	}
}
