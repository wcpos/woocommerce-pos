<?php
/**
 * Tests for receipts REST controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API\Receipts_Controller;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;

/**
 * Test_Receipts_Controller class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipts_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Endpoint instance.
	 *
	 * @var Receipts_Controller
	 */
	protected $endpoint;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Receipts_Controller();
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/receipts/(?P<order_id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/receipts/(?P<order_id>[\d]+)/pdf', $routes );
	}

	/**
	 * Test fiscal mode returns snapshot payload.
	 */
	public function test_get_item_fiscal_mode_returns_snapshot(): void {
		$order = OrderHelper::create_order();
		Receipt_Snapshot_Store::instance()->handle_payment_complete( $order->get_id() );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'fiscal' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertEquals( 'fiscal', $data['mode'] );
		$this->assertTrue( $data['has_snapshot'] );
		$this->assertArrayNotHasKey( 'receipt', $data['data'] );
		$this->assertNotEmpty( $data['data']['fiscal']['receipt_number'] );
	}

	/**
	 * Test live mode returns recalculated payload.
	 */
	public function test_get_item_live_mode_returns_payload(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'live' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'live', $data['mode'] );
		$this->assertArrayHasKey( 'totals', $data['data'] );
	}

	/**
	 * Test mode validation rejects invalid values.
	 */
	public function test_get_item_rejects_invalid_mode(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'broken' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test endpoint requires auth.
	 */
	public function test_get_item_requires_authentication(): void {
		$order = OrderHelper::create_order();
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test endpoint returns a permission error for users without POS access.
	 */
	public function test_get_item_returns_permission_error_when_user_cannot_access_pos(): void {
		$order         = OrderHelper::create_order();
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 403, $response->get_status() );
		$this->assertEquals( 'wcpos_rest_insufficient_permissions', $data['code'] );
	}

	/**
	 * Test cashiers can retrieve live receipts.
	 */
	public function test_get_item_allows_cashier(): void {
		$order      = OrderHelper::create_order();
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier_id );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'live' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test PDF endpoint returns downloadable PDF bytes.
	 */
	public function test_get_receipt_pdf_returns_pdf_bytes(): void {
		$order       = OrderHelper::create_order();
		$template_id = $this->create_receipt_template();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );
		$headers  = $response->get_headers();
		$body     = $this->serve_raw_response_body( $response, $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'application/pdf', $headers['Content-Type'] );
		$this->assertEquals( 'attachment; filename="receipt-' . $order->get_id() . '.pdf"', $headers['Content-Disposition'] );
		$this->assertStringStartsWith( '%PDF-', $body );
		$this->assertEquals( (string) \strlen( $body ), $headers['Content-Length'] );
	}

	/**
	 * Test PDF endpoint sets no-store cache header.
	 */
	public function test_get_receipt_pdf_sets_no_store_cache_header(): void {
		$order       = OrderHelper::create_order();
		$template_id = $this->create_receipt_template();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'no-store', $headers['Cache-Control'] );
	}

	/**
	 * Test PDF endpoint returns 404 for unknown order.
	 */
	public function test_get_receipt_pdf_unknown_order_returns_404(): void {
		$template_id = $this->create_receipt_template();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/999999/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test PDF endpoint returns 404 for unknown template.
	 */
	public function test_get_receipt_pdf_unknown_template_returns_404(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', '999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test PDF endpoint rejects an empty template id.
	 */
	public function test_get_receipt_pdf_empty_template_id_returns_400(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', '   ' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test PDF endpoint requires POS access.
	 */
	public function test_get_receipt_pdf_requires_capability(): void {
		$order         = OrderHelper::create_order();
		$template_id   = $this->create_receipt_template();
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Create a minimal receipt template for PDF endpoint tests.
	 *
	 * @return int Template post ID.
	 */
	private function create_receipt_template(): int {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'PDF Receipt',
				'post_content' => '<receipt paper-width="48"><text>Order {{order.number}}</text></receipt>',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
		update_post_meta( $post_id, '_template_engine', 'thermal' );
		update_post_meta( $post_id, '_template_output_type', 'html' );
		update_post_meta( $post_id, '_template_language', 'xml' );
		update_post_meta( $post_id, '_template_tax_display', 'default' );

		return $post_id;
	}

	/**
	 * Capture raw REST bytes emitted through rest_pre_serve_request.
	 *
	 * @param \WP_REST_Response $response Response object.
	 * @param \WP_REST_Request  $request  Request object.
	 *
	 * @return string
	 */
	private function serve_raw_response_body( \WP_REST_Response $response, \WP_REST_Request $request ): string {
		ob_start();
		apply_filters( 'rest_pre_serve_request', false, $response, $request, $this->server );
		$body = ob_get_clean();

		return false === $body ? '' : $body;
	}
}
