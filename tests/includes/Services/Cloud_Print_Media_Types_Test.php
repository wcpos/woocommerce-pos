<?php
/**
 * Tests for Star CloudPRNT media-type negotiation.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Media_Types;
use WP_UnitTestCase;

/**
 * Cloud_Print_Media_Types_Test class.
 */
class Cloud_Print_Media_Types_Test extends WP_UnitTestCase {

	/**
	 * The negotiator under test.
	 *
	 * @var Cloud_Print_Media_Types
	 */
	private $media_types;

	/**
	 * A Star CloudPRNT printer row.
	 *
	 * @var array
	 */
	private $printer;

	/**
	 * Set up the negotiator and a Star printer row.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->media_types = new Cloud_Print_Media_Types();
		$this->printer     = array(
			'id'       => 'p1',
			'provider' => 'star-cloudprnt',
		);
	}

	/**
	 * Create a published thermal receipt template.
	 *
	 * @return int The template post ID.
	 */
	private function create_thermal_template(): int {
		$tid = wp_insert_post(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'T',
			)
		);

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<receipt paper-width="48"><text>Hi</text><cut /></receipt>' ),
			array( 'ID' => $tid ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $tid );
		update_post_meta( $tid, '_template_engine', 'thermal' );
		wp_set_object_terms( $tid, 'receipt', 'wcpos_template_type' );

		return (int) $tid;
	}

	/**
	 * Build a template-backed job array.
	 *
	 * @return array
	 */
	private function template_job(): array {
		return array(
			'id'           => 1,
			'printer_id'   => 'p1',
			'order_id'     => 99,
			'template_id'  => (string) $this->create_thermal_template(),
			'pn_kind'      => '',
			'content_type' => Cloud_Print_Media_Types::STARPRNT,
		);
	}

	/**
	 * It offers every server-renderable format for a template-backed job.
	 */
	public function test_for_job_offers_the_renderable_list_for_a_template_job(): void {
		$offer = $this->media_types->for_job( $this->template_job(), $this->printer );

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT, Cloud_Print_Media_Types::TEXT ),
			$offer
		);
	}

	/**
	 * It offers only the stored type for a job whose bytes were uploaded.
	 */
	public function test_for_job_offers_only_the_stored_type_for_an_uploaded_payload(): void {
		$job = array(
			'id'           => 2,
			'printer_id'   => 'p1',
			'order_id'     => 0,
			'template_id'  => '',
			'pn_kind'      => '',
			'content_type' => Cloud_Print_Media_Types::STARPRNT,
		);

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT ),
			$this->media_types->for_job( $job, $this->printer )
		);
	}

	/**
	 * It falls back to octet-stream for a stored job with no content type.
	 */
	public function test_for_job_falls_back_to_octet_stream(): void {
		$job = array(
			'id'           => 3,
			'printer_id'   => 'p1',
			'order_id'     => 0,
			'template_id'  => '',
			'content_type' => '',
		);

		$this->assertSame(
			array( Cloud_Print_Media_Types::OCTET_STREAM ),
			$this->media_types->for_job( $job, $this->printer )
		);
	}

	/**
	 * It drops formats a Line Mode-only printer says it cannot decode.
	 */
	public function test_for_job_filters_the_offer_by_the_printers_encodings(): void {
		$offer = $this->media_types->for_job(
			$this->template_job(),
			$this->printer,
			array( 'text/plain', 'application/vnd.star.line' )
		);

		$this->assertSame( array( Cloud_Print_Media_Types::TEXT ), $offer );
	}

	/**
	 * It offers the whole list when the printer decodes none of it, so the
	 * printer's own 510 reports the mismatch rather than an empty offer.
	 */
	public function test_for_job_offers_everything_when_nothing_intersects(): void {
		$offer = $this->media_types->for_job(
			$this->template_job(),
			$this->printer,
			array( 'application/vnd.star.starconfiguration' )
		);

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT, Cloud_Print_Media_Types::TEXT ),
			$offer
		);
	}

	/**
	 * It leaves non-Star providers on their stored type.
	 */
	public function test_for_job_does_not_negotiate_for_other_providers(): void {
		$job = $this->template_job();

		$offer = $this->media_types->for_job(
			$job,
			array(
				'id'       => 'p1',
				'provider' => 'epson-sdp',
			)
		);

		$this->assertSame( array( Cloud_Print_Media_Types::STARPRNT ), $offer );
	}

	/**
	 * It never negotiates a PrintNode job, whose kind picks the wire.
	 */
	public function test_for_job_does_not_negotiate_a_printnode_job(): void {
		$job            = $this->template_job();
		$job['pn_kind'] = 'pdf';

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT ),
			$this->media_types->for_job( $job, $this->printer )
		);
	}

	/**
	 * It falls back to the stored type when the template no longer exists.
	 */
	public function test_for_job_falls_back_when_the_template_is_gone(): void {
		$job                = $this->template_job();
		$job['template_id'] = '999999';

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT ),
			$this->media_types->for_job( $job, $this->printer )
		);
	}

	/**
	 * It reports every renderable format as servable, unfiltered by capabilities.
	 */
	public function test_servable_for_job_ignores_the_printers_encodings(): void {
		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT, Cloud_Print_Media_Types::TEXT ),
			$this->media_types->servable_for_job( $this->template_job(), $this->printer )
		);
	}

	/**
	 * It can only serve an uploaded payload as the bytes that were uploaded.
	 */
	public function test_servable_for_job_is_the_stored_type_for_an_upload(): void {
		$job = array(
			'id'           => 4,
			'printer_id'   => 'p1',
			'order_id'     => 0,
			'template_id'  => '',
			'content_type' => Cloud_Print_Media_Types::STARPRNT,
		);

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT ),
			$this->media_types->servable_for_job( $job, $this->printer )
		);
	}

	/**
	 * It maps offered media types onto thermal wire formats.
	 */
	public function test_wire_format_maps_the_offered_types(): void {
		$this->assertSame( 'starprnt', Cloud_Print_Media_Types::wire_format( Cloud_Print_Media_Types::STARPRNT ) );
		$this->assertSame( 'text', Cloud_Print_Media_Types::wire_format( 'TEXT/PLAIN; charset=utf-8' ) );
		$this->assertSame( '', Cloud_Print_Media_Types::wire_format( Cloud_Print_Media_Types::OCTET_STREAM ) );
	}

	/**
	 * It marks only command-free formats as header-controlled.
	 */
	public function test_is_header_controlled_only_for_command_free_formats(): void {
		$this->assertTrue( Cloud_Print_Media_Types::is_header_controlled( 'text/plain' ) );
		$this->assertFalse( Cloud_Print_Media_Types::is_header_controlled( Cloud_Print_Media_Types::STARPRNT ) );
	}

	/**
	 * It matches a requested type against the offer, ignoring case and parameters.
	 */
	public function test_match_ignores_case_and_parameters(): void {
		$offer = array( Cloud_Print_Media_Types::STARPRNT, Cloud_Print_Media_Types::TEXT );

		$this->assertSame( Cloud_Print_Media_Types::TEXT, Cloud_Print_Media_Types::match( 'Text/Plain; charset=utf-8', $offer ) );
		$this->assertSame( Cloud_Print_Media_Types::STARPRNT, Cloud_Print_Media_Types::match( 'APPLICATION/VND.STAR.STARPRNT', $offer ) );
		$this->assertSame( '', Cloud_Print_Media_Types::match( 'image/png', $offer ) );
		$this->assertSame( '', Cloud_Print_Media_Types::match( '', $offer ) );
	}
}
