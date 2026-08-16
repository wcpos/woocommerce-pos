<?php
/**
 * Receipt payload assembler service.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Receipt_Payload_Assembler class.
 */
class Receipt_Payload_Assembler {

	/**
	 * Assemble receipt sections in canonical payload order.
	 *
	 * @param array<string,mixed> $sections Already-computed receipt sections.
	 *
	 * @return array<string,mixed>
	 */
	public static function assemble( array $sections ): array {
		return array(
			'order'              => $sections['order'],
			'store'              => $sections['store'],
			'cashier'            => $sections['cashier'],
			'customer'           => $sections['customer'],
			'lines'              => $sections['lines'],
			'fees'               => $sections['fees'],
			'shipping'           => $sections['shipping'],
			'discounts'          => $sections['discounts'],
			'totals'             => $sections['totals'],
			'tax'                => $sections['tax'],
			'tax_summary'        => $sections['tax_summary'],
			'has_tax_summary'    => ! empty( $sections['tax_summary'] ),
			'payments'           => $sections['payments'],
			'refunds'            => $sections['refunds'],
			'fiscal'             => $sections['fiscal'],
			'presentation_hints' => $sections['presentation_hints'],
			'i18n'               => Receipt_I18n_Labels::get_labels( $sections['presentation_hints']['locale'] ?? '' ),
		);
	}

	/**
	 * Assemble fiscal values in canonical section order.
	 *
	 * @param array<string,mixed> $values Already-computed fiscal values.
	 *
	 * @return array<string,mixed>
	 */
	public static function fiscal( array $values ): array {
		return array(
			'immutable_id'      => $values['immutable_id'],
			'receipt_number'    => $values['receipt_number'],
			'sequence'          => $values['sequence'],
			'hash'              => $values['hash'],
			'qr_payload'        => $values['qr_payload'],
			'tax_agency_code'   => $values['tax_agency_code'],
			'signed_at'         => $values['signed_at'],
			'signature_excerpt' => $values['signature_excerpt'],
			'document_label'    => $values['document_label'],
			'is_reprint'        => $values['is_reprint'],
			'reprint_count'     => $values['reprint_count'],
			'extra_fields'      => $values['extra_fields'],
		);
	}
}
