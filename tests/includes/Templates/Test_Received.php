<?php

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Templates\Received;
use WC_REST_Unit_Test_Case;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Received extends WC_REST_Unit_Test_Case {

	public function setUp(): void {
		parent::setUp();
		$this->user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that get_order_json returns valid JSON matching the REST API shape.
	 */
	public function test_get_order_json_returns_valid_json(): void {
		$order    = OrderHelper::create_order();
		$received = new Received( $order->get_id() );

		$json = $received->get_order_json( $order );

		$this->assertIsString( $json );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertEquals( $order->get_id(), $data['id'] );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'total', $data );
	}
}
