<?php
/**
 * Permanent aliases for legacy WCPOS REST API class names.
 *
 * WCPOS Pro and third-party extensions subclass these controllers by FQCN.
 * Keep the old names as a permanent public API while implementations live in V1.
 *
 * @package WCPOS\WooCommercePOS\API
 */

spl_autoload_register(
	static function ( string $class ): void {
		static $aliases = array(
			'WCPOS\WooCommercePOS\API\Auth'                          => 'WCPOS\WooCommercePOS\API\V1\Auth',
			'WCPOS\WooCommercePOS\API\Bulk_ID_Fast_Path'             => 'WCPOS\WooCommercePOS\API\V1\Bulk_ID_Fast_Path',
			'WCPOS\WooCommercePOS\API\Cashier'                       => 'WCPOS\WooCommercePOS\API\V1\Cashier',
			'WCPOS\WooCommercePOS\API\Checkout_Controller'           => 'WCPOS\WooCommercePOS\API\V1\Checkout_Controller',
			'WCPOS\WooCommercePOS\API\Coupons_Controller'            => 'WCPOS\WooCommercePOS\API\V1\Coupons_Controller',
			'WCPOS\WooCommercePOS\API\Customers_Controller'          => 'WCPOS\WooCommercePOS\API\V1\Customers_Controller',
			'WCPOS\WooCommercePOS\API\Data_Order_Statuses_Controller' => 'WCPOS\WooCommercePOS\API\V1\Data_Order_Statuses_Controller',
			'WCPOS\WooCommercePOS\API\Extensions'                    => 'WCPOS\WooCommercePOS\API\V1\Extensions',
			'WCPOS\WooCommercePOS\API\Gateway_Bootstrap_Controller'   => 'WCPOS\WooCommercePOS\API\V1\Gateway_Bootstrap_Controller',
			'WCPOS\WooCommercePOS\API\Logs'                          => 'WCPOS\WooCommercePOS\API\V1\Logs',
			'WCPOS\WooCommercePOS\API\Orders_Controller'             => 'WCPOS\WooCommercePOS\API\V1\Orders_Controller',
			'WCPOS\WooCommercePOS\API\Payment_Gateways'              => 'WCPOS\WooCommercePOS\API\V1\Payment_Gateways',
			'WCPOS\WooCommercePOS\API\Print_Jobs_Controller'         => 'WCPOS\WooCommercePOS\API\V1\Print_Jobs_Controller',
			'WCPOS\WooCommercePOS\API\Product_Brands_Controller'     => 'WCPOS\WooCommercePOS\API\V1\Product_Brands_Controller',
			'WCPOS\WooCommercePOS\API\Product_Categories_Controller' => 'WCPOS\WooCommercePOS\API\V1\Product_Categories_Controller',
			'WCPOS\WooCommercePOS\API\Product_Tags_Controller'       => 'WCPOS\WooCommercePOS\API\V1\Product_Tags_Controller',
			'WCPOS\WooCommercePOS\API\Product_Variations_Controller' => 'WCPOS\WooCommercePOS\API\V1\Product_Variations_Controller',
			'WCPOS\WooCommercePOS\API\Products_Controller'           => 'WCPOS\WooCommercePOS\API\V1\Products_Controller',
			'WCPOS\WooCommercePOS\API\Raw_Response'                  => 'WCPOS\WooCommercePOS\API\V1\Raw_Response',
			'WCPOS\WooCommercePOS\API\Receipts_Controller'           => 'WCPOS\WooCommercePOS\API\V1\Receipts_Controller',
			'WCPOS\WooCommercePOS\API\Settings'                      => 'WCPOS\WooCommercePOS\API\V1\Settings',
			'WCPOS\WooCommercePOS\API\Shipping_Methods_Controller'   => 'WCPOS\WooCommercePOS\API\V1\Shipping_Methods_Controller',
			'WCPOS\WooCommercePOS\API\Stores'                        => 'WCPOS\WooCommercePOS\API\V1\Stores',
			'WCPOS\WooCommercePOS\API\Tax_Classes_Controller'        => 'WCPOS\WooCommercePOS\API\V1\Tax_Classes_Controller',
			'WCPOS\WooCommercePOS\API\Taxes_Controller'              => 'WCPOS\WooCommercePOS\API\V1\Taxes_Controller',
			'WCPOS\WooCommercePOS\API\Templates_Controller'          => 'WCPOS\WooCommercePOS\API\V1\Templates_Controller',
			'WCPOS\WooCommercePOS\API\Traits\Product_Helpers'        => 'WCPOS\WooCommercePOS\API\V1\Traits\Product_Helpers',
			'WCPOS\WooCommercePOS\API\Traits\Query_Helpers'          => 'WCPOS\WooCommercePOS\API\V1\Traits\Query_Helpers',
			'WCPOS\WooCommercePOS\API\Traits\Uuid_Handler'           => 'WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler',
			'WCPOS\WooCommercePOS\API\Traits\WCPOS_REST_API'         => 'WCPOS\WooCommercePOS\API\V1\Traits\WCPOS_REST_API',
		);

		if ( isset( $aliases[ $class ] ) ) {
			class_alias( $aliases[ $class ], $class );
		}
	}
);
