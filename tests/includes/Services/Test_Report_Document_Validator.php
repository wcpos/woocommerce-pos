<?php
/**
 * Report document contract tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader;
use WCPOS\WooCommercePOS\Services\Report_Document_Validator;
use WP_UnitTestCase;

/** Report validation and fixture parity. */
class Test_Report_Document_Validator extends WP_UnitTestCase {
	/** Both grouped and ungrouped documents validate and remain preformatted. */
	public function test_report_fixtures_validate_and_match_field_tree(): void {
		$loader = new Receipt_Preview_Fixture_Loader();
		foreach ( array( 'sales', 'cash-movements' ) as $key ) {
			$document = $loader->build( 'report', $key );
			$this->assertTrue( Report_Document_Validator::validate( $document ) );
			$this->assertSame( $document['report'], Receipt_Data_Schema::format_money_fields( $document, 'USD' )['report'] );
			$this->assert_tree_paths( $document, Receipt_Data_Schema::get_field_tree( 'report' ) );
		}
		$this->assertSame( $loader->build( 'report', 'sales' ), $loader->build( 'report' ) );
		$this->assertSame( $loader->build( 'report' ), $loader->build( 'report', 'unknown' ) );
	}

	/** Report extensions must not acquire receipt money display companions. */
	public function test_report_money_formatter_leaves_the_entire_branch_untouched(): void {
		$document = ( new Receipt_Preview_Fixture_Loader() )->build( 'report' );
		$document['report']['total'] = 12.50;
		$document['report']['rows'][0] = array( 'total' => 12.50 );
		$this->assertSame( $document['report'], Receipt_Data_Schema::format_money_fields( $document )['report'] );
	}

	/** Missing cells and misordered keys fail at their exact location. */
	public function test_report_invalid_cells_name_the_path(): void {
		foreach ( array( 'rows', 'group_rows', 'subtotal', 'totals' ) as $location ) {
			foreach ( array( 'count', 'key' ) as $mutation ) {
				$document = ( new Receipt_Preview_Fixture_Loader() )->build( 'report' );
				$document['report']['rows'] = $document['report']['groups'][0]['rows'];
				switch ( $location ) {
					case 'rows':
						$cells =& $document['report']['rows'][0]['cells'];
						$path = 'report.rows.0.cells';
						break;
					case 'group_rows':
						$cells =& $document['report']['groups'][0]['rows'][0]['cells'];
						$path = 'report.groups.0.rows.0.cells';
						break;
					case 'subtotal':
						$cells =& $document['report']['groups'][0]['subtotal']['cells'];
						$path = 'report.groups.0.subtotal.cells';
						break;
					default:
						$cells =& $document['report']['totals']['cells'];
						$path = 'report.totals.cells';
				}
				if ( 'count' === $mutation ) {
					array_pop( $cells );
				} else {
					$cells[0]['key'] = 'wrong';
				}
				unset( $cells );
				$error = Report_Document_Validator::validate( $document );
				$this->assertWPError( $error );
				$this->assertStringContainsString( $path, $error->get_error_message() );
			}
		}
	}

	/** Schema errors reject invalid modes and omitted totals. */
	public function test_report_invalid_schema_is_rejected(): void {
		$document = ( new Receipt_Preview_Fixture_Loader() )->build( 'report' );
		$document['report']['scope']['mode'] = 'invalid';
		$this->assertWPError( Report_Document_Validator::validate( $document ) );
		$document['report']['scope']['mode'] = 'session';
		unset( $document['report']['totals'] );
		$this->assertWPError( Report_Document_Validator::validate( $document ) );
	}

	/** The column count lets a logic-less template span a heading; it must agree with the columns. */
	public function test_report_column_count_must_match_columns(): void {
		$document = ( new Receipt_Preview_Fixture_Loader() )->build( 'report' );
		$this->assertSame( \count( $document['report']['columns'] ), $document['report']['column_count'] );
		$document['report']['column_count'] = 4;
		$this->assertWPError( Report_Document_Validator::validate( $document ) );
	}

	/** A key starting with "_" would be dropped by the renderers' sanitiser, so the schema forbids it. */
	public function test_report_private_looking_keys_are_rejected(): void {
		$document = ( new Receipt_Preview_Fixture_Loader() )->build( 'report' );
		$document['report']['groups'][0]['rows'][0]['key'] = '_row1';
		$error = Report_Document_Validator::validate( $document );
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'key', $error->get_error_message() );
	}

	/**
	 * Assert populated fixture paths are available in the editor.
	 *
	 * @param array  $data   Fixture subtree.
	 * @param array  $fields Field tree fragment at the same depth.
	 * @param string $path   Dotted path prefix for messages.
	 */
	private function assert_tree_paths( array $data, array $fields, string $path = '' ): void {
		foreach ( $data as $key => $value ) {
			$name = $path . $key;
			$this->assertArrayHasKey( $key, $fields, $name );
			$field = $fields[ $key ];
			if ( ! empty( $field['is_array'] ) ) {
				foreach ( $value as $item ) {
					$this->assert_tree_paths( $item, $field['fields'], $name . '[].' );
				}
			} elseif ( is_array( $value ) && isset( $field['fields'] ) ) {
				$this->assert_tree_paths( $value, $field['fields'], $name . '.' );
			}
		}
	}
}
