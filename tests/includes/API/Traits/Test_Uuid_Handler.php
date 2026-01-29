<?php
/**
 * Tests for the Uuid_Handler trait.
 */

namespace WCPOS\WooCommercePOS\Tests\API\Traits;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\API\Traits\Uuid_Handler;
use WP_User;

/**
 * Concrete class that uses the Uuid_Handler trait for testing.
 */
class Test_Uuid_Handler_Class {
	use Uuid_Handler;

	/**
	 * Expose private method for testing.
	 */
	public function test_create_uuid(): string {
		return $this->create_uuid();
	}

	/**
	 * Expose private method for testing.
	 *
	 * @param mixed $object
	 */
	public function test_maybe_add_post_uuid( $object ): void {
		$this->maybe_add_post_uuid( $object );
	}

	/**
	 * Expose private method for testing.
	 */
	public function test_maybe_add_user_uuid( WP_User $user ): void {
		$this->maybe_add_user_uuid( $user );
	}

	/**
	 * Expose private method for testing.
	 *
	 * @param mixed $term
	 */
	public function test_get_term_uuid( $term ): string {
		return $this->get_term_uuid( $term );
	}

	/**
	 * Expose private method for testing.
	 *
	 * @param mixed $object
	 */
	public function test_uuid_postmeta_exists( string $uuid, $object ): bool {
		return $this->uuid_postmeta_exists( $uuid, $object );
	}

	/**
	 * Expose private method for testing.
	 */
	public function test_uuid_usermeta_exists( string $uuid, int $exclude_id ): bool {
		return $this->uuid_usermeta_exists( $uuid, $exclude_id );
	}

	/**
	 * Expose private method for testing.
	 */
	public function test_get_order_ids_by_uuid( string $uuid ): array {
		return $this->get_order_ids_by_uuid( $uuid );
	}

	/**
	 * Expose private method for testing.
	 */
	public function test_acquire_lock( string $lock_key, int $timeout = 10 ): bool {
		return $this->acquire_lock( $lock_key, $timeout );
	}

	/**
	 * Expose private method for testing.
	 */
	public function test_release_lock( string $lock_key ): void {
		$this->release_lock( $lock_key );
	}
}

/**
 * Test_Uuid_Handler class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Uuid_Handler extends WC_Unit_Test_Case {
	/**
	 * The test handler instance.
	 *
	 * @var Test_Uuid_Handler_Class
	 */
	private $handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->handler = new Test_Uuid_Handler_Class();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test create_uuid generates valid UUID v4.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::create_uuid
	 */
	public function test_create_uuid_generates_valid_uuid(): void {
		$uuid = $this->handler->test_create_uuid();

		$this->assertNotEmpty( $uuid );
		$this->assertTrue( Uuid::isValid( $uuid ), 'Generated UUID should be valid' );
	}

	/**
	 * Test create_uuid generates unique UUIDs.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::create_uuid
	 */
	public function test_create_uuid_generates_unique_values(): void {
		$uuid1 = $this->handler->test_create_uuid();
		$uuid2 = $this->handler->test_create_uuid();
		$uuid3 = $this->handler->test_create_uuid();

		$this->assertNotEquals( $uuid1, $uuid2 );
		$this->assertNotEquals( $uuid2, $uuid3 );
		$this->assertNotEquals( $uuid1, $uuid3 );
	}

	/**
	 * Test maybe_add_post_uuid adds UUID to product.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::maybe_add_post_uuid
	 */
	public function test_maybe_add_post_uuid_to_product(): void {
		$product = ProductHelper::create_simple_product();

		// Remove any existing UUID
		$product->delete_meta_data( '_woocommerce_pos_uuid' );
		$product->save();

		$this->handler->test_maybe_add_post_uuid( $product );
		$product->save_meta_data();

		$uuid = $product->get_meta( '_woocommerce_pos_uuid' );

		$this->assertNotEmpty( $uuid, 'Product should have UUID' );
		$this->assertTrue( Uuid::isValid( $uuid ), 'UUID should be valid' );
	}

	/**
	 * Test maybe_add_post_uuid adds UUID to order.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::maybe_add_post_uuid
	 */
	public function test_maybe_add_post_uuid_to_order(): void {
		$order = OrderHelper::create_order();

		// Remove any existing UUID
		$order->delete_meta_data( '_woocommerce_pos_uuid' );
		$order->save();

		$this->handler->test_maybe_add_post_uuid( $order );
		$order->save_meta_data();

		$uuid = $order->get_meta( '_woocommerce_pos_uuid' );

		$this->assertNotEmpty( $uuid, 'Order should have UUID' );
		$this->assertTrue( Uuid::isValid( $uuid ), 'UUID should be valid' );
	}

	/**
	 * Test maybe_add_post_uuid does not overwrite existing valid UUID.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::maybe_add_post_uuid
	 */
	public function test_maybe_add_post_uuid_preserves_existing(): void {
		$product       = ProductHelper::create_simple_product();
		$existing_uuid = Uuid::uuid4()->toString();

		// Set a valid UUID
		$product->update_meta_data( '_woocommerce_pos_uuid', $existing_uuid );
		$product->save();

		// Clear cache
		clean_post_cache( $product->get_id() );

		// Try to add UUID again
		$product = wc_get_product( $product->get_id() );
		$this->handler->test_maybe_add_post_uuid( $product );
		$product->save_meta_data();

		$product = wc_get_product( $product->get_id() );
		$uuid    = $product->get_meta( '_woocommerce_pos_uuid' );

		$this->assertEquals( $existing_uuid, $uuid, 'Existing UUID should be preserved' );
	}

	/**
	 * Test maybe_add_user_uuid adds UUID to user.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::maybe_add_user_uuid
	 */
	public function test_maybe_add_user_uuid(): void {
		$user = $this->factory->user->create_and_get( array( 'role' => 'customer' ) );

		// Remove any existing UUID
		delete_user_meta( $user->ID, '_woocommerce_pos_uuid' );

		$this->handler->test_maybe_add_user_uuid( $user );

		$uuid = get_user_meta( $user->ID, '_woocommerce_pos_uuid', true );

		$this->assertNotEmpty( $uuid, 'User should have UUID' );
		$this->assertTrue( Uuid::isValid( $uuid ), 'UUID should be valid' );

		wp_delete_user( $user->ID );
	}

	/**
	 * Test maybe_add_user_uuid preserves existing valid UUID.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::maybe_add_user_uuid
	 */
	public function test_maybe_add_user_uuid_preserves_existing(): void {
		$user          = $this->factory->user->create_and_get( array( 'role' => 'customer' ) );
		$existing_uuid = Uuid::uuid4()->toString();

		// Set a valid UUID
		update_user_meta( $user->ID, '_woocommerce_pos_uuid', $existing_uuid );

		$this->handler->test_maybe_add_user_uuid( $user );

		$uuid = get_user_meta( $user->ID, '_woocommerce_pos_uuid', true );

		$this->assertEquals( $existing_uuid, $uuid, 'Existing UUID should be preserved' );

		wp_delete_user( $user->ID );
	}

	/**
	 * Test get_term_uuid adds and returns UUID for term.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::get_term_uuid
	 */
	public function test_get_term_uuid(): void {
		// Create a product category
		$term_id = wp_insert_term( 'Test Category', 'product_cat' );
		$term    = get_term( $term_id['term_id'], 'product_cat' );

		// Remove any existing UUID
		delete_term_meta( $term->term_id, '_woocommerce_pos_uuid' );

		$uuid = $this->handler->test_get_term_uuid( $term );

		$this->assertNotEmpty( $uuid, 'Term should have UUID' );
		$this->assertTrue( Uuid::isValid( $uuid ), 'UUID should be valid' );

		wp_delete_term( $term->term_id, 'product_cat' );
	}

	/**
	 * Test uuid_postmeta_exists returns false for unique UUID.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::uuid_postmeta_exists
	 */
	public function test_uuid_postmeta_exists_unique(): void {
		$product     = ProductHelper::create_simple_product();
		$unique_uuid = Uuid::uuid4()->toString();

		$exists = $this->handler->test_uuid_postmeta_exists( $unique_uuid, $product );

		$this->assertFalse( $exists, 'Unique UUID should not exist' );
	}

	/**
	 * Test uuid_postmeta_exists returns true for duplicate UUID.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::uuid_postmeta_exists
	 */
	public function test_uuid_postmeta_exists_duplicate(): void {
		// Create two products with the same UUID
		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();

		$duplicate_uuid = Uuid::uuid4()->toString();

		$product1->update_meta_data( '_woocommerce_pos_uuid', $duplicate_uuid );
		$product1->save();

		// Check if duplicate exists from perspective of product2
		$exists = $this->handler->test_uuid_postmeta_exists( $duplicate_uuid, $product2 );

		$this->assertTrue( $exists, 'Duplicate UUID should be detected' );
	}

	/**
	 * Test uuid_usermeta_exists returns false for unique UUID.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::uuid_usermeta_exists
	 */
	public function test_uuid_usermeta_exists_unique(): void {
		$user        = $this->factory->user->create_and_get( array( 'role' => 'customer' ) );
		$unique_uuid = Uuid::uuid4()->toString();

		$exists = $this->handler->test_uuid_usermeta_exists( $unique_uuid, $user->ID );

		$this->assertFalse( $exists, 'Unique UUID should not exist' );

		wp_delete_user( $user->ID );
	}

	/**
	 * Test get_order_ids_by_uuid returns correct order IDs.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::get_order_ids_by_uuid
	 */
	public function test_get_order_ids_by_uuid(): void {
		$order = OrderHelper::create_order();
		$uuid  = Uuid::uuid4()->toString();

		$order->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$order->save();

		$order_ids = $this->handler->test_get_order_ids_by_uuid( $uuid );

		$this->assertContains( (string) $order->get_id(), $order_ids );
	}

	/**
	 * Test get_order_ids_by_uuid returns empty for non-existent UUID.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::get_order_ids_by_uuid
	 */
	public function test_get_order_ids_by_uuid_nonexistent(): void {
		$order_ids = $this->handler->test_get_order_ids_by_uuid( 'nonexistent-uuid' );

		$this->assertIsArray( $order_ids );
		$this->assertEmpty( $order_ids );
	}

	/**
	 * Test lock acquire and release.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::acquire_lock
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::release_lock
	 */
	public function test_acquire_and_release_lock(): void {
		$lock_key = 'test_lock_' . uniqid();

		// Acquire lock
		$acquired = $this->handler->test_acquire_lock( $lock_key, 1 );
		$this->assertTrue( $acquired, 'Should acquire lock' );

		// Release lock
		$this->handler->test_release_lock( $lock_key );

		// Should be able to acquire again
		$acquired_again = $this->handler->test_acquire_lock( $lock_key, 1 );
		$this->assertTrue( $acquired_again, 'Should acquire lock after release' );

		// Cleanup
		$this->handler->test_release_lock( $lock_key );
	}

	/**
	 * Test UUID format matches expected pattern.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\Traits\Uuid_Handler::create_uuid
	 */
	public function test_uuid_format(): void {
		$uuid = $this->handler->test_create_uuid();

		// UUID v4 pattern
		$pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

		$this->assertMatchesRegularExpression( $pattern, $uuid, 'UUID should match v4 format' );
	}
}
