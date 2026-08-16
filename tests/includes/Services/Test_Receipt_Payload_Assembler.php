<?php
/**
 * Tests for Receipt_Payload_Assembler.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Payload_Assembler;
use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;
use WP_UnitTestCase;

/**
 * Test_Receipt_Payload_Assembler class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Payload_Assembler extends WP_UnitTestCase {

	/**
	 * Test assemble returns the expected top-level key set.
	 */
	public function test_assemble_returns_expected_key_set(): void {
		$payload = Receipt_Payload_Assembler::assemble( $this->get_sections() );

		$this->assertEqualsCanonicalizing( $this->get_expected_payload_keys(), array_keys( $payload ) );
	}

	/**
	 * Test assemble returns top-level keys in the canonical order.
	 */
	public function test_assemble_returns_keys_in_expected_order(): void {
		$sections = array_reverse( $this->get_sections(), true );
		$payload  = Receipt_Payload_Assembler::assemble( $sections );

		$this->assertSame( $this->get_expected_payload_keys(), array_keys( $payload ) );
	}

	/**
	 * Test assemble preserves every already-computed section value.
	 */
	public function test_assemble_preserves_section_values(): void {
		$sections = $this->get_sections();
		$payload  = Receipt_Payload_Assembler::assemble( $sections );

		foreach ( $sections as $key => $value ) {
			$this->assertSame( $value, $payload[ $key ] );
		}
		$this->assertSame( Receipt_I18n_Labels::get_labels( 'en_US' ), $payload['i18n'] );
	}

	/**
	 * Test assemble derives a false tax-summary flag from an empty section.
	 */
	public function test_assemble_sets_has_tax_summary_false_for_empty_summary(): void {
		$payload = Receipt_Payload_Assembler::assemble( $this->get_sections() );

		$this->assertFalse( $payload['has_tax_summary'] );
	}

	/**
	 * Test assemble derives a true tax-summary flag from a populated section.
	 */
	public function test_assemble_sets_has_tax_summary_true_for_populated_summary(): void {
		$payload = Receipt_Payload_Assembler::assemble(
			$this->get_sections(
				array(
					array( 'code' => 'VAT' ),
				)
			)
		);

		$this->assertTrue( $payload['has_tax_summary'] );
	}

	/**
	 * Test fiscal returns the complete fiscal key set in canonical order.
	 */
	public function test_fiscal_returns_expected_key_set(): void {
		$values = array(
			'immutable_id'      => 'receipt-id',
			'receipt_number'    => '42',
			'sequence'          => 42,
			'hash'              => 'hash',
			'qr_payload'        => 'qr',
			'tax_agency_code'   => 'agency',
			'signed_at'         => '2026-08-15T00:00:00Z',
			'signature_excerpt' => 'ABCD',
			'document_label'    => 'Tax Receipt',
			'is_reprint'        => false,
			'reprint_count'     => 0,
			'extra_fields'      => array(),
		);

		$fiscal = Receipt_Payload_Assembler::fiscal( array_reverse( $values, true ) );

		$this->assertSame( $values, $fiscal );
	}

	/**
	 * Get receipt sections for assembler tests.
	 *
	 * @param array $tax_summary Tax summary section.
	 *
	 * @return array<string,mixed>
	 */
	private function get_sections( array $tax_summary = array() ): array {
		return array(
			'order'              => array( 'id' => 42 ),
			'store'              => array( 'name' => 'Test Store' ),
			'cashier'            => array( 'name' => 'Cashier' ),
			'customer'           => array( 'name' => 'Customer' ),
			'lines'              => array(),
			'fees'               => array(),
			'shipping'           => array(),
			'discounts'          => array(),
			'totals'             => array( 'total' => 10.0 ),
			'tax'                => array(),
			'tax_summary'        => $tax_summary,
			'payments'           => array(),
			'refunds'            => array(),
			'fiscal'             => array(),
			'presentation_hints' => array( 'locale' => 'en_US' ),
		);
	}

	/**
	 * Get canonical top-level payload keys.
	 *
	 * @return string[]
	 */
	private function get_expected_payload_keys(): array {
		return array(
			'order',
			'store',
			'cashier',
			'customer',
			'lines',
			'fees',
			'shipping',
			'discounts',
			'totals',
			'tax',
			'tax_summary',
			'has_tax_summary',
			'payments',
			'refunds',
			'fiscal',
			'presentation_hints',
			'i18n',
		);
	}
}
