<?php

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\Templates;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Templates extends WP_UnitTestCase {

	public function test_template_redirect() {
		$plugin = new Templates();

		// Test when URL matches checkout slug and order-pay query var is present.
		$this->go_to( '/wcpos-checkout/order-pay/123/' );
		ob_start();
		$plugin->template_redirect();
		$output = ob_get_clean();
		$this->assertEmpty( $output );

		// Test when URL matches checkout slug and order-received query var is present.
		$this->go_to( '/wcpos-checkout/order-received/456/' );
		ob_start();
		$plugin->template_redirect();
		$output = ob_get_clean();
		$this->assertEmpty( $output );

		// Test when URL matches checkout slug and wcpos-receipt query var is present.
		$this->go_to( '/wcpos-checkout/wcpos-receipt/789/' );
		ob_start();
		$plugin->template_redirect();
		$output = ob_get_clean();
		$this->assertEmpty( $output );

		// Test when URL matches checkout slug and no query vars are present.
		$this->go_to( '/wcpos-checkout/' );
		ob_start();
		$plugin->template_redirect();
		$output = ob_get_clean();
		$this->assertContains( 'Template not found.', $output );

		// Test when URL matches pos slug.
		$this->go_to( '/pos/' );
		ob_start();
		$plugin->template_redirect();
		$output = ob_get_clean();
		$this->assertEmpty( $output );
	}

}
