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
			'store-2' => array(
				'pos_only'    => array( 'ids' => array( 111 ) ),
				'online_only' => array( 'ids' => array( 222 ) ),
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
			remove_action( 'wcpos_register_settings_sections', $this->register_override );
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
		add_action( 'wcpos_register_settings_sections', $this->register_override );

		$settings = Settings::instance();
		$settings->reset_sections_for_testing();

		$this->assertSame( $override->read(), $settings->get_visibility_settings() );
		$this->assertSame( $override->read()['products']['default'], $settings->get_product_visibility_settings() );
		$this->assertSame( $override->read()['products']['store-2'], $settings->get_product_visibility_settings( 'store-2' ) );
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

		$result = $settings->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'scope'      => 'store-2',
				'ids'        => array( 606 ),
				'visibility' => 'pos_only',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 111, 606 ), $override->read()['products']['store-2']['pos_only']['ids'] );
		$this->assertSame( array( 222 ), $override->read()['products']['store-2']['online_only']['ids'] );
		$this->assertSame( array( 101, 505 ), $override->read()['products']['default']['pos_only']['ids'] );
		$this->assertSame( array( 202 ), $override->read()['products']['default']['online_only']['ids'] );
		$this->assertSame( array( 303 ), $override->read()['variations']['default']['pos_only']['ids'] );
		$this->assertSame( array( 404 ), $override->read()['variations']['default']['online_only']['ids'] );
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

	/**
	 * Repeated lookups unserialize the option once per request, not once per call.
	 */
	public function test_is_variation_pos_only_repeated_lookups_read_the_option_once(): void {
		// Arrange.
		$this->seed_visibility( array(), array(), array( 11, 12 ), array() );
		$reads = 0;
		add_filter(
			'option_woocommerce_pos_settings_visibility',
			static function ( $value ) use ( &$reads ) {
				++$reads;
				return $value;
			}
		);
		$settings = Settings::instance();

		// Act.
		$results = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$results[] = $settings->is_variation_pos_only( array( 11, '12', 13, 11 )[ $i % 4 ] );
		}

		// Assert.
		$this->assertSame( array_merge( ...array_fill( 0, 5, array( true, true, false, true ) ) ), $results );
		$this->assertSame( 1, $reads );
	}

	/**
	 * An update_option between two lookups is seen by the second lookup.
	 */
	public function test_is_variation_pos_only_sees_update_option_between_lookups(): void {
		// Arrange.
		$this->seed_visibility( array(), array(), array( 11 ), array() );
		$settings = Settings::instance();
		$this->assertFalse( $settings->is_variation_pos_only( 13 ) );

		// Act.
		$this->seed_visibility( array(), array(), array( 11, 13 ), array() );

		// Assert.
		$this->assertTrue( $settings->is_variation_pos_only( 13 ) );
	}

	/**
	 * A delete_option and an add_option between lookups are both seen.
	 */
	public function test_is_product_pos_only_sees_delete_and_add_option_between_lookups(): void {
		// Arrange.
		$this->seed_visibility( array( 21 ), array(), array(), array() );
		$settings = Settings::instance();
		$this->assertTrue( $settings->is_product_pos_only( 21 ) );

		// Act / Assert.
		delete_option( 'woocommerce_pos_settings_visibility' );
		$this->assertFalse( $settings->is_product_pos_only( 21 ) );

		add_option( 'woocommerce_pos_settings_visibility', $this->visibility_settings( array( 21 ), array(), array(), array() ), '', false );
		$this->assertTrue( $settings->is_product_pos_only( 21 ) );
	}

	/**
	 * A filter added after the first lookup applies, and stops applying once removed.
	 */
	public function test_is_variation_pos_only_filter_added_after_first_lookup_applies(): void {
		// Arrange.
		$this->seed_visibility( array(), array(), array( 11 ), array() );
		$settings = Settings::instance();
		$this->assertFalse( $settings->is_variation_pos_only( 31 ) );
		$append = static function ( $visibility ) {
			$visibility['ids'][] = 31;
			return $visibility;
		};

		// Act / Assert.
		add_filter( 'wcpos_pos_only_variations_visibility_settings', $append );
		$this->assertTrue( $settings->is_variation_pos_only( 31 ) );

		remove_filter( 'wcpos_pos_only_variations_visibility_settings', $append );
		$this->assertFalse( $settings->is_variation_pos_only( 31 ) );
	}

	/**
	 * Repeated online-only lookups give the same answers as the old in_array check.
	 */
	public function test_is_product_online_only_repeated_lookups_match_in_array(): void {
		// Arrange.
		$this->seed_visibility( array(), array( '41', 42 ), array(), array() );
		$settings = Settings::instance();

		// Act / Assert.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertTrue( $settings->is_product_online_only( 41 ) );
			$this->assertTrue( $settings->is_product_online_only( '42' ) );
			$this->assertFalse( $settings->is_product_online_only( 43 ) );
			$this->assertFalse( $settings->is_product_online_only( 0 ) );
		}
	}

	/**
	 * Seed the full visibility settings option.
	 *
	 * @param array $product_pos      Product POS only ids.
	 * @param array $product_online   Product online only ids.
	 * @param array $variation_pos    Variation POS only ids.
	 * @param array $variation_online Variation online only ids.
	 */
	private function seed_visibility( array $product_pos, array $product_online, array $variation_pos, array $variation_online ): void {
		update_option( 'woocommerce_pos_settings_visibility', $this->visibility_settings( $product_pos, $product_online, $variation_pos, $variation_online ), false );
	}

	/**
	 * Build a full visibility settings array.
	 *
	 * @param array $product_pos      Product POS only ids.
	 * @param array $product_online   Product online only ids.
	 * @param array $variation_pos    Variation POS only ids.
	 * @param array $variation_online Variation online only ids.
	 */
	private function visibility_settings( array $product_pos, array $product_online, array $variation_pos, array $variation_online ): array {
		return array(
			'products'   => array(
				'default' => array(
					'pos_only'    => array( 'ids' => $product_pos ),
					'online_only' => array( 'ids' => $product_online ),
				),
			),
			'variations' => array(
				'default' => array(
					'pos_only'    => array( 'ids' => $variation_pos ),
					'online_only' => array( 'ids' => $variation_online ),
				),
			),
		);
	}
}
