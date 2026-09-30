<?php
/**
 * Self-test for the order post-meta tripwire installed by the bootstrap.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use LogicException;
use WCPOS\WooCommercePOS\Tests\Helpers\Order_Postmeta_Guard;
use WP_UnitTestCase;

use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\offender_read;
use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\offender_read_all;
use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\offender_write;

/**
 * The guard must fire for plugin code addressing an order through the post-meta API,
 * and stay silent for everything else, or a green suite proves nothing about HPOS.
 *
 * Runs on posts storage here and on HPOS storage in Test_Order_Postmeta_Guard_HPOS.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Order_Postmeta_Guard extends WP_UnitTestCase {
	/**
	 * Directory the guard watched before this test re-pointed it.
	 *
	 * @var string
	 */
	private $previous_dir = '';

	/**
	 * Point the guard at the fixture directory so its functions count as plugin code.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../Helpers/fixtures/order-postmeta-offender.php';
		$this->previous_dir = Order_Postmeta_Guard::watch_directory( __DIR__ . '/../Helpers/fixtures' );
	}

	/**
	 * Restore `includes/` as the policed directory.
	 */
	public function tearDown(): void {
		Order_Postmeta_Guard::watch_directory( $this->previous_dir );
		parent::tearDown();
	}

	/**
	 * A plugin-side get_post_meta() on an order fails the test and names the caller.
	 */
	public function test_guard_plugin_reads_order_postmeta_throws_naming_the_caller(): void {
		// Arrange.
		$order = wc_create_order();

		// Act + Assert.
		try {
			offender_read( $order->get_id(), '_pos_cash_change' );
			$this->fail( 'Expected the guard to throw.' );
		} catch ( LogicException $e ) {
			$this->assertStringContainsString( 'fixtures/order-postmeta-offender.php:', $e->getMessage() );
			$this->assertStringContainsString( 'get_post_meta()', $e->getMessage() );
			$this->assertStringContainsString( '#' . $order->get_id(), $e->getMessage() );
			$this->assertStringContainsString( "'_pos_cash_change'", $e->getMessage() );
			$this->assertStringContainsString( '$order->get_meta()', $e->getMessage() );
		}
	}

	/**
	 * A plugin-side update_post_meta() on an order fails the test before anything is written.
	 */
	public function test_guard_plugin_writes_order_postmeta_throws_before_writing(): void {
		// Arrange.
		$order = wc_create_order();

		// Act.
		$thrown = false;
		try {
			offender_write( $order->get_id(), '_wcpos_guard_probe', 'x' );
		} catch ( LogicException $e ) {
			$thrown = true;
			$this->assertStringContainsString( 'update_post_meta()', $e->getMessage() );
		}

		// Assert.
		$this->assertTrue( $thrown );
		$this->assertSame( '', get_post_meta( $order->get_id(), '_wcpos_guard_probe', true ) );
	}

	/**
	 * A WP wrapper such as get_post_custom() is caught too — the walk skips wp-includes frames.
	 */
	public function test_guard_plugin_reads_order_via_wp_wrapper_throws(): void {
		// Arrange.
		$order = wc_create_order();

		// Act + Assert.
		$this->expectException( LogicException::class );
		offender_read_all( $order->get_id() );
	}

	/**
	 * Post meta on a non-order post is the correct API and passes.
	 */
	public function test_guard_plugin_reads_product_postmeta_passes(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		update_post_meta( $product->get_id(), '_wcpos_guard_probe', 'ok' );

		// Act.
		$value = offender_read( $product->get_id(), '_wcpos_guard_probe' );

		// Assert.
		$this->assertSame( 'ok', $value );
	}

	/**
	 * Test code may read an order's postmeta directly (e.g. to assert a row is absent).
	 */
	public function test_guard_test_code_reads_order_postmeta_passes(): void {
		// Arrange.
		$order = wc_create_order();

		// Act.
		$value = get_post_meta( $order->get_id(), '_wcpos_guard_probe', true );

		// Assert.
		$this->assertSame( '', $value );
	}

	/**
	 * The order object's own meta API is the sanctioned path on both backends and passes,
	 * even though on posts storage it reaches postmeta through WooCommerce's data store.
	 */
	public function test_guard_order_object_meta_api_passes(): void {
		// Arrange.
		$order = wc_create_order();
		$order->update_meta_data( '_wcpos_guard_probe', 'via-order' );
		$order->save();

		// Act.
		$value = wc_get_order( $order->get_id() )->get_meta( '_wcpos_guard_probe' );

		// Assert.
		$this->assertSame( 'via-order', $value );
	}
}
