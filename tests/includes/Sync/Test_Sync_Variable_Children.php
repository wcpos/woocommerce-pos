<?php
/**
 * Parent/child agreement on which variations exist.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WCPOS\WooCommercePOS\Sync\Variable_Children;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * `product.variations[]` must advertise exactly what the POS can sell.
 *
 * It is the client's only discovery list — the row expansion, the variations popover, the
 * "Showing N of M" footer and the prefetch walk all read it and nothing else. Every other component
 * answering "which children exist" already narrows the set, so a parent that does not makes the
 * client's "missing variation" signal meaningless.
 */
class Test_Sync_Variable_Children extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Wire the augmentations the way production does.
	 *
	 * `Init::init_common()` installs the pipeline behind the schema latch; a test that serializes
	 * without it asserts a payload no deployed client receives (see #1717). These tests are about
	 * what the client actually gets, so the pipeline is installed and torn down here.
	 */
	public function setUp(): void {
		parent::setUp();
		Augmentation_Pipeline::install();
	}

	/**
	 * Remove the augmentations again so the next class starts clean.
	 */
	public function tearDown(): void {
		Augmentation_Pipeline::reset();
		parent::tearDown();
	}

	/**
	 * Hide a variation from the POS.
	 *
	 * @param int $variation_id Variation to hide.
	 */
	private function hide_from_pos( int $variation_id ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'variations' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $variation_id ) ),
					),
				),
			)
		);
	}

	/**
	 * The served child list for a product id.
	 *
	 * @param int $product_id Variable product id.
	 *
	 * @return int[]
	 */
	private function served_children( int $product_id ): array {
		$payload = ( new Product_Serializer() )->serialize( $product_id );

		return array_map( 'intval', $payload['variations'] ?? array() );
	}

	/**
	 * A POS-hidden child is not advertised on its parent.
	 */
	public function test_hidden_variation_is_dropped_from_the_parent_child_list(): void {
		$product  = ProductHelper::create_variation_product();
		$children = array_map( 'intval', $product->get_children() );
		$hidden   = $children[0];
		$this->hide_from_pos( $hidden );

		$served = $this->served_children( $product->get_id() );

		$this->assertNotContains( $hidden, $served, 'A POS-hidden variation must not be advertised.' );
		$this->assertNotEmpty( $served, 'Only the hidden child should have been removed.' );
		foreach ( array_slice( $children, 1 ) as $visible ) {
			$this->assertContains( $visible, $served );
		}
	}

	/**
	 * A DISABLED child is not advertised on its parent.
	 *
	 * "Disabled" is WooCommerce's Enabled checkbox, which writes `post_status = private`. The
	 * variations endpoint already refuses to hydrate one, so leaving it here would advertise a row
	 * the cashier can never reach — and the footer would count it forever.
	 */
	public function test_disabled_variation_is_dropped_from_the_parent_child_list(): void {
		$product  = ProductHelper::create_variation_product();
		$children = array_map( 'intval', $product->get_children() );
		$disabled = $children[0];
		$variation = wc_get_product( $disabled );
		$variation->set_status( 'private' );
		$variation->save();

		$served = $this->served_children( $product->get_id() );

		$this->assertNotContains( $disabled, $served, 'A disabled variation must not be advertised.' );
		$this->assertContains( $children[1], $served );
	}

	/**
	 * A product with nothing hidden or disabled is untouched.
	 */
	public function test_untouched_product_advertises_every_child(): void {
		$product  = ProductHelper::create_variation_product();
		$children = array_map( 'intval', $product->get_children() );

		$this->assertSame( $children, $this->served_children( $product->get_id() ) );
	}

	/**
	 * A simple product carries no child list and is not perturbed.
	 */
	public function test_simple_product_passes_through(): void {
		$payload = array(
			'type'  => 'simple',
			'price' => '10',
		);

		$this->assertSame( $payload, Variable_Children::augment_record( $payload ) );
	}

	/**
	 * The narrowed list must stay WRITE-SAFE.
	 *
	 * A client's product update layers its resident stored payload, so the narrowed array travels
	 * back to wc/v3. It is only harmless because WooCommerce declares `variations` read-only — if
	 * that ever changed, omitting a child would delete it. This pins the property the filter relies
	 * on rather than the filter itself.
	 */
	public function test_woocommerce_still_declares_the_child_list_read_only(): void {
		$schema = ( new \WC_REST_Products_Controller() )->get_public_item_schema();

		$this->assertTrue(
			! empty( $schema['properties']['variations']['readonly'] ),
			'variations must stay readonly in the wc/v3 product schema, or a narrowed list could delete children on write.'
		);
	}
}
