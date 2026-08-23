<?php
/**
 * Integration tests for stored-digest observers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * Integrity digest observation tests retained after journal observer coverage
 * moved to Test_Sync_Journal_Observation.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 */
class Test_Sync_Observation extends Sync_Store_Test_Case {
	use Sync_Observer_Unhook_Trait;

	/** @var Integrity_Digest */
	private $integrity_digest;

	/** @var Digest_Index */
	private $digest_index;

	public function setUp(): void {
		parent::setUp();
		$this->install_sync_tables_directly();
		$this->integrity_digest = new Integrity_Digest();
		$this->integrity_digest->register_hooks();
		$this->digest_index = new Digest_Index();
	}

	public function tearDown(): void {
		$this->remove_observer_callbacks( array( $this->integrity_digest ) );
		parent::tearDown();
	}

	public function test_product_and_customer_saves_upsert_digest_rows(): void {
		global $wpdb;

		$product  = ProductHelper::create_simple_product();
		$customer = $this->factory->user->create( array( 'role' => 'customer' ) );

		$rows = $wpdb->get_results( 'SELECT object_type, object_id, digest FROM ' . $this->integrity_digest->table_name() . ' ORDER BY object_type, object_id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->assertContains( 'product', array_column( $rows, 'object_type' ) );
		$this->assertContains( (string) $product->get_id(), array_column( $rows, 'object_id' ) );
		$this->assertContains( 'customer', array_column( $rows, 'object_type' ) );
		$this->assertContains( (string) $customer, array_column( $rows, 'object_id' ) );
	}

	public function test_digest_failure_does_not_break_the_host_write(): void {
		global $wpdb;

		$digest_table = $this->integrity_digest->table_name();
		$break_digest = static function ( $query ) use ( $digest_table ) {
			if ( \is_string( $query ) && false !== strpos( $query, $digest_table ) ) {
				return str_replace( $digest_table, $digest_table . '_gone', $query );
			}
			return $query;
		};

		$previous_suppress_errors = $wpdb->suppress_errors();
		add_filter( 'query', $break_digest );
		try {
			$product = ProductHelper::create_simple_product();
		} finally {
			remove_filter( 'query', $break_digest );
			$wpdb->suppress_errors( $previous_suppress_errors );
		}
		$this->assertSame( $previous_suppress_errors, $wpdb->suppress_errors( $previous_suppress_errors ), 'The fixture must restore wpdb error reporting.' );
		$this->assertFalse( has_filter( 'query', $break_digest ), 'The fixture must remove its broken-query filter.' );

		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertGreaterThan( 0, $product->get_id(), 'The host write must survive a broken digest store' );
		$digests = $wpdb->get_col( 'SELECT object_id FROM ' . $digest_table . " WHERE object_type = 'product'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->assertNotContains( (string) $product->get_id(), $digests, 'The digest write itself failed open' );
	}

	public function test_digest_delete_failures_are_logged(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$messages = array();
		$break_digest_delete = static function ( $query ) use ( $wpdb ) {
			$table = $wpdb->prefix . 'wcpos_sync_stored_digest';
			if ( is_string( $query ) && false !== strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, $table ) ) {
				return str_replace( $table, $table . '_gone', $query );
			}
			return $query;
		};
		$capture_log = static function ( $should_log, $message ) use ( &$messages ) {
			$messages[] = (string) $message;
			return false;
		};

		$previous_suppress_errors = $wpdb->suppress_errors();
		add_filter( 'query', $break_digest_delete );
		add_filter( 'woocommerce_pos_logging', $capture_log, 10, 2 );
		try {
			wp_delete_user( $user_id );
			$this->integrity_digest->record_order_deleted( 1 );
		} finally {
			remove_filter( 'query', $break_digest_delete );
			remove_filter( 'woocommerce_pos_logging', $capture_log );
			$wpdb->suppress_errors( $previous_suppress_errors );
		}

		$this->assertSame( $previous_suppress_errors, $wpdb->suppress_errors( $previous_suppress_errors ), 'The fixture must restore wpdb error reporting.' );
		$this->assertFalse( has_filter( 'query', $break_digest_delete ), 'The fixture must remove its broken-query filter.' );
		$this->assertFalse( has_filter( 'woocommerce_pos_logging', $capture_log ), 'The fixture must remove its log-capture filter.' );
		$this->assertStringContainsString( 'delete stored customer digest failed', implode( "\n", $messages ) );
		$this->assertStringContainsString( 'delete stored order digest failed', implode( "\n", $messages ) );
	}

	public function test_add_and_remove_customer_role_keep_the_customer_digest(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$customer_digests = function () use ( $wpdb ) {
			return $wpdb->get_col( 'SELECT object_id FROM ' . $this->integrity_digest->table_name() . " WHERE object_type = 'customer'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		};

		$user->add_role( 'customer' );
		$this->assertContains( (string) $user_id, $customer_digests() );
		$user->remove_role( 'customer' );
		$this->assertContains( (string) $user_id, $customer_digests() );
	}

	public function test_admin_profile_update_refreshes_customer_digest(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_update_user(
			array(
				'ID' => $user_id,
				'display_name' => 'Updated administrator',
			)
		);

		$this->assertArrayHasKey( $user_id, $this->digest_index->read_digests( 'customers', array( $user_id ) ) );
	}

	public function test_customer_digest_includes_site_capabilities_meta(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->integrity_digest->upsert_customer_digest( $user_id );
		$stored_digest = $this->digest_index->read_digests( 'customers', array( $user_id ) )[ $user_id ];
		update_user_meta( $user_id, $wpdb->prefix . 'capabilities', array( 'editor' => true ) );
		$current_digest = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT crc FROM (' . $this->integrity_digest->customer_digest_select_sql( 'u.ID = %d' ) . ') current_digest', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal query with prepared id placeholder.
				$user_id
			)
		);

		$this->assertSame( $stored_digest, $this->digest_index->read_digests( 'customers', array( $user_id ) )[ $user_id ] );
		$this->assertNotSame( $stored_digest, $current_digest );
	}

	public function test_customer_role_departure_keeps_digest(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		get_user_by( 'id', $user_id )->remove_role( 'customer' );

		$this->assertArrayHasKey( $user_id, $this->digest_index->read_digests( 'customers', array( $user_id ) ) );
	}

	public function test_delete_user_removes_digest(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->assertArrayHasKey( $user_id, $this->digest_index->read_digests( 'customers', array( $user_id ) ) );
		wp_delete_user( $user_id );
		$this->assertArrayNotHasKey( $user_id, $this->digest_index->read_digests( 'customers', array( $user_id ) ) );
	}

	public function test_new_customer_lifecycle_hook_refreshes_the_customer_digest(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, 'first_name', 'Final persisted name' );
		do_action( 'woocommerce_new_customer', $user_id, new \WC_Customer( $user_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce lifecycle hook under test.
		$current_digest = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT crc FROM (' . $this->integrity_digest->customer_digest_select_sql( 'u.ID = %d' ) . ') current_digest', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal query with prepared id placeholder.
				$user_id
			)
		);
		$stored_digests = $this->digest_index->read_digests( 'customers', array( $user_id ) );

		$this->assertArrayHasKey( $user_id, $stored_digests );
		$this->assertSame( $current_digest, $stored_digests[ $user_id ] );
	}

	public function test_cpt_order_untrash_recreates_the_order_digest(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->assertSame( 'shop_order', get_post_type( $order_id ) );
		$this->assertArrayHasKey( $order_id, $this->digest_index->read_digests( 'orders', array( $order_id ) ) );

		$order->delete( false );
		$this->assertArrayNotHasKey( $order_id, $this->digest_index->read_digests( 'orders', array( $order_id ) ) );
		wp_untrash_post( $order_id );
		$this->assertArrayHasKey( $order_id, $this->digest_index->read_digests( 'orders', array( $order_id ) ) );
	}
}
