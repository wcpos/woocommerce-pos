<?php
/**
 * Tests for the filterable consent-prompt copy hook.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use ReflectionMethod;
use WCPOS\WooCommercePOS\Admin\Consent;
use WP_UnitTestCase;

/**
 * Verifies that `woocommerce_pos_consent_copy` filter results appear in
 * the `wcpos.consent` inline config object produced by Consent::inline_script().
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Consent_Copy_Filter extends WP_UnitTestCase {
	/**
	 * Call the private inline_script() method via reflection.
	 *
	 * @param Consent $consent Consent instance.
	 *
	 * @return string The inline JavaScript string.
	 */
	private function inline_script( Consent $consent ): string {
		$method = new ReflectionMethod( Consent::class, 'inline_script' );
		$method->setAccessible( true );

		return $method->invoke( $consent, 'index.php' );
	}

	/**
	 * With no filter registered the copy value must serialize as an empty
	 * JSON object ({}), not an empty array ([]).
	 */
	public function test_consent_config_with_no_filter_has_empty_copy_object(): void {
		$script = $this->inline_script( new Consent() );

		$this->assertStringContainsString( '"copy":{}', str_replace( ' ', '', $script ) );
	}

	/**
	 * Keys added via the filter must appear verbatim in the serialized config.
	 */
	public function test_consent_copy_filter_overrides_reach_the_inline_config(): void {
		add_filter(
			'woocommerce_pos_consent_copy',
			function ( array $copy ): array {
				$copy['title'] = 'Use my store numbers?';

				return $copy;
			}
		);

		$script = $this->inline_script( new Consent() );

		$this->assertStringContainsString( 'Use my store numbers?', $script );
	}
}
