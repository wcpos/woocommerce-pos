<?php
/**
 * Per-lane thermal text metrics (pure unit tests, no REST dispatch).
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Text_Layout;
use WP_UnitTestCase;

/**
 * Thermal_Text_Layout_Instance_Test class.
 */
class Thermal_Text_Layout_Instance_Test extends WP_UnitTestCase {

	/**
	 * Direct size input cannot exceed the lane's command ceiling.
	 */
	public function test_size_over_lane_ceiling_uses_applied_scale(): void {
		// Arrange: direct input bypasses the parser's separate 8x clamp.
		$escpos   = new Thermal_Text_Layout( 48, 8 );
		$starprnt = new Thermal_Text_Layout( 48, 6 );

		// Act.
		$escpos->enter_size( 9, 9 );
		$starprnt->enter_size( 9, 9 );

		// Assert: alignment must use what the printer actually applies.
		$this->assertSame( array( 'width' => 8, 'height' => 8 ), $escpos->applied_scale() );
		$this->assertSame( array( 'width' => 6, 'height' => 6 ), $starprnt->applied_scale() );
		$this->assertSame( 2, $escpos->measure_padding( 'center', 'AB' ) );
		$this->assertSame( 3, $starprnt->measure_padding( 'center', 'AB' ) );
	}

	/**
	 * Nested sizes replace, rather than multiply, and restore both axes.
	 */
	public function test_size_nested_stack_restores_each_parent_scale(): void {
		// Arrange.
		$layout = new Thermal_Text_Layout( 48, 6 );
		$layout->enter_size( 9, 2 );

		// Act.
		$layout->enter_size( 2, 9 );
		$inner = $layout->applied_scale();
		$layout->leave_size();
		$outer = $layout->applied_scale();
		$layout->leave_size();
		$layout->leave_size(); // An unmatched leave must preserve the normal-size base.

		// Assert.
		$this->assertSame( array( 'width' => 2, 'height' => 6 ), $inner );
		$this->assertSame( array( 'width' => 6, 'height' => 2 ), $outer );
		$this->assertSame( array( 'width' => 1, 'height' => 1 ), $layout->applied_scale() );
	}

	/**
	 * Literal spaces occupy scaled columns; height does not affect padding.
	 */
	public function test_padding_scaled_text_measures_printed_columns(): void {
		// Arrange.
		$layout = new Thermal_Text_Layout( 48, 8 );
		$text   = 'Evans Hobby and Tech';

		// Act.
		$layout->enter_size( 2, 2 );
		$center = $layout->measure_padding( 'center', $text );
		$right  = $layout->measure_padding( 'right', $text );
		$layout->leave_size();
		$layout->enter_size( 1, 2 );

		// Assert: 20 glyphs at 2x leave 8 columns: 2 center or 4 right spaces; at 1x, 28 remain.
		$this->assertSame( 2, $center );
		$this->assertSame( 4, $right );
		$this->assertSame( 14, $layout->measure_padding( 'center', $text ) );
		$this->assertSame( 28, $layout->measure_padding( 'right', $text ) );
		$this->assertSame( 0, $layout->measure_padding( 'left', $text ) );
	}

	/**
	 * Full-width glyphs and fractional margins cannot overrun the paper.
	 */
	public function test_padding_wide_glyphs_and_overflow_clamp_to_whole_spaces(): void {
		// Arrange.
		$layout = new Thermal_Text_Layout( 13, 8 );
		$layout->enter_size( 2, 1 );

		// Act.
		$center   = $layout->measure_padding( 'center', '日本' );
		$right    = $layout->measure_padding( 'right', '日本' );
		$overflow = $layout->measure_padding( 'right', '日本語文' );

		// Assert: four cells at 2x leave five printed columns.
		$this->assertSame( 1, $center );
		$this->assertSame( 2, $right );
		$this->assertSame( 0, $overflow );
	}

	/**
	 * Row widths retain the existing unscaled column-distribution contract.
	 */
	public function test_row_widths_inside_size_preserve_paper_column_distribution(): void {
		// Arrange.
		$layout = new Thermal_Text_Layout( 13, 8 );
		$layout->enter_size( 2, 1 );

		// Act.
		$widths = $layout->measure_row_widths(
			array( array( 'width' => 4 ), array( 'width' => '*' ), array( 'width' => '*' ) )
		);

		// Assert.
		$this->assertSame( 13, $layout->columns() );
		$this->assertSame( array( 4, 4, 5 ), $widths );
	}
}
