<?php
/**
 * Feature flag resolver for the wp-admin landing page.
 *
 * Resolves the `landing-variant` A/B experiment server-side so the value can
 * be injected into `window.wcpos.landing` and bootstrapped into the landing
 * bundle's PostHog client at first paint — no network round-trip, no flicker,
 * and no dependency on the PostHog `/flags` endpoint (which the self-hosted
 * proxy does not authenticate for the public project token).
 *
 * Assignment uses PostHog's standard consistent-hashing algorithm — the same
 * one the server SDKs use for local evaluation — so a given distinct id always
 * maps to the same variant, with the configured rollout split. This keeps the
 * recorded `$feature_flag_response` stable and the experiment denominators
 * valid (landing-experiments spec §5.1).
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Feature_Flags service.
 */
class Feature_Flags {
	/**
	 * The landing-page experiment flag key. Program-wide identifier shared with
	 * the wp-admin-landing bundle (`FLAG_KEY` in variant-loader.ts); do not rename.
	 *
	 * @var string
	 */
	const LANDING_FLAG_KEY = 'landing-variant';

	/**
	 * PostHog's hashing constant: 0xFFFFFFFFFFFFFFF (15 hex digits, 2^60 - 1).
	 * The first 15 hex digits of the SHA-1 are scaled by this to a [0, 1) float.
	 *
	 * @var int
	 */
	const LONG_SCALE = 0xFFFFFFFFFFFFFFF;

	/**
	 * Multivariate variants for `landing-variant`, in PostHog config order.
	 *
	 * The order and rollout percentages mirror the flag definition in PostHog so
	 * local assignment matches what `get_all_feature_flags()` resolves there.
	 * `free-plus` is the reference arm and the bundle's fallback variant.
	 *
	 * KEEP IN SYNC: this is the source of truth for assignment while the
	 * self-hosted `/flags` endpoint is unusable. If the flag's variants, order,
	 * or split change in the PostHog UI, update this AND the wp-admin-landing
	 * bundle (`VALID_VARIANTS` / `FALLBACK_VARIANT` in variant-loader.ts), or the
	 * experiment denominators will silently skew. See wcpos/wp-admin-landing#39.
	 *
	 * @var array<int, array{key: string, rollout: int}>
	 */
	const LANDING_VARIANTS = array(
		array(
			'key'     => 'indie',
			'rollout' => 50,
		),
		array(
			'key'     => 'free-plus',
			'rollout' => 50,
		),
	);

	/**
	 * Build the feature-flag bootstrap map for the landing page.
	 *
	 * Returns a map of resolved flags keyed for PostHog's `bootstrap.featureFlags`
	 * (e.g. `array( 'landing-variant' => 'indie' )`). Empty when no distinct id is
	 * available, in which case the bundle resolves the variant itself.
	 *
	 * @param string $distinct_id Stable anonymous identifier for the visitor/site.
	 *
	 * @return array<string, string>
	 */
	public function get_landing_bootstrap_flags( string $distinct_id ): array {
		$flags   = array();
		$variant = $this->get_landing_variant( $distinct_id );

		if ( null !== $variant ) {
			$flags[ self::LANDING_FLAG_KEY ] = $variant;
		}

		/**
		 * Filters the feature flags bootstrapped into the landing page.
		 *
		 * Allows forcing a variant (for QA) or extending the bootstrap with
		 * additional flags. Values must be strings or booleans, matching
		 * PostHog's `bootstrap.featureFlags` contract.
		 *
		 * @since 1.9.7
		 *
		 * @param array<string, string|bool> $flags       Resolved flag map.
		 * @param string                     $distinct_id The visitor distinct id.
		 */
		return apply_filters( 'woocommerce_pos_landing_bootstrap_flags', $flags, $distinct_id );
	}

	/**
	 * Resolve the `landing-variant` value for a distinct id.
	 *
	 * @param string $distinct_id Stable anonymous identifier.
	 *
	 * @return null|string The variant key, or null when no distinct id is given.
	 */
	public function get_landing_variant( string $distinct_id ): ?string {
		if ( '' === $distinct_id ) {
			return null;
		}

		return $this->match_variant( self::LANDING_FLAG_KEY, $distinct_id, self::LANDING_VARIANTS );
	}

	/**
	 * Map a distinct id to a variant using PostHog's consistent-hash lookup.
	 *
	 * The variant hash is salted with `variant` and compared against the
	 * cumulative rollout ranges built from the variant order.
	 *
	 * @param string                                       $key         Flag key.
	 * @param string                                       $distinct_id Distinct id.
	 * @param array<int, array{key: string, rollout: int}> $variants    Ordered variants.
	 *
	 * @return null|string
	 */
	private function match_variant( string $key, string $distinct_id, array $variants ): ?string {
		$hash_value = $this->hash( $key, $distinct_id, 'variant' );

		$value_min = 0.0;
		foreach ( $variants as $variant ) {
			$value_max = $value_min + ( $variant['rollout'] / 100 );
			if ( $hash_value >= $value_min && $hash_value < $value_max ) {
				return $variant['key'];
			}
			$value_min = $value_max;
		}

		return null;
	}

	/**
	 * PostHog consistent hash: SHA-1 of `key.distinct_id+salt`, first 15 hex
	 * digits scaled to a deterministic [0, 1) float.
	 *
	 * @param string $key         Flag key.
	 * @param string $distinct_id Distinct id.
	 * @param string $salt        Hash salt (`variant` for variant selection).
	 *
	 * @return float
	 */
	private function hash( string $key, string $distinct_id, string $salt = '' ): float {
		$hex = substr( sha1( $key . '.' . $distinct_id . $salt ), 0, 15 );

		return (float) ( hexdec( $hex ) / self::LONG_SCALE );
	}
}
