<?php
/**
 * Tests for the gallery template registry.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Templates\Gallery_Registry;
use WP_UnitTestCase;

/**
 * Test_Gallery_Registry class.
 */
class Test_Gallery_Registry extends WP_UnitTestCase {

	/**
	 * Every gallery key that should ship with the plugin.
	 *
	 * @var string[]
	 */
	private const EXPECTED_KEYS = array(
		'detailed-receipt',
		'gift-receipt',
		'invoice',
		'minimal-receipt',
		'narrow-receipt',
		'packing-slip',
		'quote',
		'standard-receipt',
		'standard-receipt-rtl',
		'thermal-detailed-58mm',
		'thermal-detailed-80mm',
		'thermal-kitchen-ticket',
		'thermal-simple-58mm',
		'thermal-simple-80mm',
		'thermal-simple-80mm-rtl',
	);

	/**
	 * Test that all expected gallery keys are present.
	 */
	public function test_registry_returns_all_expected_keys(): void {
		$keys = array_keys( Gallery_Registry::all() );
		sort( $keys );

		$expected = self::EXPECTED_KEYS;
		sort( $expected );

		$this->assertEquals( $expected, $keys );
	}

	/**
	 * Test that every entry has the required metadata fields.
	 */
	public function test_every_entry_has_required_fields(): void {
		$required = array( 'title', 'description', 'type', 'category', 'engine', 'output_type', 'version', 'preview_data' );

		foreach ( Gallery_Registry::all() as $key => $entry ) {
			foreach ( $required as $field ) {
				$this->assertArrayHasKey( $field, $entry, "{$key} missing required field {$field}" );
			}
			$this->assertIsString( $entry['title'], "{$key} title must be a string" );
			$this->assertIsString( $entry['description'], "{$key} description must be a string" );
			$this->assertNotEmpty( $entry['title'], "{$key} title must not be empty" );
			$this->assertNotEmpty( $entry['description'], "{$key} description must not be empty" );
		}
	}

	/**
	 * Test that RTL templates declare direction explicitly.
	 */
	public function test_rtl_entries_declare_direction(): void {
		$entries = Gallery_Registry::all();

		$this->assertSame( 'rtl', $entries['standard-receipt-rtl']['direction'] );
		$this->assertSame( 'rtl', $entries['thermal-simple-80mm-rtl']['direction'] );
	}

	/**
	 * Test thermal entries declare paper_width.
	 */
	public function test_thermal_entries_declare_paper_width(): void {
		$thermal_keys = array(
			'thermal-detailed-58mm'   => '58mm',
			'thermal-simple-58mm'     => '58mm',
			'thermal-detailed-80mm'   => '80mm',
			'thermal-simple-80mm'     => '80mm',
			'thermal-simple-80mm-rtl' => '80mm',
			'thermal-kitchen-ticket'  => '80mm',
		);

		$entries = Gallery_Registry::all();
		foreach ( $thermal_keys as $key => $expected_width ) {
			$this->assertSame( 'thermal', $entries[ $key ]['engine'], "{$key} engine" );
			$this->assertSame( 'escpos', $entries[ $key ]['output_type'], "{$key} output_type" );
			$this->assertSame( $expected_width, $entries[ $key ]['paper_width'], "{$key} paper_width" );
		}
	}

	/**
	 * Test the RTL thermal description still references the codepage caveat
	 * (Arabic printers need CP864 or Windows-1256 support).
	 */
	public function test_thermal_rtl_description_mentions_arabic_codepage(): void {
		$entries = Gallery_Registry::all();
		$this->assertMatchesRegularExpression(
			'/CP864|Windows-1256/',
			$entries['thermal-simple-80mm-rtl']['description']
		);
	}

	/**
	 * Test registry keys match the bundled gallery content files.
	 */
	public function test_registry_keys_match_content_file_basenames(): void {
		$gallery_dir = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/';
		$html_files = glob( $gallery_dir . '*.html' );
		$php_files  = glob( $gallery_dir . '*.php' );
		$xml_files  = glob( $gallery_dir . '*.xml' );

		$content_files = array_merge(
			false === $html_files ? array() : $html_files,
			false === $php_files ? array() : $php_files,
			false === $xml_files ? array() : $xml_files
		);

		$content_keys = array_map(
			static function ( string $file ): string {
				return pathinfo( $file, PATHINFO_FILENAME );
			},
			$content_files
		);

		$registry_keys = array_keys( Gallery_Registry::all() );
		sort( $content_keys );
		sort( $registry_keys );

		$this->assertEquals( $content_keys, $registry_keys );
	}
}
