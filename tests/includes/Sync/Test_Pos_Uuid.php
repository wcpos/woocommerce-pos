<?php
/**
 * Tests for sync record identity.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_UnitTestCase;

/**
 * Pos UUID tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid
 */
class Test_Pos_Uuid extends WP_UnitTestCase {
	/**
	 * The identity brain uses the shared production meta-key constant.
	 */
	public function test_meta_key_uses_sync_api_constant(): void {
		$this->assertSame( Api::UUID_META_KEY, Pos_Uuid::META_KEY );
	}

	/**
	 * A missing UUID is minted and persisted through the WC data API.
	 */
	public function test_ensure_uuid_mints_and_persists_product_identity(): void {
		$product = ProductHelper::create_simple_product();
		$uuid    = Pos_Uuid::ensure_uuid( $product );

		$this->assertTrue( Pos_Uuid::is_uuid( $uuid ) );
		$this->assertSame( $uuid, wc_get_product( $product->get_id() )->get_meta( Api::UUID_META_KEY ) );
		$this->assertSame( $uuid, Pos_Uuid::ensure_uuid( wc_get_product( $product->get_id() ) ) );
	}

	/**
	 * Payload stamping removes conflicting copies and preserves unrelated meta.
	 */
	public function test_ensure_in_payload_keeps_one_canonical_uuid(): void {
		$uuid    = wp_generate_uuid4();
		$payload = Pos_Uuid::ensure_in_payload(
			array(
				'meta_data' => array(
					array(
						'key' => Api::UUID_META_KEY,
						'value' => '',
					),
					array(
						'key' => 'other',
						'value' => 'kept',
					),
					array(
						'key' => Api::UUID_META_KEY,
						'value' => wp_generate_uuid4(),
					),
				),
			),
			$uuid
		);

		$this->assertSame(
			array(
				array(
					'key' => 'other',
					'value' => 'kept',
				),
				array(
					'key' => Api::UUID_META_KEY,
					'value' => $uuid,
				),
			),
			$payload['meta_data']
		);
	}

	/**
	 * A copied UUID on another active post is a collision.
	 */
	public function test_uuid_owned_by_other_detects_active_product_collision(): void {
		$uuid    = wp_generate_uuid4();
		$first   = ProductHelper::create_simple_product();
		$second  = ProductHelper::create_simple_product();
		$first->update_meta_data( Api::UUID_META_KEY, $uuid );
		$first->save_meta_data();
		$second->update_meta_data( Api::UUID_META_KEY, $uuid );
		$second->save_meta_data();

		$this->assertTrue( Pos_Uuid::uuid_owned_by_other( $uuid, $second ) );
	}

	/**
	 * The shared identity brain repairs a copied UUID without touching its owner.
	 */
	public function test_ensure_uuid_repairs_active_product_collision(): void {
		$uuid   = wp_generate_uuid4();
		$owner  = ProductHelper::create_simple_product();
		$cloned = ProductHelper::create_simple_product();
		update_post_meta( $owner->get_id(), Api::UUID_META_KEY, $uuid );
		update_post_meta( $cloned->get_id(), Api::UUID_META_KEY, $uuid );

		$repaired = Pos_Uuid::ensure_uuid(
			wc_get_product( $cloned->get_id() ),
			array( 'collides' => array( Pos_Uuid::class, 'uuid_owned_by_other' ) )
		);

		$this->assertTrue( Pos_Uuid::is_uuid( $repaired ) );
		$this->assertNotSame( $uuid, $repaired );
		$this->assertSame( $uuid, get_post_meta( $owner->get_id(), Api::UUID_META_KEY, true ) );
		$this->assertSame( $repaired, get_post_meta( $cloned->get_id(), Api::UUID_META_KEY, true ) );
	}

	/**
	 * Before-save stamping attaches a UUID without recursively saving the object.
	 */
	public function test_stamp_on_save_adds_uuid_to_in_progress_product(): void {
		$product = ProductHelper::create_simple_product();
		$product->delete_meta_data( Api::UUID_META_KEY );

		Pos_Uuid::stamp_on_save( $product );

		$this->assertTrue( Pos_Uuid::is_uuid( $product->get_meta( Api::UUID_META_KEY ) ) );
	}

	/**
	 * A term collision re-key on duplicate uuid rows collapses to ONE row
	 * (update_term_meta alone rewrites all rows without pruning — codex P2).
	 */
	public function test_term_collision_rekey_collapses_duplicate_rows(): void {
		$term = wp_insert_term( 'Dup Uuid Term ' . wp_generate_uuid4(), 'product_cat' );
		$term_id = (int) $term['term_id'];
		$other = wp_insert_term( 'Owner Term ' . wp_generate_uuid4(), 'product_cat' );
		$owned = wp_generate_uuid4();
		add_term_meta( (int) $other['term_id'], Api::UUID_META_KEY, $owned );
		// Two duplicate rows, the first colliding with the other term's uuid.
		add_term_meta( $term_id, Api::UUID_META_KEY, $owned );
		add_term_meta( $term_id, Api::UUID_META_KEY, wp_generate_uuid4() );

		$resolved = Pos_Uuid::ensure_term_uuid( $term_id );

		$rows = get_term_meta( $term_id, Api::UUID_META_KEY, false );
		$this->assertCount( 1, $rows, 'duplicate uuid rows must collapse to one' );
		$this->assertSame( $resolved, $rows[0] );
		$this->assertNotSame( $owned, $resolved );
	}
}
