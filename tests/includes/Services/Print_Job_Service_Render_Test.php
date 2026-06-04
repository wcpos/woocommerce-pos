<?php
/**
 * Print job render tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Print_Job_Service_Render_Test class.
 */
class Print_Job_Service_Render_Test extends \WC_REST_Unit_Test_Case {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Set up service and CPT.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jobs = new Print_Job_Service();
		$this->jobs->register_post_type();
	}

	/**
	 * It decodes stored raw payloads.
	 */
	public function test_render_payload_decodes_raw_job_payload(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'RAW' ),
			)
		);

		$this->assertSame( 'RAW', $this->jobs->render_payload( $this->jobs->get( $id ) ) );
	}

	/**
	 * It renders order-based jobs using the requested output adapter.
	 */
	public function test_render_payload_renders_order_based_epos_xml_job(): void {
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id' => 'p1',
				'order_id'   => $order->get_id(),
				'format'     => 'epos-xml',
			)
		);

		$xml = $this->jobs->render_payload( $this->jobs->get( $id ) );

		$this->assertStringContainsString( '<epos-print', $xml );
		$this->assertStringContainsString( (string) $order->get_order_number(), $xml );
	}

	/**
	 * Create a thermal template post with raw markup, bypassing wp_kses.
	 *
	 * The wp_insert_post() call runs content through wp_kses for users without
	 * the unfiltered_html cap, which strips the custom thermal tags. Writing the
	 * content directly with $wpdb mirrors how real templates are stored.
	 *
	 * @param string $content Raw template markup to store.
	 *
	 * @return int Template post ID.
	 */
	private function create_thermal_template( string $content = '<receipt paper-width="48"><text>Order #{{order.number}}</text><cut /></receipt>' ): int {
		$tid = wp_insert_post(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'T',
				'post_content' => '',
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $tid, 'wp_insert_post() returned a WP_Error creating the thermal template.' );
		$this->assertIsInt( $tid );
		$this->assertGreaterThan( 0, $tid, 'wp_insert_post() failed to create the thermal template post.' );

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => $tid ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertNotFalse( $updated, 'Failed to write raw template content via $wpdb->update().' );
		clean_post_cache( $tid );

		update_post_meta( $tid, '_template_engine', 'thermal' );
		wp_set_object_terms( $tid, 'receipt', 'wcpos_template_type' );

		return (int) $tid;
	}

	/**
	 * It renders ESC/POS bytes for a star-cloudprnt printer from a thermal template.
	 */
	public function test_render_payload_renders_thermal_escpos_for_star_printer(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'star1',
						'name'     => 'Star',
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(),
			)
		);
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id'  => 'star1',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
			)
		);

		// Act.
		$out = $this->jobs->render_payload( $this->jobs->get( $id ) );

		// Assert.
		$this->assertSame( "\x1b\x40", substr( $out, 0, 2 ) );
		$this->assertStringContainsString( (string) $order->get_order_number(), $out );
	}

	/**
	 * It renders ePOS XML for an epson-sdp printer from a thermal template.
	 */
	public function test_render_payload_renders_thermal_epos_xml_for_epson_printer(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'epson1',
						'name'     => 'Epson',
						'provider' => 'epson-sdp',
					),
				),
				'assignments' => array(),
			)
		);
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id'  => 'epson1',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
			)
		);

		// Act.
		$out = $this->jobs->render_payload( $this->jobs->get( $id ) );

		// Assert.
		$this->assertStringContainsString( '<epos-print', $out );
		$this->assertStringContainsString( (string) $order->get_order_number(), $out );
	}

	/**
	 * It renders PDF bytes for a PrintNode pdf job.
	 */
	public function test_render_payload_renders_pdf_for_printnode_pdf_job(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'pn',
						'name'     => 'PrintNode',
						'provider' => 'printnode',
					),
				),
				'assignments' => array(),
			)
		);
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id'  => 'pn',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
				'pn_kind'     => 'pdf',
			)
		);

		// Act.
		$out = $this->jobs->render_payload( $this->jobs->get( $id ) );

		// Assert.
		$this->assertSame( '%PDF-', substr( $out, 0, 5 ) );
	}


	/**
	 * It renders WP Overnight native PDF bytes for PrintNode PDF jobs.
	 */
	public function test_render_payload_printnode_wp_overnight_invoice_uses_native_pdf_bytes(): void {
		add_filter( 'woocommerce_pos_wp_overnight_pdf_templates_enabled', '__return_true' );

		$native_pdf = "%PDF-1.4\n% native wp overnight invoice from cloud print\n";
		$callback   = static function () use ( $native_pdf ) {
			return new class( $native_pdf ) {
				private $pdf;

				public function __construct( string $pdf ) {
					$this->pdf = $pdf;
				}

				public function get_pdf(): string {
					return $this->pdf;
				}
			};
		};
		add_filter( 'woocommerce_pos_wp_overnight_pdf_document', $callback, 10, 3 );

		try {
			$order  = OrderHelper::create_order();
			$job_id = $this->jobs->create(
				array(
					'printer_id'   => 'pn',
					'order_id'     => $order->get_id(),
					'template_id'  => 'wp-overnight-invoice',
					'content_type' => 'application/pdf',
					'pn_kind'      => 'pdf',
				)
			);

			$out = $this->jobs->render_payload( $this->jobs->get( $job_id ) );

			$this->assertSame( $native_pdf, $out );
		} finally {
			remove_filter( 'woocommerce_pos_wp_overnight_pdf_document', $callback, 10 );
			remove_filter( 'woocommerce_pos_wp_overnight_pdf_templates_enabled', '__return_true' );
		}
	}

	/**
	 * It renders ESC/POS bytes for a PrintNode escpos job.
	 */
	public function test_render_payload_renders_escpos_for_printnode_escpos_job(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'pn',
						'name'     => 'PrintNode',
						'provider' => 'printnode',
					),
				),
				'assignments' => array(),
			)
		);
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id'  => 'pn',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
				'pn_kind'     => 'escpos',
			)
		);

		// Act.
		$out = $this->jobs->render_payload( $this->jobs->get( $id ) );

		// Assert.
		$this->assertNotSame( '', $out );
		$this->assertSame( "\x1b\x40", substr( $out, 0, 2 ) );
	}

	/**
	 * It returns an empty string (no throw) when the thermal renderer fails.
	 *
	 * A template whose root is not <receipt> makes the markup parser throw even
	 * after Mustache rendering and control-char stripping. render_payload() must
	 * catch this so the poll controller does not 500 and the job is not stuck.
	 */
	public function test_render_payload_returns_empty_when_thermal_render_fails(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'star1',
						'name'     => 'Star',
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(),
			)
		);
		$tid   = $this->create_thermal_template( '<notreceipt>{{order.number}}</notreceipt>' );
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id'  => 'star1',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
			)
		);

		// Act.
		$out = $this->jobs->render_payload( $this->jobs->get( $id ) );

		// Assert.
		$this->assertSame( '', $out );
	}

	/**
	 * It returns an empty string when the template cannot be loaded.
	 */
	public function test_render_payload_returns_empty_when_template_missing(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'star1',
						'name'     => 'Star',
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(),
			)
		);
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id'  => 'star1',
				'order_id'    => $order->get_id(),
				'template_id' => '999999',
			)
		);

		// Act.
		$out = $this->jobs->render_payload( $this->jobs->get( $id ) );

		// Assert.
		$this->assertSame( '', $out );
	}
}
