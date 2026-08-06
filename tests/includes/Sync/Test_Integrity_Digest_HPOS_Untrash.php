<?php
/**
 * HPOS coverage for the order digest trash/untrash lifecycle.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * A restored COT order must get its integrity digest back.
 *
 * `untrashed_post` never fires for COT orders; recreation rides
 * `woocommerce_untrash_order` instead. Without it, a restored order stays
 * invisible to client integrity scans forever.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Integrity_Digest_HPOS_Untrash extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;
	use Sync_Observer_Unhook_Trait;

	/**
	 * Digest service under observation.
	 *
	 * @var Integrity_Digest
	 */
	private $integrity_digest;

	/**
	 * Enable the sync surface and COT storage; wire the digest observer.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );

		$this->integrity_digest = new Integrity_Digest();
		$this->integrity_digest->register_hooks();
	}

	/**
	 * Restore posts storage and unhook the observer.
	 */
	public function tearDown(): void {
		$this->remove_observer_callbacks( array( $this->integrity_digest ) );

		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Trash removes the COT order's digest; untrash recreates it.
	 */
	public function test_cot_order_untrash_recreates_the_order_digest(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();

		$this->assertSame( 'shop_order_placehold', get_post_type( $order_id ), 'This class must run under COT storage.' );
		$this->assertArrayHasKey( $order_id, $this->integrity_digest->read_order_digests( array( $order_id ) ), 'Creating a COT order must record its digest.' );

		$order->delete( false );
		$this->assertArrayNotHasKey( $order_id, $this->integrity_digest->read_order_digests( array( $order_id ) ), 'Trashing must remove the digest.' );

		// Bypass the order cache so the reloaded instance carries trash status.
		wp_cache_flush();
		$trashed = wc_get_order( $order_id );
		$this->assertSame( 'trash', $trashed->get_status(), 'The reloaded order must be in the trash.' );
		$trashed->untrash();
		$this->assertGreaterThan( 0, did_action( 'woocommerce_untrash_order' ), 'The HPOS untrash hook must fire.' );
		$this->assertArrayHasKey( $order_id, $this->integrity_digest->read_order_digests( array( $order_id ) ), 'Untrash must recreate the digest (woocommerce_untrash_order).' );
	}
}
