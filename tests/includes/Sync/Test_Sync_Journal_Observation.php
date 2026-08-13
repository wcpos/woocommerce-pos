<?php
/**
 * Integration tests for the unified sync journal writer.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Request;

/**
 * One observer owns every collection's change rows.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 */
class Test_Sync_Journal_Observation extends Sync_Store_Test_Case {
	use HPOSToggleTrait;
	use Sync_Observer_Unhook_Trait;

	/** @var Sync_Journal */
	private $journal;

	public function setUp(): void {
		parent::setUp();
		$this->journal = new Sync_Journal();
		$this->journal->install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->journal->register_hooks();
	}

	public function tearDown(): void {
		$this->remove_observer_callbacks( array( $this->journal ) );
		parent::tearDown();
	}

	public function test_product_create_update_trash_and_untrash_rows_follow_revision_rules(): void {
		$cursor  = $this->journal->head_sequence();
		$product = ProductHelper::create_simple_product();
		$created = $this->latest_row( 'product', $product->get_id(), $cursor );
		$this->assert_present_hook_row( $created, 'product', $product->get_id(), $this->object_revision( $product ) );

		$cursor = $this->journal->head_sequence();
		$product->set_name( 'Journal update' );
		$product->save();
		$updated = $this->latest_row( 'product', $product->get_id(), $cursor );
		$this->assert_present_hook_row( $updated, 'product', $product->get_id(), $this->object_revision( $product ) );

		$cursor = $this->journal->head_sequence();
		$revision_before_trash = $this->object_revision( $product );
		wp_trash_post( $product->get_id() );
		$trashed = $this->latest_row( 'product', $product->get_id(), $cursor );
		$this->assertSame( 1, $trashed['deleted'] );
		$this->assertSame( 'hook', $trashed['origin'] );
		$this->assertSame( $revision_before_trash, $trashed['revision'] );

		$cursor = $this->journal->head_sequence();
		wp_untrash_post( $product->get_id() );
		$restored = $this->latest_row( 'product', $product->get_id(), $cursor );
		$this->assert_present_hook_row( $restored, 'product', $product->get_id(), $this->object_revision( wc_get_product( $product->get_id() ) ) );
	}

	public function test_variation_lifecycle_also_touches_parent_product(): void {
		$product = ProductHelper::create_simple_product();
		$cursor  = $this->journal->head_sequence();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( '5' );
		$variation->save();

		$this->assert_present_hook_row( $this->latest_row( 'variation', $variation->get_id(), $cursor ), 'variation', $variation->get_id(), $this->object_revision( wc_get_product( $variation->get_id() ) ) );
		$this->assert_present_hook_row( $this->latest_row( 'product', $product->get_id(), $cursor ), 'product', $product->get_id(), $this->object_revision( wc_get_product( $product->get_id() ) ) );

		$cursor = $this->journal->head_sequence();
		$variation->set_regular_price( '7' );
		$variation->save();
		$this->assertSame( 0, $this->latest_row( 'variation', $variation->get_id(), $cursor )['deleted'] );
		$this->assertSame( $product->get_id(), $this->latest_row( 'product', $product->get_id(), $cursor )['object_id'] );

		$cursor = $this->journal->head_sequence();
		wp_trash_post( $variation->get_id() );
		$this->assertSame( 1, $this->latest_row( 'variation', $variation->get_id(), $cursor )['deleted'] );
		$this->assertSame( 0, $this->latest_row( 'product', $product->get_id(), $cursor )['deleted'] );

		$cursor = $this->journal->head_sequence();
		wp_untrash_post( $variation->get_id() );
		$this->assertSame( 0, $this->latest_row( 'variation', $variation->get_id(), $cursor )['deleted'] );
		$this->assertSame( 0, $this->latest_row( 'product', $product->get_id(), $cursor )['deleted'] );
	}

	public function test_coupon_create_update_trash_and_untrash_use_empty_revisions(): void {
		$cursor = $this->journal->head_sequence();
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'journal-' . wp_generate_password( 8, false ) );
		$coupon->save();
		$this->assert_present_hook_row( $this->latest_row( 'coupon', $coupon->get_id(), $cursor ), 'coupon', $coupon->get_id(), '' );

		$cursor = $this->journal->head_sequence();
		$coupon->set_amount( '4.50' );
		$coupon->save();
		$this->assert_present_hook_row( $this->latest_row( 'coupon', $coupon->get_id(), $cursor ), 'coupon', $coupon->get_id(), '' );

		$cursor = $this->journal->head_sequence();
		wp_trash_post( $coupon->get_id() );
		$this->assertSame( array( 1, '', 'hook' ), $this->row_semantics( $this->latest_row( 'coupon', $coupon->get_id(), $cursor ) ) );

		$cursor = $this->journal->head_sequence();
		wp_untrash_post( $coupon->get_id() );
		$this->assertSame( array( 0, '', 'hook' ), $this->row_semantics( $this->latest_row( 'coupon', $coupon->get_id(), $cursor ) ) );
	}

	public function test_tax_rate_create_update_and_delete_use_empty_revisions(): void {
		$cursor = $this->journal->head_sequence();
		$tax_id = TaxHelper::create_tax_rate(
			array(
				'rate' => '10.0000',
				'name' => 'Journal tax',
			)
		);
		$this->assertSame( array( 0, '', 'hook' ), $this->row_semantics( $this->latest_row( 'tax_rate', $tax_id, $cursor ) ) );

		$cursor = $this->journal->head_sequence();
		\WC_Tax::_update_tax_rate(
			$tax_id,
			array(
				'tax_rate_country' => '',
				'tax_rate_state' => '',
				'tax_rate' => '12.0000',
				'tax_rate_name' => 'Journal tax updated',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 0,
				'tax_rate_order' => 1,
				'tax_rate_class' => '',
			)
		);
		$this->assertSame( array( 0, '', 'hook' ), $this->row_semantics( $this->latest_row( 'tax_rate', $tax_id, $cursor ) ) );

		$cursor = $this->journal->head_sequence();
		TaxHelper::delete_tax_rate( $tax_id );
		$this->assertSame( array( 1, '', 'hook' ), $this->row_semantics( $this->latest_row( 'tax_rate', $tax_id, $cursor ) ) );
	}

	/**
	 * @dataProvider tracked_taxonomy_provider
	 */
	public function test_term_create_update_meta_and_delete_rows_follow_taxonomy_map( string $taxonomy, string $object_type ): void {
		$cursor = $this->journal->head_sequence();
		$term = wp_insert_term( 'Journal ' . $object_type . ' ' . wp_generate_password( 6, false ), $taxonomy );
		$term_id = (int) $term['term_id'];
		$this->assertSame( array( 0, '', 'hook' ), $this->row_semantics( $this->latest_row( $object_type, $term_id, $cursor ) ) );

		$cursor = $this->journal->head_sequence();
		wp_update_term( $term_id, $taxonomy, array( 'description' => 'updated' ) );
		$this->assertSame( array( 0, '', 'hook' ), $this->row_semantics( $this->latest_row( $object_type, $term_id, $cursor ) ) );

		$cursor = $this->journal->head_sequence();
		update_term_meta( $term_id, 'thumbnail_id', 10 );
		$this->assertSame( array( 0, '', 'hook' ), $this->row_semantics( $this->latest_row( $object_type, $term_id, $cursor ) ) );

		$cursor = $this->journal->head_sequence();
		delete_term_meta( $term_id, 'thumbnail_id' );
		$this->assertSame( array( 0, '', 'hook' ), $this->row_semantics( $this->latest_row( $object_type, $term_id, $cursor ) ) );

		$cursor = $this->journal->head_sequence();
		wp_delete_term( $term_id, $taxonomy );
		$this->assertSame( array( 1, '', 'hook' ), $this->row_semantics( $this->latest_row( $object_type, $term_id, $cursor ) ) );
	}

	public static function tracked_taxonomy_provider(): array {
		return array(
			'category' => array( 'product_cat', 'category' ),
			'brand' => array( 'product_brand', 'brand' ),
			'tag' => array( 'product_tag', 'tag' ),
		);
	}

	public function test_unknown_taxonomy_writes_no_row(): void {
		$cursor = $this->journal->head_sequence();
		wp_insert_term( 'Ignored journal term', 'post_tag' );
		$this->assertSame( array(), $this->journal->page( array(), $cursor, 20 )['rows'] );
	}

	public function test_customer_create_persisted_dedup_profile_role_and_delete_rows(): void {
		$cursor = $this->journal->head_sequence();
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$created = $this->latest_row( 'customer', $user_id, $cursor );
		$this->assert_present_hook_row( $created, 'customer', $user_id, $this->customer_revision( $user_id ) );

		$persisted_cursor = $this->journal->head_sequence();
		do_action( 'woocommerce_created_customer', $user_id, array(), false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook under test.
		$first_persisted = $this->journal->head_sequence();
		do_action( 'woocommerce_new_customer', $user_id, new \WC_Customer( $user_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook under test.
		$persisted_rows = $this->rows_for( 'customer', $user_id, $persisted_cursor );
		$this->assertCount( 1, $persisted_rows, 'The two post-persist hooks must leave one last-write-wins row.' );
		$this->assertGreaterThan( $first_persisted, $persisted_rows[0]['sequence'] );

		$operations = array(
			'profile_update' => static function () use ( $user_id ): void {
				wp_update_user(
					array(
						'ID' => $user_id,
						'display_name' => 'Journal customer',
					)
				);
			},
			'add_user_role' => static function () use ( $user_id ): void {
				get_user_by( 'id', $user_id )->add_role( 'subscriber' );
			},
			'remove_user_role' => static function () use ( $user_id ): void {
				get_user_by( 'id', $user_id )->remove_role( 'subscriber' );
			},
			'set_user_role fanout dedup' => static function () use ( $user_id ): void {
				get_user_by( 'id', $user_id )->set_role( 'editor' );
			},
		);
		foreach ( $operations as $label => $operation ) {
			$rows = $this->customer_rows_from_fresh_request( $user_id, $operation );
			$this->assertCount( 1, $rows, $label );
			$this->assertSame( array( 0, $this->customer_revision( $user_id ), 'hook' ), $this->row_semantics( $rows[0] ), $label );
		}

		$cursor = $this->journal->head_sequence();
		$revision_before_delete = $this->customer_revision( $user_id );
		wp_delete_user( $user_id );
		$deleted = $this->latest_row( 'customer', $user_id, $cursor );
		$this->assertSame( 1, $deleted['deleted'] );
		$this->assertSame( 'hook', $deleted['origin'] );
		$this->assertSame( $revision_before_delete, $deleted['revision'] );
	}

	public function test_order_create_update_trash_untrash_and_delete_rows_keep_order_semantics(): void {
		$cursor   = $this->journal->head_sequence();
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->assert_order_row( $this->latest_row( 'order', $order_id, $cursor ), 'hook:create', false );

		$cursor = $this->journal->head_sequence();
		$order->set_customer_note( 'journal update' );
		$order->save();
		$this->assert_order_row( $this->latest_row( 'order', $order_id, $cursor ), 'hook:update', false );

		$cursor = $this->journal->head_sequence();
		$order->delete( false ); // trashing zeroes the object's id — use the captured one
		$this->assert_order_row( $this->latest_row( 'order', $order_id, $cursor ), 'hook:delete', true );

		$cursor = $this->journal->head_sequence();
		wp_untrash_post( $order_id );
		$this->assert_order_row( $this->latest_row( 'order', $order_id, $cursor ), 'hook:untrash', false );

		$cursor = $this->journal->head_sequence();
		wc_get_order( $order_id )->delete( true );
		$this->assert_order_row( $this->latest_row( 'order', $order_id, $cursor ), 'hook:delete', true );
	}

	public function test_hpos_order_trash_and_untrash_append_delete_then_present_row(): void {
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
		try {
			$order = wc_create_order();
			$order_id = $order->get_id();
			$this->assertSame( 'shop_order_placehold', get_post_type( $order_id ) );
			$cursor = $this->journal->head_sequence();
			$order->delete( false );
			$this->assert_order_row( $this->latest_row( 'order', $order_id, $cursor ), 'hook:delete', true );

			wp_cache_flush();
			$cursor = $this->journal->head_sequence();
			$intervening_save = static function ( int $order_id ): void {
				wc_get_order( $order_id )->save();
			};
			add_action( 'woocommerce_untrash_order', $intervening_save, 20, 1 );
			try {
				wc_get_order( $order_id )->untrash();
			} finally {
				remove_action( 'woocommerce_untrash_order', $intervening_save, 20 );
			}
			$this->assert_order_row( $this->latest_row( 'order', $order_id, $cursor ), 'hook:untrash', false );
		} finally {
			$this->toggle_cot_feature_and_usage( false );
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
	}

	public function test_order_backfill_appends_order_rows_and_advances_the_id_cursor(): void {
		global $wpdb;
		wc_create_order();
		wc_create_order();
		$order_ids = array_map(
			'intval',
			wc_get_orders(
				array(
					'type' => 'shop_order',
					'limit' => -1,
					'orderby' => 'ID',
					'order' => 'ASC',
					'return' => 'ids',
				)
			)
		);
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->journal->reset_backfill_state();

		$first = $this->journal->run_backfill_chunk( 1 );
		$second = $this->journal->run_backfill_chunk( 1 );
		$rows = $this->journal->rows_after_sequence( 0, 10 );

		$this->assertSame( $order_ids[0], $first['lastOrderId'] );
		$this->assertSame( $order_ids[1], $second['lastOrderId'] );
		$this->assertSame( array( $order_ids[0], $order_ids[1] ), array_column( $rows, 'order_id' ) );
		$this->assertSame( array( 'backfill', 'backfill' ), array_column( $rows, 'origin' ) );
		$this->assertStringStartsWith( 'sha256:', $rows[0]['revision'] );
	}

	public function test_order_backfill_does_not_advance_past_a_failed_journal_write(): void {
		global $wpdb;
		wc_create_order();
		wc_create_order();
		$order_ids = array_map(
			'intval',
			wc_get_orders(
				array(
					'type' => 'shop_order',
					'limit' => -1,
					'orderby' => 'ID',
					'order' => 'ASC',
					'return' => 'ids',
				)
			)
		);
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->journal->reset_backfill_state();
		$writes = 0;
		$fail_second = static function ( string $query ) use ( $wpdb, &$writes ): string {
			$table = $wpdb->prefix . Sync_Journal::TABLE;
			if ( false !== strpos( $query, $table ) && false !== strpos( $query, "'backfill'" ) && 2 === ++$writes ) {
				return str_replace( $table, $table . '_missing', $query );
			}
			return $query;
		};
		$previous_suppress_errors = $wpdb->suppress_errors();
		add_filter( 'query', $fail_second );
		try {
			$failed = $this->journal->run_backfill_chunk( 2 );
		} finally {
			remove_filter( 'query', $fail_second );
			$wpdb->suppress_errors( $previous_suppress_errors );
		}

		$this->assertSame( $order_ids[0], $failed['lastOrderId'] );
		$this->assertSame( 1, $failed['processedThisRun'] );
		$retried = $this->journal->run_backfill_chunk( 2 );
		$this->assertSame( $order_ids[1], $retried['lastOrderId'] );
	}

	private function assert_present_hook_row( array $row, string $object_type, int $object_id, string $revision ): void {
		$this->assertSame( $object_type, $row['object_type'] );
		$this->assertSame( $object_id, $row['object_id'] );
		$this->assertSame( array( 0, $revision, 'hook' ), $this->row_semantics( $row ) );
	}

	private function assert_order_row( array $row, string $origin, bool $deleted ): void {
		$this->assertSame( 'order', $row['object_type'] );
		$this->assertSame( $deleted ? 1 : 0, $row['deleted'] );
		$this->assertSame( $origin, $row['origin'] );
		if ( $deleted ) {
			$this->assertSame( 'deleted', $row['revision'] );
		} else {
			$this->assertSame( $this->order_revision( $row['object_id'] ), $row['revision'] );
		}
	}

	private function row_semantics( array $row ): array {
		return array( $row['deleted'], $row['revision'], $row['origin'] );
	}

	private function latest_row( string $object_type, int $object_id, int $since ): array {
		$rows = $this->rows_for( $object_type, $object_id, $since );
		$this->assertNotEmpty( $rows, "Expected {$object_type} {$object_id} past cursor {$since}." );
		return $rows[ count( $rows ) - 1 ];
	}

	private function rows_for( string $object_type, int $object_id, int $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->journal->table_name() . ' WHERE object_type = %s AND object_id = %d AND sequence > %d ORDER BY sequence ASC LIMIT 250', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
				$object_type,
				$object_id,
				$since
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['sequence']  = (int) $row['sequence'];
				$row['object_id'] = (int) $row['object_id'];
				$row['deleted']   = (int) $row['deleted'];
				return $row;
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	private function object_revision( $object ): string {
		$date = is_object( $object ) && method_exists( $object, 'get_date_modified' ) ? $object->get_date_modified() : null;
		return $date ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : '';
	}

	private function customer_revision( int $customer_id ): string {
		return $this->object_revision( new \WC_Customer( $customer_id ) );
	}

	private function customer_rows_from_fresh_request( int $customer_id, callable $operation ): array {
		$this->remove_observer_callbacks( array( $this->journal ) );
		$observer = new Sync_Journal();
		$observer->register_hooks();
		$cursor = $this->journal->head_sequence();
		try {
			$operation();
		} finally {
			$this->remove_observer_callbacks( array( $observer ) );
			$this->journal->register_hooks();
		}
		return $this->rows_for( 'customer', $customer_id, $cursor );
	}

	private function order_revision( int $order_id ): string {
		$serializer = new Order_Serializer();
		$payload = $serializer->serialize_order( $order_id, new WP_REST_Request() );
		return (string) $serializer->sync_metadata( $payload, $order_id, 'custom-pull', false, 0 )['revision'];
	}
}
