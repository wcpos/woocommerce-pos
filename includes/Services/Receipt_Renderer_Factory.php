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
use WCPOS\WooCommercePOS\Templates\Renderers\Thermal_Html_Renderer;

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
			case 'thermal':
				// Thermal content is stored raw (kses-exempt), so it must render
				// through the thermal pipeline — never the PHP-executing legacy
				// renderer, which would treat the content as executable PHP.
				return new Thermal_Html_Renderer();
			case 'legacy-php':
			default:
				return new Legacy_Php_Renderer();
		}
	}
}
