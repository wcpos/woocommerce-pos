<?php
/**
 * Tests for the template editor's sample receipt data.
 *
 * The editor seeds sample-mode previews with Single_Template::get_sample_receipt_data().
 * It must carry formatted `*_display` money companions, otherwise the starter
 * templates' `{{line_total_display}}` / `{{totals.total_incl_display}}` (and the
 * PHP starter's wc_price() inputs) have nothing to render.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Admin\Templates;

use WCPOS\WooCommercePOS\Admin\Templates\Single_Template;
use WC_REST_Unit_Test_Case;

/**
 * Test_Single_Template_Sample_Data class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Single_Template_Sample_Data extends WC_REST_Unit_Test_Case {

	/**
	 * The editor's sample data must carry formatted `*_display` money companions
	 * alongside the bare numeric keys, matching the live /preview endpoint.
	 */
	public function test_get_sample_receipt_data_includes_formatted_money_companions(): void {
		// Act.
		$sample = Single_Template::get_sample_receipt_data();

		// Assert: grand total has a formatted companion next to the numeric value.
		$this->assertArrayHasKey( 'totals', $sample );
		$this->assertArrayHasKey( 'total_incl', $sample['totals'] );
		$this->assertArrayHasKey( 'total_incl_display', $sample['totals'] );
		$this->assertIsString( $sample['totals']['total_incl_display'] );
		$this->assertIsNumeric( $sample['totals']['total_incl'] );

		// Assert: line items have a formatted companion too.
		$this->assertArrayHasKey( 'lines', $sample );
		$this->assertNotEmpty( $sample['lines'] );
		$this->assertArrayHasKey( 0, $sample['lines'] );
		$first_line = $sample['lines'][0];
		$this->assertArrayHasKey( 'line_total', $first_line );
		$this->assertArrayHasKey( 'line_total_display', $first_line );
		$this->assertIsString( $first_line['line_total_display'] );
		$this->assertIsNumeric( $first_line['line_total'] );
	}
}
