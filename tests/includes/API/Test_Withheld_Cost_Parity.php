<?php
/**
 * The store ignores cost in writes from a role without view_woocommerce_pos_costs (roadmap 623).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

require_once __DIR__ . '/../../Helpers/Withheld_Fields_Walk.php';

use WCPOS\WooCommercePOS\Tests\Helpers\Withheld_Fields_Walk;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;
use WP_REST_Request;

/**
 * Every write lane drops a restricted user's cost; the admin's cost is recorded.
 */
class Test_Withheld_Cost_Parity extends Sync_REST_Store_Test_Case {
	use Withheld_Fields_Walk;

	/**
	 * Restricted cashier id.
	 *
	 * @var int
	 */
	private $cashier = 0;

	/** Build the COGS fixtures. */
	public function setUp(): void {
		parent::setUp();
		$this->arrange_storage();
		$this->install_sync_pipeline();
		$this->enable_cogs();
		$this->create_cost_products();
		$this->create_cost_order();
		$this->cashier = $this->restricted_cashier();
	}

	/** Restore options, auth and roles. */
	public function tearDown(): void {
		$this->restore_withheld_state();
		parent::tearDown();
	}

	/** Storage seam for the HPOS subclass. */
	protected function arrange_storage(): void {
	}

	/**
	 * Product cost body in the wc/v3 shape.
	 *
	 * @param float $value Cost.
	 */
	private function cost( float $value ): array {
		return array( 'cost_of_goods_sold' => array( 'values' => array( array( 'defined_value' => $value ) ) ) );
	}

	/**
	 * Write lanes for one product or variation, each built when it runs.
	 *
	 * @param string $fixture simple|variation.
	 * @return array<string, callable>
	 */
	private function product_lanes( string $fixture ): array {
		$id         = $this->ids[ $fixture ];
		$collection = 'simple' === $fixture ? 'products' : 'variations';
		$v1         = 'simple' === $fixture ? '/wcpos/v1/products' : '/wcpos/v1/products/' . $this->ids['variable'] . '/variations';
		$lanes      = array(
			'v1 update' => fn( float $value ) => $this->json_post( $v1 . '/' . $id, $this->cost( $value ) ),
			'v1 batch'  => fn( float $value ) => $this->json_post( $v1 . '/batch', array( 'update' => array( $this->cost( $value ) + array( 'id' => $id ) ) ) ),
			'v2 push'   => fn( float $value ) => $this->push_request( $collection, 'update', $this->uuid_of( $fixture ), $this->pulled_record( $collection, $id )['_rxdb_revision'], $this->cost( $value ) ),
		);
		if ( 'simple' === $fixture ) {
			$lanes['wc/v3 with token'] = function ( float $value ) use ( $id ) {
				$request = new WP_REST_Request( 'POST', '/wc/v3/products/' . $id );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $this->cost( $value ) ) );
				return $request;
			};
			$lanes['wc/v3 with token, form body'] = function ( float $value ) use ( $id ) {
				$request = new WP_REST_Request( 'POST', '/wc/v3/products/' . $id );
				$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
				$request->set_body_params( $this->cost( $value ) );
				return $request;
			};
			$lanes['wc/v3 with token, query params'] = function ( float $value ) use ( $id ) {
				$request = new WP_REST_Request( 'POST', '/wc/v3/products/' . $id );
				$request->set_query_params( $this->cost( $value ) );
				return $request;
			};
		}
		return $lanes;
	}

	/**
	 * Run each lane as the cashier, then as the admin, and record the stored costs.
	 *
	 * @param string $fixture simple|variation.
	 */
	private function assert_lanes_ignore_cashier_cost( string $fixture ): void {
		$id = $this->ids[ $fixture ];
		foreach ( $this->product_lanes( $fixture ) as $lane => $build ) {
			$before = $this->stored_cost( $id );
			// Act: the cashier's cost is dropped, the rest of the write lands.
			$this->sign_in_with_token( $this->cashier );
			list( $status, $body ) = $this->serve( $build( $before + 50 ) );
			// Assert.
			$this->assertLessThan( 300, $status, $lane . ' ' . wp_json_encode( $body ) );
			$this->assertSame( $before, $this->stored_cost( $id ), $lane . ': cashier cost must be ignored' );
			// Act: the admin control on the same lane.
			$this->sign_in_with_token( $this->user );
			list( $status, $body ) = $this->serve( $build( $before + 1 ) );
			// Assert.
			$this->assertLessThan( 300, $status, $lane . ' admin ' . wp_json_encode( $body ) );
			$this->assertSame( $before + 1, $this->stored_cost( $id ), $lane . ': admin cost must be recorded' );
		}
	}

	/** Product cost from the cashier is ignored on v1 update, v1 batch, v2 push and wc/v3 with a token (JSON body, form body and query params). */
	public function test_restricted_product_cost_writes_are_ignored_on_every_lane(): void {
		$this->assert_lanes_ignore_cashier_cost( 'simple' );
	}

	/** Variation cost from the cashier is ignored on v1 update, v1 batch and v2 push. */
	public function test_restricted_variation_cost_writes_are_ignored_on_every_lane(): void {
		$this->assert_lanes_ignore_cashier_cost( 'variation' );
	}

	/** The variation's additive cost flag in meta_data from the cashier is ignored on v1 update, v1 batch, v2 push and wc/v3 with a token. */
	public function test_restricted_variation_additive_flag_meta_is_ignored_on_every_lane(): void {
		// Arrange: the admin control reaches WooCommerce's internal-key notice.
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );
		$id     = $this->ids['variation'];
		$parent = wc_get_product( $this->ids['variable'] );
		$parent->set_cogs_value( 10.0 );
		$parent->save();
		$flag  = array(
			'key'   => '_cogs_value_is_additive',
			'value' => true,
		);
		$body  = array( 'meta_data' => array( $flag ) );
		$v1    = '/wcpos/v1/products/' . $this->ids['variable'] . '/variations';
		$lanes = array(
			'v1 update'        => fn() => $this->json_post( $v1 . '/' . $id, $body ),
			'v1 batch'         => fn() => $this->json_post( $v1 . '/batch', array( 'update' => array( $body + array( 'id' => $id ) ) ) ),
			'v2 push'          => fn() => $this->push_request( 'variations', 'update', $this->uuid_of( 'variation' ), $this->pulled_record( 'variations', $id )['_rxdb_revision'], $body ),
			'wc/v3 with token' => function () use ( $id, $body ) {
				$request = new WP_REST_Request( 'POST', '/wc/v3/products/' . $this->ids['variable'] . '/variations/' . $id );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $body ) );
				return $request;
			},
		);
		foreach ( $lanes as $lane => $build ) {
			$variation = wc_get_product( $id );
			$variation->set_cogs_value_is_additive( false );
			$variation->set_cogs_value( 4.0 );
			$variation->save();
			// Act: the cashier's flag is dropped.
			$this->sign_in_with_token( $this->cashier );
			list( $status, $response ) = $this->serve( $build() );
			// Assert.
			$this->assertLessThan( 300, $status, $lane . ' ' . wp_json_encode( $response ) );
			clean_post_cache( $id );
			clean_post_cache( $this->ids['variable'] );
			$this->assertSame( '', get_post_meta( $id, '_cogs_value_is_additive', true ), $lane . ': cashier flag must be ignored' );
			$this->assertSame( 4.0, wc_get_product( $id )->get_cogs_total_value(), $lane . ': cashier total cost must stay' );
			// Act: the admin control on the same lane.
			$this->sign_in_with_token( $this->user );
			list( $status, $response ) = $this->serve( $build() );
			// Assert.
			$this->assertLessThan( 300, $status, $lane . ' admin ' . wp_json_encode( $response ) );
			clean_post_cache( $id );
			clean_post_cache( $this->ids['variable'] );
			$this->assertSame( 'yes', get_post_meta( $id, '_cogs_value_is_additive', true ), $lane . ': admin flag must be recorded' );
			$this->assertSame( 14.0, wc_get_product( $id )->get_cogs_total_value(), $lane . ': admin total cost must add the parent' );
		}
	}

	/**
	 * A productless line posting its own cost.
	 *
	 * @param float $value Posted cost.
	 */
	private function custom_line( float $value ): array {
		return array(
			'name'               => 'Custom amount',
			'product_id'         => 0,
			'quantity'           => 1,
			'total'              => '5.00',
			'cost_of_goods_sold' => array( 'value' => $value ),
		);
	}

	/**
	 * The stored cost of the order's only line.
	 *
	 * @param int $order_id Order id.
	 */
	private function line_cost( int $order_id ): float {
		$items = wc_get_order( $order_id )->get_items();
		$this->assertCount( 1, $items );
		return (float) current( $items )->get_cogs_value();
	}

	/**
	 * Create an order on a lane and return its id.
	 *
	 * @param string $lane v1|v2.
	 * @param array  $line Line item.
	 */
	private function create_order_on( string $lane, array $line ): int {
		$body = array(
			'status'     => 'pending',
			'line_items' => array( $line ),
		);
		$request               = 'v1' === $lane
			? $this->json_post( '/wcpos/v1/orders', $body )
			: $this->push_request( 'orders', 'create', wp_generate_uuid4(), null, $body );
		list( $status, $data ) = $this->serve( $request );
		$this->assertLessThan( 300, $status, $lane . ' ' . wp_json_encode( $data ) );
		return (int) ( $data['document']['id'] ?? $data['id'] );
	}

	/** A posted line cost on a productless line is ignored for the cashier and recorded for the admin. */
	public function test_line_cost_on_productless_line_is_ignored_for_role(): void {
		foreach ( array( 'v2', 'v1' ) as $lane ) {
			// Act.
			$this->sign_in_with_token( $this->cashier );
			$cashier_order = $this->create_order_on( $lane, $this->custom_line( 4 ) );
			$this->sign_in_with_token( $this->user );
			$admin_order = $this->create_order_on( $lane, $this->custom_line( 4 ) );
			// Assert.
			$this->assertSame( 0.0, $this->line_cost( $cashier_order ), $lane . ': cashier line cost must be ignored' );
			$this->assertSame( 4.0, $this->line_cost( $admin_order ), $lane . ': admin line cost must be recorded' );
		}
	}

	/** An admin sale posting a line cost records it. */
	public function test_sale_records_admin_line_cost(): void {
		// Arrange.
		$this->sign_in_with_token( $this->user );
		// Act.
		$order_id = $this->create_order_on( 'v2', $this->custom_line( 3 ) );
		// Assert.
		$this->assertSame( 3.0, $this->line_cost( $order_id ) );
		$this->assertSame( 3.0, (float) wc_get_order( $order_id )->get_cogs_total_value() );
	}

	/** A cashier's sale of a product still records the store's own cost for the line. */
	public function test_restricted_sale_records_the_product_cost(): void {
		// Arrange.
		$this->sign_in_with_token( $this->cashier );
		// Act.
		$order_id = $this->create_order_on(
			'v2',
			array(
				'product_id' => $this->ids['simple'],
				'quantity'   => 2,
			)
		);
		// Assert.
		$this->assertSame( 15.0, $this->line_cost( $order_id ) );
	}

	/** An admin edit posting a line cost records it; the same edit from the cashier is ignored. */
	public function test_edit_records_admin_line_cost(): void {
		// Arrange.
		$this->sign_in_with_token( $this->user );
		$order_id = $this->create_order_on( 'v2', $this->custom_line( 3 ) );
		$item_id  = key( wc_get_order( $order_id )->get_items() );
		$uuid     = (string) wc_get_order( $order_id )->get_meta( '_woocommerce_pos_uuid' );
		$edit     = function ( float $value ) use ( $order_id, $item_id, $uuid ) {
			return $this->push_request(
				'orders',
				'update',
				$uuid,
				$this->pulled_order( $order_id )['sync']['revision'],
				array(
					'line_items' => array(
						array(
							'id'                 => $item_id,
							'cost_of_goods_sold' => array( 'value' => $value ),
						),
					),
				)
			);
		};
		// Act.
		$this->sign_in_with_token( $this->cashier );
		list( $cashier_status, $cashier_body ) = $this->serve( $edit( 9 ) );
		$after_cashier                         = $this->line_cost( $order_id );
		$this->sign_in_with_token( $this->user );
		list( $admin_status, $admin_body ) = $this->serve( $edit( 6 ) );
		// Assert.
		$this->assertSame( 200, $cashier_status, wp_json_encode( $cashier_body ) );
		$this->assertSame( 3.0, $after_cashier );
		$this->assertSame( 200, $admin_status, wp_json_encode( $admin_body ) );
		$this->assertSame( 6.0, $this->line_cost( $order_id ) );
	}
}
