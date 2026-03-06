<?php
/**
 * Tests for receipt template behavior.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Templates as TemplatesManager;
use WCPOS\WooCommercePOS\Templates\Receipt;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt extends WC_REST_Unit_Test_Case {
	/**
	 * Test fiscal mode falls back to live data when snapshot is unavailable.
	 */
	public function test_get_receipt_data_fiscal_without_snapshot_returns_live_mode_payload(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$method = new \ReflectionMethod( Receipt::class, 'get_receipt_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $receipt, $order, 'fiscal' );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertEquals( 'live', $data['meta']['mode'] );
	}

	/**
	 * Helper to invoke the private get_custom_template method.
	 *
	 * @param Receipt $receipt Receipt instance.
	 *
	 * @return array|null
	 */
	private function invoke_get_custom_template( Receipt $receipt ): ?array {
		$method = new \ReflectionMethod( Receipt::class, 'get_custom_template' );
		$method->setAccessible( true );

		return $method->invoke( $receipt );
	}

	/**
	 * Test that a numeric template query parameter selects a published database template.
	 */
	public function test_template_query_param_selects_published_database_template(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Switchable Receipt',
				'post_content' => '<p>Switchable</p>',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = (string) $post_id;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		$this->assertIsArray( $template );
		$this->assertEquals( $post_id, $template['id'] );
		$this->assertEquals( 'receipt', $template['type'] );
	}

	/**
	 * Test that a draft database template is not returned via the query parameter.
	 */
	public function test_template_query_param_rejects_draft_template(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'draft',
				'post_title'   => 'Draft Receipt',
				'post_content' => '<p>Draft</p>',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = (string) $post_id;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		// Should fall back to the active/default template, not the draft.
		$this->assertIsArray( $template );
		$this->assertNotEquals( $post_id, $template['id'] );
	}

	/**
	 * Test that a virtual template ID string selects the correct virtual template.
	 */
	public function test_template_query_param_selects_virtual_template(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = TemplatesManager::TEMPLATE_PLUGIN_CORE;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		$this->assertIsArray( $template );
		$this->assertEquals( TemplatesManager::TEMPLATE_PLUGIN_CORE, $template['id'] );
		$this->assertEquals( 'receipt', $template['type'] );
		$this->assertTrue( $template['is_virtual'] );
	}

	/**
	 * Test that an invalid template ID falls back to the active template.
	 */
	public function test_template_query_param_falls_back_on_invalid_id(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = '999999';
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		// Should return the active/default template (not null, not the invalid ID).
		$this->assertIsArray( $template );
		$this->assertNotEquals( 999999, $template['id'] );
	}

	/**
	 * Test that a non-receipt type template is rejected via the query parameter.
	 */
	public function test_template_query_param_rejects_non_receipt_type(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Report Template',
				'post_content' => '<p>Report</p>',
			)
		);
		wp_set_object_terms( $post_id, 'report', 'wcpos_template_type' );

		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = (string) $post_id;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		// Should fall back since the template is a report, not a receipt.
		$this->assertIsArray( $template );
		$this->assertNotEquals( $post_id, $template['id'] );
	}
}
