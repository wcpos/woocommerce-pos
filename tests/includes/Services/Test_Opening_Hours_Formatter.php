<?php
/**
 * Tests for Opening_Hours_Formatter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use DateTimeZone;
use WCPOS\WooCommercePOS\Services\Opening_Hours_Formatter;
use WCPOS\WooCommercePOS\Services\Receipt_Date_Formatter;
use WP_UnitTestCase;
use const WCPOS\WooCommercePOS\TRANSLATION_VERSION;

/**
 * @internal
 * @coversNothing
 */
class Test_Opening_Hours_Formatter extends WP_UnitTestCase {

	/**
	 * Standard hours: Mon-Fri 9-5, Sat 10-4, Sun closed.
	 */
	private function get_standard_hours(): array {
		return array(
			'0' => array( '09:00', '17:00' ),
			'1' => array( '09:00', '17:00' ),
			'2' => array( '09:00', '17:00' ),
			'3' => array( '09:00', '17:00' ),
			'4' => array( '09:00', '17:00' ),
			'5' => array( '10:00', '16:00' ),
			'6' => array(),
		);
	}

	/**
	 * Multi-slot hours: Mon-Fri with lunch break, Sat half-day, Sun closed.
	 */
	private function get_multi_slot_hours(): array {
		return array(
			'0' => array( '09:00', '12:00', '13:00', '17:00' ),
			'1' => array( '09:00', '12:00', '13:00', '17:00' ),
			'2' => array( '09:00', '12:00', '13:00', '17:00' ),
			'3' => array( '09:00', '12:00', '13:00', '17:00' ),
			'4' => array( '09:00', '12:00', '13:00', '17:00' ),
			'5' => array( '10:00', '14:00' ),
			'6' => array(),
		);
	}

	/**
	 * All days closed.
	 */
	private function get_all_closed(): array {
		return array(
			'0' => array(),
			'1' => array(),
			'2' => array(),
			'3' => array(),
			'4' => array(),
			'5' => array(),
			'6' => array(),
		);
	}

	// ── format_vertical ──────────────────────────────────────────────

	public function test_format_vertical_standard_hours(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_vertical( $this->get_standard_hours() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		$this->assertStringContainsString( 'Mon', $lines[0] );
		$this->assertContainsTime( '9:00 AM', $lines[0] );
		$this->assertContainsTime( '5:00 PM', $lines[0] );
		$this->assertStringContainsString( 'Sat', $lines[5] );
		$this->assertContainsTime( '10:00 AM', $lines[5] );
		$this->assertContainsTime( '4:00 PM', $lines[5] );
		$this->assertStringContainsString( 'Sun', $lines[6] );
		$this->assertStringContainsString( 'Closed', $lines[6] );
	}

	public function test_format_vertical_24h(): void {
		update_option( 'time_format', 'H:i' );
		$result = Opening_Hours_Formatter::format_vertical( $this->get_standard_hours() );
		$lines  = explode( "\n", $result );

		$this->assertStringContainsString( '09:00', $lines[0] );
		$this->assertStringContainsString( '17:00', $lines[0] );
		$this->assertStringNotContainsString( 'AM', $lines[0] );
	}

	public function test_format_vertical_multi_slot(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_vertical( $this->get_multi_slot_hours() );
		$lines  = explode( "\n", $result );

		// Mon should show two time ranges separated by comma.
		$this->assertContainsTime( '9:00 AM', $lines[0] );
		$this->assertContainsTime( '12:00 PM', $lines[0] );
		$this->assertContainsTime( '1:00 PM', $lines[0] );
		$this->assertContainsTime( '5:00 PM', $lines[0] );
	}

	public function test_format_vertical_all_closed(): void {
		$result = Opening_Hours_Formatter::format_vertical( $this->get_all_closed() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		foreach ( $lines as $line ) {
			$this->assertStringContainsString( 'Closed', $line );
		}
	}

	public function test_format_vertical_missing_days_show_closed(): void {
		// Only Monday set.
		$hours  = array( '0' => array( '09:00', '17:00' ) );
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_vertical( $hours );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		$this->assertContainsTime( '9:00 AM', $lines[0] );
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertStringContainsString( 'Closed', $lines[ $i ] );
		}
	}

	// ── format_compact ───────────────────────────────────────────────

	public function test_format_compact_groups_identical_consecutive_days(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_compact( $this->get_standard_hours() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 3, $lines );
		$this->assertStringStartsWith( 'Mon–Fri', $lines[0] );
		$this->assertContainsTime( '9:00 AM', $lines[0] );
		$this->assertStringStartsWith( 'Sat', $lines[1] );
		$this->assertStringStartsWith( 'Sun', $lines[2] );
		$this->assertStringContainsString( 'Closed', $lines[2] );
	}

	public function test_format_compact_all_same_hours(): void {
		update_option( 'time_format', 'g:i A' );
		$hours = array_fill( 0, 7, array( '09:00', '17:00' ) );
		// Convert to string keys.
		$hours_keyed = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$hours_keyed[ (string) $i ] = $hours[ $i ];
		}
		$result = Opening_Hours_Formatter::format_compact( $hours_keyed );
		$lines  = explode( "\n", $result );

		$this->assertCount( 1, $lines );
		$this->assertStringStartsWith( 'Mon–Sun', $lines[0] );
	}

	public function test_format_compact_single_day_not_ranged(): void {
		update_option( 'time_format', 'g:i A' );
		// Each day has different hours.
		$hours = array(
			'0' => array( '09:00', '17:00' ),
			'1' => array( '10:00', '18:00' ),
			'2' => array( '11:00', '19:00' ),
			'3' => array( '09:00', '17:00' ),
			'4' => array( '10:00', '18:00' ),
			'5' => array( '11:00', '19:00' ),
			'6' => array(),
		);
		$result = Opening_Hours_Formatter::format_compact( $hours );
		$lines  = explode( "\n", $result );

		// No ranges — each day standalone (no en-dash in day names).
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( '–', explode( ' ', $line )[0] );
		}
	}

	public function test_format_compact_multi_slot(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_compact( $this->get_multi_slot_hours() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 3, $lines );
		$this->assertStringStartsWith( 'Mon–Fri', $lines[0] );
		// Should contain both time ranges.
		$this->assertContainsTime( '12:00 PM', $lines[0] );
		$this->assertContainsTime( '1:00 PM', $lines[0] );
	}

	// ── format_inline ────────────────────────────────────────────────

	public function test_format_inline_standard_hours(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_inline( $this->get_standard_hours() );

		// Single line, no newlines.
		$this->assertStringNotContainsString( "\n", $result );
		// Contains comma separators.
		$this->assertStringContainsString( ', ', $result );
		$this->assertStringContainsString( 'Mon–Fri', $result );
		$this->assertStringContainsString( 'Sun Closed', $result );
	}

	// ── empty input ──────────────────────────────────────────────────

	public function test_format_vertical_empty_array(): void {
		$result = Opening_Hours_Formatter::format_vertical( array() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		foreach ( $lines as $line ) {
			$this->assertStringContainsString( 'Closed', $line );
		}
	}

	public function test_format_compact_empty_array(): void {
		$result = Opening_Hours_Formatter::format_compact( array() );
		$lines  = explode( "\n", $result );

		// All closed should group into one line.
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'Mon–Sun', $lines[0] );
		$this->assertStringContainsString( 'Closed', $lines[0] );
	}

	public function test_format_inline_empty_array(): void {
		$result = Opening_Hours_Formatter::format_inline( array() );
		$this->assertStringContainsString( 'Mon–Sun Closed', $result );
	}

	/**
	 * Assert a clock time appears, tolerating the CLDR no-break separator
	 * between the hour and the day period.
	 *
	 * @param string $expected Time as written with a plain space.
	 * @param string $actual   Formatted opening hours fragment.
	 */
	private function assertContainsTime( string $expected, string $actual ): void {
		$this->assertStringContainsString(
			$expected,
			str_replace( array( "\u{00A0}", "\u{202F}", "\u{2009}" ), ' ', $actual )
		);
	}

	// ── shared time convention (#1399) ─────────────────

	/**
	 * Reference wall clock for a stored "HH:MM" opening-hours value.
	 *
	 * @param string $time Time in H:i form.
	 * @return int
	 */
	private function reference_timestamp( string $time ): int {
		return (int) strtotime( '2000-01-01 ' . $time . ' UTC' );
	}

	/**
	 * Reference timestamp for an opening-hours day index (0 = Monday).
	 *
	 * @param int $day Day index.
	 * @return int
	 */
	private function reference_day_timestamp( int $day ): int {
		return (int) strtotime( '2024-01-01 +' . $day . ' days UTC' );
	}

	public function test_format_vertical_renders_times_through_the_shared_receipt_formatter(): void {
		// date_i18n() renders the WordPress meridiem ("9:00 am") while the
		// order timestamps render the CLDR day period ("9:00 a.m." in nl_NL);
		// one receipt must not show both.
		update_option( 'time_format', 'g:i a' );

		$lines = explode( "\n", Opening_Hours_Formatter::format_vertical( array( '0' => array( '09:00', '17:00' ) ), 'nl_NL' ) );
		$utc   = new DateTimeZone( 'UTC' );

		$this->assertStringContainsString(
			Receipt_Date_Formatter::time( $this->reference_timestamp( '09:00' ), $utc, 'nl_NL' ),
			$lines[0]
		);
		$this->assertStringContainsString(
			Receipt_Date_Formatter::time( $this->reference_timestamp( '17:00' ), $utc, 'nl_NL' ),
			$lines[0]
		);
	}

	public function test_format_vertical_renders_day_names_through_the_shared_receipt_formatter(): void {
		update_option( 'time_format', 'H:i' );

		$lines = explode( "\n", Opening_Hours_Formatter::format_vertical( $this->get_standard_hours(), 'nl_NL' ) );
		$utc   = new DateTimeZone( 'UTC' );

		foreach ( $lines as $day => $line ) {
			$this->assertStringStartsWith(
				Receipt_Date_Formatter::weekday_short( $this->reference_day_timestamp( $day ), $utc, 'nl_NL' ),
				$line
			);
		}
	}

	public function test_format_compact_renders_day_ranges_through_the_shared_receipt_formatter(): void {
		update_option( 'time_format', 'H:i' );

		$lines = explode( "\n", Opening_Hours_Formatter::format_compact( $this->get_standard_hours(), 'nl_NL' ) );
		$utc   = new DateTimeZone( 'UTC' );

		$monday = Receipt_Date_Formatter::weekday_short( $this->reference_day_timestamp( 0 ), $utc, 'nl_NL' );
		$friday = Receipt_Date_Formatter::weekday_short( $this->reference_day_timestamp( 4 ), $utc, 'nl_NL' );

		$this->assertStringStartsWith( $monday . "\u{2013}" . $friday, $lines[0] );
	}

	/**
	 * Render opening hours with `nl_NL` genuinely switchable.
	 *
	 * WP_Locale_Switcher captures get_available_languages() up front and refuses
	 * to switch to a locale with no installed language pack, so the locale has
	 * to be injected. The `missing` transient is the i18n loader's negative
	 * cache — without it `new i18n()` would try to fetch translations from the
	 * CDN. Mirrors the fixture in Test_Store_Abstract.
	 *
	 * The unload is load-bearing: WCPOS's i18n loader installs the text domain
	 * directly, so restore_previous_locale() leaves it in place and every later
	 * test in the process would then read plugin strings in Dutch.
	 *
	 * @param callable $render Receives nothing, returns the formatted string.
	 * @return string
	 */
	private function render_with_switchable_dutch( callable $render ): string {
		global $wp_locale_switcher;

		$available_languages = new \ReflectionProperty( $wp_locale_switcher, 'available_languages' );
		$available_languages->setAccessible( true );
		$original_languages = $available_languages->getValue( $wp_locale_switcher );
		$available_languages->setValue( $wp_locale_switcher, array_merge( $original_languages, array( 'nl_NL' ) ) );

		$translation_filter = static function ( $translation, $text, $domain ) {
			if ( 'woocommerce-pos' === $domain && 'Closed' === $text ) {
				return 'CLOSED[' . get_locale() . ']';
			}

			return $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );
		set_transient( 'wcpos_i18n_woocommerce-pos_missing_nl_NL', TRANSLATION_VERSION, DAY_IN_SECONDS );

		try {
			return $render();
		} finally {
			unload_textdomain( 'woocommerce-pos' );
			delete_transient( 'wcpos_i18n_woocommerce-pos_missing_nl_NL' );
			remove_filter( 'gettext', $translation_filter, 10 );
			$available_languages->setValue( $wp_locale_switcher, $original_languages );
		}
	}

	public function test_format_vertical_resolves_the_closed_label_in_the_receipt_locale(): void {
		// The day names and times follow the receipt locale, so the closed-day
		// label must too — otherwise the line reads "zo Closed".
		$hours = $this->get_all_closed();

		$result = $this->render_with_switchable_dutch(
			static function () use ( $hours ) {
				return Opening_Hours_Formatter::format_vertical( $hours, 'nl_NL' );
			}
		);

		$this->assertStringContainsString( 'CLOSED[nl_NL]', $result );
		$this->assertStringNotContainsString( 'CLOSED[' . get_locale() . ']', $result );
	}

	public function test_format_compact_and_inline_resolve_the_closed_label_in_the_receipt_locale(): void {
		$hours = $this->get_standard_hours();

		$compact = $this->render_with_switchable_dutch(
			static function () use ( $hours ) {
				return Opening_Hours_Formatter::format_compact( $hours, 'nl_NL' );
			}
		);
		$inline  = $this->render_with_switchable_dutch(
			static function () use ( $hours ) {
				return Opening_Hours_Formatter::format_inline( $hours, 'nl_NL' );
			}
		);

		$this->assertStringContainsString( 'CLOSED[nl_NL]', $compact );
		$this->assertStringContainsString( 'CLOSED[nl_NL]', $inline );
	}

	public function test_format_vertical_without_a_locale_keeps_the_site_closed_label(): void {
		$hours = $this->get_all_closed();

		$result = $this->render_with_switchable_dutch(
			static function () use ( $hours ) {
				return Opening_Hours_Formatter::format_vertical( $hours );
			}
		);

		$this->assertStringContainsString( 'CLOSED[' . get_locale() . ']', $result );
	}

	public function test_format_vertical_no_longer_renders_through_date_i18n(): void {
		// The two engines are the defect: nothing on an opening-hours line may
		// still come from date_i18n() once order timestamps use Intl.
		update_option( 'time_format', 'g:i a' );

		$sentinel = static function () {
			return 'DATE_I18N_SENTINEL';
		};

		add_filter( 'date_i18n', $sentinel );
		try {
			$result = Opening_Hours_Formatter::format_vertical( $this->get_standard_hours() );
		} finally {
			remove_filter( 'date_i18n', $sentinel );
		}

		$this->assertStringNotContainsString( 'DATE_I18N_SENTINEL', $result );
	}

	public function test_format_vertical_omits_the_day_period_for_a_24_hour_format(): void {
		update_option( 'time_format', 'H:i' );

		$lines = explode( "\n", Opening_Hours_Formatter::format_vertical( $this->get_standard_hours(), 'nl_NL' ) );

		$this->assertStringContainsString( '09:00', $lines[0] );
		$this->assertStringContainsString( '17:00', $lines[0] );
		$this->assertStringNotContainsString( 'AM', $lines[0] );
		$this->assertStringNotContainsString( 'a.m.', $lines[0] );
	}

	public function test_format_vertical_without_a_locale_matches_the_site_locale(): void {
		update_option( 'time_format', 'g:i A' );

		$default = Opening_Hours_Formatter::format_vertical( $this->get_standard_hours() );
		$site    = Opening_Hours_Formatter::format_vertical( $this->get_standard_hours(), get_locale() );

		$this->assertSame( $site, $default );
	}
}
