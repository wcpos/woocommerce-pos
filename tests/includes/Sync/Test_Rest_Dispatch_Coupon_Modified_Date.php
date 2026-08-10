<?php
/**
 * Route-dispatch pin for the coupon `date_modified_gmt` guarantee on the v2 push lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;
use WP_REST_Response;

/**
 * WC's coupon data store only calls wp_update_post() when a POST field changes.
 * An amount-only edit is meta-backed, so `post_modified_gmt` is left stale.
 *
 * v1 papered over this by installing `woocommerce_update_coupon =>
 * wcpos_touch_coupon_modified_date` for the duration of a v1 REST dispatch
 * (API/V1/Coupons_Controller.php). The app now writes through
 * `POST /wcpos/v2/push/coupons`, which forwards to stock wc/v3 and never
 * installed that listener — so the guarantee was silently dropped.
 *
 * It still matters, because the client's catalogue replication is INCREMENTAL
 * and date-based: `CollectionReplicationState` polls
 * `?modified_after=<last>&fields=id,date_modified_gmt`
 * (packages/query/src/{collection-replication-state,data-fetcher}.ts), and
 * `Catalog_Proxy_Controller` forwards that param untouched to wc/v3, where
 * WooCommerce filters on `post_modified_gmt`. A stale timestamp therefore means
 * a second till NEVER learns the coupon's amount changed.
 *
 * The two pre-existing guards were both structurally blind: pro's v1 test
 * INSTALLS the production hook itself, and the free v2 contract test STUBS the
 * wc/v3 response, so neither could observe the real timestamp. This test
 * installs NO hooks and asserts the cashier-visible outcome — the incremental
 * poll that the second till actually issues.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Rest_Dispatch_Coupon_Modified_Date extends Sync_REST_Store_Test_Case {
	/**
	 * Timestamp the fixture coupon is aged to before the push.
	 *
	 * @var string
	 */
	private const AGED_GMT = '2020-01-01 00:00:00';

	/**
	 * Cursor a second till would hold: after the aged write, before the push.
	 *
	 * @var string
	 */
	private const POLL_AFTER = '2021-01-01T00:00:00';

	/**
	 * Enable the v2 routes and mark the request as POS.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	/**
	 * Restore request state.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Create a coupon carrying a POS uuid and an aged modified date.
	 *
	 * @param string $code Coupon code.
	 * @param string $uuid Record uuid the push will address it by.
	 *
	 * @return int Coupon post id.
	 */
	private function aged_coupon( string $code, string $uuid ): int {
		global $wpdb;

		$id = CouponHelper::create_coupon( $code )->get_id();
		update_post_meta( $id, Pos_Uuid::META_KEY, $uuid );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => self::AGED_GMT,
				'post_modified_gmt' => self::AGED_GMT,
			),
			array( 'ID' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $id );

		return $id;
	}

	/**
	 * Push one coupon mutation envelope through the registered v2 route.
	 *
	 * @param int    $id       Coupon post id.
	 * @param string $uuid     Record uuid.
	 * @param array  $payload  Mutation payload.
	 * @param int    $sequence Unique envelope sequence.
	 *
	 * @return WP_REST_Response
	 */
	private function push_coupon( int $id, string $uuid, array $payload, int $sequence ): WP_REST_Response {
		$current  = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/coupons/' . $id ) )->get_data();
		$revision = Revision::compute( $current );
		$envelope = array(
			'mutationId'   => sprintf( '61000000-0000-4000-8000-%012d', $sequence ),
			'operation'    => 'update',
			'collection'   => 'coupons',
			'recordId'     => $uuid,
			'baseRevision' => $revision,
			'payload'      => $payload,
		);

		$request = $this->wp_rest_post_request( '/wcpos/v2/push/coupons' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$request->set_header( 'If-Match', '"' . $revision . '"' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Ids a second till's incremental catalogue poll would return.
	 *
	 * @return int[]
	 */
	private function poll_modified_after(): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/coupons' );
		$request->set_query_params(
			array(
				'modified_after' => self::POLL_AFTER,
				'fields'         => array( 'id', 'date_modified_gmt' ),
				'posts_per_page' => -1,
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );
	}

	/**
	 * An amount-only push must leave the coupon discoverable by the incremental poll.
	 */
	public function test_amount_only_push_advances_date_modified_for_the_incremental_poll(): void {
		// Arrange: an untouched aged coupon is the negative control. Without it,
		// assertContains() below would still pass if `modified_after` were ignored
		// and the proxy simply returned every coupon.
		$uuid = '62000000-0000-4000-8000-000000000001';
		$id   = $this->aged_coupon( 'v2-amount-only', $uuid );
		$untouched_id = $this->aged_coupon( 'v2-untouched-control', '62000000-0000-4000-8000-000000000003' );

		// Act.
		$response = $this->push_coupon( $id, $uuid, array( 'amount' => '12.00' ), 1 );

		// Assert: the write landed...
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '12.00', $response->get_data()['document']['amount'] );

		// ...and the second till's incremental poll can still see it.
		clean_post_cache( $id );
		$this->assertNotSame(
			self::AGED_GMT,
			get_post_field( 'post_modified_gmt', $id ),
			'An amount-only v2 push left post_modified_gmt stale; the incremental catalogue poll will never re-fetch this coupon.'
		);
		$polled = $this->poll_modified_after();
		$this->assertContains(
			$id,
			$polled,
			'A coupon whose amount changed via the v2 push lane was invisible to a ?modified_after poll.'
		);
		$this->assertNotContains(
			$untouched_id,
			$polled,
			'The poll returned an unmodified coupon, so modified_after is not actually filtering — the assertion above proves nothing.'
		);
	}

	/**
	 * A code change already moves the post date; the touch must not regress it.
	 */
	public function test_code_change_push_remains_visible_to_the_incremental_poll(): void {
		// Arrange.
		$uuid = '62000000-0000-4000-8000-000000000002';
		$id   = $this->aged_coupon( 'v2-code-before', $uuid );

		// Act.
		$response = $this->push_coupon( $id, $uuid, array( 'code' => 'v2-code-after' ), 2 );

		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'v2-code-after', $response->get_data()['document']['code'] );
		$this->assertContains( $id, $this->poll_modified_after() );
	}
}
