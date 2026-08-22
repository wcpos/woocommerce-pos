<?php
/**
 * Opening hours formatter service.
 *
 * Converts the structured opening hours array into human-readable strings
 * in three formats: compact (grouped), vertical (one day per line), and
 * inline (single comma-separated line).
 *
 * Times and day names are rendered through Receipt_Date_Formatter so a receipt
 * uses a single convention source: the opening hours and the order timestamps
 * share the same clock convention, day-period style, and locale.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Opening_Hours_Formatter class.
 */
class Opening_Hours_Formatter {

	/**
	 * Day keys in order (Monday=0 through Sunday=6).
	 */
	private const DAY_KEYS = array( 0, 1, 2, 3, 4, 5, 6 );

	/**
	 * Format as vertical list — one day per line, newline-separated.
	 *
	 * @param array  $hours  Structured hours array (keys 0–6).
	 * @param string $locale Optional receipt locale; defaults to the site locale.
	 * @return string Newline-separated string.
	 */
	public static function format_vertical( array $hours, string $locale = '' ): string {
		$lines = array();
		foreach ( self::DAY_KEYS as $day ) {
			$day_name  = self::get_day_name( $day, $locale );
			$slots     = isset( $hours[ (string) $day ] ) ? $hours[ (string) $day ] : array();
			$formatted = self::format_slots( $slots, $locale );
			$lines[]   = $day_name . ' ' . $formatted;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format as compact grouped — consecutive days with identical hours are ranged.
	 *
	 * @param array  $hours  Structured hours array (keys 0–6).
	 * @param string $locale Optional receipt locale; defaults to the site locale.
	 * @return string Newline-separated string.
	 */
	public static function format_compact( array $hours, string $locale = '' ): string {
		$groups = self::group_consecutive_days( $hours, $locale );
		$lines  = array();

		foreach ( $groups as $group ) {
			$day_label = self::format_day_range( $group['start'], $group['end'], $locale );
			$lines[]   = $day_label . ' ' . $group['formatted'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format as inline — single comma-separated line using compact grouping.
	 *
	 * @param array  $hours  Structured hours array (keys 0–6).
	 * @param string $locale Optional receipt locale; defaults to the site locale.
	 * @return string Single line string.
	 */
	public static function format_inline( array $hours, string $locale = '' ): string {
		$groups = self::group_consecutive_days( $hours, $locale );
		$parts  = array();

		foreach ( $groups as $group ) {
			$day_label = self::format_day_range( $group['start'], $group['end'], $locale );
			$parts[]   = $day_label . ' ' . $group['formatted'];
		}

		return implode( ', ', $parts );
	}

	/**
	 * Group consecutive days that share identical time slots.
	 *
	 * @param array  $hours  Structured hours array.
	 * @param string $locale Receipt locale.
	 * @return array Array of groups, each with 'start', 'end', 'formatted'.
	 */
	private static function group_consecutive_days( array $hours, string $locale ): array {
		$groups  = array();
		$current = null;

		foreach ( self::DAY_KEYS as $day ) {
			$slots     = isset( $hours[ (string) $day ] ) ? $hours[ (string) $day ] : array();
			$formatted = self::format_slots( $slots, $locale );

			if ( null === $current || $current['formatted'] !== $formatted ) {
				if ( null !== $current ) {
					$groups[] = $current;
				}
				$current = array(
					'start'     => $day,
					'end'       => $day,
					'formatted' => $formatted,
				);
			} else {
				$current['end'] = $day;
			}
		}

		// @phpstan-ignore notIdentical.alwaysTrue (belt-and-braces for empty input)
		if ( null !== $current ) {
			$groups[] = $current;
		}

		return $groups;
	}

	/**
	 * Format a day range label.
	 *
	 * @param int    $start  Start day index (0–6).
	 * @param int    $end    End day index (0–6).
	 * @param string $locale Receipt locale.
	 * @return string
	 */
	private static function format_day_range( int $start, int $end, string $locale ): string {
		if ( $start === $end ) {
			return self::get_day_name( $start, $locale );
		}

		return self::get_day_name( $start, $locale ) . "\u{2013}" . self::get_day_name( $end, $locale );
	}

	/**
	 * Format time slots for a single day.
	 *
	 * @param array  $slots  Flat array of time pairs.
	 * @param string $locale Receipt locale.
	 * @return string
	 */
	private static function format_slots( array $slots, string $locale ): string {
		if ( empty( $slots ) ) {
			return /* translators: Short WCPOS UI label; keep concise. */ __( 'Closed', 'woocommerce-pos' );
		}

		// Drop trailing unpaired element to ensure open/close pairs.
		if ( count( $slots ) % 2 !== 0 ) {
			array_pop( $slots );
		}

		if ( empty( $slots ) ) {
			return /* translators: Short WCPOS UI label; keep concise. */ __( 'Closed', 'woocommerce-pos' );
		}

		$ranges     = array();
		$slot_count = count( $slots );
		for ( $i = 0; $i < $slot_count - 1; $i += 2 ) {
			$open     = self::format_time( $slots[ $i ], $locale );
			$close    = self::format_time( $slots[ $i + 1 ], $locale );
			$ranges[] = $open . " \u{2013} " . $close;
		}

		return implode( ', ', $ranges );
	}

	/**
	 * Format a wall-clock time through the shared receipt time renderer.
	 *
	 * The stored value is a bare wall clock with no date or zone, so it is
	 * anchored to a fixed UTC instant and rendered in UTC — the reference day
	 * exists only to give the formatter a timestamp.
	 *
	 * @param string $time   Time in H:i format (e.g. "09:00").
	 * @param string $locale Receipt locale.
	 * @return string Formatted time (e.g. "9:00 AM" or "09:00").
	 */
	private static function format_time( string $time, string $locale ): string {
		$timestamp = strtotime( '2000-01-01 ' . $time . ' UTC' );

		if ( false === $timestamp ) {
			return $time;
		}

		return Receipt_Date_Formatter::time( $timestamp, new DateTimeZone( 'UTC' ), '' !== $locale ? $locale : null );
	}

	/**
	 * Get the localized short day name through the shared receipt renderer.
	 *
	 * @param int    $day    Day index (0=Monday, 6=Sunday).
	 * @param string $locale Receipt locale.
	 * @return string Short day name (e.g. "Mon", "Tue").
	 */
	private static function get_day_name( int $day, string $locale ): string {
		// 2024-01-01 is a Monday. Offset by $day to get the right weekday.
		$utc       = new DateTimeZone( 'UTC' );
		$timestamp = ( new DateTimeImmutable( '2024-01-01 00:00:00', $utc ) )->modify( '+' . $day . ' days' )->getTimestamp();

		return Receipt_Date_Formatter::weekday_short( $timestamp, $utc, '' !== $locale ? $locale : null );
	}
}
