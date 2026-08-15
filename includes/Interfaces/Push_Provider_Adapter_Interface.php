<?php
/**
 * Push cloud-print provider adapter interface.
 *
 * @package WCPOS\WooCommercePOS\Interfaces
 */

namespace WCPOS\WooCommercePOS\Interfaces;

/**
 * Push_Provider_Adapter_Interface interface.
 */
interface Push_Provider_Adapter_Interface extends Provider_Adapter_Interface {
	/**
	 * Submit rendered bytes to the provider.
	 *
	 * @param array  $printer Printer configuration.
	 * @param array  $job     Print job data.
	 * @param string $payload Rendered payload bytes.
	 * @param string $title   Provider job title.
	 *
	 * @return array{success:bool, retryable:bool, error:string, external_job_id:string, drawer_error:string}
	 */
	public function submit( array $printer, array $job, string $payload, string $title ): array;
}
