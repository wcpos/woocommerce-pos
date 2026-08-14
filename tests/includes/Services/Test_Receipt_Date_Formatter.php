<?php
/**
 * Tests for receipt date formatter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use DateTimeZone;
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
	 * Test formatter supports fixed-offset timezones without throwing.
	 */
	public function test_from_timestamp_returns_practical_display_fields_for_fixed_offset_timezone(): void {
		$date = Receipt_Date_Formatter::from_timestamp(
			strtotime( '2026-04-15 14:25:00 UTC' ),
			new DateTimeZone( '+00:00' )
		);

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

	/**
	 * Timestamp used across the time-format tests: 15:42 in Amsterdam (CEST, +02:00).
	 */
	private const SUMMER_TIMESTAMP = '2026-07-29 13:42:00 UTC';

	/**
	 * Fields that include a time component.
	 */
	private const TIME_FIELDS = array( 'datetime', 'time', 'datetime_short', 'datetime_long', 'datetime_full' );

	/**
	 * Assert no AM/PM day-period marker appears in any time-bearing field.
	 *
	 * @param array $date Formatted date fields.
	 */
	private function assert_no_day_period_marker( array $date ): void {
		foreach ( self::TIME_FIELDS as $field ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\d[\s\x{00A0}\x{202F}]*(am|pm|a\.\s?m\.|p\.\s?m\.)/iu',
				$date[ $field ],
				"Field {$field} should not contain an AM/PM marker: {$date[$field]}"
			);
		}
	}

	/**
	 * Test 24-hour time_format is respected with an IANA timezone and non-English locale.
	 */
	public function test_time_fields_respect_24_hour_time_format_with_iana_timezone(): void {
		update_option( 'time_format', 'H:i' );

		$date = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' ),
			'nl_NL'
		);

		$this->assertSame( '15:42', $date['time'] );
		$this->assertStringContainsString( '15:42', $date['datetime'] );
		$this->assert_no_day_period_marker( $date );
	}

	/**
	 * Test 24-hour time_format is respected with a fixed-offset timezone.
	 */
	public function test_time_fields_respect_24_hour_time_format_with_fixed_offset_timezone(): void {
		update_option( 'time_format', 'H:i' );

		$fixed = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( '+02:00' ),
			'nl_NL'
		);
		$iana  = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' ),
			'nl_NL'
		);

		$this->assertSame( '15:42', $fixed['time'] );
		$this->assert_no_day_period_marker( $fixed );
		// Equivalent offsets must produce the same clock convention and text
		// for fields that do not render a timezone name.
		$this->assertSame( $iana['time'], $fixed['time'] );
		$this->assertSame( $iana['datetime'], $fixed['datetime'] );
	}

	/**
	 * Test 24-hour time_format wins over a 12-hour store locale.
	 */
	public function test_time_fields_respect_24_hour_time_format_with_en_us_locale(): void {
		update_option( 'time_format', 'H:i' );

		$date = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' ),
			'en_US'
		);

		$this->assertSame( '15:42', $date['time'] );
		$this->assertStringContainsString( '15:42', $date['datetime'] );
		$this->assert_no_day_period_marker( $date );
	}

	/**
	 * Test 24-hour patterns adjust day-period spacing before punctuation.
	 *
	 * @dataProvider day_period_before_punctuation_provider
	 *
	 * @param string $pattern  ICU date/time pattern.
	 * @param string $expected Expected adjusted pattern.
	 */
	public function test_24_hour_pattern_with_day_period_before_punctuation_adjusts_spacing( string $pattern, string $expected ): void {
		// Arrange.
		$method = new \ReflectionMethod( Receipt_Date_Formatter::class, 'apply_clock_convention' );
		if ( 80100 > PHP_VERSION_ID ) {
			$method->setAccessible( true );
		}

		// Act.
		$actual = $method->invoke( null, $pattern, 'HH' );

		// Assert.
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Provide patterns with day-period markers before punctuation.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function day_period_before_punctuation_provider(): array {
		return array(
			'time-first glue pattern'                => array( 'h:mm a, d MMM', 'HH:mm, d MMM' ),
			'before closing parenthesis'             => array( '(h:mm a)', '(HH:mm)' ),
			'NBSP and NNBSP before comma'            => array( "h:mm\u{00A0}a\u{202F}, d MMM", 'HH:mm, d MMM' ),
			'before quoted ICU literal keeps space'  => array( "h:mm\u{202F}a 'baje'", "HH:mm 'baje'" ),
			'before opening punctuation keeps space' => array( 'h:mm a (z)', 'HH:mm (z)' ),
			'marker at end remains supported'        => array( 'd MMM, h:mm a', 'd MMM, HH:mm' ),
		);
	}

	/**
	 * Test a configured 12-hour time_format still produces AM/PM output.
	 */
	public function test_time_fields_respect_12_hour_time_format(): void {
		update_option( 'time_format', 'g:i A' );

		$date = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' ),
			'en_US'
		);

		$this->assertStringContainsString( '3:42', $date['time'] );
		$this->assertMatchesRegularExpression( '/pm/i', $date['time'] );
		$this->assertStringNotContainsString( '15:42', $date['datetime'] );
	}

	/**
	 * Test a 12-hour time_format converts a 24-hour locale to AM/PM.
	 */
	public function test_time_fields_respect_12_hour_time_format_with_24_hour_locale(): void {
		update_option( 'time_format', 'g:i A' );

		$date = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' ),
			'nl_NL'
		);

		$this->assertStringContainsString( '3:42', $date['time'] );
		$this->assertStringNotContainsString( '15:42', $date['time'] );
	}

	/**
	 * Test a forced 12-hour convention keeps the locale's day-period placement.
	 */
	public function test_12_hour_time_format_keeps_locale_day_period_placement(): void {
		update_option( 'time_format', 'g:i A' );

		$date = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' ),
			'zh_CN'
		);

		// Day-period placement must come from CLDR (zh_CN places the marker
		// before the hour when full locale data is present), so the forced
		// 12-hour output must match ICU's own native h12 rendering exactly
		// — not a marker appended at the end of the pattern.
		$native = new \IntlDateFormatter( 'zh_CN-u-hc-h12', \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, 'Europe/Amsterdam' );
		$this->assertStringContainsString( '3:42', $date['time'] );
		$this->assertSame( $native->format( strtotime( self::SUMMER_TIMESTAMP ) ), $date['time'] );
	}

	/**
	 * Test the hour-cycle keyword is added to an existing Unicode extension.
	 */
	public function test_12_hour_time_format_keeps_existing_unicode_extension(): void {
		update_option( 'time_format', 'g:i A' );

		$date = Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' ),
			'zh_CN-u-ca-gregory'
		);

		$native = new \IntlDateFormatter( 'zh_CN-u-ca-gregory-hc-h12', \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, 'Europe/Amsterdam' );
		$this->assertSame( $native->format( strtotime( self::SUMMER_TIMESTAMP ) ), $date['time'] );
	}

	/**
	 * Test the fallback path respects a 24-hour time_format when Intl is unavailable.
	 */
	public function test_fallback_respects_24_hour_time_format_when_intl_unavailable(): void {
		update_option( 'time_format', 'H:i' );

		$date = Intl_Disabled_Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' )
		);

		$this->assertSame( '15:42', $date['time'] );
		$this->assertSame( 'Jul 29, 2026 15:42', $date['datetime'] );
		$this->assert_no_day_period_marker( $date );
	}

	/**
	 * Test the fallback path respects a 12-hour time_format when Intl is unavailable.
	 */
	public function test_fallback_respects_12_hour_time_format_when_intl_unavailable(): void {
		update_option( 'time_format', 'g:i a' );

		$date = Intl_Disabled_Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' )
		);

		$this->assertSame( '3:42 pm', $date['time'] );
		$this->assertSame( 'Jul 29, 2026 3:42 pm', $date['datetime'] );
	}

	/**
	 * Test the fallback path respects time_format for a fixed-offset timezone.
	 */
	public function test_fallback_respects_24_hour_time_format_with_fixed_offset_timezone(): void {
		update_option( 'time_format', 'H:i' );

		$date = Intl_Disabled_Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( '+02:00' )
		);

		$this->assertSame( '15:42', $date['time'] );
		$this->assert_no_day_period_marker( $date );
	}

	/**
	 * Test the fallback path keeps the historical 12-hour default when time_format is empty.
	 */
	public function test_fallback_keeps_default_format_when_time_format_empty(): void {
		update_option( 'time_format', '' );

		$date = Intl_Disabled_Receipt_Date_Formatter::from_timestamp(
			strtotime( self::SUMMER_TIMESTAMP ),
			new DateTimeZone( 'Europe/Amsterdam' )
		);

		$this->assertSame( '3:42 PM', $date['time'] );
	}
}

/**
 * Test double that simulates a PHP environment without the Intl extension.
 *
 * @internal
 */
class Intl_Disabled_Receipt_Date_Formatter extends Receipt_Date_Formatter {
	/**
	 * Report Intl as unavailable so the fallback path runs.
	 *
	 * @return bool
	 */
	protected static function intl_available(): bool {
		return false;
	}
}
