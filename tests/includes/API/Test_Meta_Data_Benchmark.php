<?php
/**
 * Meta Data Scaling Benchmark.
 *
 * Measures response time at various meta_data counts to determine scaling behavior.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;

/**
 * Meta Data Benchmark test case.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Meta_Data_Benchmark extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Number of iterations per meta count for averaging.
	 *
	 * @var int
	 */
	private const ITERATIONS = 3;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Suppress monitoring logs so they don't affect timing.
		add_filter(
			'woocommerce_pos_meta_data_warning_threshold',
			function () {
				return 99999;
			}
		);
		add_filter(
			'woocommerce_pos_meta_data_error_threshold',
			function () {
				return 99999;
			}
		);
		add_filter(
			'woocommerce_pos_response_size_warning_threshold',
			function () {
				return 999999999;
			}
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_meta_data_warning_threshold' );
		remove_all_filters( 'woocommerce_pos_meta_data_error_threshold' );
		remove_all_filters( 'woocommerce_pos_response_size_warning_threshold' );
		parent::tearDown();
	}

	/**
	 * Helper: add N meta entries to a post via direct SQL.
	 *
	 * @param int    $post_id The post ID.
	 * @param int    $count   Number of entries to add.
	 * @param string $prefix  Meta key prefix.
	 */
	private function add_bulk_post_meta( int $post_id, int $count, string $prefix = '_bench_meta' ): void {
		global $wpdb;

		// Insert in batches of 500 to avoid overly long queries.
		$batch_size = 500;
		for ( $offset = 0; $offset < $count; $offset += $batch_size ) {
			$values     = array();
			$batch_end  = min( $offset + $batch_size, $count );
			for ( $i = $offset; $i < $batch_end; $i++ ) {
				$key     = $prefix . '_' . $i;
				$value   = str_repeat( 'x', 100 ); // 100-byte values to simulate real meta.
				$values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, $key, $value );
			}

			if ( ! empty( $values ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built safely above.
				$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $values ) );
			}
		}

		clean_post_cache( $post_id );
		wp_cache_flush();
	}

	/**
	 * Benchmark: measure response time scaling from 5 to 5000 meta entries.
	 */
	public function test_meta_count_scaling_benchmark(): void {
		$counts  = array( 5, 25, 50, 100, 250, 500, 1000, 2000, 5000 );
		$results = array();

		// Warmup: dispatch one request to prime autoloader/caches.
		$warmup = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$req = $this->wp_rest_get_request( '/wcpos/v1/products/' . $warmup->get_id() );
		$this->server->dispatch( $req );
		wp_delete_post( $warmup->get_id(), true );
		wp_cache_flush();

		foreach ( $counts as $meta_count ) {
			$times  = array();
			$memory = array();
			$sizes  = array();

			for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
				$product = ProductHelper::create_simple_product(
					array(
						'regular_price' => 10,
						'price'         => 10,
					)
				);
				$this->add_bulk_post_meta( $product->get_id(), $meta_count );

				$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );

				wp_cache_flush();
				$mem_before = memory_get_usage( false ); // Actual usage, not allocated.
				$t_start    = microtime( true );
				$response   = $this->server->dispatch( $request );
				$t_end      = microtime( true );
				$mem_after  = memory_get_usage( false );

				$this->assertEquals( 200, $response->get_status() );

				$data     = $response->get_data();
				$times[]  = ( $t_end - $t_start ) * 1000; // ms.
				$memory[] = ( $mem_after - $mem_before ) / 1024; // KB.
				$sizes[]  = \count( $data['meta_data'] );

				// Free data explicitly.
				unset( $data, $response );
				wp_delete_post( $product->get_id(), true );
				wp_cache_flush();
			}

			$avg_time   = array_sum( $times ) / \count( $times );
			$avg_memory = array_sum( $memory ) / \count( $memory );
			$avg_size   = array_sum( $sizes ) / \count( $sizes );

			$results[] = array(
				'meta_count'        => $meta_count,
				'avg_time_ms'       => round( $avg_time, 2 ),
				'avg_memory_kb'     => round( $avg_memory, 1 ),
				'avg_meta_returned' => round( $avg_size ),
			);
		}

		// Print results table.
		$baseline_time   = max( $results[0]['avg_time_ms'], 0.01 );
		$baseline_memory = $results[0]['avg_memory_kb'];

		fwrite( STDERR, "\n\n" );
		fwrite( STDERR, "┌───────────────┬──────────────────┬──────────────────┬────────────────┐\n" );
		fwrite( STDERR, "│  Meta Count   │  Time (ms)       │  Memory (KB)     │  Meta Returned │\n" );
		fwrite( STDERR, "├───────────────┼──────────────────┼──────────────────┼────────────────┤\n" );

		foreach ( $results as $r ) {
			$time_ratio = round( $r['avg_time_ms'] / $baseline_time, 1 );
			$mem_str    = $r['avg_memory_kb'] >= 0
				? sprintf( '%s', number_format( $r['avg_memory_kb'], 0 ) )
				: sprintf( '%s', number_format( $r['avg_memory_kb'], 0 ) );

			fwrite(
				STDERR,
				sprintf(
					"│  %5s        │  %7s  (%sx)  │  %7s KB      │  %5d         │\n",
					number_format( $r['meta_count'] ),
					$r['avg_time_ms'],
					str_pad( (string) $time_ratio, 4 ),
					$mem_str,
					$r['avg_meta_returned']
				)
			);
		}

		fwrite( STDERR, "└───────────────┴──────────────────┴──────────────────┴────────────────┘\n" );
		fwrite( STDERR, sprintf( "(Each measurement averaged over %d iterations, after warmup)\n\n", self::ITERATIONS ) );

		// Check if scaling is linear.
		$last  = end( $results );
		$first = reset( $results );
		$time_growth  = $last['avg_time_ms'] / max( $first['avg_time_ms'], 0.01 );
		$count_growth = $last['meta_count'] / $first['meta_count'];

		fwrite( STDERR, sprintf(
			"Count grew %dx, time grew %.1fx → scaling is %s\n\n",
			$count_growth,
			$time_growth,
			$time_growth < $count_growth * 2 ? 'roughly linear' : 'super-linear (potential concern)'
		) );

		// Estimate: at what meta count would we hit 128MB memory limit?
		if ( $baseline_memory > 0 && $last['avg_memory_kb'] > $baseline_memory ) {
			$mem_per_meta = ( $last['avg_memory_kb'] - $baseline_memory ) / ( $last['meta_count'] - $first['meta_count'] );
			$php_limit_kb = 128 * 1024; // 128MB.
			$available_kb = $php_limit_kb - $baseline_memory;
			$estimated_max_meta = (int) ( $available_kb / max( $mem_per_meta, 0.01 ) );
			fwrite( STDERR, sprintf(
				"Memory per meta entry: ~%.1f KB → estimated max before 128MB limit: ~%s entries\n\n",
				$mem_per_meta,
				number_format( $estimated_max_meta )
			) );
		}

		$this->assertTrue( true );
	}
}
