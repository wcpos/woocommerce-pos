<?php
/**
 * Tests for order-note display-name resolution.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Order_Notes;
use WCPOS\WooCommercePOS\Services\Store_Defaults;
use WP_UnitTestCase;

/** Test order-note label resolution. */
final class Test_Order_Notes extends WP_UnitTestCase {
	/** Test user display names and missing-user fallbacks. */
	public function test_cashier_and_customer_names_use_users_and_fallbacks(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Resolved User' ) );

		$this->assertSame( 'Resolved User', Order_Notes::cashier_name( $user_id ) );
		$this->assertSame( 'Unknown', Order_Notes::cashier_name( 999999 ) );
		$this->assertSame( 'Guest', Order_Notes::customer_name( 0 ) );
		$this->assertSame( 'Resolved User', Order_Notes::customer_name( $user_id ) );
		$this->assertSame( '#999999', Order_Notes::customer_name( 999999 ) );
	}

	/** Test free, missing, Pro-post, and string store identifiers. */
	public function test_store_name_resolves_default_missing_and_store_post(): void {
		$store_id = self::factory()->post->create(
			array(
				'post_type'  => 'wcpos_store',
				'post_title' => 'Named Store',
			)
		);

		$this->assertSame( Store_Defaults::name(), Order_Notes::store_name( 0 ) );
		$this->assertSame( 'Store #999999', Order_Notes::store_name( 999999 ) );
		$this->assertSame( 'Named Store', Order_Notes::store_name( $store_id ) );
		$this->assertSame( 'store-uuid', Order_Notes::store_name( 'store-uuid' ) );
	}
}
