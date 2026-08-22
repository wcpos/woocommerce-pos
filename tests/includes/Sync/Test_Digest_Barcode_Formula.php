<?php
/**
 * Tests for barcode-aware product digest formulas.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use ReflectionProperty;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * Product digests follow the configured WCPOS barcode key without upgrade floods.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Index
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 */
class Test_Digest_Barcode_Formula extends Sync_REST_Store_Test_Case {
	/**
	 * Isolate the request memo, settings, fingerprint, and cron state.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Digest_Index::FORMULA_FP_OPTION );
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		$this->reset_digested_meta_keys();
	}

	/**
	 * Remove state not covered by the WordPress test transaction.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Digest_Index::FORMULA_FP_OPTION );
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		$this->reset_digested_meta_keys();
		parent::tearDown();
	}

	/**
	 * Configure the barcode key before the request-level digest key memo is read.
	 *
	 * @param string $meta_key Barcode meta key.
	 */
	private function set_barcode_key( string $meta_key ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => $meta_key ) );
		$this->reset_digested_meta_keys();
	}

	/**
	 * Reset request-memoized state so each PHPUnit test represents a new request.
	 */
	private function reset_digested_meta_keys(): void {
		$property = new ReflectionProperty( Digest_Index::class, 'memoized_digested_meta_keys' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
	}

	/**
	 * Dispatch one aggregate scan covering the product's bucket.
	 *
	 * @param int $product_id Product ID used to select the bucket.
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch_scan( int $product_id ): array {
		$bucket_size = 1000;
		$bucket      = (int) floor( $product_id / $bucket_size );
		$request     = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params(
			array(
				'bucket_size' => $bucket_size,
				'after_id' => $bucket > 0 ? ( $bucket * $bucket_size ) - 1 : 0,
				'limit_buckets' => 1,
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/** Count queued digest rebuild events across all cron timestamps. */
	private function rebuild_event_count(): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += isset( $hooks[ Integrity_Digest::REBUILD_HOOK ] ) ? count( $hooks[ Integrity_Digest::REBUILD_HOOK ] ) : 0;
		}

		return $count;
	}

	/**
	 * A custom barcode meta row participates in both stored and live digests.
	 */
	public function test_custom_barcode_key_is_digested_and_hookless_write_drifts(): void {
		global $wpdb;
		$meta_key = '_merchant_barcode';
		$this->set_barcode_key( $meta_key );
		$product = ProductHelper::create_simple_product();
		add_post_meta( $product->get_id(), $meta_key, 'before' );
		$digest = new Integrity_Digest();
		$digest->upsert_digest( $product->get_id() );
		$range = array(
			'bucket_size' => 1,
			'start' => $product->get_id(),
			'end' => $product->get_id() + 1,
		);

		$this->assertContains( $meta_key, Digest_Index::digested_meta_keys() );
		$this->assertTrue( ( new Digest_Index() )->bucket_aggregates( $range )['buckets'][0]['match'] );

		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => 'after' ),
			array(
				'post_id' => $product->get_id(),
				'meta_key' => $meta_key,
			)
		);
		$buckets = ( new Digest_Index() )->bucket_aggregates( $range )['buckets'];

		$this->assertFalse( $buckets[0]['match'] );
		$this->assertNotSame( $buckets[0]['stored_digest'], $buckets[0]['current_digest'] );
	}

	/**
	 * The default barcode key is already in the legacy baseline.
	 */
	public function test_default_barcode_key_leaves_digest_key_set_byte_identical(): void {
		$this->set_barcode_key( '_global_unique_id' );

		$this->assertSame( Digest_Index::DIGESTED_META_KEYS, Digest_Index::digested_meta_keys() );
		$this->assertSame( Digest_Index::legacy_formula_fingerprint(), Digest_Index::digest_formula_fingerprint() );
	}

	/**
	 * A pre-upgrade default store seeds the legacy formula without rebuilding.
	 */
	public function test_legacy_default_store_seeds_fingerprint_without_scheduling_rebuild(): void {
		$this->set_barcode_key( '_global_unique_id' );
		$product = ProductHelper::create_simple_product();
		( new Integrity_Digest() )->upsert_digest( $product->get_id() );

		$data = $this->dispatch_scan( $product->get_id() );

		// The FLOOD is the thing under test, so it is asserted FIRST: with the seed
		// assertion leading, a regression that reintroduces the flood fails on a
		// missing option instead — the mechanism, not the merchant-visible symptom.
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertArrayNotHasKey( 'rebuilding', $data['meta'] );
		$this->assertSame( Digest_Index::legacy_formula_fingerprint(), get_option( Digest_Index::FORMULA_FP_OPTION ) );
	}

	/**
	 * A pre-upgrade custom-key store schedules one guarded formula rebuild.
	 */
	public function test_legacy_custom_key_store_schedules_exactly_one_rebuild(): void {
		$this->set_barcode_key( '_merchant_barcode' );
		$product = ProductHelper::create_simple_product();
		( new Integrity_Digest() )->upsert_digest( $product->get_id() );

		$first = $this->dispatch_scan( $product->get_id() );
		$event = wp_next_scheduled( Integrity_Digest::REBUILD_HOOK );
		$this->assertNotFalse( $event );
		$this->assertSame( 1, $this->rebuild_event_count() );
		$second = $this->dispatch_scan( $product->get_id() );

		$this->assertTrue( $first['meta']['rebuilding'] );
		$this->assertTrue( $second['meta']['rebuilding'] );
		$this->assertSame( $event, wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertSame( 1, $this->rebuild_event_count() );
	}

	/**
	 * A successful product rebuild records the current formula and removes the trigger.
	 */
	public function test_rebuild_writes_current_fingerprint_and_clears_formula_condition(): void {
		$this->set_barcode_key( '_merchant_barcode' );
		$product = ProductHelper::create_simple_product();

		( new Integrity_Digest() )->rebuild( true );

		$this->assertSame( Digest_Index::digest_formula_fingerprint(), get_option( Digest_Index::FORMULA_FP_OPTION ) );
		$data = $this->dispatch_scan( $product->get_id() );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertArrayNotHasKey( 'rebuilding', $data['meta'] );
	}

	/**
	 * The barcode key is merchant-settable FREE TEXT (the setting's REST validator accepts any
	 * string), so it must not be able to break the digest SQL it is interpolated into. Without
	 * escaping an apostrophe terminates the IN list, every digest query fails, and the
	 * integrity backstop is silently dead on that store.
	 */
	public function test_barcode_key_containing_a_quote_does_not_break_the_digest_sql(): void {
		global $wpdb;
		$meta_key = "it's_a_barcode";
		$this->set_barcode_key( $meta_key );
		$product = ProductHelper::create_simple_product();
		add_post_meta( $product->get_id(), $meta_key, 'before' );
		( new Integrity_Digest() )->upsert_digest( $product->get_id() );
		$range = array(
			'bucket_size' => 1,
			'start' => $product->get_id(),
			'end' => $product->get_id() + 1,
		);

		$buckets = ( new Digest_Index() )->bucket_aggregates( $range )['buckets'];

		$this->assertSame( '', $wpdb->last_error );
		$this->assertTrue( $buckets[0]['match'] );

		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => 'after' ),
			array(
				'post_id' => $product->get_id(),
				'meta_key' => $meta_key,
			)
		);

		$this->assertFalse( ( new Digest_Index() )->bucket_aggregates( $range )['buckets'][0]['match'] );
	}

	/**
	 * The memo must be per-blog, not process-wide. `barcode_field` is a per-site option and
	 * the settings layer re-reads it uncached, so the SETTING already follows switch_to_blog();
	 * a flat memo would pin the first site's key for the whole process.
	 */
	public function test_digest_key_memo_is_keyed_by_blog_id(): void {
		$this->set_barcode_key( '_merchant_barcode' );
		Digest_Index::digested_meta_keys();

		$property = new ReflectionProperty( Digest_Index::class, 'memoized_digested_meta_keys' );
		$property->setAccessible( true );

		$this->assertSame( array( get_current_blog_id() ), array_keys( (array) $property->getValue() ) );
	}

	/**
	 * The behavioural half of the above: on a network-activated multisite, a batch that walks
	 * sites must digest each site's products under THAT site's barcode key. Digesting site B
	 * under site A's key would leave B's real barcode field uncovered — silently reintroducing
	 * the staleness this class exists to catch.
	 */
	public function test_digest_key_set_follows_switch_to_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is not enabled (WP_TESTS_MULTISITE unset), so switch_to_blog() cannot be exercised.' );
		}

		$this->set_barcode_key( '_site_one_barcode' );
		$this->assertContains( '_site_one_barcode', Digest_Index::digested_meta_keys() );

		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_site_two_barcode' ) );
		// Deliberately NOT resetting the memo — leaking site one's key is the bug under test.
		$keys = Digest_Index::digested_meta_keys();
		restore_current_blog();

		$this->assertContains( '_site_two_barcode', $keys );
		$this->assertNotContains( '_site_one_barcode', $keys );
	}
}
