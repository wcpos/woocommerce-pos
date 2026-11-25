<?php
/**
 * Fixtures and serving helpers for the withheld-fields tests (roadmap 623).
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;

/**
 * A COGS store, a cashier without the costs permission, token sign-in, and a
 * request served the way WordPress serves it (envelope, embeds, echo filter).
 */
trait Withheld_Fields_Walk {
	/**
	 * Keys the costs permission withholds.
	 *
	 * @var string[]
	 */
	protected static $cost_keys = array( 'cost_of_goods_sold', '_cogs_value', '_cogs_total_value' );

	/**
	 * Fixture ids: simple, variable, variation, order, refund.
	 *
	 * @var array<string, int>
	 */
	protected $ids = array();

	/** Turn COGS on before fixtures are built. */
	protected function enable_cogs(): void {
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );
	}

	/** The production augmentation pipeline: revision, uuid and digest stamps. */
	protected function install_sync_pipeline(): void {
		Augmentation_Pipeline::install();
		Meta_Normalizer::register_hooks();
	}

	/** Undo COGS, the pipeline, token sign-in and role edits. */
	protected function restore_withheld_state(): void {
		Augmentation_Pipeline::reset();
		Meta_Normalizer::unregister_hooks();
		Revision::unregister_proxy_stamps();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
		remove_filter( 'wcpos_sync_proxy_response', array( Integrity_Digest::class, 'stamp_digests' ), 10 );
		remove_filter( 'wcpos_sync_order_pull_payloads', array( Integrity_Digest::class, 'stamp_digests' ), 10 );
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'no' );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		Auth::instance()->authenticate_request();
		unset( $GLOBALS['wp_user_roles'] );
		global $wp_roles;
		$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore role state after capability tests.
	}

	/** A cashier whose role lacks view_woocommerce_pos_costs. */
	protected function restricted_cashier(): int {
		$role = get_role( 'cashier' );
		$this->assertNotNull( $role );
		$role->remove_cap( 'view_woocommerce_pos_costs' );
		$user_id = self::factory()->user->create( array( 'role' => 'cashier' ) );
		$this->assertFalse( user_can( $user_id, 'view_woocommerce_pos_costs' ) );
		$this->assertTrue( user_can( $user_id, 'access_woocommerce_pos' ) );
		return $user_id;
	}

	/**
	 * Sign a user in with a WCPOS bearer token, as the app does.
	 *
	 * @param int $user_id User.
	 */
	protected function sign_in_with_token( int $user_id ): void {
		$token                         = Auth::instance()->generate_access_token( get_user_by( 'id', $user_id ) );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Force determine_current_user to run.
		$this->assertSame( $user_id, wp_get_current_user()->ID );
		$this->assertSame( $user_id, Auth::instance()->token_user_id() );
	}

	/**
	 * Sign a user in by cookie (no token).
	 *
	 * @param int $user_id User.
	 */
	protected function sign_in_with_cookie( int $user_id ): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		Auth::instance()->authenticate_request();
		wp_set_current_user( $user_id );
	}

	/**
	 * A simple product with a cost, and a variable product whose variation has one.
	 *
	 * @param bool $costed False for a store that never recorded a cost.
	 */
	protected function create_cost_products( bool $costed = true ): void {
		$simple = ProductHelper::create_simple_product();
		if ( $costed ) {
			$simple->set_cogs_value( 7.5 );
			$simple->save();
		}
		Pos_Uuid::ensure_uuid( $simple );

		$variable  = ProductHelper::create_variation_product();
		$variation = wc_get_product( current( $variable->get_children() ) );
		if ( $costed ) {
			$variation->set_cogs_value( 3.25 );
			$variation->save();
		}
		Pos_Uuid::ensure_uuid( $variable );
		Pos_Uuid::ensure_uuid( $variation );

		$this->ids['simple']    = $simple->get_id();
		$this->ids['variable']  = $variable->get_id();
		$this->ids['variation'] = $variation->get_id();
	}

	/** An order with a line from each product, and a refund of one unit. */
	protected function create_cost_order(): \WC_Order {
		$order = OrderHelper::create_order( array( 'product' => wc_get_product( $this->ids['simple'] ) ) );
		$order->add_product( wc_get_product( $this->ids['variation'] ), 2 );
		$order->calculate_totals();
		$order->save();
		Pos_Uuid::ensure_uuid( $order );
		$this->ids['order'] = $order->get_id();
		$items              = $order->get_items();
		$refund             = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 1,
				'line_items' => array(
					key( $items ) => array(
						'qty'          => 1,
						'refund_total' => 1,
					),
				),
			)
		);
		$this->assertNotWPError( $refund );
		$this->ids['refund'] = $refund->get_id();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Serve a request the way WP_REST_Server::serve_request() does, without echoing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $embed   Whether to embed links.
	 * @return array{0: int, 1: mixed} Status and the decoded body the client receives.
	 */
	protected function serve( WP_REST_Request $request, bool $embed = false ): array {
		$response = rest_do_request( $request );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, $embed );
		$data     = apply_filters( 'rest_pre_echo_response', $data, $server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Serve the core echo filter, as WP_REST_Server does.
		return array( $response->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
	}

	/**
	 * Serve a request like serve(), with the rest_post_dispatch step (core's _fields selection).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{0: int, 1: mixed} Status and the decoded body the client receives.
	 */
	protected function serve_selected( WP_REST_Request $request ): array {
		$server   = rest_get_server();
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), $server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Serve the core dispatch filter, as WP_REST_Server does.
		$data     = $server->response_to_data( $response, false );
		$data     = apply_filters( 'rest_pre_echo_response', $data, $server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Serve the core echo filter, as WP_REST_Server does.
		return array( $response->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
	}

	/**
	 * A WCPOS POST request with a JSON body.
	 *
	 * @param string $path Route.
	 * @param array  $body Body.
	 */
	protected function json_post( string $path, array $body ): WP_REST_Request {
		$request = $this->wp_rest_post_request( $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * A v2 push envelope.
	 *
	 * @param string      $collection Collection.
	 * @param string      $operation  create|update.
	 * @param string      $record_id  Record uuid.
	 * @param string|null $base       Base revision.
	 * @param array       $payload    Payload.
	 */
	protected function push_request( string $collection, string $operation, string $record_id, ?string $base, array $payload ): WP_REST_Request {
		$mutation = wp_generate_uuid4();
		$request  = $this->json_post(
			'/wcpos/v2/push/' . $collection,
			array(
				'mutationId'   => $mutation,
				'operation'    => $operation,
				'collection'   => $collection,
				'recordId'     => $record_id,
				'baseRevision' => $base,
				'payload'      => $payload,
			)
		);
		$request->set_header( 'Idempotency-Key', $mutation );
		return $request;
	}

	/**
	 * A fixture's POS uuid.
	 *
	 * @param string $fixture Key of $this->ids.
	 */
	protected function uuid_of( string $fixture ): string {
		if ( 'order' === $fixture ) {
			return (string) wc_get_order( $this->ids['order'] )->get_meta( Pos_Uuid::META_KEY );
		}
		return (string) get_post_meta( $this->ids[ $fixture ], Pos_Uuid::META_KEY, true );
	}

	/**
	 * One product or variation as the current user pulls it from the v2 flat lane.
	 *
	 * @param string $collection products|variations.
	 * @param int    $id         Record id.
	 */
	protected function pulled_record( string $collection, int $id ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/' . $collection );
		$request->set_query_params( array( 'include' => array( $id ) ) );
		list( $status, $body ) = $this->serve( $request );
		$this->assertSame( 200, $status, wp_json_encode( $body ) );
		$this->assertCount( 1, $body );
		return $body[0];
	}

	/**
	 * One order document as the current user pulls it from the v2 order lane.
	 *
	 * @param int $order_id Order id.
	 */
	protected function pulled_order( int $order_id ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders/pull' );
		$request->set_query_params(
			array(
				'limit'          => 100,
				'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
				'order_id'       => 0,
				'sequence'       => 0,
			)
		);
		list( $status, $body ) = $this->serve( $request );
		$this->assertSame( 200, $status, wp_json_encode( $body ) );
		foreach ( $body['documents'] as $document ) {
			if ( $order_id === (int) $document['payload']['id'] ) {
				return $document;
			}
		}
		$this->fail( 'Order ' . $order_id . ' was not pulled.' );
	}

	/**
	 * Every path in $data where a cost key appears (object key or meta_data entry key).
	 *
	 * @param mixed  $data Decoded body.
	 * @param string $path Path so far.
	 * @return string[]
	 */
	protected function cost_paths( $data, string $path = '' ): array {
		if ( ! \is_array( $data ) ) {
			return array();
		}
		$found = array();
		foreach ( $data as $key => $value ) {
			$here = $path . '/' . $key;
			if ( \in_array( $key, self::$cost_keys, true ) ) {
				$found[] = $here;
			}
			if ( \is_array( $value ) && isset( $value['key'] ) && \in_array( $value['key'], self::$cost_keys, true ) ) {
				$found[] = $here . '[key=' . $value['key'] . ']';
			}
			$found = array_merge( $found, $this->cost_paths( $value, $here ) );
		}
		return $found;
	}

	/**
	 * Assert no cost key anywhere in a decoded body.
	 *
	 * @param mixed  $data    Decoded body.
	 * @param string $context Message context.
	 */
	protected function assert_no_cost( $data, string $context = '' ): void {
		$this->assertSame( array(), $this->cost_paths( $data ), $context );
	}

	/**
	 * Read the product's stored cost.
	 *
	 * @param int $product_id Product or variation id.
	 */
	protected function stored_cost( int $product_id ): float {
		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
		return (float) wc_get_product( $product_id )->get_cogs_value();
	}
}
