<?php
/**
 * Tests for storefront receipt links.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Storefront_Receipts;
use WCPOS\WooCommercePOS\Templates;

/**
 * Storefront receipts test case.
 */
class Test_Storefront_Receipts extends WC_Unit_Test_Case {
	/**
	 * Storefront receipts instance.
	 *
	 * @var Storefront_Receipts
	 */
	private $storefront_receipts;

	/**
	 * Previous shortcode callback.
	 *
	 * @var callable|null
	 */
	private $previous_shortcode;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		global $shortcode_tags, $wp;

		$this->previous_shortcode = $shortcode_tags['wcpos_receipt'] ?? null;
		$this->storefront_receipts = new Storefront_Receipts();
		unset( $wp->query_vars['order-received'], $wp->query_vars['view-order'], $_GET['key'] );
		delete_option( 'wcpos_active_template_receipt' );
		delete_option( 'wcpos_disabled_virtual_templates_receipt' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;

		remove_shortcode( 'wcpos_receipt' );
		if ( null !== $this->previous_shortcode ) {
			add_shortcode( 'wcpos_receipt', $this->previous_shortcode );
		}

		remove_filter( 'woocommerce_my_account_my_orders_actions', array( $this->storefront_receipts, 'add_my_account_action' ) );
		remove_all_filters( 'woocommerce_pos_storefront_receipt_my_account_button' );
		remove_all_filters( 'woocommerce_pos_storefront_receipt_order_statuses' );
		unset( $wp->query_vars['order-received'], $wp->query_vars['view-order'], $_GET['key'] );
		delete_option( 'wcpos_active_template_receipt' );
		delete_option( 'wcpos_disabled_virtual_templates_receipt' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * An order owner can generate the default PDF link explicitly.
	 */
	public function test_shortcode_explicit_order_id_for_owner_returns_pdf_link(): void {
		// Arrange.
		$customer = CustomerHelper::create_customer();
		$order    = OrderHelper::create_order( array( 'customer_id' => $customer->get_id() ) );
		wp_set_current_user( $customer->get_id() );

		// Act.
		$output = do_shortcode( '[wcpos_receipt order_id="' . $order->get_id() . '"]' );

		// Assert.
		$this->assertStringContainsString( 'wcpos-receipt/' . $order->get_id(), $output );
		$this->assertStringContainsString( $order->get_order_key(), $output );
		$this->assertStringContainsString( 'format=pdf', $output );
	}

	/**
	 * Another customer cannot generate a link for an order they do not own.
	 */
	public function test_shortcode_explicit_order_id_for_different_user_returns_empty_string(): void {
		// Arrange.
		$owner    = CustomerHelper::create_customer();
		$customer = CustomerHelper::create_customer();
		$order    = OrderHelper::create_order( array( 'customer_id' => $owner->get_id() ) );
		wp_set_current_user( $customer->get_id() );

		// Act.
		$output = do_shortcode( '[wcpos_receipt order_id="' . $order->get_id() . '"]' );

		// Assert.
		$this->assertEquals( '', $output );
	}

	/**
	 * A shortcode outside an order context renders nothing.
	 */
	public function test_shortcode_without_order_context_returns_empty_string(): void {
		// Act.
		$output = do_shortcode( '[wcpos_receipt]' );

		// Assert.
		$this->assertEquals( '', $output );
	}

	/**
	 * HTML links omit the PDF format query argument.
	 */
	public function test_shortcode_html_format_omits_pdf_query_argument(): void {
		// Arrange.
		$customer = CustomerHelper::create_customer();
		$order    = OrderHelper::create_order( array( 'customer_id' => $customer->get_id() ) );
		wp_set_current_user( $customer->get_id() );

		// Act.
		$output = do_shortcode( '[wcpos_receipt order_id="' . $order->get_id() . '" format="html"]' );

		// Assert.
		$this->assertStringNotContainsString( 'format=pdf', $output );
	}

	/**
	 * Completed orders receive an action while pending orders do not.
	 */
	public function test_my_account_actions_default_statuses_add_completed_and_omit_pending(): void {
		// Arrange.
		$completed_order = OrderHelper::create_order( array( 'status' => 'completed' ) );
		$pending_order   = OrderHelper::create_order( array( 'status' => 'pending' ) );

		// Act.
		$completed_actions = $this->storefront_receipts->add_my_account_action( array(), $completed_order );
		$pending_actions   = $this->storefront_receipts->add_my_account_action( array(), $pending_order );

		// Assert.
		$this->assertArrayHasKey( 'wcpos-receipt', $completed_actions );
		$this->assertEquals( array( 'url', 'name', 'aria-label' ), array_keys( $completed_actions['wcpos-receipt'] ) );
		$this->assertEquals( 'string', gettype( $completed_actions['wcpos-receipt']['url'] ) );
		$this->assertEquals( 'string', gettype( $completed_actions['wcpos-receipt']['name'] ) );
		$this->assertArrayNotHasKey( 'wcpos-receipt', $pending_actions );
	}

	/**
	 * The master filter can disable the account action.
	 */
	public function test_my_account_button_disabled_by_filter_omits_action(): void {
		// Arrange.
		$order = OrderHelper::create_order( array( 'status' => 'completed' ) );
		add_filter( 'woocommerce_pos_storefront_receipt_my_account_button', '__return_false' );

		// Act.
		$actions = $this->storefront_receipts->add_my_account_action( array(), $order );

		// Assert.
		$this->assertArrayNotHasKey( 'wcpos-receipt', $actions );
	}

	/**
	 * The status filter is authoritative for pending orders.
	 */
	public function test_my_account_statuses_filter_including_pending_adds_action(): void {
		// Arrange.
		$order = OrderHelper::create_order( array( 'status' => 'pending' ) );
		add_filter(
			'woocommerce_pos_storefront_receipt_order_statuses',
			static function ( $statuses ) {
				$statuses[] = 'pending';

				return $statuses;
			}
		);

		// Act.
		$actions = $this->storefront_receipts->add_my_account_action( array(), $order );

		// Assert.
		$this->assertArrayHasKey( 'wcpos-receipt', $actions );
	}

	/**
	 * An unavailable receipt template suppresses the account action.
	 */
	public function test_my_account_without_active_template_omits_action(): void {
		// Arrange.
		$order = OrderHelper::create_order( array( 'status' => 'completed' ) );
		update_option(
			'wcpos_disabled_virtual_templates_receipt',
			array(
				Templates::TEMPLATE_THEME,
				Templates::TEMPLATE_PLUGIN_PRO,
				Templates::TEMPLATE_PLUGIN_CORE,
				Templates::TEMPLATE_WP_OVERNIGHT_INVOICE,
				Templates::TEMPLATE_WP_OVERNIGHT_PACKING_SLIP,
			)
		);

		// Act.
		$actions = $this->storefront_receipts->add_my_account_action( array(), $order );

		// Assert.
		$this->assertArrayNotHasKey( 'wcpos-receipt', $actions );
	}
}
