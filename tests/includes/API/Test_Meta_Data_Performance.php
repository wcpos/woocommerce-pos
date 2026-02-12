<?php
/**
 * Test Meta Data Performance.
 *
 * Tests for meta data monitoring, pre-flight checks, and response size estimation.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\Orders_Controller;
use WCPOS\WooCommercePOS\API\Products_Controller;

/**
 * Meta Data Performance test case.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Meta_Data_Performance extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Captured log messages.
	 *
	 * @var array
	 */
	private $captured_logs = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint      = new Products_Controller();
		$this->captured_logs = array();

		// Capture all Logger calls for assertion.
		add_filter(
			'woocommerce_pos_logging',
			function ( $should_log, $message ) {
				$this->captured_logs[] = $message;
				return false; // Prevent actual logging.
			},
			10,
			2
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_logging' );
		remove_all_filters( 'woocommerce_pos_meta_data_warning_threshold' );
		remove_all_filters( 'woocommerce_pos_meta_data_error_threshold' );
		parent::tearDown();
	}

	/**
	 * Helper: add N meta entries to a post.
	 *
	 * @param int    $post_id The post ID.
	 * @param int    $count   Number of entries to add.
	 * @param string $prefix  Meta key prefix.
	 */
	private function add_bulk_post_meta( int $post_id, int $count, string $prefix = '_test_meta' ): void {
		global $wpdb;
		$values = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$key   = $prefix . '_' . $i;
			$value = 'value_' . $i;
			$values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, $key, $value );
		}

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built safely above.
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $values ) );
		}

		// Clear WP object cache so WC picks up the new meta.
		clean_post_cache( $post_id );
		wp_cache_flush();
	}

	/**
	 * Helper: add N meta entries to an order (HPOS-aware).
	 *
	 * @param int $order_id The order ID.
	 * @param int $count    Number of entries to add.
	 */
	private function add_bulk_order_meta( int $order_id, int $count ): void {
		global $wpdb;

		$hpos_enabled = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$values = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$key   = '_test_order_meta_' . $i;
			$value = 'value_' . $i;
			$values[] = $wpdb->prepare( '(%d, %s, %s)', $order_id, $key, $value );
		}

		if ( ! empty( $values ) ) {
			if ( $hpos_enabled ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built safely above.
				$wpdb->query( "INSERT INTO {$wpdb->prefix}wc_orders_meta (order_id, meta_key, meta_value) VALUES " . implode( ', ', $values ) );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built safely above.
				$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $values ) );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Test: product with many meta entries returns 200 OK.
	 */
	public function test_product_with_many_meta_returns_200(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
			)
		);
		$this->add_bulk_post_meta( $product->get_id(), 200 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['meta_data'] );
		// Should have the 200 test entries plus any WC/POS entries.
		$this->assertGreaterThanOrEqual( 200, \count( $data['meta_data'] ) );
	}

	/**
	 * Test: order with many meta entries returns 200 OK.
	 */
	public function test_order_with_many_meta_returns_200(): void {
		$order = OrderHelper::create_order();
		$this->add_bulk_order_meta( $order->get_id(), 200 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['meta_data'] );
		$this->assertGreaterThanOrEqual( 200, \count( $data['meta_data'] ) );
	}

	/**
	 * Test: meta count warning is logged when exceeding warning threshold.
	 */
	public function test_meta_count_warning_is_logged(): void {
		// Lower the threshold for testing.
		add_filter(
			'woocommerce_pos_meta_data_warning_threshold',
			function () {
				return 10;
			}
		);

		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
			)
		);
		$this->add_bulk_post_meta( $product->get_id(), 20 );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$this->server->dispatch( $request );

		// Find the warning log message.
		$found = false;
		foreach ( $this->captured_logs as $log ) {
			if ( strpos( $log, 'meta_data entries' ) !== false && strpos( $log, 'meta bloat' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected meta count warning log not found. Logs: ' . implode( ' | ', $this->captured_logs ) );
	}

	/**
	 * Test: meta count error is logged when exceeding error threshold.
	 */
	public function test_meta_count_error_is_logged(): void {
		// Lower thresholds for testing.
		add_filter(
			'woocommerce_pos_meta_data_warning_threshold',
			function () {
				return 5;
			}
		);
		add_filter(
			'woocommerce_pos_meta_data_error_threshold',
			function () {
				return 15;
			}
		);

		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
			)
		);
		$this->add_bulk_post_meta( $product->get_id(), 30 );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$this->server->dispatch( $request );

		// Find the error log message.
		$found = false;
		foreach ( $this->captured_logs as $log ) {
			if ( strpos( $log, 'meta_data entries' ) !== false && strpos( $log, 'performance issues' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected meta count error log not found. Logs: ' . implode( ' | ', $this->captured_logs ) );
	}

	/**
	 * Test: preflight meta count returns correct count.
	 */
	public function test_preflight_meta_count_returns_correct_count(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
			)
		);
		$this->add_bulk_post_meta( $product->get_id(), 100 );

		$controller = new Products_Controller();
		$count      = $controller->wcpos_preflight_meta_count( $product->get_id(), 'post' );

		// Should be 100 + whatever WC adds by default.
		$this->assertGreaterThanOrEqual( 100, $count );
	}

	/**
	 * Test: essential meta returns only POS keys.
	 */
	public function test_essential_meta_returns_pos_keys_only(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
			)
		);

		// Add POS-specific meta.
		$product->update_meta_data( '_woocommerce_pos_uuid', Uuid::uuid4()->toString() );
		$product->update_meta_data( '_woocommerce_pos_variable_prices', '{"price":{"min":"5","max":"10"}}' );
		$product->save_meta_data();

		// Add non-POS meta.
		$this->add_bulk_post_meta( $product->get_id(), 50, '_junk_plugin' );

		$controller    = new Products_Controller();
		$essential     = $controller->wcpos_get_essential_meta( $product->get_id(), 'post' );
		$returned_keys = array_column( $essential, 'key' );

		// Should have POS keys.
		$this->assertContains( '_woocommerce_pos_uuid', $returned_keys );
		$this->assertContains( '_woocommerce_pos_variable_prices', $returned_keys );

		// Should NOT have junk keys.
		$junk_keys = array_filter(
			$returned_keys,
			function ( $key ) {
				return strpos( $key, '_junk_plugin' ) === 0;
			}
		);
		$this->assertEmpty( $junk_keys, 'Essential meta should not contain non-POS keys.' );
	}

	/**
	 * Test: order preflight bypasses for excessive meta with UUID retry.
	 *
	 * Simulates the retry loop: create an order, add excessive meta, then POST
	 * a create-order with the same UUID. The pre-flight check should return
	 * a response with essential meta only (not OOM).
	 */
	public function test_order_preflight_bypasses_for_excessive_meta(): void {
		$this->endpoint = new Orders_Controller();

		// Lower the error threshold for testing.
		add_filter(
			'woocommerce_pos_meta_data_error_threshold',
			function () {
				return 20;
			}
		);

		// Create an order with a known UUID.
		$order = OrderHelper::create_order();
		$uuid  = Uuid::uuid4()->toString();
		$order->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$order->update_meta_data( '_pos_user', '1' );
		$order->save_meta_data();

		// Add excessive meta directly to DB.
		$this->add_bulk_order_meta( $order->get_id(), 50 );

		// Simulate POS retry: POST create-order with the same UUID.
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'payment_method' => 'pos_cash',
				'meta_data'      => array(
					array(
						'key'   => '_woocommerce_pos_uuid',
						'value' => $uuid,
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );

		// Should return 200 OK (not 500/OOM).
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $order->get_id(), $data['id'] );

		// Meta should contain essential keys.
		$meta_keys = array_column( $data['meta_data'], 'key' );
		$this->assertContains( '_woocommerce_pos_uuid', $meta_keys );
		$this->assertContains( '_pos_user', $meta_keys );

		// Check that the error was logged.
		$found = false;
		foreach ( $this->captured_logs as $log ) {
			if ( strpos( $log, 'essential meta only' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected safe response error log not found.' );
	}

	/**
	 * Test: response size warning is logged for large description.
	 */
	public function test_response_size_warning_for_large_description(): void {
		// Lower the threshold for testing.
		add_filter(
			'woocommerce_pos_response_size_warning_threshold',
			function () {
				return 1000; // 1KB.
			}
		);

		$description = str_repeat( '<p>This is a long product description paragraph. </p>', 100 );
		$product     = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
				'description'   => $description,
			)
		);

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$this->server->dispatch( $request );

		// Find the response size warning.
		$found = false;
		foreach ( $this->captured_logs as $log ) {
			if ( strpos( $log, 'response size' ) !== false && strpos( $log, 'exceeds' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected response size warning log not found. Logs: ' . implode( ' | ', $this->captured_logs ) );
	}

	/**
	 * Test: meta count warning includes top meta keys context.
	 */
	public function test_meta_count_warning_includes_top_keys(): void {
		add_filter(
			'woocommerce_pos_meta_data_warning_threshold',
			function () {
				return 5;
			}
		);

		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
			)
		);
		// Add recognizable meta keys.
		$this->add_bulk_post_meta( $product->get_id(), 10, '_yoast_seo' );
		$this->add_bulk_post_meta( $product->get_id(), 5, '_elementor' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$this->server->dispatch( $request );

		// Find the log with top keys context.
		$found = false;
		foreach ( $this->captured_logs as $log ) {
			if ( strpos( $log, '_yoast_seo' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected top meta keys in log context not found. Logs: ' . implode( ' | ', $this->captured_logs ) );
	}

	/**
	 * Test: thresholds are configurable via filters.
	 */
	public function test_thresholds_are_configurable_via_filters(): void {
		// Set very high thresholds so no warnings fire.
		add_filter(
			'woocommerce_pos_meta_data_warning_threshold',
			function () {
				return 9999;
			}
		);
		add_filter(
			'woocommerce_pos_meta_data_error_threshold',
			function () {
				return 99999;
			}
		);

		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
			)
		);
		$this->add_bulk_post_meta( $product->get_id(), 200 );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$this->server->dispatch( $request );

		// Should NOT have any meta_data warning/error logs.
		$meta_logs = array_filter(
			$this->captured_logs,
			function ( $log ) {
				return strpos( $log, 'meta_data entries' ) !== false;
			}
		);
		$this->assertEmpty( $meta_logs, 'Should not log warnings when thresholds are set very high.' );
	}
}
