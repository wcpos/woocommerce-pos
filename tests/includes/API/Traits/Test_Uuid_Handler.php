<?php
/**
 * Tests for the Uuid_Handler trait.
 */

namespace WCPOS\WooCommercePOS\Tests\API\Traits;

use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\StaticMockerHack;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_User;

/**
 * Concrete class that uses the Uuid_Handler trait for testing.
 */
class Test_Uuid_Handler_Class {
	use Uuid_Handler;

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
	 */
	public function test_get_order_ids_by_uuid( string $uuid ): array {
		return $this->get_order_ids_by_uuid( $uuid );
	}

	/**
	 * Expose the excluded order-item path for testing.
	 *
	 * @param mixed $item Order item.
	 */
	public function test_maybe_add_order_item_uuid( $item ): void {
		$this->maybe_add_order_item_uuid( $item );
	}
}

/**
 * Test_Uuid_Handler class.
 *
 * @internal
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
		StaticMockerHack::get_hack_instance()->reset();
		parent::tearDown();
	}

	/**
	 * Legacy record stamping delegates to the sync identity brain once.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_post_uuid
	 */
	public function test_post_uuid_mints_through_pos_uuid_once(): void {
		$product = ProductHelper::create_simple_product();
		$product->delete_meta_data( Api::UUID_META_KEY );
		$product->save_meta_data();
		$calls = 0;

		StaticMockerHack::add_method_mocks(
			array(
				'Pos_Uuid' => array(
					'ensure_uuid' => static function ( $object, array $opts = array() ) use ( &$calls ): string {
						$calls++;
						return Pos_Uuid::ensure_uuid( $object, $opts );
					},
				),
			)
		);

		$this->handler->test_maybe_add_post_uuid( $product );
		$uuid = $product->get_meta( Api::UUID_META_KEY );

		$this->assertSame( 1, $calls );
		$this->assertTrue( Pos_Uuid::is_uuid( $uuid ) );
		$this->assertSame( $uuid, Pos_Uuid::ensure_uuid( wc_get_product( $product->get_id() ) ) );
	}

	/**
	 * Qualified calls must remain intact when the static mocker sees Pos_Uuid.
	 */
	public function test_static_mocker_preserves_qualified_pos_uuid_calls(): void {
		$source = '<?php \\WCPOS\\WooCommercePOS\\Sync\\Pos_Uuid::register_hooks();';

		$hacked = StaticMockerHack::get_hack_instance()->hack( $source, '' );

		$this->assertSame( $source, $hacked );
	}

	/**
	 * Test maybe_add_post_uuid adds UUID to product.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_post_uuid
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
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_post_uuid
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
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_post_uuid
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
	 * Pos_Uuid keeps the first valid UUID, rather than the trait deleting it
	 * because an invalid UUID row happened to be first.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_post_uuid
	 */
	public function test_maybe_add_post_uuid_uses_pos_uuid_duplicate_resolution(): void {
		$product    = ProductHelper::create_simple_product();
		$valid_uuid = wp_generate_uuid4();
		delete_post_meta( $product->get_id(), Api::UUID_META_KEY );
		add_post_meta( $product->get_id(), Api::UUID_META_KEY, 'invalid-first' );
		add_post_meta( $product->get_id(), Api::UUID_META_KEY, $valid_uuid );

		$this->handler->test_maybe_add_post_uuid( wc_get_product( $product->get_id() ) );

		$this->assertSame( array( $valid_uuid ), get_post_meta( $product->get_id(), Api::UUID_META_KEY, false ) );
	}

	/**
	 * A trashed post is not a live UUID owner under sync collision semantics.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_post_uuid
	 */
	public function test_maybe_add_post_uuid_ignores_trashed_uuid_owner(): void {
		$trashed = ProductHelper::create_simple_product();
		$active  = ProductHelper::create_simple_product();
		$uuid    = wp_generate_uuid4();
		update_post_meta( $trashed->get_id(), Api::UUID_META_KEY, $uuid );
		wp_trash_post( $trashed->get_id() );
		update_post_meta( $active->get_id(), Api::UUID_META_KEY, $uuid );

		$this->handler->test_maybe_add_post_uuid( wc_get_product( $active->get_id() ) );

		$this->assertSame( $uuid, get_post_meta( $active->get_id(), Api::UUID_META_KEY, true ) );
	}

	/**
	 * Test maybe_add_user_uuid adds UUID to user.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_user_uuid
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
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_user_uuid
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
	 * User duplicate cleanup follows Pos_Uuid's first-valid rule.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_user_uuid
	 */
	public function test_maybe_add_user_uuid_uses_pos_uuid_duplicate_resolution(): void {
		$user       = $this->factory->user->create_and_get( array( 'role' => 'customer' ) );
		$valid_uuid = wp_generate_uuid4();
		delete_user_meta( $user->ID, Api::UUID_META_KEY );
		add_user_meta( $user->ID, Api::UUID_META_KEY, 'invalid-first' );
		add_user_meta( $user->ID, Api::UUID_META_KEY, $valid_uuid );

		$this->handler->test_maybe_add_user_uuid( $user );

		$this->assertSame( array( $valid_uuid ), get_user_meta( $user->ID, Api::UUID_META_KEY, false ) );
		wp_delete_user( $user->ID );
	}

	/**
	 * Test get_term_uuid adds and returns UUID for term.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::get_term_uuid
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
	 * Term duplicate cleanup follows Pos_Uuid's first-valid rule.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::get_term_uuid
	 */
	public function test_get_term_uuid_uses_pos_uuid_duplicate_resolution(): void {
		$created    = wp_insert_term( 'Duplicate UUID Category', 'product_cat' );
		$term       = get_term( $created['term_id'], 'product_cat' );
		$valid_uuid = wp_generate_uuid4();
		delete_term_meta( $term->term_id, Api::UUID_META_KEY );
		add_term_meta( $term->term_id, Api::UUID_META_KEY, 'invalid-first' );
		add_term_meta( $term->term_id, Api::UUID_META_KEY, $valid_uuid );

		$uuid = $this->handler->test_get_term_uuid( $term );

		$this->assertSame( $valid_uuid, $uuid );
		$this->assertSame( array( $valid_uuid ), get_term_meta( $term->term_id, Api::UUID_META_KEY, false ) );
		wp_delete_term( $term->term_id, 'product_cat' );
	}

	/**
	 * Test get_order_ids_by_uuid returns correct order IDs.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::get_order_ids_by_uuid
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
	 * Trashed CPT orders do not own UUIDs returned to sync callers.
	 */
	public function test_get_order_ids_by_uuid_ignores_trashed_orders(): void {
		$uuid    = Uuid::uuid4()->toString();
		$active  = OrderHelper::create_order();
		$trashed = OrderHelper::create_order();
		$active->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$active->save_meta_data();
		$trashed->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$trashed->save_meta_data();
		$trashed->delete( false );

		$order_ids = $this->handler->test_get_order_ids_by_uuid( $uuid );

		$this->assertSame( array( (string) $active->get_id() ), $order_ids );
	}

	/**
	 * Test get_order_ids_by_uuid returns empty for non-existent UUID.
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::get_order_ids_by_uuid
	 */
	public function test_get_order_ids_by_uuid_nonexistent(): void {
		$order_ids = $this->handler->test_get_order_ids_by_uuid( 'nonexistent-uuid' );

		$this->assertIsArray( $order_ids );
		$this->assertEmpty( $order_ids );
	}

	/**
	 * Order-item UUIDs remain outside the sync identity brain (ADR 0021).
	 *
	 * @covers \WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler::maybe_add_order_item_uuid
	 */
	public function test_order_item_uuid_path_remains_legacy(): void {
		$order = OrderHelper::create_order();
		$items = $order->get_items();
		$item  = reset( $items );
		$item->update_meta_data( Api::UUID_META_KEY, 'legacy-order-item-identity' );
		$item->save_meta_data();

		$this->handler->test_maybe_add_order_item_uuid( $item );

		$this->assertSame( 'legacy-order-item-identity', $item->get_meta( Api::UUID_META_KEY ) );
	}
}
