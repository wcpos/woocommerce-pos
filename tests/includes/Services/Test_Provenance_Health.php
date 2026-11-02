<?php
/**
 * CPT provenance health tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Services\Provenance_Health;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** CPT provenance diagnostics. */
class Test_Provenance_Health extends WCPOS_REST_Unit_Test_Case {
	use Provenance_Health_Tests;

	/** Confirm the CPT storage lane. */
	public function setUp(): void {
		parent::setUp();
		$this->assertFalse( OrderUtil::custom_orders_table_usage_is_enabled() );
	}
	/** Unknown sessions are distinct, sampled, scoped and limited to the window. */
	public function test_health_unknown_sessions_excludes_known_old_and_other_stores(): void {
		$known = ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->create(
			array(
				'id' => wp_generate_uuid4(),
				'register_id' => wp_generate_uuid4(),
				'opened_at_gmt' => '2026-09-11 10:00:00',
				'opened_by' => get_current_user_id(),
				'expected_float' => null,
				'counted_float' => '100',
			)
		);
		$unknown = wp_generate_uuid4();
		$old = wp_generate_uuid4();
		$other_store = wp_generate_uuid4();
		$ids = array();
		foreach ( array( $known['id'], $unknown, $unknown, $old, $other_store ) as $id ) {
			$order = new \WC_Order();
			$order->set_date_created( $old === $id ? time() - 31 * DAY_IN_SECONDS : time() );
			$order->update_meta_data( '_wcpos_session', $id );
			$order->update_meta_data( '_pos_store', $other_store === $id ? 2 : 1 );
			$order->save();
			if ( $unknown === $id ) {
				$ids[] = $order->get_id();
			}
		}
		$report = ( new Provenance_Health() )->report( array(), array(), array( 1 ) );
		$this->assertSame( array( $unknown ), array_column( $report['unknown_sessions'], 'session_id' ) );
		$this->assertSame( 2, $report['unknown_sessions'][0]['orders'] );
		$this->assertEqualsCanonicalizing( $ids, $report['unknown_sessions'][0]['order_ids'] );
		$schema = ( new \WCPOS\WooCommercePOS\API\V2\Registers_Controller() )->get_health_schema();
		$this->assertArrayHasKey( 'unknown_sessions', $schema['properties'] );
	}
}
