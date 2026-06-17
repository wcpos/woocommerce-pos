<?php
/**
 * Tests for receipt builder contract parity.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Builders_Contract_Sync class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Builders_Contract_Sync extends WC_REST_Unit_Test_Case {

	/**
	 * Test builder payload paths stay covered by the published field tree.
	 */
	public function test_field_tree_covers_builder_payload_paths(): void {
		$tree_paths       = $this->flatten_field_tree_paths( Receipt_Data_Schema::get_field_tree() );
		$published_paths  = $tree_paths['published'];
		$container_paths  = $tree_paths['containers'];
		$open_containers  = $tree_paths['open_containers'];
		$payloads         = $this->build_contract_sample_payloads();

		foreach ( $payloads as $payload_name => $payload ) {
			$payload_paths = $this->flatten_payload_paths( $payload );
			$unexpected    = array();

			foreach ( $payload_paths as $path ) {
				if ( 0 === strpos( $path, 'presentation_hints' ) ) {
					continue;
				}

				if ( $this->is_payload_path_covered_by_field_tree( $path, $published_paths, $container_paths, $open_containers ) ) {
					continue;
				}

				$unexpected[] = $path;
			}

			$this->assertEmpty(
				$unexpected,
				sprintf( '%s receipt payload exposes fields that are missing from the editor field tree: %s', $payload_name, implode( ', ', $unexpected ) )
			);
		}
	}

	/**
	 * Test published field-tree paths are represented by at least one builder payload.
	 */
	public function test_builder_payloads_cover_published_field_tree_paths(): void {
		$tree_paths      = $this->flatten_field_tree_paths( Receipt_Data_Schema::get_field_tree() );
		$published_paths = $tree_paths['published'];
		$payload_paths   = array();

		foreach ( $this->build_contract_sample_payloads() as $payload ) {
			$payload_paths = array_merge( $payload_paths, $this->flatten_payload_paths( $payload ) );
		}

		$payload_paths = array_values( array_unique( $payload_paths ) );
		$missing       = array();

		foreach ( $published_paths as $path ) {
			if ( $this->is_field_tree_path_represented_by_payloads( $path, $payload_paths ) ) {
				continue;
			}

			$missing[] = $path;
		}

		$this->assertEmpty(
			$missing,
			sprintf( 'Published field-tree fields must be represented by at least one builder payload sample: %s', implode( ', ', $missing ) )
		);
	}

	/**
	 * Test populated array payloads require matching leaf paths.
	 */
	public function test_populated_array_payload_paths_require_leaf_coverage(): void {
		$payload_paths = array(
			'customer.tax_ids',
			'customer.tax_ids[]',
			'customer.tax_ids[].type',
			'customer.tax_ids[].value',
		);

		$this->assertTrue( $this->is_field_tree_path_represented_by_payloads( 'customer.tax_ids[].value', $payload_paths ) );
		$this->assertFalse( $this->is_field_tree_path_represented_by_payloads( 'customer.tax_ids[].label', $payload_paths ) );
		$this->assertTrue( $this->is_field_tree_path_represented_by_payloads( 'fiscal.extra_fields[].label', array( 'fiscal.extra_fields', 'fiscal.extra_fields[]' ) ) );
	}


	/**
	 * Build representative live and preview payloads for field-tree coverage.
	 *
	 * @return array<string,array<string,mixed>> Receipt payloads.
	 */
	private function build_contract_sample_payloads(): array {
		return array(
			'live'        => ( new Receipt_Data_Builder() )->build( OrderHelper::create_order(), 'live' ),
			'live_refund' => ( new Receipt_Data_Builder() )->build( $this->create_refunded_order(), 'live' ),
			'preview'     => ( new Preview_Receipt_Builder() )->build(),
		);
	}


	/**
	 * Create a live order with a line-item refund for refund field-tree coverage.
	 *
	 * @return \WC_Order
	 */
	private function create_refunded_order() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Refundable Product' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$line_item    = array_values( $order->get_items() )[0];
		$line_item_id = $line_item->get_id();

		$refund = wc_create_refund(
			array(
				'amount'     => '10.00',
				'reason'     => 'Contract coverage refund',
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$line_item_id => array(
						'qty'          => 1,
						'refund_total' => 10.00,
						'refund_tax'   => array(),
					),
				),
			)
		);

		$this->assertNotWPError( $refund );
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		$reloaded_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_order );

		return $reloaded_order;
	}

	/**
	 * Flatten field-tree leaf paths and object/array container paths.
	 *
	 * @param array<string,array<string,mixed>> $tree Field tree.
	 * @return array{published: string[], containers: string[], open_containers: string[]}
	 */
	private function flatten_field_tree_paths( array $tree ): array {
		$published       = array();
		$containers      = array();
		$open_containers = array();

		foreach ( $tree as $section_path => $section ) {
			$containers[]         = $section_path;
			$section_payload_path = ! empty( $section['is_array'] ) ? $section_path . '[]' : $section_path;
			$containers[]         = $section_payload_path;

			foreach ( $section['fields'] as $field_key => $field ) {
				$this->collect_field_tree_path(
					$section_payload_path . '.' . $field_key,
					$field,
					$published,
					$containers,
					$open_containers
				);
			}
		}

		sort( $published );
		sort( $containers );
		sort( $open_containers );

		return array(
			'published'       => array_values( array_unique( $published ) ),
			'containers'      => array_values( array_unique( $containers ) ),
			'open_containers' => array_values( array_unique( $open_containers ) ),
		);
	}

	/**
	 * Collect one field-tree path.
	 *
	 * @param string              $path       Current path.
	 * @param array<string,mixed> $field      Field metadata.
	 * @param string[]            $published  Published leaf paths.
	 * @param string[]            $containers      Object/array container paths.
	 * @param string[]            $open_containers Open-ended object/array paths.
	 */
	private function collect_field_tree_path(
		string $path,
		array $field,
		array &$published,
		array &$containers,
		array &$open_containers
	): void {
		if ( ! empty( $field['is_array'] ) ) {
			$containers[] = $path;
			$path        .= '[]';
		}

		if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
			$containers[] = $path;
			foreach ( $field['fields'] as $child_key => $child ) {
				$this->collect_field_tree_path(
					$path . '.' . $child_key,
					$child,
					$published,
					$containers,
					$open_containers
				);
			}
			return;
		}

		$published[] = $path;

		if ( isset( $field['type'] ) && in_array( $field['type'], array( 'object', 'array', 'string[]' ), true ) ) {
			$containers[]      = $path;
			$open_containers[] = $path;
		}
	}

	/**
	 * Flatten payload paths, including object/array containers.
	 *
	 * @param mixed  $value Payload value.
	 * @param string $prefix Current path.
	 * @return string[] Paths.
	 */
	private function flatten_payload_paths( $value, string $prefix = '' ): array {
		if ( '' === $prefix ) {
			$paths = array();
		} else {
			$paths = array( $prefix );
		}

		if ( ! is_array( $value ) ) {
			return $paths;
		}

		if ( $this->is_list_array( $value ) ) {
			$item_prefix = '' === $prefix ? '[]' : $prefix . '[]';
			$paths[]     = $item_prefix;

			foreach ( $value as $item ) {
				$paths = array_merge( $paths, $this->flatten_payload_paths( $item, $item_prefix ) );
			}

			return array_values( array_unique( $paths ) );
		}

		foreach ( $value as $key => $child ) {
			$child_prefix = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$paths        = array_merge( $paths, $this->flatten_payload_paths( $child, $child_prefix ) );
		}

		return array_values( array_unique( $paths ) );
	}


	/**
	 * PHP 7.4-compatible array_is_list().
	 *
	 * @param array<mixed> $value Value to inspect.
	 */
	private function is_list_array( array $value ): bool {
		$expected_key = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected_key ) {
				return false;
			}
			++$expected_key;
		}

		return true;
	}

	/**
	 * Determine whether a payload path is published or covered by a published open container.
	 *
	 * @param string   $path            Payload path.
	 * @param string[] $published_paths Field-tree leaf paths.
	 * @param string[] $container_paths Field-tree object/array container paths.
	 * @param string[] $open_containers Open-ended object/array paths.
	 */
	private function is_payload_path_covered_by_field_tree(
		string $path,
		array $published_paths,
		array $container_paths,
		array $open_containers
	): bool {
		if ( in_array( $path, $published_paths, true ) || in_array( $path, $container_paths, true ) ) {
			return true;
		}

		foreach ( $open_containers as $container_path ) {
			if ( 0 === strpos( $path, $container_path . '.' ) || 0 === strpos( $path, $container_path . '[]' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a field-tree path is represented by available payload samples.
	 *
	 * @param string   $path          Field-tree path.
	 * @param string[] $payload_paths Payload paths.
	 */
	private function is_field_tree_path_represented_by_payloads( string $path, array $payload_paths ): bool {
		if ( in_array( $path, $payload_paths, true ) ) {
			return true;
		}

		$segments = explode( '.', $path );
		while ( count( $segments ) > 1 ) {
			array_pop( $segments );
			$container = implode( '.', $segments );
			if ( '[]' === substr( $container, -2 ) && in_array( $container, $payload_paths, true ) ) {
				foreach ( $payload_paths as $payload_path ) {
					if ( 0 === strpos( $payload_path, $container . '.' ) ) {
						return false;
					}
				}
				return true;
			}
		}

		return false;
	}

	/**
	 * Test live and preview builders emit the same contract key sets.
	 */
	public function test_builders_emit_matching_contract_keys(): void {
		$order   = OrderHelper::create_order();
		$live    = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$preview = ( new Preview_Receipt_Builder() )->build();

		$this->assertSame( array_keys( $live ), array_keys( $preview ) );
		foreach ( array( 'order', 'store', 'cashier', 'customer', 'totals', 'tax', 'presentation_hints', 'fiscal' ) as $section ) {
			$live_keys    = array_keys( $live[ $section ] );
			$preview_keys = array_keys( $preview[ $section ] );
			sort( $live_keys );
			sort( $preview_keys );

			$this->assertSame(
				$live_keys,
				$preview_keys,
				sprintf( 'Live and preview receipt payloads must emit matching %s keys.', $section )
			);
		}

		$this->assertNotEmpty( $live['lines'], 'Live receipt sample must include lines.' );
		$this->assertNotEmpty( $preview['lines'], 'Preview receipt sample must include lines.' );

		$live_line_keys    = array_keys( $live['lines'][0] );
		$preview_line_keys = array_keys( $preview['lines'][0] );
		sort( $live_line_keys );
		sort( $preview_line_keys );

		$this->assertSame(
			$live_line_keys,
			$preview_line_keys,
			'Live and preview receipt payloads must emit matching line item keys.'
		);

		$this->assertSame( array_keys( $live['tax'] ), array_keys( $preview['tax'] ) );
		$this->assertSame( array_keys( $live['presentation_hints'] ), array_keys( $preview['presentation_hints'] ) );
		$this->assertArrayNotHasKey( 'display_tax', $live['presentation_hints'] );
		$this->assertArrayNotHasKey( 'display_tax', $preview['presentation_hints'] );
		$this->assertArrayNotHasKey( 'order_barcode_type', $live['presentation_hints'] );
		$this->assertArrayNotHasKey( 'order_barcode_type', $preview['presentation_hints'] );
	}

	/**
	 * Test redundant branchable tax booleans stay consistent with enums.
	 */
	public function test_tax_booleans_match_tax_enums(): void {
		$order   = OrderHelper::create_order();
		$payload = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$tax     = $payload['tax'];

		$this->assertSame( 'incl' === $tax['display'], $tax['display_incl'] );
		$this->assertSame( 'excl' === $tax['display'], $tax['display_excl'] );
		$this->assertSame( 1, count( array_filter( array( $tax['breakdown_hidden'], $tax['breakdown_single'], $tax['breakdown_itemized'] ) ) ) );
		$this->assertSame( 'hidden' === $tax['breakdown'], $tax['breakdown_hidden'] );
		$this->assertSame( 'single' === $tax['breakdown'], $tax['breakdown_single'] );
		$this->assertSame( 'itemized' === $tax['breakdown'], $tax['breakdown_itemized'] );
	}
}
