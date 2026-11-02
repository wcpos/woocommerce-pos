<?php
/**
 * Receipt money formatter.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Adds a `<key>_display` currency string beside every money field of a Receipt Data payload.
 *
 * The presentation step every server render path runs (logicless, thermal, the
 * REST preview, the editor sample). Which keys are money, and which stay
 * zero-falsy, is contract and lives in Receipt_Data_Schema; this module only
 * formats. Mirrors the app's `formatReceiptData`.
 */
final class Receipt_Money_Formatter {
	/**
	 * Format money fields in receipt data.
	 *
	 * @param array               $data               Receipt data array (or nested sub-array).
	 * @param string              $currency           WooCommerce currency code.
	 * @param array<string,mixed> $presentation_hints Receipt presentation hints.
	 *
	 * @return array Data with `_display` companions added beside money fields.
	 */
	public static function format( array $data, string $currency = 'USD', array $presentation_hints = array() ): array {
		static $lookup = null;
		static $zero_falsy = null;
		if ( null === $lookup ) {
			$lookup     = array_flip( Receipt_Data_Schema::MONEY_FIELDS );
			$zero_falsy = array_flip( Receipt_Data_Schema::ZERO_FALSY_MONEY_FIELDS );
		}

		if ( empty( $presentation_hints ) && isset( $data['presentation_hints'] ) && \is_array( $data['presentation_hints'] ) ) {
			$presentation_hints = $data['presentation_hints'];
		}

		$price_args = self::get_price_format_args( $currency, $presentation_hints );
		$result = array();

		foreach ( $data as $k => $value ) {
			if ( 'report' === $k ) {
				$result[ $k ] = $value;
			} elseif ( \is_array( $value ) ) {
				$result[ $k ] = self::format( $value, $currency, $presentation_hints );
			} elseif ( is_numeric( $value ) && isset( $lookup[ $k ] ) ) {
				// ──────────────────────────────────────────────────────────
				// Money fields: KEEP the numeric value at the bare key and
				// ADD a `<key>_display` companion holding the locale-formatted
				// currency string. Mirrors the JS `formatReceiptData` shape
				// exactly so a single Mustache template renders the same way
				// in both the studio (JS) and production print (PHP).
				//
				// DO NOT change this back to in-place replacement — bare keys
				// must be numeric so app code can still do math on them, and
				// templates have a stable `_display` variant to render
				// currency without re-implementing wc_price() in JS.
				// ──────────────────────────────────────────────────────────
				$numeric    = (float) $value;
				$is_zero_falsy = ( 0.0 === $numeric && isset( $zero_falsy[ $k ] ) );
				// Conditional-display fields (change, tendered, discounts, etc.) keep
				// the bare key as integer 0 when the value is zero so Mustache section
				// guards (`{{#change}}…{{/change}}`) treat them as falsy and the rest
				// of the test suite's `assertSame( 0, … )` checks stay green.
				$result[ $k ]              = $is_zero_falsy ? 0 : $numeric;
				$result[ $k . '_display' ] = $is_zero_falsy
					? ''
					: html_entity_decode(
						wp_strip_all_tags(
							wc_price( $numeric, $price_args )
						),
						ENT_QUOTES | ENT_SUBSTITUTE,
						'UTF-8'
					);
			} else {
				$result[ $k ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Build wc_price() arguments from presentation hints.
	 *
	 * @param string              $currency           WooCommerce currency code.
	 * @param array<string,mixed> $presentation_hints Receipt presentation hints.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_price_format_args( string $currency, array $presentation_hints ): array {
		$args = array( 'currency' => $currency );

		if ( isset( $presentation_hints['currency_position'] ) ) {
			$args['price_format'] = self::get_price_format_for_position( (string) $presentation_hints['currency_position'] );
		}
		if ( isset( $presentation_hints['price_decimal_separator'] ) ) {
			$args['decimal_separator'] = (string) $presentation_hints['price_decimal_separator'];
		}
		if ( isset( $presentation_hints['price_thousand_separator'] ) ) {
			$args['thousand_separator'] = (string) $presentation_hints['price_thousand_separator'];
		}
		if ( isset( $presentation_hints['price_num_decimals'] ) && is_numeric( $presentation_hints['price_num_decimals'] ) ) {
			$args['decimals'] = (int) $presentation_hints['price_num_decimals'];
		}

		return $args;
	}

	/**
	 * Convert a WooCommerce currency position option to a wc_price format.
	 *
	 * @param string $position WooCommerce currency position.
	 *
	 * @return string wc_price format.
	 */
	private static function get_price_format_for_position( string $position ): string {
		switch ( $position ) {
			case 'right':
				return '%2$s%1$s';
			case 'left_space':
				return '%1$s %2$s';
			case 'right_space':
				return '%2$s %1$s';
			case 'left':
			default:
				return '%1$s%2$s';
		}
	}
}
