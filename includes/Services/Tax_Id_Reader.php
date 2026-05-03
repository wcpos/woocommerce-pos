<?php
/**
 * Tax ID Reader.
 *
 * Reads customer tax IDs from a WooCommerce order or user, falling back across
 * the meta-key inventory used by the major third-party tax-ID plugins
 * (WooCommerce EU VAT Number, Aelia EU VAT Assistant, Brazilian Market on
 * WooCommerce, NIF/CIF Spain, Germanized, Italian add-ons, etc.) and
 * normalising results into the canonical Tax ID shape:
 *
 *   array(
 *       'type'     => 'eu_vat'|'gb_vat'|'au_abn'|'br_cpf'|'br_cnpj'|...,
 *       'value'    => string,
 *       'country'  => string|null,   // ISO 3166-1 alpha-2
 *       'label'    => string|null,
 *       'verified' => array|null,    // { status, verified_at, verified_name, source }
 *   )
 *
 * Pure-logic parsing is exposed via `parse_meta_map()` for unit testability.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Abstract_Order;

/**
 * Tax_Id_Reader class.
 */
class Tax_Id_Reader {
	/**
	 * Canonical meta key for WCPOS-written tax IDs (JSON-encoded TaxId[]).
	 *
	 * @var string
	 */
	const CANONICAL_META_KEY = '_wcpos_tax_ids';

	/**
	 * Sentinel handler used for generic VAT meta (EU vs GB inferred from country).
	 *
	 * @var string
	 */
	const HANDLER_GENERIC_VAT = '__generic_vat__';

	/**
	 * Sentinel handler used for the Aelia EU VAT Assistant structured array.
	 *
	 * @var string
	 */
	const HANDLER_AELIA_EU_VAT = '__aelia_eu_vat__';

	/**
	 * Order-meta fallback chain. Keys are real meta keys; values are either a
	 * tax-ID type constant (from Tax_Id_Types) or one of the HANDLER_* sentinels.
	 *
	 * Iteration order is the read priority; ties are broken by dedupe-on-first-seen.
	 *
	 * @var array<string,string>
	 */
	const ORDER_FALLBACK_KEYS = array(
		'_eu_vat_data'             => self::HANDLER_AELIA_EU_VAT,
		'_vat_number'              => self::HANDLER_GENERIC_VAT,
		'_billing_vat_number'      => self::HANDLER_GENERIC_VAT,
		'_billing_eu_vat_number'   => self::HANDLER_GENERIC_VAT,
		'_billing_vat'             => self::HANDLER_GENERIC_VAT,
		'_vat_id'                  => self::HANDLER_GENERIC_VAT,
		'_billing_vat_id'          => self::HANDLER_GENERIC_VAT,
		'_billing_cpf'             => Tax_Id_Types::TYPE_BR_CPF,
		'_billing_cnpj'            => Tax_Id_Types::TYPE_BR_CNPJ,
		'_billing_gstin'           => Tax_Id_Types::TYPE_IN_GST,
		'_billing_cf'              => Tax_Id_Types::TYPE_IT_CF,
		'_billing_codice_fiscale'  => Tax_Id_Types::TYPE_IT_CF,
		'_billing_piva'            => Tax_Id_Types::TYPE_IT_PIVA,
		'_billing_partita_iva'     => Tax_Id_Types::TYPE_IT_PIVA,
		'_billing_nif'             => Tax_Id_Types::TYPE_ES_NIF,
		'_billing_dni'             => Tax_Id_Types::TYPE_AR_CUIT,
		'_billing_cuit'            => Tax_Id_Types::TYPE_AR_CUIT,
		'_billing_tax_number'      => Tax_Id_Types::TYPE_OTHER,
	);

	/**
	 * User-meta variants of the order fallback keys.
	 *
	 * @return array<int,string>
	 */
	public static function fallback_user_meta_keys(): array {
		$keys = array();
		foreach ( array_keys( self::ORDER_FALLBACK_KEYS ) as $meta_key ) {
			$keys[] = ltrim( $meta_key, '_' );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Read tax IDs for a WooCommerce order. Reads only from the order's own meta
	 * (the order is treated as the snapshot — it is never re-pulled from the
	 * customer record).
	 *
	 * @param WC_Abstract_Order $order WooCommerce order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function read_for_order( WC_Abstract_Order $order ): array {
		$billing_country = strtoupper( (string) $order->get_billing_country() );

		$meta_map                              = array();
		$meta_map[ self::CANONICAL_META_KEY ]  = $order->get_meta( self::CANONICAL_META_KEY, true );
		$has_canonical                        = ! empty( $meta_map[ self::CANONICAL_META_KEY ] );
		$owned_keys                           = (array) $order->get_meta( Tax_Id_Writer::OWNED_KEYS_META_KEY, true );

		foreach ( array_keys( self::ORDER_FALLBACK_KEYS ) as $meta_key ) {
			if ( $has_canonical && \in_array( $meta_key, $owned_keys, true ) ) {
				continue;
			}
			$meta_map[ $meta_key ] = $order->get_meta( $meta_key, true );
		}

		return self::parse_meta_map( $meta_map, $billing_country );
	}

	/**
	 * Read tax IDs for a WP user (customer record). User meta uses the same key
	 * set as orders but without the leading underscore (WooCommerce convention),
	 * so we look up both variants for resilience.
	 *
	 * @param int         $user_id         User ID.
	 * @param null|string $billing_country Optional pre-fetched billing country (ISO alpha-2).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function read_for_user( int $user_id, $billing_country = null ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( null === $billing_country ) {
			$billing_country = (string) get_user_meta( $user_id, 'billing_country', true );
		}
		$billing_country = strtoupper( (string) $billing_country );

		$meta_map = array();

		// Canonical: stored on the user with the underscore-prefixed key.
		$meta_map[ self::CANONICAL_META_KEY ] = get_user_meta( $user_id, self::CANONICAL_META_KEY, true );
		$has_canonical                       = ! empty( $meta_map[ self::CANONICAL_META_KEY ] );
		$owned_keys                          = (array) get_user_meta( $user_id, Tax_Id_Writer::OWNED_KEYS_META_KEY, true );

		foreach ( array_keys( self::ORDER_FALLBACK_KEYS ) as $meta_key ) {
			if ( $has_canonical && \in_array( $meta_key, $owned_keys, true ) ) {
				continue;
			}

			// User-meta variant: strip the leading underscore (WC convention).
			$user_key              = ltrim( $meta_key, '_' );
			$meta_map[ $meta_key ] = get_user_meta( $user_id, $user_key, true );

			// Some plugins also store underscore-prefixed user meta; honour it as fallback.
			if ( '' === $meta_map[ $meta_key ] ) {
				$meta_map[ $meta_key ] = get_user_meta( $user_id, $meta_key, true );
			}
		}

		return self::parse_meta_map( $meta_map, $billing_country );
	}

	/**
	 * Pure-logic parser. Takes a fully-collected meta map and emits a deduped
	 * array of canonical TaxId entries.
	 *
	 * @param array<string,mixed> $meta_map        Map of meta_key => raw meta value.
	 * @param null|string         $billing_country ISO alpha-2 country code or null.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function parse_meta_map( array $meta_map, $billing_country = null ): array {
		$billing_country = null === $billing_country ? null : strtoupper( (string) $billing_country );
		$results         = array();

		// 1. Canonical seed (highest priority).
		if ( ! empty( $meta_map[ self::CANONICAL_META_KEY ] ) ) {
			$canonical = self::parse_canonical( $meta_map[ self::CANONICAL_META_KEY ] );
			if ( null !== $canonical ) {
				foreach ( $canonical as $tax_id ) {
					$results[] = $tax_id;
				}
			}
		}

		// 2. Fallback keys, in declared order.
		foreach ( self::ORDER_FALLBACK_KEYS as $meta_key => $handler ) {
			if ( ! isset( $meta_map[ $meta_key ] ) ) {
				continue;
			}
			$raw = $meta_map[ $meta_key ];
			if ( '' === $raw || array() === $raw ) {
				continue;
			}

			if ( self::HANDLER_AELIA_EU_VAT === $handler ) {
				$parsed = self::parse_aelia( $raw );
			} elseif ( self::HANDLER_GENERIC_VAT === $handler ) {
				$parsed = self::parse_generic_vat( (string) $raw, $billing_country );
			} else {
				$parsed = self::build_tax_id( $handler, (string) $raw );
			}

			if ( null !== $parsed ) {
				$results[] = $parsed;
			}
		}

		return self::dedupe( $results );
	}

	/**
	 * Parse the canonical `_wcpos_tax_ids` value, which may be a JSON-encoded
	 * string (preferred) or an already-decoded native PHP array.
	 *
	 * @param mixed $raw Meta value.
	 *
	 * @return null|array<int,array<string,mixed>>
	 */
	private static function parse_canonical( $raw ) {
		if ( \is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! \is_array( $decoded ) ) {
				return null;
			}
			$raw = $decoded;
		}

		if ( ! \is_array( $raw ) ) {
			return null;
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			$type  = isset( $entry['type'] ) ? (string) $entry['type'] : '';
			$value = isset( $entry['value'] ) ? (string) $entry['value'] : '';
			if ( '' === $value ) {
				continue;
			}
			if ( ! Tax_Id_Types::is_valid_type( $type ) ) {
				$type = Tax_Id_Types::TYPE_OTHER;
			}

			$tax_id = self::build_tax_id( $type, $value );
			if ( null === $tax_id ) {
				continue;
			}

			// Preserve optional fields from the canonical record.
			if ( isset( $entry['country'] ) && '' !== $entry['country'] ) {
				$tax_id['country'] = strtoupper( (string) $entry['country'] );
			}
			if ( isset( $entry['label'] ) && '' !== $entry['label'] ) {
				$tax_id['label'] = (string) $entry['label'];
			}
			if ( isset( $entry['verified'] ) && \is_array( $entry['verified'] ) ) {
				$tax_id['verified'] = $entry['verified'];
			}

			$out[] = $tax_id;
		}

		return $out;
	}

	/**
	 * Parse Aelia EU VAT Assistant's `_eu_vat_data` structure.
	 *
	 * Expected shape (PHP array, post-unserialize):
	 *   array(
	 *       'vat_number'   => 'IT12345678901',
	 *       'country'      => 'IT',
	 *       'is_valid'     => true|false,
	 *       'company_name' => string|null,
	 *       'request_date' => string|null,
	 *   )
	 *
	 * @param mixed $raw Meta value.
	 *
	 * @return null|array<string,mixed>
	 */
	private static function parse_aelia( $raw ) {
		// Tolerate a serialized string (rare — WP usually unserializes for us).
		if ( \is_string( $raw ) ) {
			$maybe = @unserialize( $raw, array( 'allowed_classes' => false ) );
			if ( false !== $maybe || 'b:0;' === $raw ) {
				$raw = $maybe;
			}
		}
		if ( ! \is_array( $raw ) ) {
			return null;
		}

		$value = isset( $raw['vat_number'] ) ? (string) $raw['vat_number'] : '';
		if ( '' === $value ) {
			return null;
		}

		$country = isset( $raw['country'] ) ? strtoupper( (string) $raw['country'] ) : null;
		$type    = ( null !== $country && 'GB' === $country ) ? Tax_Id_Types::TYPE_GB_VAT : Tax_Id_Types::TYPE_EU_VAT;

		$tax_id = self::build_tax_id( $type, $value );
		if ( null === $tax_id ) {
			return null;
		}
		if ( null !== $country ) {
			$tax_id['country'] = $country;
		}

		// Map Aelia's is_valid into our verified shape.
		if ( isset( $raw['is_valid'] ) ) {
			$status                     = $raw['is_valid'] ? 'verified' : 'unverified';
			$verified                   = array(
				'status' => $status,
				'source' => 'aelia',
			);
			if ( ! empty( $raw['company_name'] ) ) {
				$verified['verified_name'] = (string) $raw['company_name'];
			}
			if ( ! empty( $raw['request_date'] ) ) {
				$verified['verified_at'] = (string) $raw['request_date'];
			}
			$tax_id['verified'] = $verified;
		}

		return $tax_id;
	}

	/**
	 * Parse a generic VAT meta value. Disambiguates EU vs GB based on:
	 *
	 *   1. A two-letter country prefix in the value itself (e.g. "DE123456789").
	 *   2. The order's billing country.
	 *   3. Falls back to `eu_vat` with no country if both are absent.
	 *
	 * @param string      $raw_value       Raw meta value.
	 * @param null|string $billing_country ISO alpha-2 country code.
	 *
	 * @return null|array<string,mixed>
	 */
	private static function parse_generic_vat( string $raw_value, $billing_country ) {
		$value = self::normalize_value( $raw_value );
		if ( '' === $value ) {
			return null;
		}

		$country = null;
		$type    = Tax_Id_Types::TYPE_EU_VAT;

		// Detect a two-letter country prefix at the start of the value.
		if ( preg_match( '/^([A-Z]{2})([0-9A-Z].*)$/', $value, $matches ) ) {
			$prefix = $matches[1];
			if ( 'GB' === $prefix ) {
				$country = 'GB';
				$type    = Tax_Id_Types::TYPE_GB_VAT;
			} elseif ( Tax_Id_Types::is_eu_vat_country( $prefix ) ) {
				$country = $prefix;
				$type    = Tax_Id_Types::TYPE_EU_VAT;
			}
		}

		// No prefix — fall back to billing country.
		if ( null === $country && null !== $billing_country && '' !== $billing_country ) {
			if ( 'GB' === $billing_country ) {
				$country = 'GB';
				$type    = Tax_Id_Types::TYPE_GB_VAT;
			} elseif ( Tax_Id_Types::is_eu_vat_country( $billing_country ) ) {
				$country = $billing_country;
				$type    = Tax_Id_Types::TYPE_EU_VAT;
			}
		}

		$tax_id = self::build_tax_id( $type, $value );
		if ( null === $tax_id ) {
			return null;
		}
		if ( null !== $country ) {
			$tax_id['country'] = $country;
		}

		return $tax_id;
	}

	/**
	 * Build a canonical TaxId entry from a (type, value) pair, applying default
	 * country derivation. Returns null for empty values.
	 *
	 * @param string $type  Type constant.
	 * @param string $value Raw value.
	 *
	 * @return null|array<string,mixed>
	 */
	private static function build_tax_id( string $type, string $value ) {
		$normalized = self::normalize_value( $value );
		if ( '' === $normalized ) {
			return null;
		}

		$tax_id = array(
			'type'     => $type,
			'value'    => $normalized,
			'country'  => Tax_Id_Types::country_for_type( $type ),
			'label'    => null,
			'verified' => null,
		);

		return $tax_id;
	}

	/**
	 * Normalise a tax-ID value: trim, collapse whitespace, uppercase.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function normalize_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		// Collapse internal whitespace.
		$value = (string) preg_replace( '/\s+/', '', $value );

		return strtoupper( $value );
	}

	/**
	 * Dedupe an array of TaxId entries by (type, value), keeping the first
	 * occurrence (which is the highest-priority source).
	 *
	 * @param array<int,array<string,mixed>> $tax_ids Tax ID list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function dedupe( array $tax_ids ): array {
		$seen = array();
		$out  = array();
		foreach ( $tax_ids as $tax_id ) {
			$key = $tax_id['type'] . '|' . $tax_id['value'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tax_id;
		}

		return $out;
	}
}
