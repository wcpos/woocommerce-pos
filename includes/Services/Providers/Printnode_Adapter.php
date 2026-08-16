<?php
/**
 * PrintNode provider adapter.
 *
 * @package WCPOS\WooCommercePOS\Services\Providers
 */

namespace WCPOS\WooCommercePOS\Services\Providers;

use WCPOS\WooCommercePOS\Interfaces\Push_Provider_Adapter_Interface;
use WCPOS\WooCommercePOS\Services\Pdf_Renderer;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WCPOS\WooCommercePOS\Services\PrintNode_Client;

/**
 * Printnode_Adapter class.
 */
class Printnode_Adapter implements Push_Provider_Adapter_Interface {
	/**
	 * Return the PrintNode default PDF content type.
	 *
	 * @return string
	 */
	public function content_type(): string {
		return 'application/pdf';
	}

	/**
	 * Resolve PrintNode PDF or raw ESC/POS format data.
	 *
	 * @param array $printer  Printer configuration.
	 * @param array $template Template configuration.
	 *
	 * @return array{kind:string, content_type:string}
	 */
	public function format( array $printer, array $template ): array {
		if ( 'thermal' !== (string) ( $template['engine'] ?? '' ) || 'raw' !== (string) ( $printer['printnode_format'] ?? 'pdf' ) ) {
			return array(
				'kind' => 'pdf',
				'content_type' => $this->content_type(),
			);
		}

		return array(
			'kind' => 'escpos',
			'content_type' => 'application/octet-stream',
		);
	}

	/**
	 * Build a printer-friendly diagnostic PDF.
	 *
	 * @param string $printer_name Printer display name.
	 *
	 * @return array{content_type:string, payload:string}
	 */
	public function diagnostic( string $printer_name ): array {
		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>';
		$html .= 'body{font-family:"dejavu sans",sans-serif;color:#000;background:#fff;margin:24px;}'
			. 'h1{font-size:18px;margin:0 0 12px;}.row{font-size:13px;margin:4px 0;}'
			. '.label{font-weight:bold;}hr{border:none;border-top:1px solid #000;margin:16px 0;}'
			. '.ok{font-size:14px;font-weight:bold;margin-top:16px;}</style></head><body>';
		$html .= '<h1>WCPOS &mdash; Cloud Print Test</h1>';
		$html .= '<div class="row"><span class="label">Printer:</span> ' . esc_html( $printer_name ) . '</div>';
		$html .= '<div class="row"><span class="label">Date (UTC):</span> ' . esc_html( gmdate( 'Y-m-d H:i' ) ) . '</div>';
		$html .= '<hr><div class="ok">If you can read this, printing works!</div></body></html>';

		return array(
			'content_type' => $this->content_type(),
			'payload' => base64_encode( ( new Pdf_Renderer() )->render_html( $html ) ),
		);
	}

	/**
	 * Resolve PrintNode live status with the existing transient cache contract.
	 *
	 * @param array $printer Printer configuration.
	 * @param array $context Runtime status context.
	 *
	 * @return string
	 */
	public function status( array $printer, array $context ): string {
		$key    = 'wcpos_cloud_print_pn_status_' . md5( (string) $printer['id'] );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$api_key    = (string) ( $printer['printnode_api_key'] ?? '' );
		$printer_id = (int) ( $printer['printnode_printer_id'] ?? 0 );
		$status     = '' === $api_key || 0 === $printer_id
			? 'unknown'
			: ( new PrintNode_Client( $api_key ) )->printer_state( $printer_id );
		set_transient( $key, $status, (int) $context['cache_ttl'] );

		return $status;
	}

	/**
	 * Submit a rendered PrintNode job and optional drawer kick.
	 *
	 * @param array  $printer Printer configuration.
	 * @param array  $job     Print job data.
	 * @param string $payload Rendered payload bytes.
	 * @param string $title   Provider job title.
	 *
	 * @return array{success:bool, retryable:bool, error:string, external_job_id:string, drawer_error:string}
	 */
	public function submit( array $printer, array $job, string $payload, string $title ): array {
		$api_key    = (string) ( $printer['printnode_api_key'] ?? '' );
		$printer_id = (int) ( $printer['printnode_printer_id'] ?? 0 );
		if ( '' === $api_key || 0 === $printer_id ) {
			return $this->failure( 'Cloud print: PrintNode printer is missing an API key or printer id.', false );
		}
		if ( '' === $payload ) {
			return $this->failure( 'Cloud print: PrintNode job produced no printable content.', false );
		}

		$content_type = 'pdf_base64';
		if ( 'escpos' === ( $job['pn_kind'] ?? '' ) ) {
			$content_type = 'raw_base64';
		}

		$client = new PrintNode_Client( $api_key );
		$result = $client->submit_job(
			$printer_id,
			$title,
			$content_type,
			base64_encode( $payload )
		);
		if ( is_wp_error( $result ) ) {
			return $this->failure( $result->get_error_message(), true );
		}

		$drawer_error = '';
		if ( 'pdf' === ( $job['pn_kind'] ?? '' ) && ! empty( $job['auto_open_drawer'] ) ) {
			$drawer = $client->submit_job(
				$printer_id,
				$title . ' Cash Drawer',
				'raw_base64',
				base64_encode( $this->drawer_kick_bytes( (string) ( $job['drawer_connector'] ?? 'pin2' ) ) )
			);
			$drawer_error = is_wp_error( $drawer ) ? $drawer->get_error_message() : '';
		}

		return array(
			'success' => true,
			'retryable' => false,
			'error' => '',
			'external_job_id' => (string) $result['id'],
			'drawer_error' => $drawer_error,
		);
	}

	/**
	 * Build the stable submit failure shape.
	 *
	 * @param string $error     Provider error message.
	 * @param bool   $retryable Whether the service should retry.
	 *
	 * @return array{success:bool, retryable:bool, error:string, external_job_id:string, drawer_error:string}
	 */
	private function failure( string $error, bool $retryable ): array {
		return array(
			'success' => false,
			'retryable' => $retryable,
			'error' => $error,
			'external_job_id' => '',
			'drawer_error' => '',
		);
	}

	/**
	 * Build ESC/POS drawer kick bytes.
	 *
	 * @param string $connector pin2 or pin5.
	 *
	 * @return string
	 */
	private function drawer_kick_bytes( string $connector ): string {
		$pin = 'pin5' === Print_Job_Service::normalize_drawer_connector( $connector ) ? "\x01" : "\x00";

		return "\x1B\x70" . $pin . "\x19\xFA";
	}
}
