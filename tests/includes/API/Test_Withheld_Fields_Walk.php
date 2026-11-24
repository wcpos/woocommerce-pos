<?php
/**
 * Every WCPOS route withholds cost from a role without view_woocommerce_pos_costs (roadmap 623).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

require_once __DIR__ . '/../../Helpers/Withheld_Fields_Walk.php';

use WCPOS\WooCommercePOS\Tests\Helpers\Withheld_Fields_Walk;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;
use WP_REST_Request;

/**
 * Walks the registered wcpos/v1 and wcpos/v2 routes as served to the client.
 */
class Test_Withheld_Fields_Walk extends Sync_REST_Store_Test_Case {
	use Withheld_Fields_Walk;

	/**
	 * GET routes the walk cannot serve as JSON, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const UNWALKABLE = array(
		'#/receipts/\(\?P<order_id>[^/]+/pdf$#'  => 'PDF bytes through Raw_Response, echoed at rest_pre_serve_request; the JSON receipt route is walked.',
		'#/print-jobs/cloudprnt#'                => 'Star CloudPRNT device poll: printer-token auth and raw job bytes through Raw_Response.',
		'#/closures/export$#'                    => 'Closure CSV through Raw_Response; closure rows carry no product or line cost.',
	);

	/**
	 * Query parameters that make a GET route answer with its records.
	 *
	 * @var array<string, array>
	 */
	private const QUERY_HINTS = array(
		'#/receipts/\(\?P<order_id>[^/]+$#' => array( 'mode' => 'live' ),
		'#/orders/pull$#'                   => array(
			'limit'          => 100,
			'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
			'order_id'       => 0,
			'sequence'       => 0,
		),
		'#/digests$#'                       => array( 'collection' => 'products' ),
	);

	/**
	 * Every non-GET handler, by route pattern: how its write is protected or why it carries no cost.
	 *
	 * @var array<string, string>
	 */
	private const NON_GET = array(
		'#^/wcpos/v1/products(/|$)#'          => 'Product and variation writes: the request seam drops cost (Test_Withheld_Cost_Parity).',
		'#^/wcpos/v[12]/orders(/\(\?P<id>[^/]+)?(/|$)#' => 'Order writes: the request seam drops line and order cost (Test_Withheld_Cost_Parity).',
		'#^/wcpos/v2/push/#'                  => 'v2 push: the request seam drops cost from the payload (Test_Withheld_Cost_Parity).',
		'#^/wcpos/v[12]/(auth|settings|templates|print-jobs|printnode|star-online|stores|extensions|logs|payment-gateways|cashier|receipts|customers|coupons|taxes|shipping_methods|data)(/|$)#' => 'No product or order-line cost in the body or the stored record.',
		'#^/wcpos/v2/(registers|sessions|movements|closures|payments|payment-methods|changes|integrity|digests|uuid|order-email|resolve|echo|site|status|reports|records)(/|$)#' => 'Register, cash, sync-maintenance or probe route: no product or order-line cost.',
		'#^/wcpos/v2/orders/\(\?P<[a-z_]+>[^/]+/(payments|email|checkout)#' => 'Payment, email and checkout actions: no product or order-line cost is accepted.',
	);

	/** Build the COGS fixtures. */
	public function setUp(): void {
		parent::setUp();
		$this->arrange_storage();
		$this->install_sync_pipeline();
		$this->enable_cogs();
		$this->create_cost_products();
		$this->create_cost_order();
	}

	/** Restore options, auth and roles. */
	public function tearDown(): void {
		$this->restore_withheld_state();
		parent::tearDown();
	}

	/** Storage seam for the HPOS subclass. */
	protected function arrange_storage(): void {
	}

	/** The admin sees cost in the product, variation, order, order line and refund. */
	public function test_admin_control_sees_cost_everywhere_it_is_stored(): void {
		// Arrange.
		$this->sign_in_with_token( $this->user );
		// Act.
		$walk = $this->walk();
		// Assert.
		$product   = '/wcpos/v1/products/' . $this->ids['simple'];
		$variation = '/wcpos/v1/products/' . $this->ids['variable'] . '/variations/' . $this->ids['variation'];
		$order     = '/wcpos/v1/orders/' . $this->ids['order'];
		$this->assertContains( '/cost_of_goods_sold', $walk[ $product ]['cost'] );
		$this->assertContains( '/cost_of_goods_sold', $walk[ $variation ]['cost'] );
		$this->assertContains( '/cost_of_goods_sold', $walk[ $order ]['cost'] );
		$this->assertContains( '/line_items/0/cost_of_goods_sold', $walk[ $order ]['cost'] );
		$this->assertContains( '/0/cost_of_goods_sold', $walk['/wcpos/v2/refunds']['cost'] );
		$this->assertContains( '/documents/0/payload/line_items/0/cost_of_goods_sold', $walk['/wcpos/v2/orders/pull']['cost'] );
		$this->assertNotEmpty( $walk['/wcpos/v2/products']['cost'] );
		$this->assertNotEmpty( $walk['/wcpos/v2/variations']['cost'] );
	}

	/** A cashier without the permission gets no cost from any walked GET, and the same routes still answer. */
	public function test_restricted_walk_serves_no_cost_on_any_get(): void {
		// Arrange.
		$this->sign_in_with_token( $this->user );
		$admin   = $this->walk();
		$cashier = $this->restricted_cashier();
		$this->sign_in_with_token( $cashier );
		// Act.
		$walk = $this->walk();
		// Assert.
		$this->assertGreaterThan( 100, \count( $walk ) );
		foreach ( $walk as $path => $result ) {
			$this->assertSame( array(), $result['cost'], $path );
		}
		foreach ( $admin as $path => $result ) {
			if ( array() !== $result['cost'] ) {
				$this->assertSame( 200, $walk[ $path ]['status'], $path . ' must still answer the cashier' );
			}
		}
	}

	/** Every wcpos GET route is walked or named as unwalkable, and every unwalkable entry still exists. */
	public function test_every_get_route_is_walked_or_listed(): void {
		// Arrange.
		$skipped = array();
		// Act.
		$paths = $this->get_paths( $skipped );
		// Assert.
		foreach ( $paths as $pattern => $concrete ) {
			$this->assertNotEmpty( $concrete, $pattern . ' has no fixture path' );
		}
		$this->assertEqualsCanonicalizing( array_keys( self::UNWALKABLE ), array_values( array_unique( $skipped ) ) );
	}

	/** Every non-GET handler is classified. */
	public function test_every_non_get_handler_is_classified(): void {
		// Arrange.
		$unclassified = array();
		// Act.
		foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
			if ( 1 !== preg_match( '#^/wcpos/v[12](/|$)#', $pattern ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$methods = array_diff( array_keys( array_filter( (array) $handler['methods'] ) ), array( 'GET', 'HEAD' ) );
				if ( array() !== $methods && null === $this->classify( $pattern ) ) {
					$unclassified[] = implode( ',', $methods ) . ' ' . $pattern;
				}
			}
		}
		// Assert.
		$this->assertSame( array(), $unclassified );
	}

	/** No wcpos route may be reached through /batch/v1, whose outer route is not served under the contract. */
	public function test_no_wcpos_route_allows_core_batch(): void {
		// Arrange.
		$batched = array();
		// Act.
		foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( 1 === preg_match( '#^/wcpos/#', $pattern ) && ! empty( $handler['allow_batch'] ) ) {
					$batched[] = $pattern;
				}
			}
		}
		// Assert.
		$this->assertSame( array(), $batched );
	}

	/** Write acknowledgements carry no cost for the cashier, on every write lane. */
	public function test_restricted_write_responses_serve_no_cost(): void {
		// Arrange.
		$cashier = $this->restricted_cashier();
		$this->sign_in_with_token( $cashier );
		$cost      = array( 'cost_of_goods_sold' => array( 'values' => array( array( 'defined_value' => 99 ) ) ) );
		$v1_var    = '/wcpos/v1/products/' . $this->ids['variable'] . '/variations';
		// Each request is built when it runs, so v2 pushes carry the revision current at that moment.
		$requests = array(
			'v1 product update'    => fn() => $this->json_post( '/wcpos/v1/products/' . $this->ids['simple'], $cost + array( 'name' => 'Renamed' ) ),
			'v1 product batch'     => fn() => $this->json_post( '/wcpos/v1/products/batch', array( 'update' => array( $cost + array( 'id' => $this->ids['simple'] ) ) ) ),
			'v1 variation update'  => fn() => $this->json_post( $v1_var . '/' . $this->ids['variation'], $cost ),
			'v1 variation batch'   => fn() => $this->json_post( $v1_var . '/batch', array( 'update' => array( $cost + array( 'id' => $this->ids['variation'] ) ) ) ),
			'v1 order create'      => fn() => $this->json_post(
				'/wcpos/v1/orders',
				array(
					'line_items' => array(
						array(
							'product_id'         => $this->ids['simple'],
							'quantity'           => 1,
							'cost_of_goods_sold' => array( 'value' => 99 ),
						),
					),
				)
			),
			'v2 push product'      => fn() => $this->push_request( 'products', 'update', $this->uuid_of( 'simple' ), $this->pulled_record( 'products', $this->ids['simple'] )['_rxdb_revision'], $cost + array( 'name' => 'Pushed' ) ),
			'v2 push variation'    => fn() => $this->push_request( 'variations', 'update', $this->uuid_of( 'variation' ), $this->pulled_record( 'variations', $this->ids['variation'] )['_rxdb_revision'], $cost + array( 'sku' => 'pushed-sku' ) ),
			'v2 push order'        => fn() => $this->push_request( 'orders', 'update', $this->uuid_of( 'order' ), $this->pulled_order( $this->ids['order'] )['sync']['revision'], array( 'customer_note' => 'Pushed' ) ),
			'v2 stale (409) order' => fn() => $this->push_request( 'orders', 'update', $this->uuid_of( 'order' ), 'sha256:stale', array( 'customer_note' => 'Stale' ) ),
		);
		foreach ( $requests as $label => $build ) {
			// Act.
			list( $status, $body ) = $this->serve( $build() );
			// Assert.
			if ( 'v2 stale (409) order' === $label ) {
				$this->assertSame( 409, $status, wp_json_encode( $body ) );
			} else {
				$this->assertLessThan( 300, $status, $label . ' ' . wp_json_encode( $body ) );
			}
			$this->assert_no_cost( $body, $label );
		}
	}

	/** A stale push answers the cashier with the current document, without cost. */
	public function test_restricted_stale_push_body_has_no_cost(): void {
		// Arrange.
		$cashier = $this->restricted_cashier();
		$this->sign_in_with_token( $cashier );
		// Act.
		list( $status, $body ) = $this->serve( $this->push_request( 'products', 'update', $this->uuid_of( 'simple' ), 'sha256:stale', array( 'name' => 'Stale' ) ) );
		// Assert.
		$this->assertSame( 409, $status, wp_json_encode( $body ) );
		$this->assertStringContainsString( (string) $this->ids['simple'], (string) wp_json_encode( $body ) );
		$this->assert_no_cost( $body );
	}

	/** A WCPOS token on plain wc/v3 routes serves the cashier no cost. */
	public function test_wc_v3_with_token_serves_no_cost(): void {
		// Arrange.
		$cashier = $this->restricted_cashier();
		$this->sign_in_with_token( $cashier );
		foreach ( $this->wc_v3_paths() as $path ) {
			// Act.
			list( $status, $body ) = $this->serve( new WP_REST_Request( 'GET', $path ) );
			// Assert.
			$this->assertSame( 200, $status, $path . ' ' . wp_json_encode( $body ) );
			$this->assert_no_cost( $body, $path );
		}
	}

	/** A cookie-authenticated wc/v3 request is outside the contract and keeps cost. */
	public function test_wc_v3_with_cookie_keeps_cost(): void {
		// Arrange.
		$cashier = $this->restricted_cashier();
		$this->sign_in_with_cookie( $cashier );
		foreach ( $this->wc_v3_paths() as $path ) {
			// Act.
			list( $status, $body ) = $this->serve( new WP_REST_Request( 'GET', $path ) );
			// Assert.
			$this->assertSame( 200, $status, $path . ' ' . wp_json_encode( $body ) );
			$this->assertNotSame( array(), $this->cost_paths( $body ), $path );
		}
	}

	/** The four wc/v3 reads that carry cost. */
	private function wc_v3_paths(): array {
		return array(
			'/wc/v3/products/' . $this->ids['simple'],
			'/wc/v3/products/' . $this->ids['variable'] . '/variations/' . $this->ids['variation'],
			'/wc/v3/orders/' . $this->ids['order'],
			'/wc/v3/orders/' . $this->ids['order'] . '/refunds',
		);
	}

	/**
	 * Serve every walkable GET path as the current user.
	 *
	 * @return array<string, array{status: int, cost: string[]}>
	 */
	private function walk(): array {
		$walk = array();
		foreach ( $this->get_paths() as $pattern => $paths ) {
			foreach ( $paths as $path ) {
				$request = $this->wp_rest_get_request( $path );
				foreach ( self::QUERY_HINTS as $hint => $query ) {
					if ( 1 === preg_match( $hint, $pattern ) ) {
						$request->set_query_params( $query );
					}
				}
				ob_start();
				list( $status, $body ) = $this->serve( $request );
				ob_end_clean();
				$walk[ $path ] = array(
					'status' => $status,
					'cost'   => $this->cost_paths( $body ),
				);
			}
		}
		return $walk;
	}

	/**
	 * The classification of a non-GET route pattern, or null.
	 *
	 * @param string $pattern Route pattern.
	 */
	private function classify( string $pattern ): ?string {
		foreach ( self::NON_GET as $match => $reason ) {
			if ( 1 === preg_match( $match, $pattern ) ) {
				return $reason;
			}
		}
		return null;
	}

	/**
	 * Concrete GET paths for every walkable wcpos GET handler, by route pattern.
	 *
	 * @param array $skipped Receives the UNWALKABLE keys that matched.
	 * @return array<string, string[]>
	 */
	private function get_paths( array &$skipped = array() ): array {
		$paths = array();
		foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
			if ( 1 !== preg_match( '#^/wcpos/v[12](/|$)#', $pattern ) ) {
				continue;
			}
			$gets = array_filter(
				$handlers,
				static function ( $handler ) {
					return ! empty( $handler['methods']['GET'] );
				}
			);
			if ( array() === $gets ) {
				continue;
			}
			foreach ( array_keys( self::UNWALKABLE ) as $unwalkable ) {
				if ( 1 === preg_match( $unwalkable, $pattern ) ) {
					$skipped[] = $unwalkable;
					continue 2;
				}
			}
			$paths[ $pattern ] = $this->expand( $pattern );
		}
		return $paths;
	}

	/**
	 * Substitute each (?P<name>regex) group with fixture values that match it.
	 * An alternation group (a|b|c) is walked once per alternative.
	 *
	 * @param string $pattern Route pattern.
	 * @return string[]
	 */
	private function expand( string $pattern ): array {
		$start = strpos( $pattern, '(?P<' );
		if ( false === $start ) {
			return array( $pattern );
		}
		$close = strpos( $pattern, '>', $start );
		$name  = substr( $pattern, $start + 4, $close - $start - 4 );
		$depth = 1;
		$end   = $close + 1;
		for ( $length = \strlen( $pattern ); $end < $length && $depth > 0; $end++ ) {
			if ( '\\' === $pattern[ $end ] ) {
				++$end;
				continue;
			}
			$depth += '(' === $pattern[ $end ] ? 1 : ( ')' === $pattern[ $end ] ? -1 : 0 );
		}
		$regex       = substr( $pattern, $close + 1, $end - $close - 2 );
		$before      = substr( $pattern, 0, $start );
		$after       = substr( $pattern, $end );
		$alternation = 1 === preg_match( '#^[\w-]+(\|[\w-]+)+$#', $regex );
		$out         = array();
		foreach ( $alternation ? explode( '|', $regex ) : $this->candidates( $name, $before ) as $value ) {
			if ( 1 !== preg_match( '#^(?:' . $regex . ')$#', (string) $value ) ) {
				continue;
			}
			foreach ( $this->expand( $after ) as $rest ) {
				$out[] = $before . $value . $rest;
			}
			if ( ! $alternation ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Fixture values for a path parameter, most fitting first.
	 *
	 * @param string $name   Parameter name.
	 * @param string $before Path before the parameter.
	 */
	private function candidates( string $name, string $before ): array {
		$by_name    = array(
			'product_id' => array( $this->ids['variable'] ),
			'parent_id'  => array( $this->ids['variable'] ),
			'order_id'   => array( $this->ids['order'] ),
		);
		$by_segment = array(
			'variations' => array( $this->ids['variation'], $this->uuid_of( 'variation' ) ),
			'orders'     => array( $this->ids['order'], $this->uuid_of( 'order' ) ),
			'refunds'    => array( $this->ids['refund'] ),
			'products'   => array( $this->ids['simple'] ),
		);
		return array_merge(
			$by_name[ $name ] ?? array(),
			$by_segment[ basename( rtrim( $before, '/' ) ) ] ?? array(),
			array( $this->ids['order'], $this->ids['simple'], $this->uuid_of( 'order' ), 'products', 'orders', '1', 'default', 'a' )
		);
	}
}
