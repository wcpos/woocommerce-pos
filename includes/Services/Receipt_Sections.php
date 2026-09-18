<?php
/**
 * Shared receipt contract row shapes and aggregate rules.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Receipt sections accept priced values; builders own order reading and pricing. */
final class Receipt_Sections {
	/**
	 * Shape one line in live receipt key order, preserving nullable price bases.
	 *
	 * @param array $line Identity, metadata and incl/excl amount pairs.
	 * @param bool  $display_incl Whether bare amounts include tax.
	 * @return array
	 */
	public static function line( array $line, bool $display_incl ): array {
		$row = array();
		foreach ( array( 'key', 'sku', 'name', 'qty', 'qty_refunded' ) as $key ) {
			$row[ $key ] = $line[ $key ];
		}
		foreach ( array( 'unit_subtotal', 'unit_price', 'line_subtotal', 'discounts', 'line_total' ) as $key ) {
			$row = array_merge( $row, self::triple( $key, $line[ $key ], $display_incl ) );
		}
		foreach ( array( 'total_refunded', 'taxes', 'meta', 'attributes' ) as $key ) {
			$row[ $key ] = $line[ $key ];
		}
		foreach ( array( 'regular_price', 'selling_price', 'unit_savings', 'line_regular_total', 'line_selling_total', 'line_savings' ) as $key ) {
			$row = array_merge( $row, self::triple( $key, $line[ $key ], $display_incl ) );
		}
		$row['savings_in_discounts'] = $line['savings_in_discounts'];
		return $row;
	}

	/**
	 * Shape a fee row.
	 *
	 * @param string $label Display label.
	 * @param array  $total Incl/excl amounts.
	 * @param array  $taxes Item taxes.
	 * @param array  $meta Metadata pairs.
	 * @param bool   $display_incl Whether bare amounts include tax.
	 * @return array
	 */
	public static function fee( string $label, array $total, array $taxes, array $meta, bool $display_incl ): array {
		return array_merge(
			array( 'label' => $label ),
			self::triple( 'total', $total, $display_incl ),
			array(
				'taxes' => $taxes,
				'meta' => $meta,
			)
		);
	}

	/**
	 * Shape a shipping row.
	 *
	 * @param string $label Display label.
	 * @param string $method_id Shipping method.
	 * @param array  $total Incl/excl amounts.
	 * @param array  $taxes Item taxes.
	 * @param array  $meta Metadata pairs.
	 * @param bool   $display_incl Whether bare amounts include tax.
	 * @return array
	 */
	public static function shipping( string $label, string $method_id, array $total, array $taxes, array $meta, bool $display_incl ): array {
		return array_merge(
			array(
				'label' => $label,
				'method_id' => $method_id,
			),
			self::triple( 'total', $total, $display_incl ),
			array(
				'taxes' => $taxes,
				'meta' => $meta,
			)
		);
	}

	/**
	 * Shape a discount row.
	 *
	 * @param string $label Display label.
	 * @param string $code Coupon code.
	 * @param string $discount_type Coupon type.
	 * @param array  $total Incl/excl amounts.
	 * @param bool   $display_incl Whether bare amounts include tax.
	 * @return array
	 */
	public static function discount( string $label, string $code, string $discount_type, array $total, bool $display_incl ): array {
		return array_merge(
			array(
				'label' => $label,
				'code' => $code,
				'discount_type' => $discount_type,
			),
			self::triple( 'total', $total, $display_incl )
		);
	}

	/**
	 * Shape a payment row.
	 *
	 * @param string $method_id Payment method.
	 * @param string $method_title Display title.
	 * @param float  $amount Paid amount.
	 * @param string $transaction_id Transaction reference.
	 * @param float  $tendered Tendered amount.
	 * @param float  $change Change returned.
	 * @return array
	 */
	public static function payment( string $method_id, string $method_title, float $amount, string $transaction_id, float $tendered, float $change ): array {
		return array(
			'method_id' => $method_id,
			'method_title' => $method_title,
			'amount' => $amount,
			'transaction_id' => $transaction_id,
			'tendered' => $tendered,
			'change' => $change,
		);
	}

	/**
	 * Shape a tax summary, keeping unknown rates and bases null.
	 *
	 * @param string     $code Rate identifier.
	 * @param float      $rate Percentage rate.
	 * @param string     $label Display label.
	 * @param bool       $compound Whether the rate is compound.
	 * @param float|null $taxable_excl Known taxable base, if any.
	 * @param float      $tax_amount Tax charged.
	 * @return array
	 */
	public static function tax_summary_entry( string $code, float $rate, string $label, bool $compound, ?float $taxable_excl, float $tax_amount ): array {
		return array(
			'code' => $code,
			'rate' => $rate > 0 ? $rate : null,
			'label' => $label,
			'compound' => $compound,
			'taxable_amount_excl' => $taxable_excl,
			'tax_amount' => $tax_amount,
			'taxable_amount_incl' => null !== $taxable_excl ? $taxable_excl + $tax_amount : null,
		);
	}

	/**
	 * Aggregate shaped lines and order-level amounts into receipt totals.
	 *
	 * @param array $lines Shaped line rows.
	 * @param array $amounts Discount pair, tax, inclusive total, paid, change and refund totals.
	 * @param bool  $display_incl Whether bare amounts include tax.
	 * @return array
	 */
	public static function totals( array $lines, array $amounts, bool $display_incl ): array {
		// Legacy POS lines already include regular-to-selling savings in WooCommerce's
		// discount total. Add only current-shape savings to total_saved to avoid overlap.
		$sale_savings_totals = array(
			'incl' => 0.0,
			'excl' => 0.0,
		);
		$additional_savings  = array(
			'incl' => 0.0,
			'excl' => 0.0,
		);
		$savings_complete    = array(
			'incl' => true,
			'excl' => true,
		);
		$price_precision = wc_get_price_decimals();
		foreach ( $lines as $line ) {
			foreach ( array( 'incl', 'excl' ) as $basis ) {
				$key = 'line_savings_' . $basis;
				if ( ! isset( $line[ $key ] ) || ! is_numeric( $line[ $key ] ) ) {
					$savings_complete[ $basis ] = false;
					continue;
				}

				$line_savings = (float) $line[ $key ];
				$sale_savings_totals[ $basis ] += $line_savings;
				if ( empty( $line['savings_in_discounts'] ) ) {
					$subtotal_key = 'line_subtotal_' . $basis;
					$selling_key  = 'line_selling_total_' . $basis;
					if (
						$line_savings > 0.0
						&& (
							! isset( $line[ $subtotal_key ], $line[ $selling_key ] )
							|| round( abs( (float) $line[ $subtotal_key ] - (float) $line[ $selling_key ] ), $price_precision ) > 0.0
						)
					) {
						$savings_complete[ $basis ] = false;
						continue;
					}
					$additional_savings[ $basis ] += $line_savings;
				}
			}
		}

		$total_saved = array(
			'incl' => $savings_complete['incl'] ? $amounts['discount_total']['incl'] + $additional_savings['incl'] : null,
			'excl' => $savings_complete['excl'] ? $amounts['discount_total']['excl'] + $additional_savings['excl'] : null,
		);
		foreach ( array( 'incl', 'excl' ) as $basis ) {
			if ( ! $savings_complete[ $basis ] ) {
				$sale_savings_totals[ $basis ] = null;
			}
		}
		$display_basis = $display_incl ? 'incl' : 'excl';

		$subtotal_excl = array_sum( array_column( $lines, 'line_subtotal_excl' ) );
		$subtotal_incl = array_sum( array_column( $lines, 'line_subtotal_incl' ) );

		// Item count summaries — useful for packing slips and kitchen tickets
		// where Mustache can't sum/count an array at render time.
		$total_qty  = (float) array_sum( array_column( $lines, 'qty' ) );
		$line_count = \count( $lines );

		$tax_total = $amounts['tax_total'];
		$total = $amounts['total'];
		$total_excl = $total - $tax_total;
		$refund_total = $amounts['refund_total'];
		// Templates render the customer-facing balance after a partial refund.
		// Stays at 0 when nothing was refunded so detailed-receipt's section
		// guard `{{#totals.net_total}}…{{/totals.net_total}}` collapses.
		$net_total = $refund_total > 0 ? max( 0.0, $total - $refund_total ) : 0.0;

		return array(
			'subtotal'                => $display_incl ? $subtotal_incl : $subtotal_excl,
			'subtotal_incl'           => $subtotal_incl,
			'subtotal_excl'           => $subtotal_excl,
			'discount_total'          => $display_incl ? $amounts['discount_total']['incl'] : $amounts['discount_total']['excl'],
			'discount_total_incl'     => $amounts['discount_total']['incl'],
			'discount_total_excl'     => $amounts['discount_total']['excl'],
			'sale_savings_total'      => $sale_savings_totals[ $display_basis ],
			'sale_savings_total_incl' => $sale_savings_totals['incl'],
			'sale_savings_total_excl' => $sale_savings_totals['excl'],
			'total_saved'             => $total_saved[ $display_basis ],
			'total_saved_incl'        => $total_saved['incl'],
			'total_saved_excl'        => $total_saved['excl'],
			'total_saved_complete'    => $savings_complete[ $display_basis ],
			'tax_total'               => $tax_total,
			'total'                   => $display_incl ? $total : $total_excl,
			'total_incl'              => $total,
			'total_excl'              => $total_excl,
			'paid_total'              => $amounts['paid_total'],
			'change_total'            => $amounts['change_total'],
			'refund_total'            => $refund_total,
			'net_total'               => $net_total,
			'total_qty'               => $total_qty,
			'line_count'              => $line_count,
		);
	}

	/**
	 * Attach labels: explicit label → scoped type key → scoped other fallback.
	 *
	 * @param array  $tax_ids Tax ID rows.
	 * @param string $scope Customer or store.
	 * @param string $locale Receipt locale.
	 * @return array
	 */
	public static function label_tax_ids( array $tax_ids, string $scope, string $locale = '' ): array {
		$labels = Receipt_I18n_Labels::get_labels( $locale );
		$prefix = $scope . '_tax_id_label_';

		return array_map(
			static function ( array $tax_id ) use ( $labels, $prefix ): array {
				if ( ! empty( $tax_id['label'] ) ) {
					return $tax_id;
				}

				$type            = isset( $tax_id['type'] ) ? (string) $tax_id['type'] : 'other';
				$key             = $prefix . $type;
				$tax_id['label'] = $labels[ $key ] ?? $labels[ $prefix . 'other' ];

				return $tax_id;
			},
			$tax_ids
		);
	}

	/**
	 * Resolve non-empty variation attributes to tag-free label/value pairs.
	 *
	 * @param \WC_Product_Variation $variation Variation product.
	 * @return array
	 */
	public static function variation_attribute_pairs( \WC_Product_Variation $variation ): array {
		$pairs = array();
		foreach ( $variation->get_variation_attributes() as $attribute_key => $attribute_value ) {
			if ( '' === (string) $attribute_value ) {
				continue;
			}

			$taxonomy = preg_replace( '/^attribute_/', '', (string) $attribute_key );
			$value    = $variation->get_attribute( $taxonomy );
			$pairs[]  = array(
				'key'   => wp_strip_all_tags( wc_attribute_label( $taxonomy, $variation ) ),
				'value' => wp_strip_all_tags( '' !== $value ? $value : (string) $attribute_value ),
			);
		}

		return $pairs;
	}

	/**
	 * Map a basis pair to its display value and two explicit values.
	 *
	 * @param string $key Bare field name.
	 * @param array  $pair Nullable incl/excl amounts.
	 * @param bool   $display_incl Whether the bare amount includes tax.
	 * @return array
	 */
	private static function triple( string $key, array $pair, bool $display_incl ): array {
		return array(
			$key => $pair[ $display_incl ? 'incl' : 'excl' ],
			$key . '_incl' => $pair['incl'],
			$key . '_excl' => $pair['excl'],
		);
	}
}
