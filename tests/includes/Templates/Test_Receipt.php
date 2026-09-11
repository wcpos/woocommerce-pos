<?php
/**
 * Tests for receipt template behavior.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Templates\Gallery_Registry;
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
	 * Every receipt must identify the refund without labelling sales as corrections.
	 *
	 * @dataProvider refund_receipt_templates
	 * @param string $key Template key.
	 */
	public function test_refund_template_renders_credit_note_and_sale_without_corrects( string $key ): void {
		list( $order, $refund ) = $this->create_refund_order();
		$builder = new Receipt_Data_Builder();
		$receipt = new Receipt( $order->get_id() );
		if ( 'receipt.php' === $key ) {
			$template = array( 'engine' => 'legacy-php', 'file_path' => \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/receipt.php' );
		} else {
			$template = Gallery_Registry::all()[ $key ];
			$extension = 'thermal' === $template['engine'] ? 'xml' : 'html';
			$template['content'] = file_get_contents( \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/' . $key . '.' . $extension );
		}
		$data = $builder->build_refund_document( $order, $refund, 7, 'SALE-1' );
		$output = $this->invoke_render_custom_template( $receipt, $template, $order, $data );
		foreach ( array( 'Refund', 'Corrects', 'SALE-1', 'Widget' ) as $text ) {
			$this->assertStringContainsString( $text, $output );
		}
		// Invoice retains a secondary sale number in its payment reference section.
		$number_output = 'invoice' === $key ? explode( '</header>', $output )[0] : $output;
		$this->assertStringContainsString( '#7</', $number_output );
		$this->assertStringNotContainsString( '#' . $order->get_order_number() . '</', $number_output );
		$this->assertStringContainsString( $data['fiscal']['sale_time']['datetime'], $output );
		if ( 'invoice' === $key ) {
			$this->assertStringNotContainsString( 'Paid via', $output );
		}
		if ( 'narrow-receipt' !== $key ) {
			$this->assertStringContainsString( 'Refunded to', $output );
		}
		$sale_output = $this->invoke_render_custom_template( $receipt, $template, $order, $builder->build( $order, 'live' ) );
		$this->assertStringNotContainsString( 'Corrects', $sale_output );
		$this->assertStringNotContainsString( 'Refunded to', $sale_output );
	}

	/** Receipt templates only; non-receipt documents deliberately excluded. */
	public static function refund_receipt_templates(): array {
		return array_map(
			static function ( $key ) { return array( $key ); },
			array(
				'receipt.php',
				'thermal-detailed-58mm',
				'thermal-detailed-80mm',
				'thermal-simple-58mm',
				'thermal-simple-80mm',
				'thermal-simple-80mm-rtl',
				'detailed-receipt',
				'invoice',
				'minimal-receipt',
				'narrow-receipt',
				'standard-receipt',
				'standard-receipt-rtl',
			)
		);
	}

	/** The actual page must use the frozen payload, not rebuild the edited order. */
	public function test_refund_page_renders_frozen_document_and_rejects_invalid_documents(): void {
		list( $order, $refund ) = $this->create_refund_order();
		$payload = ( new Receipt_Data_Builder() )->build_refund_document( $order, $refund, 7, 'SALE-1' );
		$payload['order']['number'] = 'FROZEN-REFUND';
		$record = ( new Fiscal_Record_Store() )->record( array( 'type' => 'refund', 'order_id' => $order->get_id(), 'refund_id' => $refund->get_id(), 'payload' => $payload ) );
		$this->assertIsArray( $record );
		$order->set_customer_note( 'Edited after refund' );
		$order->save();
		$params = array( 'key' => $order->get_order_key(), 'template' => 'standard-receipt', 'document' => 'refund:' . $refund->get_id(), 'mode' => 'ignored' );
		$page = $this->render_receipt_page( $order->get_id(), $params );
		$this->assertNull( $page['error'] );
		foreach ( array( '#7</', 'Refund', 'Corrects', 'SALE-1', 'Widget' ) as $text ) {
			$this->assertStringContainsString( $text, $page['output'] );
		}
		$this->assertStringNotContainsString( 'Edited after refund', $page['output'] );

		$pdf_data = null;
		$capture_pdf = static function ( $data ) use ( &$pdf_data ) {
			$pdf_data = $data;
			throw new \Error( 'Receipt page stopped for test.' );
		};
		add_filter( 'woocommerce_pos_receipt_pdf_data', $capture_pdf );
		try {
			$params['format'] = 'pdf';
			$this->render_receipt_page( $order->get_id(), $params );
			$this->assertIsArray( $pdf_data );
			$this->assertSame( 'FROZEN-REFUND', $pdf_data['order']['number'] );
			$params['mode'] = 'preview';
			$this->render_receipt_page( $order->get_id(), $params );
			$this->assertNull( $pdf_data );
		} finally {
			remove_filter( 'woocommerce_pos_receipt_pdf_data', $capture_pdf );
			unset( $params['format'] );
		}

		$params['mode'] = 'preview';
		$page = $this->render_receipt_page( $order->get_id(), $params );
		$this->assertNull( $page['error'] );
		$this->assertStringNotContainsString( '#7</', $page['output'] );
		unset( $params['mode'] );
		$missing = $this->render_receipt_page( 0, $params );
		$this->assertNotNull( $missing['error'] );
		$params['document'] = 'refund:1junk';
		$page = $this->render_receipt_page( $order->get_id(), $params );
		$this->assertSame( 'Invalid receipt document.', $page['error'] );
		$this->assertSame( 400, $page['status'] );
		$other = OrderHelper::create_order();
		$params['key'] = $other->get_order_key();
		$params['document'] = 'refund:' . $refund->get_id();
		$page = $this->render_receipt_page( $other->get_id(), $params );
		$this->assertSame( 'Receipt document not found.', $page['error'] );
		$this->assertSame( 404, $page['status'] );
	}

	/** Build real paid POS and refund line fixtures for all receipt surfaces. */
	private function create_refund_order(): array {
		$order = OrderHelper::create_order();
		$order->remove_order_items();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_date_created( '2025-01-02 10:00:00' );
		$order->set_payment_method( 'pos_cash' );
		$order->set_payment_method_title( 'Cash' );
		$item = new \WC_Order_Item_Product();
		$item->set_name( 'Widget' );
		$item->set_quantity( 1 );
		$item->set_subtotal( 5 );
		$item->set_total( 5 );
		$order->add_item( $item );
		$order->calculate_totals();
		$order->payment_complete();
		$order->save();
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_date_created( '2025-02-03 11:30:00' );
		$refund->set_amount( 5 );
		$line = new \WC_Order_Item_Product();
		$line->set_name( 'Widget' );
		$line->set_quantity( 1 );
		$line->set_subtotal( -5 );
		$line->set_total( -5 );
		$refund->add_item( $line );
		$refund->update_meta_data( '_wcpos_refund_allocations', array( array( 'payment_id' => 'refund-payment', 'method_id' => 'pos_cash', 'amount' => '5.00' ) ) );
		$refund->save();
		return array( $order, $refund );
	}

	/**
	 * Exercise get_template(), stopping at its completion hook before exit.
	 *
	 * @param int   $order_id Requested order.
	 * @param array $params Query parameters.
	 */
	private function render_receipt_page( int $order_id, array $params ): array {
		$original_get = $_GET;
		$buffer_level = ob_get_level();
		$page = array( 'output' => '', 'error' => null, 'status' => null );
		$stop = static function () { throw new \Error( 'Receipt page stopped for test.' ); };
		$die = static function () use ( &$page, $stop ) {
			return static function ( $message, $title = '', $args = array() ) use ( &$page, $stop ) {
				$page['error'] = $message;
				$page['status'] = $args['response'] ?? null;
				$stop();
			};
		};
		add_action( 'woocommerce_pos_after_template_render', $stop );
		add_filter( 'wp_die_handler', $die );
		$_GET = $params;
		ob_start();
		try {
			( new Receipt( $order_id ) )->get_template();
		} catch ( \Error $e ) {
			if ( 'Receipt page stopped for test.' !== $e->getMessage() ) {
				throw $e;
			}
			$page['output'] = (string) ob_get_contents();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_GET = $original_get;
			remove_action( 'woocommerce_pos_after_template_render', $stop );
			remove_filter( 'wp_die_handler', $die );
		}
		return $page;
	}

	/** Live rendering must carry the persisted fiscal identity through the real builder. */
	public function test_live_render_uses_frozen_identity(): void {
		$order = OrderHelper::create_order();
		$snapshot = ( new \WCPOS\WooCommercePOS\Services\Receipt_Data_Builder() )->build( $order, 'fiscal' );
		$snapshot['fiscal']['qr_payload'] = 'FROZEN-QR-243';
		$store = \WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store::instance();
		$store->persist_snapshot( $order->get_id(), $snapshot );
		$number = $store->get_snapshot( $order->get_id() )['fiscal']['receipt_number'];
		$receipt = new Receipt( $order->get_id() );
		$method = new \ReflectionMethod( Receipt::class, 'get_receipt_data' );
		$method->setAccessible( true );
		$data = $method->invoke( $receipt, $order, 'live' );
		$output = $this->invoke_render_custom_template(
			$receipt,
			array(
				'engine' => 'logicless',
				'content' => '<p>ID:{{fiscal.receipt_number}} {{fiscal.qr_payload}}</p>',
			),
			$order,
			$data
		);
		$this->assertStringContainsString( 'FROZEN-QR-243', $output );
		$this->assertStringContainsString( 'ID:' . $number, $output );
	}

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
		$this->assertArrayHasKey( 'order', $data );
		$this->assertArrayNotHasKey( 'meta', $data );
		$this->assertArrayNotHasKey( 'receipt', $data );
	}

	/**
	 * Helper to invoke the private render_custom_template method and capture output.
	 *
	 * @param Receipt $receipt      Receipt instance.
	 * @param array   $template     Template metadata/content.
	 * @param mixed   $order        Order object.
	 * @param array   $receipt_data Canonical receipt payload.
	 *
	 * @return string Rendered output.
	 */
	private function invoke_render_custom_template( Receipt $receipt, array $template, $order, array $receipt_data ): string {
		$method = new \ReflectionMethod( Receipt::class, 'render_custom_template' );
		$method->setAccessible( true );

		ob_start();
		try {
			$method->invoke( $receipt, $template, $order, $receipt_data );
		} finally {
			$output = ob_get_clean();
		}

		return (string) $output;
	}

	/**
	 * Logicless output is the browser print surface: it keeps its colour on
	 * screen (and in PDFs, which render via Template_Pdf_Service and never pass
	 * here) but must print B&W, so the route wraps it in a print-only grayscale
	 * filter.
	 */
	public function test_render_custom_template_logicless_wraps_output_in_print_grayscale_root(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$template = array(
			'engine'  => 'logicless',
			'content' => '<div style="color: #15803d;">Order {{order.number}}</div>',
		);

		$receipt_data = array(
			'order' => array(
				'currency' => 'USD',
				'number'   => '123',
			),
		);

		$output = $this->invoke_render_custom_template( $receipt, $template, $order, $receipt_data );

		$this->assertStringContainsString( '@media print { .wcpos-receipt-print-root', $output );
		$this->assertStringContainsString( 'grayscale(1)', $output );
		$this->assertStringContainsString( '<div class="wcpos-receipt-print-root">', $output );
		$this->assertStringContainsString( 'Order 123', $output );
		// Template colour survives sanitization: only the print media query grayscales it.
		$this->assertStringContainsString( '#15803d', $output );
	}

	/**
	 * Legacy PHP templates emit a full HTML document and own their print styling,
	 * so the route must not inject the grayscale wrapper around them.
	 */
	public function test_render_custom_template_legacy_engine_renders_without_print_wrapper(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$template = array(
			'engine'  => 'legacy-php',
			'content' => '',
		);

		$output = $this->invoke_render_custom_template( $receipt, $template, $order, array() );

		$this->assertStringNotContainsString( 'wcpos-receipt-print-root', $output );
	}

	/**
	 * Thermal templates use their XML-to-HTML pipeline on the browser print surface.
	 */
	public function test_render_custom_template_thermal_uses_html_thermal_pipeline(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>Order {{order.number}}</text></receipt>',
		);

		$receipt_data = array(
			'order' => array(
				'currency' => 'USD',
				'number'   => 'BROWSER-123',
			),
		);

		$output = $this->invoke_render_custom_template( $receipt, $template, $order, $receipt_data );

		$this->assertStringContainsString( 'font-family: \'Courier New\'', $output );
		$this->assertStringContainsString( 'Order BROWSER-123', $output );
		$this->assertStringNotContainsString( '<receipt', $output );
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
	 * Test that a gallery template key selects the matching receipt template.
	 */
	public function test_template_query_param_selects_gallery_template(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = 'standard-receipt';
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		$this->assertIsArray( $template );
		$this->assertEquals( 'standard-receipt', $template['key'] );
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
