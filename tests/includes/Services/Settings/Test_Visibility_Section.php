<?php
/**
 * Tests for the Visibility Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Services\Settings\Abstract_Section;
use WCPOS\WooCommercePOS\Services\Settings\Visibility_Section;
use WP_Error;
use WP_UnitTestCase;

/**
 * Generic visibility override with no visibility-specific methods.
 */
class Visibility_Override_Fixture_Section extends Abstract_Section {
	/** @var array */
	private $settings = array(
		'products'   => array(
			'default' => array(
				'pos_only'    => array( 'ids' => array( 101 ) ),
				'online_only' => array( 'ids' => array( 202 ) ),
			),
		),
		'variations' => array(
			'default' => array(
				'pos_only'    => array( 'ids' => array( 303 ) ),
				'online_only' => array( 'ids' => array( 404 ) ),
			),
		),
	);

	/** {@inheritDoc} */
	public function id(): string {
		return 'visibility';
	}

	/** {@inheritDoc} */
	public function defaults(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function read(): array {
		return $this->settings;
	}

	/** {@inheritDoc} */
	public function write( array $settings ) {
		$this->settings = $settings;

		return $this->settings;
	}
}

/**
 * Test_Visibility_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Visibility_Section extends WP_UnitTestCase {
	/** @var null|callable */
	private $register_override;

	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		if ( null !== $this->register_override ) {
			remove_action( 'woocommerce_pos_register_settings_sections', $this->register_override );
			$this->register_override = null;
		}
		Settings::instance()->reset_sections_for_testing();
		delete_option( 'woocommerce_pos_settings_visibility' );
		parent::tearDown();
	}

	/**
	 * Every legacy facade remains compatible with a generic registry override.
	 */
	public function test_visibility_facades_use_generic_registry_override_read_and_write(): void {
		$override                = new Visibility_Override_Fixture_Section();
		$this->register_override = static function ( $registry ) use ( $override ): void {
			$registry->register( $override );
		};
		add_action( 'woocommerce_pos_register_settings_sections', $this->register_override );

		$settings = Settings::instance();
		$settings->reset_sections_for_testing();

		$this->assertSame( $override->read(), $settings->get_visibility_settings() );
		$this->assertSame( $override->read()['products']['default'], $settings->get_product_visibility_settings() );
		$this->assertSame( array( 'ids' => array( 101 ) ), $settings->get_pos_only_product_visibility_settings() );
		$this->assertSame( array( 'ids' => array( 202 ) ), $settings->get_online_only_product_visibility_settings() );
		$this->assertSame( $override->read()['variations']['default'], $settings->get_variations_visibility_settings() );
		$this->assertSame( array( 'ids' => array( 303 ) ), $settings->get_pos_only_variations_visibility_settings() );
		$this->assertSame( array( 'ids' => array( 404 ) ), $settings->get_online_only_variations_visibility_settings() );
		$this->assertTrue( $settings->is_product_pos_only( 101 ) );
		$this->assertTrue( $settings->is_product_online_only( 202 ) );
		$this->assertTrue( $settings->is_variation_pos_only( 303 ) );
		$this->assertTrue( $settings->is_variation_online_only( 404 ) );

		$result = $settings->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'ids'        => array( 505 ),
				'visibility' => 'pos_only',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 101, 505 ), $override->read()['products']['default']['pos_only']['ids'] );
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

	/**
	 * Updating a new scope initializes it from the default visibility shape.
	 */
	public function test_update_visibility_settings_initializes_a_missing_scope(): void {
		$section = new Visibility_Section();

		$result = $section->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'scope'      => 'store-2',
				'ids'        => array( 606 ),
				'visibility' => 'pos_only',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 606 ), $section->get_pos_only_product_visibility_settings( 'store-2' )['ids'] );
		$this->assertSame( array(), $section->get_online_only_product_visibility_settings( 'store-2' )['ids'] );
	}

	/**
	 * Updating an unsupported post type returns the existing invalid-arguments error.
	 */
	public function test_update_visibility_settings_rejects_an_unsupported_post_type(): void {
		$section = new Visibility_Section();

		$result = $section->update_visibility_settings(
			array(
				'post_type'  => 'orders',
				'ids'        => array( 707 ),
				'visibility' => 'pos_only',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_settings_error', $result->get_error_code() );
	}
}
