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
			'software'           => $sections['software'],
			'register'           => $sections['register'],
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
		$document_type = $values['document_type'] ?? 'sale';
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
			'document_type'     => $document_type,
			'sale_time'         => $values['sale_time'] ?? null,
			'sale_tz'           => $values['sale_tz'] ?? '',
			'sale_counter'      => $values['sale_counter'] ?? null,
			'received_at'       => $values['received_at'] ?? null,
			'corrects'          => $values['corrects'] ?? '',
			'is_sale_document'         => 'sale' === $document_type,
			'is_refund_document'       => 'refund' === $document_type,
			'is_void_document'         => 'void' === $document_type,
			'is_cancellation_document' => 'cancellation' === $document_type,
			'is_closure_document'      => in_array( $document_type, array( 'closure', 'xreport', 'x_report' ), true ),
			'is_x_report'              => in_array( $document_type, array( 'xreport', 'x_report' ), true ),
		);
	}
}
