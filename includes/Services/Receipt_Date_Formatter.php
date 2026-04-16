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

		return array(
			'datetime'       => self::format_style( $date, $timezone, $locale, self::INTL_MEDIUM, self::INTL_SHORT, 'M j, Y g:i A' ),
			'date'           => self::format_style( $date, $timezone, $locale, self::INTL_MEDIUM, self::INTL_NONE, 'M j, Y' ),
			'time'           => self::format_style( $date, $timezone, $locale, self::INTL_NONE, self::INTL_SHORT, 'g:i A' ),
			'datetime_short' => self::format_style( $date, $timezone, $locale, self::INTL_SHORT, self::INTL_SHORT, 'n/j/y g:i A' ),
			'datetime_long'  => self::format_style( $date, $timezone, $locale, self::INTL_LONG, self::INTL_SHORT, 'F j, Y g:i A' ),
			'datetime_full'  => self::format_style( $date, $timezone, $locale, self::INTL_FULL, self::INTL_SHORT, 'l, F j, Y g:i A' ),
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
	 *
	 * @return string
	 */
	private static function format_style( DateTimeInterface $date, DateTimeZone $timezone, string $locale, int $date_style, int $time_style, string $fallback_pattern ): string {
		return self::run_intl_with_fallback(
			$date,
			$timezone,
			$fallback_pattern,
			static function ( string $timezone_name ) use ( $locale, $date_style, $time_style ) {
				return new IntlDateFormatter( $locale, $date_style, $time_style, $timezone_name );
			}
		);
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
			$fallback_pattern,
			static function ( string $timezone_name ) use ( $locale, $pattern ) {
				return new IntlDateFormatter( $locale, self::INTL_NONE, self::INTL_NONE, $timezone_name, null, $pattern );
			}
		);
	}

	/**
	 * Run an IntlDateFormatter with fixed-offset timezone guard and fallback.
	 *
	 * @param DateTimeInterface $date             Date to format.
	 * @param DateTimeZone      $timezone         Date timezone.
	 * @param string            $fallback_pattern wp_date()/DateTime fallback pattern.
	 * @param callable          $make_formatter   Callback receiving timezone name, returns IntlDateFormatter.
	 *
	 * @return string
	 */
	private static function run_intl_with_fallback( DateTimeInterface $date, DateTimeZone $timezone, string $fallback_pattern, callable $make_formatter ): string {
		$timezone_name = $timezone->getName();
		if ( self::is_fixed_offset_timezone_name( $timezone_name ) ) {
			return self::format_fallback( $date, $fallback_pattern );
		}

		if ( class_exists( IntlDateFormatter::class ) ) {
			try {
				$formatter = $make_formatter( $timezone_name );
				$formatted = $formatter->format( $date );
				if ( false !== $formatted ) {
					return (string) $formatted;
				}
			} catch ( \Throwable $error ) {
				return self::format_fallback( $date, $fallback_pattern );
			}
		}

		return self::format_fallback( $date, $fallback_pattern );
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
	 *
	 * @return string
	 */
	private static function format_fallback( DateTimeInterface $date, string $pattern ): string {
		if ( function_exists( 'wp_date' ) ) {
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
