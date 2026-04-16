<?php
/**
 * Tests for receipt date formatter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Date_Formatter;
use WP_UnitTestCase;

/**
 * Test_Receipt_Date_Formatter class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Date_Formatter extends WP_UnitTestCase {
	/**
	 * Test formatter returns rich display fields for a timestamp.
	 */
	public function test_from_timestamp_returns_practical_display_fields(): void {
		$date = Receipt_Date_Formatter::from_timestamp( strtotime( '2026-04-15 14:25:00 UTC' ) );

		foreach ( array( 'datetime', 'datetime_short', 'datetime_long', 'datetime_full', 'date', 'date_short', 'date_long', 'date_full', 'date_ymd', 'date_dmy', 'date_mdy', 'weekday_short', 'weekday_long', 'month_short', 'month_long', 'year' ) as $field ) {
			$this->assertArrayHasKey( $field, $date );
			$this->assertIsString( $date[ $field ] );
			$this->assertNotSame( '', $date[ $field ] );
		}
	}

	/**
	 * Test empty formatter returns blank strings for every field.
	 */
	public function test_empty_returns_blank_strings_for_all_fields(): void {
		$date = Receipt_Date_Formatter::empty();

		foreach ( $date as $value ) {
			$this->assertSame( '', $value );
		}
	}
}
