<?php
/**
 * Declared order search carriers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Order_Search;
use WP_UnitTestCase;

/** Pins the human-readable order search declaration for future client exposure. */
class Test_Collection_Rules_Order_Search extends WP_UnitTestCase {
	/** Phone fragments retain digits and discard punctuation. */
	public function test_phone_digits_strips_punctuation_from_a_digit_term(): void {
		// Arrange.
		$local_term         = '(04) 1234-5678';
		$international_term = '+61.412';

		// Act.
		$local_digits         = Order_Search::phone_digits( $local_term );
		$international_digits = Order_Search::phone_digits( $international_term );

		// Assert.
		$this->assertSame( '0412345678', $local_digits );
		$this->assertSame( '61412', $international_digits );
	}

	/** Letters prevent a term from being a phone fragment. */
	public function test_phone_digits_rejects_a_term_with_letters(): void {
		// Arrange.
		$term = 'A12';

		// Act.
		$digits = Order_Search::phone_digits( $term );

		// Assert.
		$this->assertSame( '', $digits );
	}

	/** A phone fragment must contain at least one digit. */
	public function test_phone_digits_rejects_a_term_without_digits(): void {
		// Arrange.
		$term = '-+()';

		// Act.
		$digits = Order_Search::phone_digits( $term );

		// Assert.
		$this->assertSame( '', $digits );
	}

	/** Malformed UTF-8 is never treated as a phone fragment. */
	public function test_phone_digits_rejects_malformed_utf8(): void {
		// Arrange.
		$term = "\xff12";

		// Act.
		$digits = Order_Search::phone_digits( $term );

		// Assert.
		$this->assertSame( '', $digits );
	}

	/** Every searched field is represented in the carrier list. */
	public function test_order_search_declares_all_carriers(): void {
		$this->assertSame(
			array(
				'id',
				'billing_email',
				'first_name',
				'last_name',
				'company',
				'email',
				'phone',
				'shipping_first_name',
				'shipping_last_name',
				'shipping_company',
				'shipping_phone',
				'line_item_name',
				'line_item_sku',
				'number_meta',
			),
			Collection_Rules::rules( 'orders' )['search']['carriers']
		);
	}
}
