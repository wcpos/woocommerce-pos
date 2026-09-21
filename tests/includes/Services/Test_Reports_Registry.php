<?php
/**
 * Report declaration tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Reports_Registry;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;

/** Invalid extensions must not hide valid declarations or alter the core schema. */
class Test_Reports_Registry extends \WC_Unit_Test_Case {
	/** Reset the request cache between simulated requests. */
	public function setUp(): void {
		parent::setUp();
		$this->reset_registry();
	}

	/** Release cached registrations and test filters. */
	public function tearDown(): void {
		$this->reset_registry();
		parent::tearDown();
	}

	/** No production reset API is needed for a request-lifetime cache. */
	private function reset_registry(): void {
		$property = new \ReflectionProperty( Reports_Registry::class, 'reports' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/** Removing validation would admit a broken plugin; removing caching reruns its filter. */
	public function test_registry_malformed_entries_are_dropped_and_cached(): void {
		$calls = 0;
		add_filter(
			'woocommerce_pos_reports',
			function ( $reports ) use ( &$calls ) {
				++$calls;
				$valid = array(
					'title' => 'Example',
					'scopes' => array( 'range' ),
				);
				return $reports + array(
					'example' => $valid + array( 'source' => 'server' ),
					'Bad-Key' => $valid,
					'no_title' => array( 'scopes' => array( 'range' ) ),
					'empty_scope' => array(
						'title' => 'Bad',
						'scopes' => array(),
					),
					'bad_scope' => array(
						'title' => 'Bad',
						'scopes' => array( 'day' ),
					),
					'bad_callback' => $valid + array( 'callback' => 'missing_report_function' ),
					'bad_value' => false,
				);
			}
		);
		$reports = Reports_Registry::all();
		$this->assertSame( array( 'sales', 'cash_movements', 'example' ), array_keys( $reports ) );
		$this->assertSame( 'device', $reports['example']['source'] );
		$this->assertSame( 'view_woocommerce_pos_reports', $reports['example']['capability'] );
		$this->assertSame( $reports, Reports_Registry::all() );
		$this->assertSame( 1, $calls );
	}

	/** Built-ins declare the fixed bases, never a server query. */
	public function test_registry_builtins_preserve_column_bases_without_callbacks(): void {
		$reports = Reports_Registry::all();
		foreach ( $reports as $report ) {
			$this->assertSame( 'device', $report['source'] );
			$this->assertNull( $report['callback'] );
		}
		$this->assertSame( array( 'sales', 'gross', 'refunds', 'net', 'tax' ), array_column( $reports['sales']['columns'], 'key' ) );
		$expected = array(
			'payment_method' => array( 'method', 'payments', 'taken', 'refunded', 'net' ),
			'cashier' => array( 'name', 'sales', 'gross', 'refunds', 'net', 'tax' ),
			'register' => array( 'name', 'sales', 'gross', 'refunds', 'net', 'tax' ),
			'tax_rate' => array( 'rate', 'sales', 'taxable_base', 'tax', 'gross' ),
			'item' => array( 'item', 'qty_sold', 'qty_refunded', 'net' ),
			'category' => array( 'category', 'qty', 'net', 'tax' ),
		);
		foreach ( $expected as $key => $columns ) {
			$this->assertSame( $columns, array_column( $reports['sales']['group_columns'][ $key ], 'key' ) );
		}
		$this->assertSame( array( 'time', 'type', 'reason', 'actor', 'amount' ), array_column( $reports['cash_movements']['columns'], 'key' ) );
		$this->assertSame( array( 'in', 'out', 'net' ), $reports['cash_movements']['totals'] );
	}

	/** A registrant that reads the field tree must not recurse into the registry forever. */
	public function test_registry_filter_reading_the_field_tree_does_not_recurse(): void {
		$seen = null;
		add_filter(
			'woocommerce_pos_reports',
			static function ( $reports ) use ( &$seen ) {
				// Shaping extras from the existing tree re-enters Reports_Registry::all().
				$seen = Receipt_Data_Schema::get_field_tree( 'report' );
				$reports['example'] = array(
					'title' => 'Example',
					'scopes' => array( 'range' ),
				);
				return $reports;
			}
		);
		$reports = Reports_Registry::all();
		$this->assertArrayHasKey( 'example', $reports );
		$this->assertArrayHasKey( 'report', $seen );
	}

	/** A throwing registrant must not leave the registry permanently empty for the request. */
	public function test_registry_throwing_filter_does_not_wedge_later_reads(): void {
		$thrower = static function () {
			throw new \RuntimeException( 'Registration exploded' );
		};
		add_filter( 'woocommerce_pos_reports', $thrower );
		try {
			Reports_Registry::all();
			$this->fail( 'Expected the registration filter to throw.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Registration exploded', $error->getMessage() );
		}
		remove_filter( 'woocommerce_pos_reports', $thrower );
		$this->assertSame( array( 'sales', 'cash_movements' ), array_keys( Reports_Registry::all() ) );
	}

	/** Extras remain top-level, optional and grouped under the registrant's title. */
	public function test_registry_extras_extend_field_picker_without_requiring_them(): void {
		add_filter(
			'woocommerce_pos_reports',
			static function ( $reports ) {
				$reports['example'] = array(
					'title' => 'Example report',
					'scopes' => array( 'range' ),
					'extras' => array(
						'example' => array(
							'fields' => array(
								'note' => array(
									'type' => 'string',
									'label' => 'Note',
								),
							),
						),
						'report.scope' => array( 'fields' => array( 'forged' => array( 'type' => 'string' ) ) ),
					),
				);
				return $reports;
			}
		);
		$tree = Receipt_Data_Schema::get_field_tree( 'report' );
		$this->assertSame( 'Example report', $tree['example']['label'] );
		$this->assertArrayHasKey( 'note', $tree['example']['fields'] );
		$schema = Receipt_Data_Schema::get_json_schema( 'report' );
		$this->assertNotContains( 'example', $schema['required'] );
		$this->assertArrayNotHasKey( 'example', $schema['properties']['report']['properties'] );
		$this->assertArrayNotHasKey( 'forged', $schema['properties']['report']['properties']['scope']['properties'] );
	}
}
