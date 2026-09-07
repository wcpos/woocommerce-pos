<?php
/**
 * WooCommerce Tax (automated taxes) must not restore stale tax lines onto open
 * POS orders (#1896).
 *
 * @package WCPOS\WooCommercePOS\Tests\Integrations
 */

namespace WCPOS\WooCommercePOS\Tests\Integrations;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Connect_TaxJar_Integration;
use WC_Order;
use WC_Product;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;
use WP_REST_Response;

require_once dirname( __DIR__, 2 ) . '/Helpers/WC_Connect_TaxJar_Integration_Stub.php';

/**
 * Drives real order writes on both POS lanes with a faithful double of the
 * plugin's preserve/restore pair hooked in.
 *
 * Store tax: base address, US 10%. Product A is 10.00 and product B is 20.00,
 * so one line is 1.00 tax / 11.00 total and both lines are 3.00 / 33.00.
 *
 * @covers \WCPOS\WooCommercePOS\Integrations\WooCommerce_Tax
 */
class Test_WooCommerce_Tax extends Sync_REST_Store_Test_Case {
	/**
	 * The plugin double.
	 *
	 * @var WC_Connect_TaxJar_Integration
	 */
	private $taxjar;

	/**
	 * Base-address US tax, one rate, and the plugin double hooked in.
	 */
	public function setUp(): void {
		parent::setUp();
		// wcpos_request() reads the physical header from the server globals.
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.000',
				'name'     => 'US',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);

		$this->taxjar = new WC_Connect_TaxJar_Integration();
		$this->taxjar->init();
	}

	/**
	 * Unhook the double and drop the request header.
	 */
	public function tearDown(): void {
		$this->taxjar->teardown();
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	/**
	 * Statuses an order can hold while the till is still editing it.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function open_status_provider(): array {
		return array(
			'pos-open'    => array( 'pos-open' ),
			'pos-partial' => array( 'pos-partial' ),
			'pending'     => array( 'pending' ),
		);
	}

	/**
	 * Adding a line to an open order on wcpos/v1 returns tax for the new lines.
	 *
	 * @dataProvider open_status_provider
	 *
	 * @param string $status Order status at create time.
	 */
	public function test_v1_open_order_update_recalculates_tax_for_the_new_lines( string $status ): void {
		// Arrange.
		$a       = $this->product( 10 );
		$b       = $this->product( 20 );
		$created = $this->v1_create( $status, array( $this->line( $a ) ) );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$order_id = (int) $created->get_data()['id'];
		$this->assert_order_amounts( $order_id, 1.0, 11.0 );

		// Act.
		$updated = $this->v1_update( $order_id, array( $this->line( $b ) ) );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 0, $this->taxjar->snapshots_taken, 'The preserve callback must not run for an open POS order.' );
		$this->assertSame( 0, $this->taxjar->restores_applied );
		$this->assert_order_amounts( $order_id, 3.0, 33.0 );
		$this->assertSame( 3.0, (float) $updated->get_data()['total_tax'], 'The response must carry the tax the client can reproduce.' );
		$this->assertSame( 33.0, (float) $updated->get_data()['total'] );
	}

	/**
	 * The same on the wcpos/v2 push lane, which forwards to wc/v3.
	 */
	public function test_v2_open_order_update_recalculates_tax_for_the_new_lines(): void {
		// Arrange.
		$a         = $this->product( 10 );
		$b         = $this->product( 20 );
		$record_id = wp_generate_uuid4();
		$created   = $this->push_order(
			'create',
			$record_id,
			array(
				'status'     => 'pos-open',
				'line_items' => array( $this->line( $a ) ),
			)
		);
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$document = $created->get_data()['document'];
		$order_id = (int) $document['id'];
		$this->assert_order_amounts( $order_id, 1.0, 11.0 );

		// Act.
		$document['line_items'][] = $this->line( $b );
		$updated                  = $this->push_order( 'update', $record_id, $document, $created->get_data()['currentRevision'] );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 0, $this->taxjar->snapshots_taken, 'The preserve callback must not run for an open POS order.' );
		$this->assert_order_amounts( $order_id, 3.0, 33.0 );
		$this->assertSame( 3.0, (float) $updated->get_data()['document']['total_tax'] );
		$this->assertSame( 33.0, (float) $updated->get_data()['document']['total'] );
	}

	/**
	 * Paying an open order and changing its lines in one request charges tax
	 * for the final lines: WooCommerce recalculates before it applies the status.
	 */
	public function test_v1_open_order_paid_in_the_same_request_gets_tax_for_the_final_lines(): void {
		// Arrange.
		$a        = $this->product( 10 );
		$created  = $this->v1_create( 'pos-open', array( $this->line( $a ) ) );
		$order_id = (int) $created->get_data()['id'];

		// Act.
		$request = $this->wp_rest_patch_request( '/wcpos/v1/orders/' . $order_id );
		$request->set_body_params(
			array(
				'status'     => 'completed',
				'line_items' => array( $this->line( $this->product( 20 ) ) ),
			)
		);
		$updated = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 'completed', $updated->get_data()['status'] );
		$this->assertSame( 0, $this->taxjar->snapshots_taken );
		$this->assert_order_amounts( $order_id, 3.0, 33.0 );
	}

	/**
	 * Reopening a paid order and changing its lines in one request recalculates
	 * for the new lines even though the persisted status is still paid.
	 */
	public function test_v1_reopened_order_with_changed_lines_recalculates_tax(): void {
		// Arrange.
		$a        = $this->product( 10 );
		$created  = $this->v1_create( 'completed', array( $this->line( $a ) ) );
		$order_id = (int) $created->get_data()['id'];
		$this->assert_order_amounts( $order_id, 1.0, 11.0 );

		// Act.
		$request = $this->wp_rest_patch_request( '/wcpos/v1/orders/' . $order_id );
		$request->set_body_params(
			array(
				'status'     => 'pos-open',
				'line_items' => array( $this->line( $this->product( 20 ) ) ),
			)
		);
		$updated = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 'pos-open', $updated->get_data()['status'] );
		$this->assertSame( 0, $this->taxjar->snapshots_taken );
		$this->assert_order_amounts( $order_id, 3.0, 33.0 );
	}

	/**
	 * The wcpos/v2 push carries the whole document, so a reopen and a line change
	 * can arrive as one update.
	 */
	public function test_v2_reopened_order_with_changed_lines_recalculates_tax(): void {
		// Arrange.
		$record_id = wp_generate_uuid4();
		$created   = $this->push_order(
			'create',
			$record_id,
			array(
				'status'     => 'completed',
				'line_items' => array( $this->line( $this->product( 10 ) ) ),
			)
		);
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$document = $created->get_data()['document'];
		$order_id = (int) $document['id'];
		$this->assert_order_amounts( $order_id, 1.0, 11.0 );

		// Act.
		$document['status']       = 'pos-open';
		$document['line_items'][] = $this->line( $this->product( 20 ) );
		$updated                  = $this->push_order( 'update', $record_id, $document, $created->get_data()['currentRevision'] );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 'pos-open', $updated->get_data()['document']['status'] );
		$this->assertSame( 0, $this->taxjar->snapshots_taken );
		$this->assert_order_amounts( $order_id, 3.0, 33.0 );
	}

	/**
	 * The suspension lasts exactly one recalculation.
	 */
	public function test_preserve_callback_is_hooked_again_after_the_pos_recalculation(): void {
		// Arrange.
		$a        = $this->product( 10 );
		$created  = $this->v1_create( 'pos-open', array( $this->line( $a ) ) );
		$order_id = (int) $created->get_data()['id'];

		// Act.
		$this->v1_update( $order_id, array( $this->line( $this->product( 20 ) ) ) );

		// Assert: the plugin's own hook is intact for whatever recalculates next.
		$this->assertSame( 10, has_action( 'woocommerce_order_before_calculate_taxes', array( $this->taxjar, 'preserve_order_taxes_on_recalculation' ) ) );
		$this->assertSame( 0, $this->taxjar->snapshots_taken );

		// And it really does run again: a paid order recalculated afterwards is preserved.
		$paid = wc_get_order( $order_id );
		$paid->set_status( 'completed' );
		$paid->save();
		$paid->calculate_totals( true );
		$this->assertSame( 1, $this->taxjar->snapshots_taken );
		$this->assertSame( 1, $this->taxjar->restores_applied );
	}

	/**
	 * A paid order's tax is the plugin's business: its snapshot is left alone.
	 */
	public function test_paid_order_update_is_left_to_the_plugin(): void {
		// Arrange.
		$a       = $this->product( 10 );
		$created = $this->v1_create( 'completed', array( $this->line( $a ) ) );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$order_id = (int) $created->get_data()['id'];
		$this->assert_order_amounts( $order_id, 1.0, 11.0 );

		// Act.
		$updated = $this->v1_update( $order_id, array( $this->line( $this->product( 20 ) ) ) );

		// Assert: the plugin preserved the recorded tax, exactly as it does without WCPOS.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 1, $this->taxjar->snapshots_taken );
		$this->assertSame( 1, $this->taxjar->restores_applied );
		$this->assert_order_amounts( $order_id, 1.0, 31.0 );
	}

	/**
	 * Only WCPOS requests are touched; a core REST write of the same open order
	 * keeps the plugin's behaviour.
	 */
	public function test_non_pos_request_is_left_to_the_plugin(): void {
		// Arrange.
		$a       = $this->product( 10 );
		$created = $this->v1_create( 'pos-open', array( $this->line( $a ) ) );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$order_id = (int) $created->get_data()['id'];
		unset( $_SERVER['HTTP_X_WCPOS'] );

		// Act.
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $order_id );
		$request->set_body_params( array( 'line_items' => array( $this->line( $this->product( 20 ) ) ) ) );
		$updated = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 1, $this->taxjar->snapshots_taken );
		$this->assert_order_amounts( $order_id, 1.0, 31.0 );
	}

	/**
	 * A taxable simple product at the standard rate.
	 *
	 * @param int $price Regular price.
	 */
	private function product( int $price ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'regular_price' => $price,
				'price'         => $price,
				'tax_status'    => 'taxable',
				'tax_class'     => '',
			)
		);
	}

	/**
	 * A quantity-one line item payload.
	 *
	 * @param WC_Product $product The product.
	 */
	private function line( WC_Product $product ): array {
		return array(
			'product_id' => $product->get_id(),
			'quantity'   => 1,
		);
	}

	/**
	 * POST /wcpos/v1/orders.
	 *
	 * @param string $status     Order status.
	 * @param array  $line_items Line item payloads.
	 */
	private function v1_create( string $status, array $line_items ): WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'status'         => $status,
				'payment_method' => 'pos_cash',
				'line_items'     => $line_items,
			)
		);

		return $this->server->dispatch( $request );
	}

	/**
	 * PATCH /wcpos/v1/orders/{id}. Lines without an id are appended by WooCommerce.
	 *
	 * @param int   $order_id   Order id.
	 * @param array $line_items Line item payloads.
	 */
	private function v1_update( int $order_id, array $line_items ): WP_REST_Response {
		$request = $this->wp_rest_patch_request( '/wcpos/v1/orders/' . $order_id );
		$request->set_body_params( array( 'line_items' => $line_items ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * POST /wcpos/v2/push/orders with one mutation envelope.
	 *
	 * @param string      $operation     create|update.
	 * @param string      $record_id     Client record uuid.
	 * @param array       $payload       Order document.
	 * @param string|null $base_revision Revision the update is based on.
	 */
	private function push_order( string $operation, string $record_id, array $payload, ?string $base_revision = null ): WP_REST_Response {
		$envelope = array(
			'mutationId'   => wp_generate_uuid4(),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => $record_id,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Assert the persisted order's tax and total, and that the tax lines add up.
	 *
	 * @param int   $order_id  Order id.
	 * @param float $total_tax Expected order tax.
	 * @param float $total     Expected order total.
	 */
	private function assert_order_amounts( int $order_id, float $total_tax, float $total ): void {
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $total_tax, (float) $order->get_total_tax() );
		$this->assertSame( $total, (float) $order->get_total() );
		$this->assertSame( $total_tax, array_sum( array_map( static fn( $tax ) => (float) $tax->get_tax_total(), $order->get_taxes() ) ), 'Tax lines must add up to the order tax.' );
	}
}
