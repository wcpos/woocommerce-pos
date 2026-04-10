<?php
/**
 * Tests for Opening_Hours_Formatter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Opening_Hours_Formatter;
use WP_UnitTestCase;

/**
 * @internal
 * @coversNothing
 */
class Test_Opening_Hours_Formatter extends WP_UnitTestCase {

	/**
	 * Standard hours: Mon-Fri 9-5, Sat 10-4, Sun closed.
	 */
	private function get_standard_hours(): array {
		return array(
			'0' => array( '09:00', '17:00' ),
			'1' => array( '09:00', '17:00' ),
			'2' => array( '09:00', '17:00' ),
			'3' => array( '09:00', '17:00' ),
			'4' => array( '09:00', '17:00' ),
			'5' => array( '10:00', '16:00' ),
			'6' => array(),
		);
	}

	/**
	 * Multi-slot hours: Mon-Fri with lunch break, Sat half-day, Sun closed.
	 */
	private function get_multi_slot_hours(): array {
		return array(
			'0' => array( '09:00', '12:00', '13:00', '17:00' ),
			'1' => array( '09:00', '12:00', '13:00', '17:00' ),
			'2' => array( '09:00', '12:00', '13:00', '17:00' ),
			'3' => array( '09:00', '12:00', '13:00', '17:00' ),
			'4' => array( '09:00', '12:00', '13:00', '17:00' ),
			'5' => array( '10:00', '14:00' ),
			'6' => array(),
		);
	}

	/**
	 * All days closed.
	 */
	private function get_all_closed(): array {
		return array(
			'0' => array(),
			'1' => array(),
			'2' => array(),
			'3' => array(),
			'4' => array(),
			'5' => array(),
			'6' => array(),
		);
	}

	// ── format_vertical ──────────────────────────────────────────────

	public function test_format_vertical_standard_hours(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_vertical( $this->get_standard_hours() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		$this->assertStringContainsString( 'Mon', $lines[0] );
		$this->assertStringContainsString( '9:00 AM', $lines[0] );
		$this->assertStringContainsString( '5:00 PM', $lines[0] );
		$this->assertStringContainsString( 'Sat', $lines[5] );
		$this->assertStringContainsString( '10:00 AM', $lines[5] );
		$this->assertStringContainsString( '4:00 PM', $lines[5] );
		$this->assertStringContainsString( 'Sun', $lines[6] );
		$this->assertStringContainsString( 'Closed', $lines[6] );
	}

	public function test_format_vertical_24h(): void {
		update_option( 'time_format', 'H:i' );
		$result = Opening_Hours_Formatter::format_vertical( $this->get_standard_hours() );
		$lines  = explode( "\n", $result );

		$this->assertStringContainsString( '09:00', $lines[0] );
		$this->assertStringContainsString( '17:00', $lines[0] );
		$this->assertStringNotContainsString( 'AM', $lines[0] );
	}

	public function test_format_vertical_multi_slot(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_vertical( $this->get_multi_slot_hours() );
		$lines  = explode( "\n", $result );

		// Mon should show two time ranges separated by comma.
		$this->assertStringContainsString( '9:00 AM', $lines[0] );
		$this->assertStringContainsString( '12:00 PM', $lines[0] );
		$this->assertStringContainsString( '1:00 PM', $lines[0] );
		$this->assertStringContainsString( '5:00 PM', $lines[0] );
	}

	public function test_format_vertical_all_closed(): void {
		$result = Opening_Hours_Formatter::format_vertical( $this->get_all_closed() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		foreach ( $lines as $line ) {
			$this->assertStringContainsString( 'Closed', $line );
		}
	}

	public function test_format_vertical_missing_days_show_closed(): void {
		// Only Monday set.
		$hours  = array( '0' => array( '09:00', '17:00' ) );
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_vertical( $hours );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		$this->assertStringContainsString( '9:00 AM', $lines[0] );
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertStringContainsString( 'Closed', $lines[ $i ] );
		}
	}

	// ── format_compact ───────────────────────────────────────────────

	public function test_format_compact_groups_identical_consecutive_days(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_compact( $this->get_standard_hours() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 3, $lines );
		$this->assertStringStartsWith( 'Mon–Fri', $lines[0] );
		$this->assertStringContainsString( '9:00 AM', $lines[0] );
		$this->assertStringStartsWith( 'Sat', $lines[1] );
		$this->assertStringStartsWith( 'Sun', $lines[2] );
		$this->assertStringContainsString( 'Closed', $lines[2] );
	}

	public function test_format_compact_all_same_hours(): void {
		update_option( 'time_format', 'g:i A' );
		$hours = array_fill( 0, 7, array( '09:00', '17:00' ) );
		// Convert to string keys.
		$hours_keyed = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$hours_keyed[ (string) $i ] = $hours[ $i ];
		}
		$result = Opening_Hours_Formatter::format_compact( $hours_keyed );
		$lines  = explode( "\n", $result );

		$this->assertCount( 1, $lines );
		$this->assertStringStartsWith( 'Mon–Sun', $lines[0] );
	}

	public function test_format_compact_single_day_not_ranged(): void {
		update_option( 'time_format', 'g:i A' );
		// Each day has different hours.
		$hours = array(
			'0' => array( '09:00', '17:00' ),
			'1' => array( '10:00', '18:00' ),
			'2' => array( '11:00', '19:00' ),
			'3' => array( '09:00', '17:00' ),
			'4' => array( '10:00', '18:00' ),
			'5' => array( '11:00', '19:00' ),
			'6' => array(),
		);
		$result = Opening_Hours_Formatter::format_compact( $hours );
		$lines  = explode( "\n", $result );

		// No ranges — each day standalone (no en-dash in day names).
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( '–', explode( ' ', $line )[0] );
		}
	}

	public function test_format_compact_multi_slot(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_compact( $this->get_multi_slot_hours() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 3, $lines );
		$this->assertStringStartsWith( 'Mon–Fri', $lines[0] );
		// Should contain both time ranges.
		$this->assertStringContainsString( '12:00 PM', $lines[0] );
		$this->assertStringContainsString( '1:00 PM', $lines[0] );
	}

	// ── format_inline ────────────────────────────────────────────────

	public function test_format_inline_standard_hours(): void {
		update_option( 'time_format', 'g:i A' );
		$result = Opening_Hours_Formatter::format_inline( $this->get_standard_hours() );

		// Single line, no newlines.
		$this->assertStringNotContainsString( "\n", $result );
		// Contains comma separators.
		$this->assertStringContainsString( ', ', $result );
		$this->assertStringContainsString( 'Mon–Fri', $result );
		$this->assertStringContainsString( 'Sun Closed', $result );
	}

	// ── empty input ──────────────────────────────────────────────────

	public function test_format_vertical_empty_array(): void {
		$result = Opening_Hours_Formatter::format_vertical( array() );
		$lines  = explode( "\n", $result );

		$this->assertCount( 7, $lines );
		foreach ( $lines as $line ) {
			$this->assertStringContainsString( 'Closed', $line );
		}
	}

	public function test_format_compact_empty_array(): void {
		$result = Opening_Hours_Formatter::format_compact( array() );
		$lines  = explode( "\n", $result );

		// All closed should group into one line.
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'Mon–Sun', $lines[0] );
		$this->assertStringContainsString( 'Closed', $lines[0] );
	}

	public function test_format_inline_empty_array(): void {
		$result = Opening_Hours_Formatter::format_inline( array() );
		$this->assertStringContainsString( 'Mon–Sun Closed', $result );
	}
}
