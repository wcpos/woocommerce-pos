<?php
/**
 * Opening hours formatter service.
 *
 * Converts the structured opening hours array into human-readable strings
 * in three formats: compact (grouped), vertical (one day per line), and
 * inline (single comma-separated line).
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

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
	 * @param array $hours Structured hours array (keys 0–6).
	 * @return string Newline-separated string.
	 */
	public static function format_vertical( array $hours ): string {
		$lines = array();
		foreach ( self::DAY_KEYS as $day ) {
			$day_name  = self::get_day_name( $day );
			$slots     = isset( $hours[ (string) $day ] ) ? $hours[ (string) $day ] : array();
			$formatted = self::format_slots( $slots );
			$lines[]   = $day_name . ' ' . $formatted;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format as compact grouped — consecutive days with identical hours are ranged.
	 *
	 * @param array $hours Structured hours array (keys 0–6).
	 * @return string Newline-separated string.
	 */
	public static function format_compact( array $hours ): string {
		$groups = self::group_consecutive_days( $hours );
		$lines  = array();

		foreach ( $groups as $group ) {
			$day_label = self::format_day_range( $group['start'], $group['end'] );
			$lines[]   = $day_label . ' ' . $group['formatted'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format as inline — single comma-separated line using compact grouping.
	 *
	 * @param array $hours Structured hours array (keys 0–6).
	 * @return string Single line string.
	 */
	public static function format_inline( array $hours ): string {
		$groups = self::group_consecutive_days( $hours );
		$parts  = array();

		foreach ( $groups as $group ) {
			$day_label = self::format_day_range( $group['start'], $group['end'] );
			$parts[]   = $day_label . ' ' . $group['formatted'];
		}

		return implode( ', ', $parts );
	}

	/**
	 * Group consecutive days that share identical time slots.
	 *
	 * @param array $hours Structured hours array.
	 * @return array Array of groups, each with 'start', 'end', 'formatted'.
	 */
	private static function group_consecutive_days( array $hours ): array {
		$groups  = array();
		$current = null;

		foreach ( self::DAY_KEYS as $day ) {
			$slots     = isset( $hours[ (string) $day ] ) ? $hours[ (string) $day ] : array();
			$formatted = self::format_slots( $slots );

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

		if ( null !== $current ) {
			$groups[] = $current;
		}

		return $groups;
	}

	/**
	 * Format a day range label.
	 *
	 * @param int $start Start day index (0–6).
	 * @param int $end   End day index (0–6).
	 * @return string
	 */
	private static function format_day_range( int $start, int $end ): string {
		if ( $start === $end ) {
			return self::get_day_name( $start );
		}

		return self::get_day_name( $start ) . "\u{2013}" . self::get_day_name( $end );
	}

	/**
	 * Format time slots for a single day.
	 *
	 * @param array $slots Flat array of time pairs.
	 * @return string
	 */
	private static function format_slots( array $slots ): string {
		if ( empty( $slots ) ) {
			return __( 'Closed', 'woocommerce-pos' );
		}

		// Drop trailing unpaired element to ensure open/close pairs.
		if ( count( $slots ) % 2 !== 0 ) {
			array_pop( $slots );
		}

		if ( empty( $slots ) ) {
			return __( 'Closed', 'woocommerce-pos' );
		}

		$ranges     = array();
		$slot_count = count( $slots );
		for ( $i = 0; $i < $slot_count - 1; $i += 2 ) {
			$open     = self::format_time( $slots[ $i ] );
			$close    = self::format_time( $slots[ $i + 1 ] );
			$ranges[] = $open . " \u{2013} " . $close;
		}

		return implode( ', ', $ranges );
	}

	/**
	 * Format a time string according to WP time_format option.
	 *
	 * @param string $time Time in H:i format (e.g. "09:00").
	 * @return string Formatted time (e.g. "9:00 AM" or "09:00").
	 */
	private static function format_time( string $time ): string {
		$timestamp = strtotime( '2000-01-01 ' . $time );

		if ( false === $timestamp ) {
			return $time;
		}

		$time_format = get_option( 'time_format', 'g:i A' );

		return date_i18n( $time_format, $timestamp );
	}

	/**
	 * Get the localized short day name.
	 *
	 * @param int $day Day index (0=Monday, 6=Sunday).
	 * @return string Short day name (e.g. "Mon", "Tue").
	 */
	private static function get_day_name( int $day ): string {
		// 2024-01-01 is a Monday. Offset by $day to get the right weekday.
		$timestamp = strtotime( '2024-01-01 +' . $day . ' days' );

		return date_i18n( 'D', $timestamp );
	}
}
