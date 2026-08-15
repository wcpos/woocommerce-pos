<?php
/**
 * Tests for the Visibility Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Visibility_Section;
use WP_UnitTestCase;

/**
 * Test_Visibility_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Visibility_Section extends WP_UnitTestCase {
	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_visibility' );
		parent::tearDown();
	}

	/**
	 * Product POS-only and online-only settings are read directly by the section.
	 */
	public function test_product_visibility_settings_identify_pos_only_and_online_only_products(): void {
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array( 101 ) ),
						'online_only' => array( 'ids' => array( 202 ) ),
					),
				),
			)
		);

		$section = new Visibility_Section();

		$this->assertTrue( $section->is_product_pos_only( 101 ) );
		$this->assertFalse( $section->is_product_pos_only( 202 ) );
		$this->assertTrue( $section->is_product_online_only( 202 ) );
		$this->assertFalse( $section->is_product_online_only( 101 ) );
	}

	/**
	 * Variation POS-only and online-only settings are read directly by the section.
	 */
	public function test_variation_visibility_settings_identify_pos_only_and_online_only_variations(): void {
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'variations' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array( 303 ) ),
						'online_only' => array( 'ids' => array( 404 ) ),
					),
				),
			)
		);

		$section = new Visibility_Section();

		$this->assertTrue( $section->is_variation_pos_only( 303 ) );
		$this->assertFalse( $section->is_variation_pos_only( 404 ) );
		$this->assertTrue( $section->is_variation_online_only( 404 ) );
		$this->assertFalse( $section->is_variation_online_only( 303 ) );
	}

	/**
	 * Updating visibility adds an ID and an empty visibility removes it.
	 */
	public function test_update_visibility_settings_adds_and_removes_an_id(): void {
		$section = new Visibility_Section();
		$section->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'ids'        => array( 505 ),
				'visibility' => 'pos_only',
			)
		);

		$this->assertSame( array( 505 ), $section->get_pos_only_product_visibility_settings()['ids'] );

		$section->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'ids'        => array( 505 ),
				'visibility' => '',
			)
		);

		$this->assertSame( array(), $section->get_pos_only_product_visibility_settings()['ids'] );
		$this->assertSame( array(), $section->get_online_only_product_visibility_settings()['ids'] );
	}
}
