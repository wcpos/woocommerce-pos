<?php
/**
 * Filter-table leak goldens for Collection_Rules_Plan::around().
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use RuntimeException;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `around()` is the only path that installs anything, so it is the only path that can
 * leak. These goldens snapshot `$wp_filter` around a forward and require it to come back
 * byte-for-byte — including when the forward throws, and when two plans are nested.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
 */
class Test_Collection_Rules_Leak extends WP_UnitTestCase {
	/**
	 * The proxy lane's narrowing map, mirroring V2\Catalog_Proxy_Controller.
	 *
	 * @var array<string, mixed>
	 */
	private const V2_MAP = array(
		'orderby'     => 'orderby',
		'order'       => 'order',
		'pos_cashier' => 'pos_cashier',
		'pos_store'   => 'pos_store',
		'created_via' => 'created_via',
		'include'     => array(
			'key'   => 'include',
			'when'  => 'search',
			'parse' => 'id_list',
		),
		'exclude'     => array(
			'key'   => 'exclude',
			'when'  => 'search',
			'parse' => 'id_list',
		),
	);

	/**
	 * A plan that installs the maximum number of bindings for a storage.
	 *
	 * @param string $storage Storage dialect.
	 *
	 * @return Collection_Rules_Plan
	 */
	private function busy_plan( string $storage ): Collection_Rules_Plan {
		$request = new WP_REST_Request();
		$request->set_query_params(
			array(
				'orderby'     => 'status',
				'order'       => 'asc',
				'search'      => 'Aurelia',
				'include'     => '3,1',
				'exclude'     => '9',
				'pos_cashier' => 7,
				'pos_store'   => 9,
				'created_via' => 'checkout',
			)
		);

		return Collection_Rules::for_request( 'orders', $request, self::V2_MAP, $storage );
	}

	/**
	 * A normal forward leaves the filter table exactly as it found it.
	 */
	public function test_around_leaves_the_filter_table_unchanged(): void {
		foreach ( array( Collection_Rules::STORAGE_HPOS, Collection_Rules::STORAGE_POSTS ) as $storage ) {
			$plan   = $this->busy_plan( $storage );
			$before = $this->hook_signature();

			$this->assertSame(
				'ok',
				$plan->around(
					static function () {
						return 'ok';
					}
				)
			);
			$this->assertSame( $before, $this->hook_signature(), "leaked callbacks on {$storage}" );
		}
	}

	/**
	 * A throwing forward still unwinds, and the exception propagates afterwards.
	 */
	public function test_around_unwinds_when_the_forward_throws(): void {
		foreach ( array( Collection_Rules::STORAGE_HPOS, Collection_Rules::STORAGE_POSTS ) as $storage ) {
			$plan   = $this->busy_plan( $storage );
			$before = $this->hook_signature();
			$thrown = null;

			try {
				$plan->around(
					static function (): void {
						throw new RuntimeException( 'inner dispatch exploded' );
					}
				);
			} catch ( RuntimeException $exception ) {
				$thrown = $exception;
			}

			$this->assertInstanceOf( RuntimeException::class, $thrown, "exception swallowed on {$storage}" );
			$this->assertSame( $before, $this->hook_signature(), "leaked callbacks after a throw on {$storage}" );
		}
	}

	/**
	 * Nested forwards unwind completely, innermost first.
	 */
	public function test_nested_around_calls_unwind_completely(): void {
		$outer  = $this->busy_plan( Collection_Rules::STORAGE_HPOS );
		$inner  = $this->busy_plan( Collection_Rules::STORAGE_POSTS );
		$before = $this->hook_signature();

		$outer->around(
			function () use ( $inner ) {
				return $inner->around(
					static function () {
						return 'nested';
					}
				);
			}
		);

		$this->assertSame( $before, $this->hook_signature() );
	}

	/**
	 * The bindings really are installed for the duration of the forward.
	 *
	 * Without this, the leak goldens above would also pass for a plan that installs
	 * nothing at all.
	 */
	public function test_around_installs_its_bindings_for_the_duration_of_the_forward(): void {
		$plan   = $this->busy_plan( Collection_Rules::STORAGE_POSTS );
		$before = $this->hook_signature();
		$during = array();

		$plan->around(
			function () use ( &$during ): void {
				$during = $this->hook_signature();
			}
		);

		foreach ( array( 'woocommerce_rest_shop_order_object_query', 'posts_orderby', 'posts_where' ) as $hook ) {
			$this->assertSame(
				( $before[ $hook ] ?? 0 ) + 1,
				$during[ $hook ] ?? 0,
				"{$hook} was not installed during the forward"
			);
		}
	}

	/**
	 * A signature of the global filter table: hook name => number of callbacks.
	 *
	 * @return array<string, int>
	 */
	private function hook_signature(): array {
		$signature = array();
		foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
			$count = 0;
			foreach ( $callbacks->callbacks as $entries ) {
				$count += \count( $entries );
			}
			$signature[ $hook ] = $count;
		}

		return $signature;
	}
}
