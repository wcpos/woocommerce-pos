<?php
/**
 * Storage-level tests for sync mutations.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WP_Error;

/**
 * Mutation reservation/finalization tests adapted from the lab.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Mutation_Store
 */
class Test_Mutation_Store extends Sync_Store_Test_Case {
	/** @var Mutation_Store */
	private $store;

	/**
	 * Install a clean mutation table.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->install_sync_tables_directly();
		$this->store = new Mutation_Store();
	}

	/**
	 * A mutation can be reserved, checkpointed, and finalized once.
	 */
	public function test_mutation_create_and_finalize_persists_done_row(): void {
		$this->assertTrue( $this->store->reserve( 'products', 'mutation-1', 'record-1', 'create' ) );
		$this->assertFalse( $this->store->reserve( 'products', 'mutation-1', 'record-1', 'create' ) );
		$this->assertSame( 'pending', $this->store->lookup( 'products', 'mutation-1' )['status'] );
		$this->assertTrue( $this->store->mark_applied( 'mutation-1', 42, 201 ) );
		$this->assertTrue( $this->store->finalize( 'mutation-1', 42 ) );

		$row = $this->store->lookup( 'products', 'mutation-1' );
		$this->assertSame( 'done', $row['status'] );
		$this->assertSame( '42', $row['remote_id'] );
	}

	public function test_reservation_persists_fingerprint_and_lookup_finds_cross_collection_reuse(): void {
		$this->assertTrue( $this->store->reserve( 'products', 'mutation-global', 'record-1', 'create', 'fingerprint-1' ) );

		$row = $this->store->lookup( 'customers', 'mutation-global' );

		$this->assertSame( 'products', $row['collection'] );
		$this->assertSame( 'fingerprint-1', $row['fingerprint'] );
	}

	/**
	 * Finalization is idempotent for the same id and rejects a different id.
	 */
	public function test_finalize_replay_requires_the_same_remote_id(): void {
		$this->store->reserve( 'products', 'mutation-1', 'record-1', 'create' );
		$this->store->mark_applied( 'mutation-1', 42, 201 );
		$this->store->finalize( 'mutation-1', 42 );

		$this->assertTrue( $this->store->finalize( 'mutation-1', 42 ) );
		$this->assertFalse( $this->store->finalize( 'mutation-1', 99 ) );
	}

	/**
	 * A post-forward poison checkpoint survives generic pending cleanup.
	 */
	public function test_poison_checkpoint_survives_release_and_can_finalize_without_reforwarding(): void {
		$this->assertTrue( $this->store->reserve( 'products', 'mutation-poison', 'record-1', 'create' ) );
		$this->assertTrue( $this->store->mark_poison( 'mutation-poison', 42, 201 ) );

		$this->store->release( 'mutation-poison' );
		$row = $this->store->lookup( 'products', 'mutation-poison' );

		$this->assertSame( 'poison', $row['status'] );
		$this->assertSame( '201', $row['response_status'] );
		$this->assertTrue( $this->store->finalize_poison( 'mutation-poison', 42 ) );
		$this->assertSame( 'done', $this->store->lookup( 'products', 'mutation-poison' )['status'] );
	}

	/**
	 * An applied checkpoint is durable even if finalization must be retried.
	 */
	public function test_applied_checkpoint_survives_release_and_replay_finalize(): void {
		$this->store->reserve( 'orders', 'mutation-applied', 'record-1', 'update' );
		$this->assertTrue( $this->store->mark_applied( 'mutation-applied', 73, 200 ) );

		$this->store->release( 'mutation-applied' );
		$this->assertSame( 'applied', $this->store->lookup( 'orders', 'mutation-applied' )['status'] );
		$this->assertTrue( $this->store->finalize( 'mutation-applied', 73 ) );
		$this->assertSame( 'done', $this->store->lookup( 'orders', 'mutation-applied' )['status'] );
	}

	/**
	 * Only pending reservations are released after a pre-forward failure.
	 */
	public function test_release_deletes_only_pending_reservations(): void {
		$this->store->reserve( 'products', 'mutation-pending', 'record-1', 'create' );
		$this->store->release( 'mutation-pending' );

		$this->assertNull( $this->store->lookup( 'products', 'mutation-pending' ) );
	}

	/**
	 * A crashed pending claim becomes reclaimable after its fixed lease.
	 */
	public function test_stale_pending_reservation_is_reclaimed(): void {
		global $wpdb;

		$this->store->reserve( 'products', 'mutation-stale', 'record-1', 'create' );
		$wpdb->update(
			$this->store->table_name(),
			array( 'created_at' => '2000-01-01 00:00:00' ),
			array( 'mutation_id' => 'mutation-stale' )
		);

		$this->assertTrue( $this->store->reclaim_stale( 'mutation-stale', 60 ) );
		$this->assertNull( $this->store->lookup( 'products', 'mutation-stale' ) );
	}

	/**
	 * Duplicate live UUID owners fail closed with the canonical wire error.
	 */
	public function test_resolve_post_uuid_fails_closed_when_two_live_products_own_it(): void {
		$uuid   = '5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c6d';
		$first  = ProductHelper::create_simple_product();
		$second = ProductHelper::create_simple_product();
		update_post_meta( $first->get_id(), Api::UUID_META_KEY, $uuid );
		update_post_meta( $second->get_id(), Api::UUID_META_KEY, $uuid );

		$result = $this->store->resolve_id_by_uuid( 'post', $uuid, array( 'post_type' => 'product' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_identity_ambiguous', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Order UUID persistence uses the HPOS-safe order object path.
	 */
	public function test_order_uuid_persists_through_the_order_object(): void {
		$order = OrderHelper::create_order();
		$uuid  = 'a1b2c3d4-1111-4222-8333-444455556666';

		$this->assertTrue( $this->store->persist_uuid( 'order', $order->get_id(), $uuid ) );
		$this->assertSame( $uuid, wc_get_order( $order->get_id() )->get_meta( Api::UUID_META_KEY ) );
	}

	/**
	 * POS order audit fields are write-once while missing till fields can be filled.
	 */
	public function test_order_audit_meta_is_write_once_and_created_via_is_authoritative(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_user', '10' );
		$order->save();

		$this->store->persist_order_audit_meta(
			$order->get_id(),
			array(
				'_pos_user' => '99',
				'_pos_store' => 'store-a',
			),
			'woocommerce-pos'
		);

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( '10', $reloaded->get_meta( '_pos_user' ) );
		$this->assertSame( 'store-a', $reloaded->get_meta( '_pos_store' ) );
		$this->assertSame( 'woocommerce-pos', $reloaded->get_created_via() );
	}
}
