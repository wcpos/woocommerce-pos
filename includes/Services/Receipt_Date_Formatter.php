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
			$locale,
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
			$locale,
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
	 * @param string            $locale           Locale code.
	 * @param string            $fallback_pattern wp_date()/DateTime fallback pattern.
	 * @param callable          $make_formatter   Callback receiving timezone name, returns IntlDateFormatter.
	 *
	 * @return string
	 */
	private static function run_intl_with_fallback( DateTimeInterface $date, DateTimeZone $timezone, string $locale, string $fallback_pattern, callable $make_formatter ): string {
		$timezone_name = $timezone->getName();
		if ( self::is_fixed_offset_timezone_name( $timezone_name ) ) {
			return self::format_fallback( $date, $fallback_pattern, $locale );
		}

		if ( class_exists( IntlDateFormatter::class ) ) {
			try {
				$formatter = $make_formatter( $timezone_name );
				$formatted = $formatter->format( $date );
				if ( false !== $formatted && ! self::looks_like_unlocalized_output( (string) $formatted, $locale ) ) {
					return (string) $formatted;
				}
			} catch ( \Throwable $error ) {
				return self::format_fallback( $date, $fallback_pattern, $locale );
			}
		}

		return self::format_fallback( $date, $fallback_pattern, $locale );
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
		$pattern = self::get_locale_fallback_pattern( $pattern, $locale );

		if ( function_exists( 'wp_date' ) ) {
			$current_locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
			if ( '' !== $locale && $locale !== $current_locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale ) ) {
				try {
					return self::translate_fallback_date( wp_date( $pattern, $date->getTimestamp(), $date->getTimezone() ), $locale );
				} finally {
					restore_previous_locale();
				}
			}

			return self::translate_fallback_date( wp_date( $pattern, $date->getTimestamp(), $date->getTimezone() ), $locale );
		}

		return self::translate_fallback_date( $date->format( $pattern ), $locale );
	}

	/**
	 * Detect minimal-ICU output where Intl accepts a locale but still emits English-style output.
	 *
	 * @param string $formatted Formatted date.
	 * @param string $locale    Locale code.
	 *
	 * @return bool
	 */
	private static function looks_like_unlocalized_output( string $formatted, string $locale ): bool {
		if ( 0 !== strpos( strtolower( str_replace( '_', '-', $locale ) ), 'es' ) ) {
			return false;
		}

		return 1 === preg_match( '/\b(?:Jan|January|Feb|February|Mar|March|Apr|April|May|Jun|June|Jul|July|Aug|August|Sep|September|Oct|October|Nov|November|Dec|December)\b/', $formatted )
			|| 1 === preg_match( '/\b(?:AM|PM)\b/i', $formatted )
			|| 1 === preg_match( '/^(?:0?[1-9]|1[0-2])\/(?:1[3-9]|2\d|3[01])\/\d{2,4}(?:\D|$)/', $formatted );
	}

	/**
	 * Adapt English fallback patterns for locales where WordPress/Intl data may be unavailable.
	 *
	 * @param string $pattern Fallback date pattern.
	 * @param string $locale  Locale code.
	 *
	 * @return string
	 */
	private static function get_locale_fallback_pattern( string $pattern, string $locale ): string {
		if ( 0 !== strpos( strtolower( str_replace( '_', '-', $locale ) ), 'es' ) ) {
			return $pattern;
		}

		$patterns = array(
			'M j, Y g:i A'     => 'j M Y, H:i',
			'M j, Y'           => 'j M Y',
			'g:i A'            => 'H:i',
			'n/j/y g:i A'      => 'd/m/y H:i',
			'F j, Y g:i A'     => 'j \d\e F \d\e Y, H:i',
			'l, F j, Y g:i A'  => 'l, j \d\e F \d\e Y, H:i',
			'n/j/y'            => 'd/m/y',
			'F j, Y'           => 'j \d\e F \d\e Y',
			'l, F j, Y'        => 'l, j \d\e F \d\e Y',
		);

		return $patterns[ $pattern ] ?? $pattern;
	}

	/**
	 * Translate fallback month and weekday names for locales without loaded WordPress language packs.
	 *
	 * @param string $formatted Formatted date.
	 * @param string $locale    Locale code.
	 *
	 * @return string
	 */
	private static function translate_fallback_date( string $formatted, string $locale ): string {
		if ( 0 !== strpos( strtolower( str_replace( '_', '-', $locale ) ), 'es' ) ) {
			return $formatted;
		}

		return strtr(
			$formatted,
			array(
				'January'   => 'enero',
				'February'  => 'febrero',
				'March'     => 'marzo',
				'April'     => 'abril',
				'May'       => 'mayo',
				'June'      => 'junio',
				'July'      => 'julio',
				'August'    => 'agosto',
				'September' => 'septiembre',
				'October'   => 'octubre',
				'November'  => 'noviembre',
				'December'  => 'diciembre',
				'Monday'    => 'lunes',
				'Tuesday'   => 'martes',
				'Wednesday' => 'miércoles',
				'Thursday'  => 'jueves',
				'Friday'    => 'viernes',
				'Saturday'  => 'sábado',
				'Sunday'    => 'domingo',
				'Jan'       => 'ene',
				'Feb'       => 'feb',
				'Mar'       => 'mar',
				'Apr'       => 'abr',
				'Jun'       => 'jun',
				'Jul'       => 'jul',
				'Aug'       => 'ago',
				'Sep'       => 'sept',
				'Oct'       => 'oct',
				'Nov'       => 'nov',
				'Dec'       => 'dic',
				'Mon'       => 'lun',
				'Tue'       => 'mar',
				'Wed'       => 'mié',
				'Thu'       => 'jue',
				'Fri'       => 'vie',
				'Sat'       => 'sáb',
				'Sun'       => 'dom',
			)
		);
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
