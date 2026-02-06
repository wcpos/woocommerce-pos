<?php
/**
 * Tests for the Received order template.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\Templates\Received;
use WC_REST_Unit_Test_Case;

/**
 * Received template tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Received extends WC_REST_Unit_Test_Case {
	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $user;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		parent::setUp();
		$this->user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
	}

	/**
	 * Register the WCPOS REST API routes.
	 */
	public function rest_api_init(): void {
		new API();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		parent::tearDown();
	}

	/**
	 * Test that get_order_json returns valid JSON matching the REST API shape.
	 */
	public function test_get_order_json_returns_valid_json(): void {
		$order    = OrderHelper::create_order();
		$received = new Received( $order->get_id() );

		$json = $received->get_order_json( $order->get_id() );

		$this->assertIsString( $json );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertEquals( $order->get_id(), $data['id'] );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'total', $data );
	}

	/**
	 * Test that the order JSON contains the _links with payment and receipt URLs.
	 */
	public function test_get_order_json_contains_pos_links(): void {
		$order    = OrderHelper::create_order();
		$received = new Received( $order->get_id() );

		$json = $received->get_order_json( $order->get_id() );
		$data = json_decode( $json, true );

		$this->assertArrayHasKey( '_links', $data, 'Response must contain _links' );

		$links = $data['_links'];

		// Payment link.
		$this->assertArrayHasKey( 'payment', $links, '_links must contain payment' );
		$this->assertNotEmpty( $links['payment'] );
		$payment_href = $links['payment'][0]['href'];
		$this->assertStringContainsString( '/wcpos-checkout/order-pay/' . $order->get_id(), $payment_href );
		$this->assertStringContainsString( 'pay_for_order=1', $payment_href );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $payment_href );

		// Receipt link.
		$this->assertArrayHasKey( 'receipt', $links, '_links must contain receipt' );
		$this->assertNotEmpty( $links['receipt'] );
		$receipt_href = $links['receipt'][0]['href'];
		$this->assertStringContainsString( '/wcpos-checkout/wcpos-receipt/' . $order->get_id(), $receipt_href );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $receipt_href );
	}

	/**
	 * Test that the order JSON contains self and collection links.
	 */
	public function test_get_order_json_contains_self_and_collection_links(): void {
		$order    = OrderHelper::create_order();
		$received = new Received( $order->get_id() );

		$json  = $received->get_order_json( $order->get_id() );
		$data  = json_decode( $json, true );

		$this->assertArrayHasKey( '_links', $data );
		$links = $data['_links'];

		// Self link pointing to the WCPOS endpoint.
		$this->assertArrayHasKey( 'self', $links, '_links must contain self' );
		$self_href = $links['self'][0]['href'];
		$this->assertStringContainsString( '/wcpos/v1/orders/' . $order->get_id(), $self_href );

		// Collection link.
		$this->assertArrayHasKey( 'collection', $links, '_links must contain collection' );
		$collection_href = $links['collection'][0]['href'];
		$this->assertStringContainsString( '/wcpos/v1/orders', $collection_href );
	}

	/**
	 * Test that order JSON includes meta_data (parsed by WCPOS controller).
	 */
	public function test_get_order_json_includes_meta_data(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_woocommerce_pos_uuid', 'test-uuid-1234' );
		$order->save();

		$received = new Received( $order->get_id() );
		$json     = $received->get_order_json( $order->get_id() );
		$data     = json_decode( $json, true );

		$this->assertArrayHasKey( 'meta_data', $data );
	}

	/**
	 * Test that get_order_json works without an authenticated user.
	 * The received template is viewed by unauthenticated users, so the
	 * internal REST request must bypass permission checks.
	 */
	public function test_get_order_json_works_without_authenticated_user(): void {
		$order = OrderHelper::create_order();

		// Clear the current user to simulate unauthenticated access.
		wp_set_current_user( 0 );

		$received = new Received( $order->get_id() );
		$json     = $received->get_order_json( $order->get_id() );

		$this->assertIsString( $json );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertEquals( $order->get_id(), $data['id'] );

		// Links should still be present.
		$this->assertArrayHasKey( '_links', $data );
		$this->assertArrayHasKey( 'payment', $data['_links'] );
		$this->assertArrayHasKey( 'receipt', $data['_links'] );
	}

	/**
	 * Test that the permissions filter is cleaned up after get_order_json.
	 */
	public function test_permissions_filter_removed_after_get_order_json(): void {
		$order    = OrderHelper::create_order();
		$received = new Received( $order->get_id() );

		$received->get_order_json( $order->get_id() );

		$this->assertFalse(
			has_filter( 'woocommerce_rest_check_permissions', '__return_true' ),
			'The permissions bypass filter must be removed after the internal request'
		);
	}
}
