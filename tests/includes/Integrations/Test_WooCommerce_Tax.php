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
	 *
	 * Dispatched on wc/v3, which the client calls directly and the v2 writer
	 * forwards to; the WCPOS header is what puts the request in the POS lane.
	 */
	public function test_wc3_open_order_paid_in_the_same_request_gets_tax_for_the_final_lines(): void {
		// Arrange.
		$a        = $this->product( 10 );
		$created  = $this->v1_create( 'pos-open', array( $this->line( $a ) ) );
		$order_id = (int) $created->get_data()['id'];

		// Act.
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $order_id );
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
	public function test_wc3_reopened_order_with_changed_lines_recalculates_tax(): void {
		// Arrange.
		$a        = $this->product( 10 );
		$created  = $this->v1_create( 'completed', array( $this->line( $a ) ) );
		$order_id = (int) $created->get_data()['id'];
		$this->assert_order_amounts( $order_id, 1.0, 11.0 );

		// Act.
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $order_id );
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
	 * Checkout on the wcpos/v2 push: the final document carries the paid status
	 * and the final lines together.
	 */
	public function test_v2_open_order_paid_in_the_same_push_gets_tax_for_the_final_lines(): void {
		// Arrange.
		$record_id = wp_generate_uuid4();
		$created   = $this->push_order(
			'create',
			$record_id,
			array(
				'status'     => 'pos-open',
				'line_items' => array( $this->line( $this->product( 10 ) ) ),
			)
		);
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$document = $created->get_data()['document'];
		$order_id = (int) $document['id'];

		// Act.
		$document['status']       = 'completed';
		$document['line_items'][] = $this->line( $this->product( 20 ) );
		$updated                  = $this->push_order( 'update', $record_id, $document, $created->get_data()['currentRevision'] );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 'completed', $updated->get_data()['document']['status'] );
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
	 * A snapshot the plugin took on an earlier bare calculate_taxes() must not be
	 * written back when the POS recalculates: the restore callback is re-hooked
	 * only after its priority has passed.
	 */
	public function test_stale_snapshot_from_a_bare_recalculation_is_not_restored_onto_an_open_order(): void {
		// Arrange: the plugin snapshots the order during a non-POS recalculation
		// that never reaches after_calculate_totals.
		$a        = $this->product( 10 );
		$created  = $this->v1_create( 'pos-open', array( $this->line( $a ) ) );
		$order_id = (int) $created->get_data()['id'];
		unset( $_SERVER['HTTP_X_WCPOS'] );
		wc_get_order( $order_id )->calculate_taxes();
		$this->assertSame( 1, $this->taxjar->snapshots_taken );
		$this->assertSame( 0, $this->taxjar->restores_applied );
		$_SERVER['HTTP_X_WCPOS'] = '1';

		// Act.
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $order_id );
		$request->set_body_params( array( 'line_items' => array( $this->line( $this->product( 20 ) ) ) ) );
		$updated = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 0, $this->taxjar->restores_applied, 'The stale snapshot must not be written back during the POS recalculation.' );
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

	/** Priming supplies the rate matched by the subsequent WooCommerce calculation. */
	public function test_pos_create_primes_the_rates_table_for_the_order_tax_location(): void {
		// Arrange.
		$a       = $this->product( 10 );
		$payload = $this->priming_payload( $a );
		// Act.
		$created = $this->push_order( 'create', wp_generate_uuid4(), $payload, null );
		// Assert.
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$this->assertCount( 1, $this->taxjar->calculate_tax_calls );
		$options = $this->taxjar->calculate_tax_calls[0];
		$this->assertSame( 'US', $options['to_country'] );
		$this->assertSame( 'CA', $options['to_state'] );
		$this->assertSame( '90210', $options['to_zip'] );
		$this->assertSame( 'Beverly Hills', $options['to_city'] );
		$this->assertSame( '1 Rodeo Dr', $options['to_street'] );
		$this->assertSame( 0.0, (float) $options['shipping_amount'] );
		$this->assertCount( 1, $options['line_items'] );
		$line = $options['line_items'][0];
		$this->assertSame( (string) $a->get_id(), $line['id'] );
		$this->assertSame( 1, $line['quantity'] );
		$this->assertSame( 10.0, (float) $line['unit_price'] );
		$this->assertSame( 0.0, (float) $line['discount'] );
		$this->assertSame( '', $line['product_tax_code'] );
		$this->assertSame( array( 'id', 'quantity', 'unit_price', 'discount', 'product_tax_code' ), array_keys( $line ), 'The request carries exactly the keys the plugin sends to TaxJar.' );
		$order_id = (int) $created->get_data()['document']['id'];
		$this->assert_order_amounts( $order_id, 0.85, 10.85 );
		$taxes = array_values( wc_get_order( $order_id )->get_taxes() );
		$this->assertCount( 1, $taxes );
		$this->assertSame( 'CA STATE TAX', $taxes[0]->get_label() );
	}

	/** Each write primes with its current lines, without deduplicating calls. */
	public function test_pos_update_primes_again_for_the_current_lines(): void {
		// Arrange.
		$record_id = wp_generate_uuid4();
		$created   = $this->push_order( 'create', $record_id, $this->priming_payload( $this->product( 10 ) ) );
		$this->assertSame( 201, $created->get_status() );
		$document                 = $created->get_data()['document'];
		$document['line_items'][] = $this->line( $this->product( 20 ) );
		// Act.
		$updated = $this->push_order( 'update', $record_id, $document, $created->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertCount( 2, $this->taxjar->calculate_tax_calls );
		$this->assertCount( 2, $this->taxjar->calculate_tax_calls[1]['line_items'] );
		$this->assert_order_amounts( (int) $document['id'], 2.55, 32.55 );
	}

	/** Store-based tax uses the store street rather than an empty order address. */
	public function test_base_address_is_sent_when_pos_tax_is_based_on_the_store(): void {
		// Arrange.
		update_option( 'woocommerce_store_address', '100 Main St' );
		update_option( 'woocommerce_store_postcode', '94103' );
		$payload = array(
			'status' => 'pos-open',
			'line_items' => array( $this->line( $this->product( 10 ) ) ),
		);
		// Act.
		$created = $this->push_order( 'create', wp_generate_uuid4(), $payload, null );
		// Assert.
		$this->assertSame( 201, $created->get_status() );
		$this->assertCount( 1, $this->taxjar->calculate_tax_calls );
		$options = $this->taxjar->calculate_tax_calls[0];
		$this->assertSame( '94103', $options['to_zip'] );
		$this->assertSame( '100 Main St', $options['to_street'] );
		$this->assertSame( 'CA', $options['to_state'] );
	}

	/**
	 * Billing and shipping can share a country, state, postcode and city; the
	 * street sent to TaxJar must be the one for the declared tax basis.
	 */
	public function test_street_follows_the_declared_tax_basis_when_addresses_share_a_location(): void {
		// Arrange.
		$address = array(
			'country'  => 'US',
			'state'    => 'CA',
			'postcode' => '90210',
			'city'     => 'Beverly Hills',
		);
		$payload = array(
			'status'     => 'pos-open',
			'line_items' => array( $this->line( $this->product( 10 ) ) ),
			'billing'    => $address + array( 'address_1' => '1 Rodeo Dr' ),
			'shipping'   => $address + array( 'address_1' => '2 Rodeo Dr' ),
			'meta_data'  => array(
				array(
					'key'   => '_woocommerce_pos_tax_based_on',
					'value' => 'shipping',
				),
			),
		);

		// Act.
		$created = $this->push_order( 'create', wp_generate_uuid4(), $payload );

		// Assert.
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$this->assertCount( 1, $this->taxjar->calculate_tax_calls );
		$this->assertSame( '90210', $this->taxjar->calculate_tax_calls[0]['to_zip'] );
		$this->assertSame( '2 Rodeo Dr', $this->taxjar->calculate_tax_calls[0]['to_street'] );
	}

	/** Non-POS writes leave rate priming to the plugin. */
	public function test_non_pos_request_does_not_prime(): void {
		// Arrange.
		$created = $this->v1_create( 'pos-open', array( $this->line( $this->product( 10 ) ) ) );
		$this->assertSame( 201, $created->get_status() );
		$this->taxjar->calculate_tax_calls = array();
		unset( $_SERVER['HTTP_X_WCPOS'] );
		// Act.
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $created->get_data()['id'] );
		$request->set_body_params( array( 'line_items' => array( $this->line( $this->product( 20 ) ) ) ) );
		$updated = $this->server->dispatch( $request );
		// Assert.
		$this->assertSame( 200, $updated->get_status() );
		$this->assertCount( 0, $this->taxjar->calculate_tax_calls );
	}

	/** Paid-order updates must not prime new rates. */
	public function test_paid_order_update_does_not_prime(): void {
		// Arrange.
		$created = $this->v1_create( 'completed', array( $this->line( $this->product( 10 ) ) ) );
		$this->assertSame( 201, $created->get_status() );
		$this->taxjar->calculate_tax_calls = array();
		// Act.
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $created->get_data()['id'] );
		$request->set_body_params( array( 'line_items' => array( $this->line( $this->product( 20 ) ) ) ) );
		$updated = $this->server->dispatch( $request );
		// Assert.
		$this->assertSame( 200, $updated->get_status() );
		$this->assertCount( 0, $this->taxjar->calculate_tax_calls );
	}

	/** TaxJar failure keeps the existing rate-matching behavior and saves the order. */
	public function test_priming_failure_does_not_block_the_write(): void {
		// Arrange.
		$payload                            = $this->priming_payload( $this->product( 10 ) );
		$this->taxjar->calculate_tax_throws = true;
		// Act.
		$created = $this->push_order( 'create', wp_generate_uuid4(), $payload, null );
		// Assert.
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$this->assertCount( 1, $this->taxjar->calculate_tax_calls );
		$this->assert_order_amounts( (int) $created->get_data()['document']['id'], 1.0, 11.0 );
	}

	/**
	 * A numeric tax-class suffix becomes the TaxJar product code, and an item
	 * WooCommerce will not tax is left out of the request: sent as exempt it comes
	 * back at 0%, and the plugin would write that over the shared rate row for the
	 * item's class.
	 */
	public function test_tax_classes_map_to_product_codes_and_exempt_items_are_omitted(): void {
		// Arrange.
		\WC_Tax::create_tax_class( 'Clothing 20010', 'clothing-20010' );
		$a = $this->product( 10 );
		$a->set_tax_class( 'clothing-20010' );
		$a->save();
		$b = $this->product( 20 );
		$b->set_tax_status( 'none' );
		$b->save();
		$payload = array(
			'status'     => 'pos-open',
			'line_items' => array( $this->line( $a ), $this->line( $b ) ),
		);

		// Act.
		$created = $this->push_order( 'create', wp_generate_uuid4(), $payload );

		// Assert.
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$this->assertCount( 1, $this->taxjar->calculate_tax_calls );
		$lines = $this->taxjar->calculate_tax_calls[0]['line_items'];
		$this->assertCount( 1, $lines );
		$this->assertSame( (string) $a->get_id(), $lines[0]['id'] );
		$this->assertSame( '20010', $lines[0]['product_tax_code'] );
		$this->assertSame( 10.0, (float) $lines[0]['unit_price'] );
	}

	/**
	 * Nothing taxable and no shipping means nothing to prime; the plugin would
	 * only abort.
	 */
	public function test_order_with_only_exempt_items_does_not_prime(): void {
		// Arrange.
		$b = $this->product( 20 );
		$b->set_tax_status( 'none' );
		$b->save();

		// Act.
		$created = $this->push_order(
			'create',
			wp_generate_uuid4(),
			array(
				'status'     => 'pos-open',
				'line_items' => array( $this->line( $b ) ),
			)
		);

		// Assert.
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$this->assertCount( 0, $this->taxjar->calculate_tax_calls );
	}

	/**
	 * A bare calculate_taxes() leaves the plugin's callbacks suspended until the
	 * next recalculation. Priming must still find the plugin instance then.
	 */
	public function test_priming_still_finds_the_plugin_after_a_leftover_suspension(): void {
		// Arrange: a POS recalculation that never reaches after_calculate_totals.
		$created  = $this->v1_create( 'pos-open', array( $this->line( $this->product( 10 ) ) ) );
		$order_id = (int) $created->get_data()['id'];
		wc_get_order( $order_id )->calculate_taxes();
		$this->assertFalse( has_action( 'woocommerce_order_before_calculate_taxes', array( $this->taxjar, 'preserve_order_taxes_on_recalculation' ) ) );
		$this->taxjar->calculate_tax_calls = array();

		// Act.
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $order_id );
		$request->set_body_params( array( 'line_items' => array( $this->line( $this->product( 20 ) ) ) ) );
		$updated = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertCount( 1, $this->taxjar->calculate_tax_calls );
		$this->assertCount( 2, $this->taxjar->calculate_tax_calls[0]['line_items'] );
	}

	/**
	 * An open billing-based order and the rate TaxJar will supply for it.
	 *
	 * @param WC_Product $product The order's product.
	 * @return array
	 */
	private function priming_payload( WC_Product $product ): array {
		$this->taxjar->rates_by_postcode['90210'] = array(
			'rate' => '8.5000',
			'name' => 'CA STATE TAX',
			'state' => 'CA',
		);
		return array(
			'status'     => 'pos-open',
			'line_items' => array( $this->line( $product ) ),
			'billing'    => array(
				'country' => 'US',
				'state' => 'CA',
				'postcode' => '90210',
				'city' => 'Beverly Hills',
				'address_1' => '1 Rodeo Dr',
			),
			'meta_data'  => array(
				array(
					'key' => '_woocommerce_pos_tax_based_on',
					'value' => 'billing',
				),
			),
		);
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
