<?php
/**
 * Route-dispatch pin for the coupon `date_modified_gmt` guarantee on the v2 push lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

require_once __DIR__ . '/coupon-modified-date-clock.php';

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use WCPOS\WooCommercePOS\Sync\Coupon_Modified_Date;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;
use WP_REST_Response;

/**
 * WC's coupon data store only calls wp_update_post() when a POST field changes.
 * An amount-only edit is meta-backed, so `post_modified_gmt` is left stale.
 *
 * V1 papered over this by installing `woocommerce_update_coupon =>
 * wcpos_touch_coupon_modified_date` for the duration of a v1 REST dispatch
 * (API/V1/Coupons_Controller.php). The app now writes through
 * `POST /wcpos/v2/push/coupons`, which forwards to stock wc/v3 and never
 * installed that listener — so the guarantee was silently dropped. Being
 * request-scoped, v1's version never covered a wp-admin/WP-CLI/third-party save
 * either; the listener is now registered unconditionally at plugins_loaded, so
 * ANY coupon save moves the date.
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
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	/**
	 * Restore request state.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['woocommerce_pos_coupon_modified_date_now_gmt'] );
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	/**
	 * Create a coupon carrying a POS uuid and a chosen modified date.
	 *
	 * @param string $code         Coupon code.
	 * @param string $uuid         Record uuid the push will address it by.
	 * @param string $modified_gmt Modified date in GMT.
	 *
	 * @return int Coupon post id.
	 */
	private function coupon_with_modified_date( string $code, string $uuid, string $modified_gmt = self::AGED_GMT ): int {
		global $wpdb;

		$id = CouponHelper::create_coupon( $code )->get_id();
		update_post_meta( $id, Pos_Uuid::META_KEY, $uuid );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => get_date_from_gmt( $modified_gmt ),
				'post_modified_gmt' => $modified_gmt,
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
	 * @param string $modified_after Incremental cursor.
	 *
	 * @return int[]
	 */
	private function poll_modified_after( string $modified_after = self::POLL_AFTER ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/coupons' );
		$request->set_query_params(
			array(
				'modified_after' => $modified_after,
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
		// Arrange: freeze the writer in the exact second held by the second till's
		// cursor. An untouched coupon is the negative control. Without it,
		// assertContains() below would still pass if `modified_after` were ignored
		// and the proxy simply returned every coupon.
		$cursor_gmt = \current_time( 'mysql', true );
		$GLOBALS['woocommerce_pos_coupon_modified_date_now_gmt'] = $cursor_gmt;
		$uuid         = '62000000-0000-4000-8000-000000000001';
		$id           = $this->coupon_with_modified_date( 'v2-amount-only', $uuid, $cursor_gmt );
		$untouched_id = $this->coupon_with_modified_date( 'v2-untouched-control', '62000000-0000-4000-8000-000000000003', $cursor_gmt );

		// Act.
		$response = $this->push_coupon( $id, $uuid, array( 'amount' => '12.00' ), 1 );

		// Assert: the write landed...
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '12.00', $response->get_data()['document']['amount'] );

		// ...and the second till's incremental poll can still see it.
		clean_post_cache( $id );
		$modified_gmt = get_post_field( 'post_modified_gmt', $id );
		$this->assertGreaterThan(
			strtotime( $cursor_gmt . ' UTC' ),
			strtotime( $modified_gmt . ' UTC' ),
			'An amount-only v2 push did not advance post_modified_gmt beyond the second till cursor.'
		);
		$polled = $this->poll_modified_after( str_replace( ' ', 'T', $cursor_gmt ) );
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
	 * A merchant editing a coupon amount OUTSIDE the POS must still reach the tills.
	 *
	 * The cashier-expectation ruling: a cashier does not care which screen a
	 * discount was changed on. wp-admin, WP-CLI and third-party plugin saves all
	 * go through WC_Coupon::save() and none of them run a POS REST dispatch, so
	 * v1's request-scoped listener never covered them — an admin-side amount edit
	 * was invisible to every till. The listener is now registered unconditionally
	 * at plugins_loaded, so this test installs NOTHING: it saves a coupon the way
	 * wp-admin does and asserts the same incremental poll picks it up. If that
	 * registration is ever dropped from Init, this fails.
	 */
	public function test_admin_side_amount_edit_reaches_the_incremental_poll(): void {
		// Arrange: same worst case the push test pins — the writer runs inside the
		// exact second the second till already holds as its cursor, so only a
		// strictly-advancing touch keeps the coupon visible.
		$cursor_gmt = \current_time( 'mysql', true );
		$GLOBALS['woocommerce_pos_coupon_modified_date_now_gmt'] = $cursor_gmt;
		$id           = $this->coupon_with_modified_date( 'v2-admin-edit', '62000000-0000-4000-8000-000000000004', $cursor_gmt );
		$untouched_id = $this->coupon_with_modified_date( 'v2-admin-control', '62000000-0000-4000-8000-000000000005', $cursor_gmt );

		// Act: exactly what wp-admin does — no REST request, no POS marker at all.
		$coupon = new \WC_Coupon( $id );
		$coupon->set_amount( '7.50' );
		$coupon->save();

		// Assert.
		clean_post_cache( $id );
		$this->assertSame( '7.50', ( new \WC_Coupon( $id ) )->get_amount( 'edit' ) );
		$this->assertGreaterThan(
			strtotime( $cursor_gmt . ' UTC' ),
			strtotime( (string) get_post_field( 'post_modified_gmt', $id ) . ' UTC' ),
			'An admin-side coupon amount edit did not advance post_modified_gmt beyond the second till cursor; no till will ever re-fetch it.'
		);
		$polled = $this->poll_modified_after( $cursor_gmt );
		$this->assertContains( $id, $polled );
		$this->assertNotContains( $untouched_id, $polled );
	}

	/**
	 * The touch listener is wired up by production init, not by any test.
	 *
	 * Guards the wiring the behaviour tests above cannot: they would still pass if
	 * something else moved the date. This fails if the Init registration is
	 * removed or moved behind the schema latch — note the schema is NOT latched
	 * at plugins_loaded in this suite, which is exactly the condition that must
	 * not switch the listener off.
	 */
	public function test_the_touch_listener_is_registered_unconditionally_by_init(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_update_coupon', array( Coupon_Modified_Date::class, 'touch' ) ),
			'Sync\Coupon_Modified_Date::touch is not hooked to woocommerce_update_coupon; Init no longer registers it unconditionally.'
		);
	}

	/**
	 * A code change already moves the post date; the touch must not regress it.
	 */
	public function test_code_change_push_remains_visible_to_the_incremental_poll(): void {
		// Arrange.
		$uuid = '62000000-0000-4000-8000-000000000002';
		$id   = $this->coupon_with_modified_date( 'v2-code-before', $uuid );

		// Act.
		$response = $this->push_coupon( $id, $uuid, array( 'code' => 'v2-code-after' ), 2 );

		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'v2-code-after', $response->get_data()['document']['code'] );
		$this->assertContains( $id, $this->poll_modified_after() );
	}
}
