<?php
/**
 * HPOS coverage for the sync-index order trash/untrash lifecycle.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Sync_Index;
use WP_REST_Request;

/**
 * A restored COT order must be re-emitted to the sync index as PRESENT.
 *
 * `untrashed_post` never fires for COT orders, and the restore's own
 * `$order->save()` does NOT fire `woocommerce_update_order` either — the HPOS
 * data store returns early whenever `trash` sits on either side of the status
 * change. Without a COT-specific hook a client that tombstoned the order on
 * trash never learns it came back.
 *
 * The lifecycle is driven through the real WooCommerce APIs on purpose: the
 * bug is invisible to any test that calls the recorder methods directly.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Sync_Index_HPOS_Untrash extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;
	use Sync_Observer_Unhook_Trait;

	/**
	 * Sync index under observation.
	 *
	 * @var Sync_Index
	 */
	private $sync_index;

	/**
	 * Enable the sync surface and COT storage; wire the sync-index observer.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );

		$this->sync_index = new Sync_Index();
		$this->sync_index->register_hooks();
	}

	/**
	 * Restore posts storage and unhook the observer.
	 */
	public function tearDown(): void {
		$this->remove_observer_callbacks( array( $this->sync_index ) );

		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Trash tombstones the COT order in the index; untrash re-emits it as present.
	 */
	public function test_cot_order_untrash_reemits_the_order_as_present(): void {
		// Arrange: a live COT order, tombstoned by a real trash.
		$order    = wc_create_order();
		$order_id = $order->get_id();

		$this->assertEquals( 'shop_order_placehold', get_post_type( $order_id ), 'This class must run under COT storage.' );
		$this->assertEquals( 0, $this->latest_index_row( $order_id )['deleted'], 'Creating a COT order must index it as present.' );

		$order->delete( false );
		$this->assertEquals( 1, $this->latest_index_row( $order_id )['deleted'], 'Trashing must tombstone the order in the index.' );

		// Bypass the order cache so the reloaded instance carries trash status.
		wp_cache_flush();
		$trashed = wc_get_order( $order_id );
		$this->assertEquals( 'trash', $trashed->get_status(), 'The reloaded order must be in the trash.' );
		$head_before_untrash = $this->sync_index->head_sequence();

		// A later untrash callback may save metadata while the order is still
		// trashed. That save must not consume the observer waiting for the actual
		// restore save.
		$intervening_save = static function ( int $untrashed_order_id ) use ( $order_id ): void {
			if ( $order_id === $untrashed_order_id ) {
				wc_get_order( $order_id )->save();
			}
		};
		add_action( 'woocommerce_untrash_order', $intervening_save, 20, 1 );

		// Act: restore it exactly the way WooCommerce does.
		try {
			$trashed->untrash();
		} finally {
			remove_action( 'woocommerce_untrash_order', $intervening_save, 20 );
		}

		// Assert: the client's pull sees a fresh PRESENT row past its cursor.
		$this->assertGreaterThan( 0, did_action( 'woocommerce_untrash_order' ), 'The HPOS untrash hook must fire.' );

		$appended = $this->index_rows_after( $head_before_untrash, $order_id );
		$this->assertNotEmpty( $appended, 'Untrash must append a sync-index row so tombstoned clients re-pull the order.' );

		$latest = $appended[ count( $appended ) - 1 ];
		$this->assertEquals( 0, $latest['deleted'], 'The restored order must be re-emitted as PRESENT.' );
		$this->assertEquals( 'hook:untrash', $latest['origin'], 'The restore must be recorded with the untrash origin.' );

		$serializer       = new Order_Serializer();
		$payload          = $serializer->serialize_order( $order_id, new WP_REST_Request() );
		$current_revision = $serializer->sync_metadata( $payload, $order_id, 'custom-pull', false, 0 )['revision'];
		$this->assertEquals( $current_revision, $latest['revision'], 'The re-emitted row must carry the restored order revision.' );
	}

	/**
	 * The most recent index row for an order.
	 *
	 * @param int $order_id Order to read.
	 *
	 * @return array<string, mixed> Latest index row.
	 */
	private function latest_index_row( int $order_id ): array {
		$rows = $this->index_rows_after( 0, $order_id );
		$this->assertNotEmpty( $rows, 'Expected at least one sync-index row for order ' . $order_id . '.' );

		return $rows[ count( $rows ) - 1 ];
	}

	/**
	 * Index rows for one order appended past a sequence cursor.
	 *
	 * Read through the client-facing `rows_after_sequence` surface so the pin
	 * exercises the same page a pulling client would receive.
	 *
	 * @param int $sequence Exclusive sequence cursor.
	 * @param int $order_id Order to filter to.
	 *
	 * @return array<int, array<string, mixed>> Matching rows, oldest first.
	 */
	private function index_rows_after( int $sequence, int $order_id ): array {
		return array_values(
			array_filter(
				$this->sync_index->rows_after_sequence( $sequence, 250 ),
				static function ( array $row ) use ( $order_id ): bool {
					return $order_id === $row['order_id'];
				}
			)
		);
	}
}
