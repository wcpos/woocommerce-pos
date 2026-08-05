<?php
/**
 * Tests for the unified product augmentation pipeline.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variable;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Variable_Prices;
use WP_UnitTestCase;

/**
 * Augmentation pipeline tests.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline
 */
class Test_Augmentation_Pipeline extends WP_UnitTestCase {
	/**
	 * Remove every pipeline projection between tests.
	 */
	public function tearDown(): void {
		Augmentation_Pipeline::reset();
		remove_all_filters( Augmentation_Pipeline::PROXY_FILTER );
		remove_all_filters( Augmentation_Pipeline::SERIALIZED_FILTER );
		parent::tearDown();
	}

	/**
	 * Build a variable product whose children all carry the same known price.
	 *
	 * @param string $price Child regular/active price.
	 *
	 * @return WC_Product_Variable
	 */
	private function make_variable_product( string $price ): WC_Product_Variable {
		$product = ProductHelper::create_variation_product();
		foreach ( $product->get_children() as $child_id ) {
			$variation = new WC_Product_Variation( $child_id );
			$variation->set_regular_price( $price );
			$variation->set_price( $price );
			$variation->save();
		}

		return $product;
	}

	/**
	 * Read the injected variable-price meta entry from a record.
	 *
	 * @param array $record Serialized product record.
	 *
	 * @return null|array
	 */
	private function read_range( array $record ): ?array {
		foreach ( $record['meta_data'] ?? array() as $entry ) {
			if ( Variable_Prices::META_KEY === ( $entry['key'] ?? '' ) ) {
				return $entry['value'];
			}
		}

		return null;
	}

	/**
	 * One registration serves both public read lanes.
	 */
	public function test_pipeline_install_augments_both_read_lanes(): void {
		// Arrange.
		$variable = $this->make_variable_product( '31' );
		$record   = array(
			'id'         => $variable->get_id(),
			'type'       => 'variable',
			'price'      => '9',
			'meta_data'  => array(),
		);
		Augmentation_Pipeline::install();

		// Act.
		$proxied    = apply_filters( Augmentation_Pipeline::PROXY_FILTER, array( $record ), 'products', null );
		$serialized = apply_filters( Augmentation_Pipeline::SERIALIZED_FILTER, $record, $variable, null );

		// Assert.
		$this->assertEquals( '31', $this->read_range( $proxied[0] )['price']['min'] );
		$this->assertEquals( '31', $this->read_range( $serialized )['price']['min'] );
	}

	/**
	 * A single declaration runs exactly once per record on each lane.
	 */
	public function test_single_declaration_runs_once_per_record_on_each_lane(): void {
		// Arrange.
		$calls = 0;
		Augmentation_Pipeline::reset();
		Augmentation_Pipeline::add_record_augmenter(
			static function ( $payload, $object = null, $request = null ) use ( &$calls ) {
				++$calls;

				return $payload;
			}
		);
		Augmentation_Pipeline::wire();

		// Act.
		apply_filters( Augmentation_Pipeline::PROXY_FILTER, array( array( 'id' => 1 ), array( 'id' => 2 ) ), 'products', null );
		apply_filters( Augmentation_Pipeline::SERIALIZED_FILTER, array( 'id' => 1 ), null, null );

		// Assert: two proxied records + one serialized record.
		$this->assertEquals( 3, $calls );
	}

	/**
	 * Rewiring replaces projections instead of stacking them.
	 */
	public function test_rewiring_replaces_projections_instead_of_stacking(): void {
		// Arrange.
		$calls = 0;
		Augmentation_Pipeline::reset();
		Augmentation_Pipeline::add_record_augmenter(
			static function ( $payload, $object = null, $request = null ) use ( &$calls ) {
				++$calls;

				return $payload;
			}
		);
		Augmentation_Pipeline::wire();
		Augmentation_Pipeline::wire();

		// Act.
		apply_filters( Augmentation_Pipeline::SERIALIZED_FILTER, array( 'id' => 1 ), null, null );

		// Assert.
		$this->assertEquals( 1, $calls );
	}

	/**
	 * Record augmenters skip proxy resources they do not serve.
	 */
	public function test_pipeline_skips_unrelated_proxy_resources(): void {
		// Arrange.
		$calls = 0;
		Augmentation_Pipeline::reset();
		Augmentation_Pipeline::add_record_augmenter(
			static function ( $payload, $object = null, $request = null ) use ( &$calls ) {
				++$calls;

				return $payload;
			}
		);
		Augmentation_Pipeline::wire();

		// Act.
		apply_filters( Augmentation_Pipeline::PROXY_FILTER, array( array( 'id' => 1 ) ), 'orders', null );

		// Assert.
		$this->assertEquals( 0, $calls );
	}

	/**
	 * Both public filter names stay live for third-party hookers.
	 */
	public function test_public_filter_names_remain_available_to_external_hookers(): void {
		// Arrange.
		Augmentation_Pipeline::install();
		add_filter(
			Augmentation_Pipeline::PROXY_FILTER,
			static function ( $data, $resource = '', $request = null ) {
				$data[0]['external'] = 'proxy';

				return $data;
			},
			20,
			3
		);
		add_filter(
			Augmentation_Pipeline::SERIALIZED_FILTER,
			static function ( $payload, $object = null, $request = null ) {
				$payload['external'] = 'serialized';

				return $payload;
			},
			20,
			3
		);

		// Act.
		$proxied    = apply_filters( Augmentation_Pipeline::PROXY_FILTER, array( array( 'id' => 1 ) ), 'products', null );
		$serialized = apply_filters( Augmentation_Pipeline::SERIALIZED_FILTER, array( 'id' => 1 ), null, null );

		// Assert.
		$this->assertEquals( 'proxy', $proxied[0]['external'] );
		$this->assertEquals( 'serialized', $serialized['external'] );
	}

	/**
	 * Reset removes every projection the pipeline installed.
	 */
	public function test_pipeline_reset_removes_projections(): void {
		// Arrange.
		Augmentation_Pipeline::install();

		// Act.
		Augmentation_Pipeline::reset();
		$serialized = apply_filters(
			Augmentation_Pipeline::SERIALIZED_FILTER,
			array(
				'id'        => 1,
				'type'      => 'variable',
				'meta_data' => array(),
			),
			null,
			null
		);

		// Assert.
		$this->assertNull( $this->read_range( $serialized ) );
	}
}
