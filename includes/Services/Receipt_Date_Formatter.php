<?php
/**
 * Receipt date formatter.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use WC_DateTime;

/**
 * Receipt_Date_Formatter class.
 */
class Receipt_Date_Formatter {
	/**
	 * Canonical list of date field keys returned by every formatting method.
	 */
	private const DATE_FIELDS = array(
		'datetime',
		'date',
		'time',
		'datetime_short',
		'datetime_long',
		'datetime_full',
		'date_short',
		'date_long',
		'date_full',
		'date_ymd',
		'date_dmy',
		'date_mdy',
		'weekday_short',
		'weekday_long',
		'day',
		'month',
		'month_short',
		'month_long',
		'year',
	);

	/**
	 * Intl full style fallback value.
	 */
	private const INTL_FULL = 0;

	/**
	 * Intl long style fallback value.
	 */
	private const INTL_LONG = 1;

	/**
	 * Intl medium style fallback value.
	 */
	private const INTL_MEDIUM = 2;

	/**
	 * Intl short style fallback value.
	 */
	private const INTL_SHORT = 3;

	/**
	 * Intl none style fallback value.
	 */
	private const INTL_NONE = -1;

	/**
	 * Build all practical display formats for a WooCommerce date.
	 *
	 * @param WC_DateTime|null $date   WooCommerce date.
	 * @param string|null      $locale Optional locale override.
	 *
	 * @return array<string, string>
	 */
	public static function from_wc_datetime( ?WC_DateTime $date, ?string $locale = null ): array {
		if ( ! $date ) {
			return self::empty();
		}

		return self::from_timestamp( $date->getTimestamp(), $date->getTimezone(), $locale );
	}

	/**
	 * Build all practical display formats for a timestamp.
	 *
	 * @param int               $timestamp Unix timestamp.
	 * @param DateTimeZone|null $timezone  Optional timezone override.
	 * @param string|null       $locale    Optional locale override.
	 *
	 * @return array<string, string>
	 */
	public static function from_timestamp( int $timestamp, ?DateTimeZone $timezone = null, ?string $locale = null ): array {
		$timezone = $timezone ? $timezone : self::get_default_timezone();
		$locale   = $locale ? $locale : self::get_default_locale();
		$date     = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );

		// The WordPress time_format setting controls the clock convention
		// (12 vs 24-hour); the locale keeps controlling localized date text.
		$time_format   = self::get_time_format_option();
		$hour_token    = self::get_icu_hour_token( $time_format );
		$fallback_time = '' !== $time_format ? $time_format : 'g:i A';

		return array(
			'datetime'       => self::format_style( $date, $timezone, $locale, self::INTL_MEDIUM, self::INTL_SHORT, 'M j, Y ' . $fallback_time, $hour_token ),
			'date'           => self::format_style( $date, $timezone, $locale, self::INTL_MEDIUM, self::INTL_NONE, 'M j, Y' ),
			'time'           => self::format_style( $date, $timezone, $locale, self::INTL_NONE, self::INTL_SHORT, $fallback_time, $hour_token ),
			'datetime_short' => self::format_style( $date, $timezone, $locale, self::INTL_SHORT, self::INTL_SHORT, 'n/j/y ' . $fallback_time, $hour_token ),
			'datetime_long'  => self::format_style( $date, $timezone, $locale, self::INTL_LONG, self::INTL_SHORT, 'F j, Y ' . $fallback_time, $hour_token ),
			'datetime_full'  => self::format_style( $date, $timezone, $locale, self::INTL_FULL, self::INTL_SHORT, 'l, F j, Y ' . $fallback_time, $hour_token ),
			'date_short'     => self::format_style( $date, $timezone, $locale, self::INTL_SHORT, self::INTL_NONE, 'n/j/y' ),
			'date_long'      => self::format_style( $date, $timezone, $locale, self::INTL_LONG, self::INTL_NONE, 'F j, Y' ),
			'date_full'      => self::format_style( $date, $timezone, $locale, self::INTL_FULL, self::INTL_NONE, 'l, F j, Y' ),
			'date_ymd'       => self::format_pattern( $date, $timezone, $locale, 'yyyy-MM-dd', 'Y-m-d' ),
			'date_dmy'       => self::format_pattern( $date, $timezone, $locale, 'dd/MM/yyyy', 'd/m/Y' ),
			'date_mdy'       => self::format_pattern( $date, $timezone, $locale, 'MM/dd/yyyy', 'm/d/Y' ),
			'weekday_short'  => self::format_pattern( $date, $timezone, $locale, 'EEE', 'D' ),
			'weekday_long'   => self::format_pattern( $date, $timezone, $locale, 'EEEE', 'l' ),
			'day'            => self::format_pattern( $date, $timezone, $locale, 'dd', 'd' ),
			'month'          => self::format_pattern( $date, $timezone, $locale, 'MM', 'm' ),
			'month_short'    => self::format_pattern( $date, $timezone, $locale, 'MMM', 'M' ),
			'month_long'     => self::format_pattern( $date, $timezone, $locale, 'MMMM', 'F' ),
			'year'           => self::format_pattern( $date, $timezone, $locale, 'yyyy', 'Y' ),
		);
	}

	/**
	 * Build an empty date structure.
	 *
	 * @return array<string, string>
	 */
	public static function empty(): array {
		return array_fill_keys( self::DATE_FIELDS, '' );
	}

	/**
	 * Format using Intl styles with a sane fallback.
	 *
	 * @param DateTimeInterface $date             Date to format.
	 * @param DateTimeZone      $timezone         Date timezone.
	 * @param string            $locale           Locale code.
	 * @param int               $date_style       Intl date style.
	 * @param int               $time_style       Intl time style.
	 * @param string            $fallback_pattern wp_date()/DateTime fallback pattern.
	 * @param string|null       $hour_token       ICU hour token enforcing the configured clock convention, or null to keep the locale default.
	 *
	 * @return string
	 */
	private static function format_style( DateTimeInterface $date, DateTimeZone $timezone, string $locale, int $date_style, int $time_style, string $fallback_pattern, ?string $hour_token = null ): string {
		return self::run_intl_with_fallback(
			$date,
			$timezone,
			$locale,
			$fallback_pattern,
			static function ( string $timezone_name ) use ( $locale, $date_style, $time_style, $hour_token ) {
				return self::build_clock_aware_formatter( $locale, $date_style, $time_style, $timezone_name, $hour_token );
			}
		);
	}

	/**
	 * Build an Intl formatter with the requested clock convention.
	 *
	 * @param string      $locale       Locale code.
	 * @param int         $date_style    Intl date style.
	 * @param int         $time_style    Intl time style.
	 * @param string      $timezone_name Intl timezone name.
	 * @param string|null $hour_token    ICU hour token, or null to keep the locale default.
	 *
	 * @return IntlDateFormatter
	 */
	private static function build_clock_aware_formatter( string $locale, int $date_style, int $time_style, string $timezone_name, ?string $hour_token ): IntlDateFormatter {
		if ( null === $hour_token || self::INTL_NONE === $time_style ) {
			return new IntlDateFormatter( $locale, $date_style, $time_style, $timezone_name );
		}

		$use_24_hour = 'H' === $hour_token[0];

		// Request the hour cycle via the Unicode locale extension first so
		// CLDR controls day-period placement (before vs after the hour and
		// time-first patterns). The pattern probe detects unsupported cycles.
		$hour_cycle_locale = $locale . ( false === strpos( $locale, '-u-' ) ? '-u-hc-' : '-hc-' ) . ( $use_24_hour ? 'h23' : 'h12' );
		$formatter         = new IntlDateFormatter( $hour_cycle_locale, $date_style, $time_style, $timezone_name );
		$pattern           = (string) $formatter->getPattern();

		if ( ! self::pattern_matches_clock( $pattern, $use_24_hour ) ) {
			$formatter = new IntlDateFormatter( $locale, $date_style, $time_style, $timezone_name );
			$pattern   = (string) $formatter->getPattern();
		}

		// Normalize the hour symbols to the configured padding and,
		// on the no-extension fallback, rewrite the clock convention.
		// Never set an empty pattern (PCRE failure) — blank output
		// is worse than the locale-default clock.
		$adjusted = self::apply_clock_convention( $pattern, $hour_token );
		if ( '' !== $adjusted && $adjusted !== $pattern ) {
			$formatter->setPattern( $adjusted );
		}

		return $formatter;
	}

	/**
	 * Format using an Intl pattern with a sane fallback.
	 *
	 * @param DateTimeInterface $date             Date to format.
	 * @param DateTimeZone      $timezone         Date timezone.
	 * @param string            $locale           Locale code.
	 * @param string            $pattern          Intl pattern.
	 * @param string            $fallback_pattern wp_date()/DateTime fallback pattern.
	 *
	 * @return string
	 */
	private static function format_pattern( DateTimeInterface $date, DateTimeZone $timezone, string $locale, string $pattern, string $fallback_pattern ): string {
		return self::run_intl_with_fallback(
			$date,
			$timezone,
			$locale,
			$fallback_pattern,
			static function ( string $timezone_name ) use ( $locale, $pattern ) {
				return new IntlDateFormatter( $locale, self::INTL_NONE, self::INTL_NONE, $timezone_name, null, $pattern );
			}
		);
	}

	/**
	 * Run an IntlDateFormatter with fixed-offset timezone normalization and fallback.
	 *
	 * @param DateTimeInterface $date             Date to format.
	 * @param DateTimeZone      $timezone         Date timezone.
	 * @param string            $locale           Locale code.
	 * @param string            $fallback_pattern wp_date()/DateTime fallback pattern.
	 * @param callable          $make_formatter   Callback receiving timezone name, returns IntlDateFormatter.
	 *
	 * @return string
	 */
	private static function run_intl_with_fallback( DateTimeInterface $date, DateTimeZone $timezone, string $locale, string $fallback_pattern, callable $make_formatter ): string {
		$timezone_name = $timezone->getName();
		if ( self::is_fixed_offset_timezone_name( $timezone_name ) ) {
			// ICU rejects raw offset names like "+02:00" but accepts the
			// equivalent "GMT+02:00" spelling, so fixed-offset sites can use
			// the same Intl path as IANA timezones.
			$timezone_name = 'GMT' . $timezone_name;
		}

		if ( static::intl_available() ) {
			try {
				$formatter = $make_formatter( $timezone_name );
				$formatted = $formatter->format( $date );
				if ( false !== $formatted ) {
					return (string) $formatted;
				}
			} catch ( \Throwable $error ) {
				return self::format_fallback( $date, $fallback_pattern, $locale );
			}
		}

		return self::format_fallback( $date, $fallback_pattern, $locale );
	}

	/**
	 * Whether the Intl extension can be used. Overridable for testing.
	 *
	 * @return bool
	 */
	protected static function intl_available(): bool {
		return class_exists( IntlDateFormatter::class );
	}

	/**
	 * Read the WordPress time_format option.
	 *
	 * @return string Configured format, or empty string when unavailable/blank.
	 */
	private static function get_time_format_option(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$time_format = get_option( 'time_format', '' );
		if ( ! is_string( $time_format ) || '' === trim( $time_format ) ) {
			return '';
		}

		return $time_format;
	}

	/**
	 * Map the configured WordPress time format to an ICU hour token.
	 *
	 * The token carries both the clock convention and the zero-padding intent:
	 * G → H, H → HH (24-hour), g → h, h → hh (12-hour).
	 *
	 * @param string $time_format WordPress time format string.
	 *
	 * @return string|null ICU hour token, or null when no hour token is present.
	 */
	private static function get_icu_hour_token( string $time_format ): ?string {
		// Ignore backslash-escaped literal characters.
		$unescaped = (string) preg_replace( '/\\\\./', '', $time_format );

		if ( false !== strpos( $unescaped, 'H' ) ) {
			return 'HH';
		}
		if ( false !== strpos( $unescaped, 'G' ) ) {
			return 'H';
		}
		if ( false !== strpos( $unescaped, 'h' ) ) {
			return 'hh';
		}
		if ( false !== strpos( $unescaped, 'g' ) ) {
			return 'h';
		}

		return null;
	}

	/**
	 * Check whether a pattern's hour symbols already match a clock convention.
	 *
	 * Used to detect ICU builds that ignore the hours locale keyword.
	 *
	 * @param string $pattern     ICU date/time pattern.
	 * @param bool   $use_24_hour Whether the 24-hour convention is requested.
	 *
	 * @return bool
	 */
	private static function pattern_matches_clock( string $pattern, bool $use_24_hour ): bool {
		$in_quote = false;
		$length   = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( "'" === $char ) {
				if ( $i + 1 < $length && "'" === $pattern[ $i + 1 ] ) {
					++$i;
					continue;
				}
				$in_quote = ! $in_quote;
				continue;
			}

			if ( $in_quote ) {
				continue;
			}

			if ( $use_24_hour && ( 'H' === $char || 'k' === $char ) ) {
				return true;
			}
			if ( ! $use_24_hour && ( 'h' === $char || 'K' === $char ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rewrite an ICU pattern so its clock convention matches the hour token.
	 *
	 * Hour symbols (h, H, k, K) outside quoted literals are replaced with the
	 * token. For 24-hour tokens, day-period markers (a, b, B) are removed; for
	 * 12-hour tokens, a day-period marker is appended when missing. The
	 * time_format option contributes only the clock convention and padding —
	 * the locale owns separators, ordering, and day-period placement.
	 *
	 * Byte-wise iteration is safe on UTF-8 patterns: no ASCII byte can occur
	 * inside a multibyte sequence.
	 *
	 * @param string $pattern    ICU date/time pattern.
	 * @param string $hour_token ICU hour token: H, HH, h, or hh.
	 *
	 * @return string
	 */
	private static function apply_clock_convention( string $pattern, string $hour_token ): string {
		$use_24_hour    = 'H' === $hour_token[0];
		$result         = '';
		$in_quote       = false;
		$has_day_period = false;
		$has_hour       = false;
		$time_end       = 0;
		$length         = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( "'" === $char ) {
				$result .= $char;
				// A doubled quote is an escaped literal quote, not a toggle.
				if ( $i + 1 < $length && "'" === $pattern[ $i + 1 ] ) {
					$result .= "'";
					++$i;
					continue;
				}
				$in_quote = ! $in_quote;
				continue;
			}

			if ( $in_quote ) {
				$result .= $char;
				continue;
			}

			if ( 'h' === $char || 'H' === $char || 'k' === $char || 'K' === $char ) {
				while ( $i + 1 < $length && $pattern[ $i + 1 ] === $char ) {
					++$i;
				}
				$result  .= $hour_token;
				$has_hour = true;
				$time_end = strlen( $result );
				continue;
			}

			if ( 'a' === $char || 'b' === $char || 'B' === $char ) {
				if ( $use_24_hour ) {
					$result .= "\x01";
					continue;
				}
				$has_day_period = true;
			}

			$result .= $char;

			// Track the end of the time cluster (minutes/seconds and their
			// separator) so a missing day-period marker can be inserted after
			// the time rather than after a trailing date in time-first patterns.
			if ( $has_hour && ( 'm' === $char || 's' === $char || ':' === $char || '.' === $char ) ) {
				$time_end = strlen( $result );
			}
		}

		if ( $use_24_hour ) {
			// Strip removed day-period markers with their surrounding spacing
			// (including NBSP/NNBSP used by newer CLDR data).
			$result = (string) preg_replace( '/[\s\x{00A0}\x{202F}]*\x01+[\s\x{00A0}\x{202F}]*(?=\p{P})/u', '', $result );
			$result = (string) preg_replace( '/[\s\x{00A0}\x{202F}]*\x01+[\s\x{00A0}\x{202F}]*/u', ' ', $result );
			$result = (string) preg_replace( '/[\s\x{00A0}\x{202F}]{2,}/u', ' ', $result );

			return trim( $result );
		}

		if ( $has_hour && ! $has_day_period ) {
			if ( $time_end >= strlen( $result ) ) {
				return rtrim( $result ) . ' a';
			}

			return substr( $result, 0, $time_end ) . ' a' . substr( $result, $time_end );
		}

		return $result;
	}

	/**
	 * Check whether a timezone name is a fixed UTC offset like +00:00.
	 *
	 * @param string $timezone_name Timezone name.
	 *
	 * @return bool
	 */
	private static function is_fixed_offset_timezone_name( string $timezone_name ): bool {
		return 1 === preg_match( '/^[+-]\d{2}:\d{2}$/', $timezone_name );
	}

	/**
	 * Format a date when Intl is unavailable.
	 *
	 * @param DateTimeInterface $date    Date to format.
	 * @param string            $pattern wp_date()/DateTime pattern.
	 * @param string            $locale  Locale code.
	 *
	 * @return string
	 */
	private static function format_fallback( DateTimeInterface $date, string $pattern, string $locale ): string {
		if ( function_exists( 'wp_date' ) ) {
			$current_locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
			if ( '' !== $locale && $locale !== $current_locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale ) ) {
				try {
					return wp_date( $pattern, $date->getTimestamp(), $date->getTimezone() );
				} finally {
					restore_previous_locale();
				}
			}

			return wp_date( $pattern, $date->getTimestamp(), $date->getTimezone() );
		}

		return $date->format( $pattern );
	}

	/**
	 * Resolve default locale.
	 *
	 * @return string
	 */
	private static function get_default_locale(): string {
		if ( function_exists( 'get_locale' ) ) {
			return (string) get_locale();
		}

		return 'en_US';
	}

	/**
	 * Resolve default timezone.
	 *
	 * @return DateTimeZone
	 */
	private static function get_default_timezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( date_default_timezone_get() );
	}
}
