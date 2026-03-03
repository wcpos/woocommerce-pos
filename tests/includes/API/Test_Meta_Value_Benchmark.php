<?php
/**
 * Meta Value Type Benchmark.
 *
 * Measures how different meta value types/sizes affect memory consumption.
 * Holds count constant at 100 entries and varies the value.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;

/**
 * Meta Value Benchmark test case.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Meta_Value_Benchmark extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Meta count to use for all scenarios.
	 *
	 * @var int
	 */
	private const META_COUNT = 100;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Suppress monitoring logs.
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
	 * Helper: add N meta entries with a specific value generator.
	 *
	 * @param int      $post_id  The post ID.
	 * @param int      $count    Number of entries.
	 * @param callable $value_fn Function that returns the value for each entry.
	 * @param string   $prefix   Meta key prefix.
	 */
	private function add_meta_with_values( int $post_id, int $count, callable $value_fn, string $prefix = '_bench' ): void {
		global $wpdb;

		$batch_size = 100;
		for ( $offset = 0; $offset < $count; $offset += $batch_size ) {
			$values    = array();
			$batch_end = min( $offset + $batch_size, $count );
			for ( $i = $offset; $i < $batch_end; $i++ ) {
				$key     = $prefix . '_' . $i;
				$value   = $value_fn( $i );
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
	 * Benchmark: compare memory usage across different meta value types.
	 */
	public function test_meta_value_type_benchmark(): void {
		$scenarios = array(
			'tiny_string_20B'       => function () {
				return str_repeat( 'x', 20 );
			},
			'small_string_100B'     => function () {
				return str_repeat( 'x', 100 );
			},
			'medium_string_1KB'     => function () {
				return str_repeat( 'x', 1024 );
			},
			'large_string_10KB'     => function () {
				return str_repeat( 'x', 10240 );
			},
			'huge_string_100KB'     => function () {
				return str_repeat( 'x', 102400 );
			},
			'small_serialized'      => function ( $i ) {
				return maybe_serialize(
					array(
						'key_' . $i => 'value_' . $i,
						'active'    => true,
						'count'     => $i,
					)
				);
			},
			'medium_serialized_1KB' => function ( $i ) {
				$arr = array();
				for ( $j = 0; $j < 20; $j++ ) {
					$arr[ 'field_' . $j ] = str_repeat( 'v', 40 );
				}
				return maybe_serialize( $arr );
			},
			'large_serialized_10KB' => function ( $i ) {
				$arr = array();
				for ( $j = 0; $j < 50; $j++ ) {
					$arr[ 'field_' . $j ] = array(
						'value'   => str_repeat( 'v', 100 ),
						'options' => array( 'a', 'b', 'c', 'd', 'e' ),
						'nested'  => array( 'deep' => str_repeat( 'n', 50 ) ),
					);
				}
				return maybe_serialize( $arr );
			},
			'json_blob_1KB'         => function ( $i ) {
				$data = array();
				for ( $j = 0; $j < 10; $j++ ) {
					$data[] = array(
						'id'    => $j,
						'name'  => 'item_' . $j,
						'value' => str_repeat( 'j', 50 ),
					);
				}
				return wp_json_encode( $data );
			},
			'json_blob_10KB'        => function ( $i ) {
				$data = array();
				for ( $j = 0; $j < 50; $j++ ) {
					$data[] = array(
						'id'       => $j,
						'name'     => 'item_' . $j,
						'content'  => str_repeat( 'j', 150 ),
						'metadata' => array( 'a' => 1, 'b' => 2, 'c' => str_repeat( 'z', 50 ) ),
					);
				}
				return wp_json_encode( $data );
			},
		);

		// Warmup.
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

		$results = array();

		foreach ( $scenarios as $label => $value_fn ) {
			$product = ProductHelper::create_simple_product(
				array(
					'regular_price' => 10,
					'price'         => 10,
				)
			);
			$this->add_meta_with_values( $product->get_id(), self::META_COUNT, $value_fn );

			// Measure DB storage size.
			global $wpdb;
			$db_size = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(LENGTH(meta_value)) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE '_bench_%'",
					$product->get_id()
				)
			);

			$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );

			wp_cache_flush();
			$mem_before = memory_get_usage( false );
			$response   = $this->server->dispatch( $request );
			$mem_after  = memory_get_usage( false );

			$this->assertEquals( 200, $response->get_status() );

			$data          = $response->get_data();
			$response_json = wp_json_encode( $data );
			$response_size = strlen( $response_json );
			$meta_count    = \count( $data['meta_data'] );

			$results[] = array(
				'label'         => $label,
				'db_size_kb'    => round( $db_size / 1024, 1 ),
				'memory_kb'     => round( ( $mem_after - $mem_before ) / 1024, 1 ),
				'response_kb'   => round( $response_size / 1024, 1 ),
				'meta_returned' => $meta_count,
				'mem_per_meta'  => round( ( $mem_after - $mem_before ) / max( $meta_count, 1 ) / 1024, 2 ),
			);

			unset( $data, $response, $response_json );
			wp_delete_post( $product->get_id(), true );
			wp_cache_flush();
		}

		// Print results.
		fwrite( STDERR, "\n\n" );
		fwrite( STDERR, "Meta value type benchmark (" . self::META_COUNT . " entries each)\n" );
		fwrite( STDERR, "┌─────────────────────────┬────────────┬────────────┬────────────┬──────────────┐\n" );
		fwrite( STDERR, "│  Value Type             │  DB (KB)   │  Mem (KB)  │  Resp (KB) │  KB/entry    │\n" );
		fwrite( STDERR, "├─────────────────────────┼────────────┼────────────┼────────────┼──────────────┤\n" );

		foreach ( $results as $r ) {
			fwrite(
				STDERR,
				sprintf(
					"│  %-22s │  %7s   │  %7s   │  %7s   │  %7s      │\n",
					$r['label'],
					number_format( $r['db_size_kb'], 1 ),
					number_format( $r['memory_kb'], 1 ),
					number_format( $r['response_kb'], 1 ),
					$r['mem_per_meta']
				)
			);
		}

		fwrite( STDERR, "└─────────────────────────┴────────────┴────────────┴────────────┴──────────────┘\n\n" );

		// Highlight the worst offenders.
		usort(
			$results,
			function ( $a, $b ) {
				return $b['memory_kb'] <=> $a['memory_kb'];
			}
		);

		fwrite( STDERR, "Ranked by memory consumption (worst first):\n" );
		foreach ( $results as $i => $r ) {
			$ratio = $results[ \count( $results ) - 1 ]['memory_kb'] > 0
				? round( $r['memory_kb'] / max( $results[ \count( $results ) - 1 ]['memory_kb'], 0.1 ), 1 )
				: 0;
			fwrite( STDERR, sprintf( "  %d. %-22s  %s KB  (%sx vs smallest)\n", $i + 1, $r['label'], number_format( $r['memory_kb'], 0 ), $ratio ) );
		}
		fwrite( STDERR, "\n" );

		$this->assertTrue( true );
	}
}
