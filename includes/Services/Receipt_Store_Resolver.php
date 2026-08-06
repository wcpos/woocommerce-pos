<?php
/**
 * Shared receipt store setting resolver.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeZone;

/**
 * Receipt_Store_Resolver class.
 */
class Receipt_Store_Resolver {
	/**
	 * POS store object.
	 *
	 * @var object
	 */
	private $pos_store;

	/**
	 * Constructor.
	 *
	 * @param object $pos_store POS store object.
	 */
	public function __construct( $pos_store ) {
		$this->pos_store = $pos_store;
	}

	/**
	 * Safely read a value from a POS store object.
	 *
	 * @param string $getter   Getter method name.
	 * @param mixed  $fallback Fallback value.
	 *
	 * @return mixed
	 */
	public function get_store_value( string $getter, $fallback ) {
		if ( ! \is_object( $this->pos_store ) || ! method_exists( $this->pos_store, $getter ) ) {
			return $fallback;
		}

		return $this->pos_store->{$getter}();
	}

	/**
	 * Resolve a string setting from the store with a WooCommerce fallback.
	 *
	 * Empty strings are preserved as explicit overrides.
	 *
	 * @param string $getter   Store getter method.
	 * @param mixed  $fallback Fallback value.
	 *
	 * @return string
	 */
	public function resolve_store_string( string $getter, $fallback ): string {
		$value = $this->get_store_value( $getter, null );

		return null !== $value ? (string) $value : (string) $fallback;
	}

	/**
	 * Resolve an enum-like store setting with a WooCommerce fallback.
	 *
	 * Empty strings fall back because these values must be valid option tokens.
	 *
	 * @param string $getter   Store getter method.
	 * @param mixed  $fallback Fallback value.
	 *
	 * @return string
	 */
	public function resolve_store_option_string( string $getter, $fallback ): string {
		$value = $this->get_store_value( $getter, null );

		return null !== $value && '' !== (string) $value ? (string) $value : (string) $fallback;
	}

	/**
	 * Resolve the receipt timezone from the store, falling back to the site timezone.
	 *
	 * @return DateTimeZone
	 */
	public function resolve_store_timezone(): DateTimeZone {
		$timezone = (string) $this->get_store_value( 'get_timezone', '' );

		if ( '' !== $timezone ) {
			try {
				return new DateTimeZone( $timezone );
			} catch ( \Exception $error ) {
				return wp_timezone();
			}
		}

		return wp_timezone();
	}

	/**
	 * Resolve the store locale with site fallback.
	 *
	 * @return string
	 */
	public function resolve_locale(): string {
		$store_locale = (string) $this->get_store_value( 'get_locale', '' );

		return '' !== $store_locale ? $store_locale : get_locale();
	}

	/**
	 * Resolve the number of price decimals from the store with WC fallback.
	 *
	 * @return int
	 */
	public function resolve_price_num_decimals(): int {
		$value = $this->get_store_value( 'get_price_number_of_decimals', wc_get_price_decimals() );

		return is_numeric( $value ) && (float) $value >= 0 ? (int) $value : wc_get_price_decimals();
	}

	/**
	 * Build template-facing tax mode signals.
	 *
	 * @return array<string,mixed>
	 */
	public function build_tax_section(): array {
		$display = 'incl' === $this->resolve_store_option_string(
			'get_tax_display_cart',
			get_option( 'woocommerce_tax_display_cart', 'excl' )
		) ? 'incl' : 'excl';

		$tax_enabled = 'yes' === $this->resolve_store_option_string(
			'get_calc_taxes',
			get_option( 'woocommerce_calc_taxes', 'no' )
		);
		$breakdown   = $tax_enabled ? $this->resolve_store_option_string(
			'get_tax_total_display',
			get_option( 'woocommerce_tax_total_display', 'itemized' )
		) : 'hidden';

		if ( ! in_array( $breakdown, array( 'hidden', 'single', 'itemized' ), true ) ) {
			$breakdown = 'itemized';
		}

		return array(
			'display'            => $display,
			'display_incl'       => 'incl' === $display,
			'display_excl'       => 'excl' === $display,
			'breakdown'          => $breakdown,
			'breakdown_hidden'   => 'hidden' === $breakdown,
			'breakdown_single'   => 'single' === $breakdown,
			'breakdown_itemized' => 'itemized' === $breakdown,
		);
	}

	/**
	 * Assemble the template-facing `store` section from the POS store object.
	 *
	 * Every field is read through get_store_value(), so partial store objects —
	 * including the bare \stdClass used for orders whose store has since been
	 * deleted — fall back field by field instead of fataling. Blank values count
	 * as "not set" for the fields that accept a fallback, which is how both
	 * builders have always treated empty store settings.
	 *
	 * @param array<string,mixed> $fallbacks Optional per-field fallbacks. Recognised keys:
	 *                                       `id` (int, default 0),
	 *                                       `name` (string, default site title),
	 *                                       `opening_hours` (array, default empty — renders null),
	 *                                       `opening_hours_notes`, `personal_notes`,
	 *                                       `policies_and_conditions` and `footer_imprint`
	 *                                       (string|null, default null).
	 *
	 * @return array<string,mixed>
	 */
	public function build_store_section( array $fallbacks = array() ): array {
		$address = array(
			'address_1' => (string) $this->get_store_value( 'get_store_address', '' ),
			'address_2' => (string) $this->get_store_value( 'get_store_address_2', '' ),
			'city'      => (string) $this->get_store_value( 'get_store_city', '' ),
			'state'     => (string) $this->get_store_value( 'get_store_state', '' ),
			'postcode'  => (string) $this->get_store_value( 'get_store_postcode', '' ),
			'country'   => (string) $this->get_store_value( 'get_store_country', '' ),
		);

		$tax_ids = $this->get_store_value( 'get_tax_ids', array() );
		if ( ! \is_array( $tax_ids ) ) {
			$tax_ids = array();
		}

		// Resolved lazily so the bloginfo filters only run when they are actually needed.
		$name = $this->resolve_optional_text( 'get_name', null );
		if ( null === $name ) {
			$name = isset( $fallbacks['name'] ) ? (string) $fallbacks['name'] : (string) get_bloginfo( 'name' );
		}

		$store = array(
			'id'            => (int) $this->get_store_value( 'get_id', $fallbacks['id'] ?? 0 ),
			'name'          => $name,
			// Structured address parts mirror customer.billing_address — templates that
			// want country-specific layouts compose from these. address_lines[] is the
			// pre-formatted default for templates that just iterate, composed via
			// WC_Countries::get_formatted_address() so per-country layouts are honoured.
			'address'       => $address,
			'address_lines' => self::compose_address_lines( $address ),
			'tax_ids'       => self::with_store_tax_id_labels( $tax_ids, $this->resolve_locale() ),
			'phone'         => (string) $this->get_store_value( 'get_phone', '' ),
			'email'         => (string) $this->get_store_value( 'get_email', '' ),
			'logo'          => Store_Logo_Resolver::resolve( $this->pos_store ),
		);

		$opening_hours = $this->get_store_value( 'get_opening_hours', array() );
		$has_structured_hours = \is_array( $opening_hours ) && ! empty( $opening_hours );
		$has_legacy_hours     = \is_string( $opening_hours ) && '' !== trim( $opening_hours );
		if ( ! $has_structured_hours && ! $has_legacy_hours ) {
			$opening_hours        = $fallbacks['opening_hours'] ?? array();
			$has_structured_hours = \is_array( $opening_hours ) && ! empty( $opening_hours );
		}

		if ( $has_structured_hours ) {
			$store['opening_hours']          = Opening_Hours_Formatter::format_compact( $opening_hours );
			$store['opening_hours_vertical'] = Opening_Hours_Formatter::format_vertical( $opening_hours );
			$store['opening_hours_inline']   = Opening_Hours_Formatter::format_inline( $opening_hours );
		} elseif ( $has_legacy_hours ) {
			// Legacy free-text hours have no per-day structure to reformat.
			$store['opening_hours']          = $opening_hours;
			$store['opening_hours_vertical'] = null;
			$store['opening_hours_inline']   = null;
		} else {
			$store['opening_hours']          = null;
			$store['opening_hours_vertical'] = null;
			$store['opening_hours_inline']   = null;
		}

		$store['opening_hours_notes']     = $this->resolve_optional_text( 'get_opening_hours_notes', $fallbacks['opening_hours_notes'] ?? null );
		$store['personal_notes']          = $this->resolve_optional_text( 'get_personal_notes', $fallbacks['personal_notes'] ?? null );
		$store['policies_and_conditions'] = $this->resolve_optional_text( 'get_policies_and_conditions', $fallbacks['policies_and_conditions'] ?? null );
		$store['footer_imprint']          = $this->resolve_optional_text( 'get_footer_imprint', $fallbacks['footer_imprint'] ?? null );

		return $store;
	}

	/**
	 * Build price, currency, and locale presentation hints for renderers.
	 *
	 * @param string    $currency           Currency code.
	 * @param bool|null $prices_include_tax Optional precomputed prices-include-tax flag.
	 *
	 * @return array<string,mixed>
	 */
	public function build_presentation_hints( string $currency, ?bool $prices_include_tax = null ): array {
		if ( null === $prices_include_tax ) {
			$prices_include_tax = 'yes' === $this->resolve_store_option_string(
				'get_prices_include_tax',
				wc_prices_include_tax() ? 'yes' : 'no'
			);
		}

		return array(
			'prices_entered_with_tax'  => $prices_include_tax,
			'rounding_mode'            => $this->resolve_store_option_string(
				'get_tax_round_at_subtotal',
				get_option( 'woocommerce_tax_round_at_subtotal', 'no' )
			),
			'locale'                   => $this->resolve_locale(),
			'timezone'                 => $this->resolve_store_timezone()->getName(),
			'currency_position'        => $this->resolve_store_option_string(
				'get_currency_position',
				get_option( 'woocommerce_currency_pos', 'left' )
			),
			'currency_symbol'          => get_woocommerce_currency_symbol( $currency ),
			'price_thousand_separator' => $this->resolve_store_string(
				'get_price_thousand_separator',
				wc_get_price_thousand_separator()
			),
			'price_decimal_separator'  => $this->resolve_store_string(
				'get_price_decimal_separator',
				wc_get_price_decimal_separator()
			),
			'price_num_decimals'       => $this->resolve_price_num_decimals(),
			'price_display_suffix'     => $this->resolve_store_string(
				'get_price_display_suffix',
				get_option( 'woocommerce_price_display_suffix', '' )
			),
		);
	}

	/**
	 * Compose `address_lines[]` using the country's WC address format.
	 *
	 * @param array<string,string> $fields Store address fields.
	 *
	 * @return array<int,string>
	 */
	public static function compose_address_lines( array $fields ): array {
		$country = isset( $fields['country'] ) ? (string) $fields['country'] : '';
		$formatted = WC()->countries->get_formatted_address(
			array(
				'first_name' => '',
				'last_name'  => '',
				'company'    => '',
				'address_1'  => $fields['address_1'] ?? '',
				'address_2'  => $fields['address_2'] ?? '',
				'city'       => $fields['city'] ?? '',
				'state'      => $fields['state'] ?? '',
				'postcode'   => $fields['postcode'] ?? '',
				'country'    => $country,
			),
			"\n"
		);

		$lines = preg_split( '/\r?\n/', (string) $formatted );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', $lines ),
				static function ( string $line ): bool {
					return '' !== $line;
				}
			)
		);
	}

	/**
	 * Ensure store tax IDs include display labels for logicless templates.
	 *
	 * @param array<int,array<string,mixed>> $tax_ids Store tax IDs.
	 * @param string                         $locale  Receipt locale.
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_store_tax_id_labels( array $tax_ids, string $locale = '' ): array {
		$labels = Receipt_I18n_Labels::get_labels( $locale );

		return array_map(
			static function ( array $tax_id ) use ( $labels ): array {
				if ( ! empty( $tax_id['label'] ) ) {
					return $tax_id;
				}

				$type            = isset( $tax_id['type'] ) ? (string) $tax_id['type'] : 'other';
				$key             = 'store_tax_id_label_' . $type;
				$tax_id['label'] = $labels[ $key ] ?? $labels['store_tax_id_label_other'];

				return $tax_id;
			},
			$tax_ids
		);
	}

	/**
	 * Resolve an optional free-text store field, treating a blank value as unset.
	 *
	 * @param string      $getter   Store getter method.
	 * @param string|null $fallback Value used when the store has nothing set.
	 *
	 * @return string|null
	 */
	private function resolve_optional_text( string $getter, ?string $fallback ): ?string {
		$value = (string) $this->get_store_value( $getter, '' );

		return '' !== $value ? $value : $fallback;
	}
}
