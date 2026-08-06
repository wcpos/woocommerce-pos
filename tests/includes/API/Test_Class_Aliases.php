<?php
/**
 * Tests for permanent legacy API class aliases.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\API\V1\Orders_Controller;

/**
 * Test double matching the Pro extension pattern of subclassing the old FQCN.
 */
class Legacy_Orders_Controller_Test_Double extends \WCPOS\WooCommercePOS\API\Orders_Controller {
}

/**
 * Test double for extensions subclassing the legacy customers controller.
 */
class Legacy_Customers_Controller_Test_Double extends \WCPOS\WooCommercePOS\API\Customers_Controller {
}

/**
 * Test double for extensions subclassing the legacy products controller.
 */
class Legacy_Products_Controller_Test_Double extends \WCPOS\WooCommercePOS\API\Products_Controller {
}

/**
 * Test double for extensions subclassing the legacy variations controller.
 */
class Legacy_Product_Variations_Controller_Test_Double extends \WCPOS\WooCommercePOS\API\Product_Variations_Controller {
}

/**
 * Test double matching the Pro pattern of consuming a trait by its old FQCN
 * (e.g. Pro's Order_Refunds_Controller uses API\Traits\WCPOS_REST_API).
 */
class Legacy_Trait_Consumer_Test_Double {
	use \WCPOS\WooCommercePOS\API\Traits\WCPOS_REST_API;
}

/**
 * Legacy API class alias tests.
 */
class Test_Class_Aliases extends WC_Unit_Test_Case {
	/**
	 * The old Orders controller FQCN resolves to the V1 implementation.
	 */
	public function test_old_orders_controller_fqcn_resolves_to_v1_class(): void {
		$legacy_class = 'WCPOS\WooCommercePOS\API\Orders_Controller';

		$this->assertTrue( class_exists( $legacy_class ) );
		$this->assertTrue( is_a( $legacy_class, Orders_Controller::class, true ) );
	}

	/**
	 * Extensions can continue subclassing and instantiating the old FQCN.
	 */
	public function test_subclass_of_old_orders_controller_fqcn_instantiates(): void {
		$controller = new Legacy_Orders_Controller_Test_Double();

		$this->assertInstanceOf( Orders_Controller::class, $controller );
		$this->assertTrue( is_subclass_of( $controller, Orders_Controller::class ) );
	}

	/**
	 * Public controller subclasses retain the inherited meta-query helper.
	 *
	 * @dataProvider query_helper_controller_provider
	 */
	public function test_legacy_controller_subclass_keeps_inherited_query_helper( string $controller_class ): void {
		$controller = new $controller_class();
		$meta_query = array(
			array(
				'key'   => 'example',
				'value' => 'value',
			),
		);

		$this->assertTrue( is_callable( array( $controller, 'wcpos_combine_meta_queries' ) ) );
		$this->assertEquals( $meta_query, $controller->wcpos_combine_meta_queries( array(), $meta_query ) );
	}

	/**
	 * Controllers that historically composed Query_Helpers.
	 *
	 * @return array<string, array{string}>
	 */
	public function query_helper_controller_provider(): array {
		return array(
			'customers'          => array( Legacy_Customers_Controller_Test_Double::class ),
			'products'           => array( Legacy_Products_Controller_Test_Double::class ),
			'product variations' => array( Legacy_Product_Variations_Controller_Test_Double::class ),
		);
	}

	/**
	 * Extensions can continue consuming traits by their old FQCNs.
	 */
	public function test_trait_alias_resolves_for_old_fqcn_consumers(): void {
		$consumer = new Legacy_Trait_Consumer_Test_Double();

		$this->assertContains(
			'WCPOS\WooCommercePOS\API\V1\Traits\WCPOS_REST_API',
			class_uses( $consumer )
		);
	}
}
