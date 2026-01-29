<?php

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * Base test class for WCPOS decimal quantity tests.
 *
 * This class applies the decimal_qty setting BEFORE the API routes are registered,
 * which is required for schema validation to use the correct type.
 */
abstract class WCPOS_REST_Decimal_Quantity_Unit_Test_Case extends WCPOS_REST_Unit_Test_Case {
	public function setUp(): void {
		// Apply decimal quantity filter BEFORE rest_api_init fires
		add_filter(
			'woocommerce_pos_general_settings',
			array( $this, 'enable_decimal_quantities' ),
			1 // High priority to run early
		);

		// Also modify WooCommerce stock amount handling
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );

		parent::setUp();
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_general_settings', array( $this, 'enable_decimal_quantities' ), 1 );
		remove_filter( 'woocommerce_stock_amount', 'floatval' );
		add_filter( 'woocommerce_stock_amount', 'intval' );

		parent::tearDown();
	}

	/**
	 * Enable decimal quantities in settings.
	 *
	 * @param array $settings The settings array.
	 * @return array Modified settings.
	 */
	public function enable_decimal_quantities( $settings ) {
		$settings['decimal_qty'] = true;
		return $settings;
	}
}
