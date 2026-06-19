<?php
/**
 * Tests for the Feature_Flags service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Feature_Flags;
use WP_UnitTestCase;

/**
 * Tests the landing-variant feature flag resolver.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Feature_Flags
 */
class Test_Feature_Flags extends WP_UnitTestCase {
	/**
	 * The resolver under test.
	 *
	 * @var Feature_Flags
	 */
	private $flags;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->flags = new Feature_Flags();
	}

	/**
	 * The same distinct id must always resolve to the same variant.
	 */
	public function test_get_landing_variant_is_deterministic_for_a_given_id(): void {
		$id = '8c2f7e10-1234-4abc-89de-0123456789ab';

		$this->assertSame(
			$this->flags->get_landing_variant( $id ),
			$this->flags->get_landing_variant( $id )
		);
	}

	/**
	 * Local assignment must match PostHog's consistent-hash algorithm, so that
	 * `get_all_feature_flags()` (server-side local eval) agrees with the
	 * bootstrapped value. Vectors generated from the reference implementation.
	 */
	public function test_get_landing_variant_matches_posthog_reference_vectors(): void {
		$vectors = array(
			'a1b2c3d4-0000-4000-8000-000000000001' => 'indie',
			'11111111-1111-4111-8111-111111111111' => 'free-plus',
			'test-distinct-id'                      => 'indie',
			'wcpos'                                 => 'free-plus',
			'00000000-0000-4000-8000-000000000000' => 'free-plus',
			'f47ac10b-58cc-4372-a567-0e02b2c3d479' => 'free-plus',
		);

		foreach ( $vectors as $distinct_id => $expected ) {
			$this->assertSame(
				$expected,
				$this->flags->get_landing_variant( $distinct_id ),
				"Variant mismatch for distinct id {$distinct_id}"
			);
		}
	}

	/**
	 * The flag is only ever assigned one of the two configured variants.
	 */
	public function test_get_landing_variant_only_returns_configured_variants(): void {
		for ( $i = 0; $i < 100; $i++ ) {
			$variant = $this->flags->get_landing_variant( wp_generate_uuid4() );
			$this->assertContains( $variant, array( 'indie', 'free-plus' ) );
		}
	}

	/**
	 * Traffic must split roughly 50/50 across many anonymous visitors.
	 */
	public function test_get_landing_variant_splits_traffic_roughly_evenly(): void {
		$counts = array(
			'indie'     => 0,
			'free-plus' => 0,
		);

		$total = 2000;
		for ( $i = 0; $i < $total; $i++ ) {
			$variant = $this->flags->get_landing_variant( wp_generate_uuid4() );
			++$counts[ $variant ];
		}

		// Generous bounds (40%–60%) so the assertion never flakes while still
		// catching a broken split (e.g. everyone landing on one arm).
		$this->assertGreaterThan( $total * 0.4, $counts['indie'], 'indie share too low' );
		$this->assertLessThan( $total * 0.6, $counts['indie'], 'indie share too high' );
		$this->assertSame( $total, $counts['indie'] + $counts['free-plus'] );
	}

	/**
	 * An empty distinct id yields no assignment (the bundle resolves it instead).
	 */
	public function test_get_landing_variant_returns_null_for_empty_id(): void {
		$this->assertNull( $this->flags->get_landing_variant( '' ) );
	}

	/**
	 * The bootstrap map carries the landing-variant flag keyed for PostHog.
	 */
	public function test_get_landing_bootstrap_flags_contains_landing_variant(): void {
		$flags = $this->flags->get_landing_bootstrap_flags( 'test-distinct-id' );

		$this->assertArrayHasKey( 'landing-variant', $flags );
		$this->assertSame( 'indie', $flags['landing-variant'] );
	}

	/**
	 * No distinct id means an empty bootstrap map (no bootstrap injected).
	 */
	public function test_get_landing_bootstrap_flags_is_empty_for_empty_id(): void {
		$this->assertSame( array(), $this->flags->get_landing_bootstrap_flags( '' ) );
	}

	/**
	 * The bootstrap map is filterable for QA / kill-switch overrides.
	 */
	public function test_bootstrap_flags_filter_can_override_variant(): void {
		$override = static function (): array {
			return array( 'landing-variant' => 'free-plus' );
		};
		add_filter( 'woocommerce_pos_landing_bootstrap_flags', $override );

		try {
			$flags = $this->flags->get_landing_bootstrap_flags( 'test-distinct-id' );
		} finally {
			remove_filter( 'woocommerce_pos_landing_bootstrap_flags', $override );
		}

		$this->assertSame( 'free-plus', $flags['landing-variant'] );
	}
}
