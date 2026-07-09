<?php
/**
 * Cloud Print Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Provider;
use WCPOS\WooCommercePOS\Services\Star_Online_Client;
use WP_Error;

/**
 * The Cloud Print Settings Section: printer rows and store assignments.
 *
 * Owns the per-provider schema (PrintNode, Star CloudPRNT, Star Online),
 * sanitization, secret redaction (poll_token_hash / printnode_api_key /
 * star_api_key), preserve-on-omitted-key write semantics, and poll-token
 * generation. Write is a full replacement of printers+assignments, not a
 * PATCH; the persisted option shape is frozen (no date_modified_gmt stamp).
 *
 * read()/write() are wholesale overrides: Abstract_Section's
 * migrate/compose/redact/sanitize template hooks, the
 * woocommerce_pos_cloud_print_settings filter, and the pre_save/saved hooks
 * do NOT run for this section. Registry replacement is the supported
 * override mechanism.
 */
class Cloud_Print_Section extends Abstract_Section {
	/**
	 * Secret printer fields stripped from every public view. Future
	 * providers that add secrets must list them here — redaction is a
	 * one-place change.
	 *
	 * @var string[]
	 */
	const SECRET_FIELDS = array( 'poll_token_hash', 'printnode_api_key', 'star_api_key' );
	/**
	 * Section id. Option name: woocommerce_pos_settings_cloud_print.
	 */
	public function id(): string {
		return 'cloud_print';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'printers'    => array(),
			'assignments' => array(),
		);
	}

	/**
	 * Read the cloud-print view: enrich printers with runtime status,
	 * last-seen, and encoding fields; strip secrets.
	 */
	public function read(): array {
		$settings = wp_parse_args( $this->read_raw(), $this->defaults() );

		$registry             = new Cloud_Print_Registry();
		$settings['printers'] = array_map(
			function ( $printer ) use ( $registry ) {
				if ( ! \is_array( $printer ) ) {
					return $printer;
				}
				$id                   = (string) ( $printer['id'] ?? '' );
				$seen                 = $registry->get_seen( $id );
				$printer              = $this->redact_printer( $printer );
				$printer['status']    = $registry->status_for( $id );
				$printer['last_seen'] = $seen > 0 ? $seen : null;

				return $printer;
			},
			$settings['printers']
		);

		return $settings;
	}

	/**
	 * Replace cloud-print settings (full replacement, not PATCH).
	 *
	 * @param array $settings Payload with printers/assignments arrays.
	 *
	 * @return array|WP_Error On success: printers (redacted), assignments,
	 *                        and generated poll tokens keyed by printer id.
	 */
	public function write( array $settings ) {
		$printers = isset( $settings['printers'] ) && \is_array( $settings['printers'] ) ? array_values( $settings['printers'] ) : array();
		$assigns  = isset( $settings['assignments'] ) && \is_array( $settings['assignments'] ) ? array_values( $settings['assignments'] ) : array();

		$existing        = $this->read_raw();
		$existing_hashes = array();
		$existing_keys      = array();
		$existing_star_keys = array();
		$existing_ids       = array();
		if ( isset( $existing['printers'] ) && \is_array( $existing['printers'] ) ) {
			foreach ( $existing['printers'] as $printer ) {
				if ( ! empty( $printer['id'] ) ) {
					$existing_ids[] = $printer['id'];
				}
				if ( ! empty( $printer['id'] ) && ! empty( $printer['poll_token_hash'] ) ) {
					$existing_hashes[ $printer['id'] ] = $printer['poll_token_hash'];
				}
				if ( ! empty( $printer['id'] ) && ! empty( $printer['printnode_api_key'] ) ) {
					$existing_keys[ $printer['id'] ] = $printer['printnode_api_key'];
				}
				if ( ! empty( $printer['id'] ) && ! empty( $printer['star_api_key'] ) ) {
					$existing_star_keys[ $printer['id'] ] = $printer['star_api_key'];
				}
			}
		}

		$generated      = array();
		$clean_printers = array();
		$seen_ids       = array();
		foreach ( $printers as $printer ) {
			$printer = $this->sanitize_printer( $printer );

			if ( '' === $printer['id'] ) {
				$printer['id'] = Cloud_Print_Registry::derive_id( $printer['name'], array_merge( $existing_ids, array_keys( $seen_ids ) ) );
			}
			$id = $printer['id'];

			// Preserve a previously stored PrintNode API key when the incoming
			// payload omits it (GET strips the key, so the React app re-POSTs
			// printers without it when toggling other fields). A non-empty
			// incoming key still overwrites, letting users rotate it.
			if ( 'printnode' === $printer['provider'] && '' === $printer['printnode_api_key'] && ! empty( $existing_keys[ $id ] ) ) {
				$printer['printnode_api_key'] = $existing_keys[ $id ];
			}

			if ( 'star-online' === $printer['provider'] && '' === $printer['star_api_key'] && ! empty( $existing_star_keys[ $id ] ) ) {
				$printer['star_api_key'] = $existing_star_keys[ $id ];
			}

			if ( 'star-online' === $printer['provider'] ) {
				$api_base = Star_Online_Client::api_base_from_cloudprnt_url( (string) $printer['star_cloudprnt_url'] );
				$group    = Star_Online_Client::group_from_cloudprnt_url( (string) $printer['star_cloudprnt_url'] );
				if ( '' === $printer['star_api_key'] || null === $api_base || '' === $group || '' === $printer['star_device_id'] ) {
					return new WP_Error(
						'wcpos_cloud_print_star_online_invalid',
						__( 'Star Online printers need an API key, a valid stario.online CloudPRNT URL, and a device.', 'woocommerce-pos' ),
						array( 'status' => 400 )
					);
				}
			}

			if ( isset( $seen_ids[ $id ] ) ) {
				return new WP_Error(
					'wcpos_cloud_print_duplicate_printer_id',
					__( 'Duplicate printer id.', 'woocommerce-pos' ),
					array( 'status' => 400 )
				);
			}
			$seen_ids[ $id ] = true;

			$regenerate = ! empty( $printer['regenerate_token'] );
			unset( $printer['regenerate_token'] );

			if ( Provider::is_polling( $printer['provider'] ) ) {
				if ( $regenerate || empty( $existing_hashes[ $id ] ) ) {
					$token                      = Cloud_Print_Registry::generate_token();
					$printer['poll_token_hash'] = Cloud_Print_Registry::hash_token( $token );
					$generated[ $id ]           = $token;
				} else {
					$printer['poll_token_hash'] = $existing_hashes[ $id ];
				}
			}

			$clean_printers[] = $printer;
		}

		$clean = array(
			'printers'    => $clean_printers,
			'assignments' => array_map( array( $this, 'sanitize_assignment' ), $assigns ),
		);
		update_option( $this->option_name(), $clean );

		// Drop runtime last-seen entries for printers that were removed.
		( new Cloud_Print_Registry() )->prune_seen( array_keys( $seen_ids ) );

		$response_printers = array_map(
			function ( $printer ) {
				return $this->redact_printer( $printer );
			},
			$clean_printers
		);

		return array(
			'printers'    => $response_printers,
			'assignments' => $clean['assignments'],
			'generated'   => $generated,
		);
	}

	/**
	 * Sanitize a cloud printer entry.
	 *
	 * @param mixed $printer Printer.
	 */
	private function sanitize_printer( $printer ): array {
		$printer  = \is_array( $printer ) ? $printer : array();
		$provider = \in_array( $printer['provider'] ?? '', Provider::valid(), true )
			? $printer['provider'] : 'star-cloudprnt';

		$clean = array(
			'id'               => sanitize_text_field( $printer['id'] ?? '' ),
			'name'             => sanitize_text_field( $printer['name'] ?? '' ),
			'provider'         => $provider,
			'store_id'         => isset( $printer['store_id'] ) ? (int) $printer['store_id'] : 0,
			'regenerate_token' => ! empty( $printer['regenerate_token'] ),
		);
		if ( 'printnode' === $provider ) {
			$clean['printnode_api_key']    = sanitize_text_field( $printer['printnode_api_key'] ?? '' );
			$clean['printnode_printer_id'] = isset( $printer['printnode_printer_id'] ) ? (int) $printer['printnode_printer_id'] : 0;
			$clean['printnode_format']     = \in_array( $printer['printnode_format'] ?? '', array( 'pdf', 'raw' ), true )
				? $printer['printnode_format'] : 'pdf';
		}
		if ( 'star-cloudprnt' === $provider ) {
			$encoding_fields = array_intersect_key(
				$printer,
				array_flip( array( 'columns', 'language', 'autoCut', 'fullReceiptRaster' ) )
			);
			$clean           = $this->with_encoding_fields(
				array_merge( $clean, $encoding_fields )
			);
		}
		if ( 'star-online' === $provider ) {
			$clean['star_api_key']       = sanitize_text_field( $printer['star_api_key'] ?? '' );
			$clean['star_cloudprnt_url'] = esc_url_raw( $printer['star_cloudprnt_url'] ?? '' );
			$clean['star_device_id']     = sanitize_text_field( $printer['star_device_id'] ?? '' );
			$clean['star_client_type']   = sanitize_text_field( $printer['star_client_type'] ?? '' );
		}

		return $clean;
	}

	/**
	 * Public printer view: server-owned encoding fields added, secrets
	 * stripped.
	 *
	 * @param array $printer Printer row.
	 *
	 * @return array
	 */
	private function redact_printer( array $printer ): array {
		$printer = $this->with_encoding_fields( $printer );
		foreach ( self::SECRET_FIELDS as $field ) {
			unset( $printer[ $field ] );
		}

		return $printer;
	}

	/**
	 * Add server-owned client encoding fields for Star CloudPRNT printers.
	 *
	 * These fields let POS clients synthesize read-only cloud printer targets
	 * without guessing how to render raw payloads before CloudPRNT delivery.
	 *
	 * @param array $printer Printer row.
	 */
	private function with_encoding_fields( array $printer ): array {
		if ( 'star-cloudprnt' !== ( $printer['provider'] ?? '' ) ) {
			return $printer;
		}

		$language = \in_array( $printer['language'] ?? '', array( 'esc-pos', 'star-prnt', 'star-line' ), true )
			? $printer['language'] : 'esc-pos';
		$columns  = isset( $printer['columns'] ) ? (int) $printer['columns'] : 42;
		if ( ! \in_array( $columns, array( 32, 42, 48 ), true ) ) {
			$columns = 42;
		}

		$printer['columns']           = $columns;
		$printer['language']          = $language;
		$printer['autoCut']           = array_key_exists( 'autoCut', $printer ) ? rest_sanitize_boolean( $printer['autoCut'] ) : true;
		$printer['fullReceiptRaster'] = array_key_exists( 'fullReceiptRaster', $printer ) ? rest_sanitize_boolean( $printer['fullReceiptRaster'] ) : false;

		return $printer;
	}

	/**
	 * Sanitize a cloud assignment entry.
	 *
	 * @param mixed $assignment Assignment.
	 */
	public function sanitize_assignment( $assignment ): array {
		$assignment = \is_array( $assignment ) ? $assignment : array();

		return array(
			'printer_id'  => sanitize_text_field( $assignment['printer_id'] ?? '' ),
			'store_id'    => isset( $assignment['store_id'] ) ? (int) $assignment['store_id'] : 0,
			'scope'       => \in_array( $assignment['scope'] ?? '', array( 'every', 'pos', 'online' ), true ) ? $assignment['scope'] : 'every',
			'template_id' => sanitize_text_field( (string) ( $assignment['template_id'] ?? '' ) ),
		);
	}
}
