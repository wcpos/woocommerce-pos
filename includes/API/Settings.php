<?php
/**
 * Settings.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

use Closure;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Provider;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Tax_Id_Detector;
use WCPOS\WooCommercePOS\Services\Tax_Id_Settings;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Class Settings REST API.
 */
class Settings extends WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Settings constructor.
	 */
	public function __construct() {
		add_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'payment_gateways_settings' ) );

		// remove this once Pro settings have been moved to the new settings service.
		add_filter( 'pre_update_option_woocommerce_pos_pro_settings_license', array( $this, 'remove_license_transient' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_general_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		// register_rest_route(
		// $this->namespace,
		// '/' . $this->rest_base . '/general/barcodes',
		// array(
		// 'methods' => WP_REST_Server::READABLE,
		// 'callback' => array( $this, 'get_barcodes' ),
		// 'permission_callback' => array( $this, 'read_permission_check' ),
		// )
		// );.

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_general_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_general_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkout_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout/order-statuses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_statuses' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_checkout_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tax_ids',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tax_ids_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tax_ids',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_tax_ids_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_tax_ids_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tax_ids/detection',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tax_ids_detection' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_access_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_access_settings' ),
				'permission_callback' => array( $this, 'update_access_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tools_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_tools_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/license',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_license_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/cloud-print',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cloud_print_settings' ),
					'permission_callback' => array( $this, 'cloud_print_read_permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_cloud_print_settings' ),
					'permission_callback' => array( $this, 'update_permission_check' ),
				),
			)
		);
	}

	/**
	 * Get general endpoint arguments.
	 *
	 * @return Closure[][]
	 */
	public function get_general_endpoint_args(): array {
		return array(
			'pos_only_products' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'decimal_qty' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'force_ssl' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_customer' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_integer( $param );
				},
			),
			'default_customer_is_cashier' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'barcode_field' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'generate_username' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'restore_stock_on_delete' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'tracking_consent' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param ) && \in_array( $param, array( 'allowed', 'denied', 'undecided' ), true );
				},
			),
			'store_tax_ids' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
			'store_name' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'store_phone' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'store_email' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'policies_and_conditions' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
		);
	}

	/**
	 * Get tax IDs endpoint arguments.
	 *
	 * @return Closure[][]
	 */
	public function get_tax_ids_endpoint_args(): array {
		return array(
			'write_map' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					if ( ! \is_array( $param ) ) {
						return false;
					}
					foreach ( $param as $type => $meta_key ) {
						if ( ! \is_string( $type ) || ! Tax_Id_Types::is_valid_type( $type ) ) {
							return false;
						}
						if ( ! \is_string( $meta_key ) ) {
							return false;
						}
					}

					return true;
				},
			),
		);
	}

	/**
	 * Get checkout endpoint arguments.
	 *
	 * @return Closure[][]
	 */
	public function get_checkout_endpoint_args(): array {
		return array(
			'receipt_default_mode' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param ) && \in_array( $param, array( 'fiscal', 'live' ), true );
				},
			),
			'admin_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
			'customer_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
			'cashier_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
			'auto_print_receipt' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_gateway' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'gateways' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
		);
	}

	/**
	 * Get general settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_general_settings( WP_REST_Request $request ) {
		$general_settings = woocommerce_pos_get_settings( 'general' );

		if ( is_wp_error( $general_settings ) ) {
			return $general_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $general_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get checkout settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_checkout_settings( WP_REST_Request $request ) {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout' );

		if ( is_wp_error( $checkout_settings ) ) {
			return $checkout_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $checkout_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get payment gateways settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_payment_gateways_settings( WP_REST_Request $request ) {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways' );

		if ( is_wp_error( $payment_gateways_settings ) ) {
			return $payment_gateways_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $payment_gateways_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get tax IDs settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_tax_ids_settings( WP_REST_Request $request ) {
		$tax_ids_settings = woocommerce_pos_get_settings( 'tax_ids' );

		if ( is_wp_error( $tax_ids_settings ) ) {
			return $tax_ids_settings;
		}

		$response = new WP_REST_Response( $tax_ids_settings );
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Update tax IDs settings. POST data is treated as PATCH (partial), merged
	 * with existing settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_tax_ids_settings( WP_REST_Request $request ) {
		$old_settings = woocommerce_pos_get_settings( 'tax_ids' );
		$payload      = $request->get_json_params();

		// `write_map` is intentionally a full replacement (not deep-merged) so
		// users can remove entries by sending the trimmed map.
		$settings = array_replace_recursive( $old_settings, $payload );
		if ( isset( $payload['write_map'] ) && \is_array( $payload['write_map'] ) ) {
			$settings['write_map'] = $payload['write_map'];
		}

		$settings_service = SettingsService::instance();

		return $settings_service->save_settings( 'tax_ids', $settings );
	}

	/**
	 * Get tax-ID auto-detection summary for the Compatibility tab.
	 *
	 * Returns the active third-party plugin ids, the per-type defaults, and the
	 * fully composed write_map (defaults < inferred < plugin claims < user
	 * overrides). The UI renders the composed map and surfaces overrides
	 * inline.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_tax_ids_detection( WP_REST_Request $request ) {
		$summary = ( new Tax_Id_Detector() )->summary();

		$response = new WP_REST_Response(
			array(
				'plugins'           => $summary['plugins'],
				'default_write_map' => Tax_Id_Settings::default_write_map(),
				'composed_write_map' => $summary['write_map'],
				// Only customer-applicable types are surfaced: business-register
				// identifiers (DE/NL/FR/CH commercial-register types) live on the
				// store, not on customers, so they have no write-map row.
				'types'             => Tax_Id_Types::customer_applicable_types(),
			)
		);
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get access settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_access_settings( WP_REST_Request $request ) {
		$access_settings = woocommerce_pos_get_settings( 'access' );

		if ( is_wp_error( $access_settings ) ) {
			return $access_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $access_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get tools settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_tools_settings( WP_REST_Request $request ) {
		$tools_settings = woocommerce_pos_get_settings( 'tools' );

		if ( is_wp_error( $tools_settings ) ) {
			return $tools_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $tools_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get license settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_license_settings( WP_REST_Request $request ) {
		$license_settings = woocommerce_pos_get_settings( 'license' );

		if ( is_wp_error( $license_settings ) ) {
			return $license_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $license_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get cloud-print settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_cloud_print_settings() {
		$settings = get_option( 'woocommerce_pos_settings_cloud_print', array() );
		$settings = wp_parse_args(
			\is_array( $settings ) ? $settings : array(),
			array(
				'printers'    => array(),
				'assignments' => array(),
			)
		);
		$registry             = new Cloud_Print_Registry();
		$settings['printers'] = array_map(
			function ( $printer ) use ( $registry ) {
				if ( ! \is_array( $printer ) ) {
					return $printer;
				}
				$id                   = (string) ( $printer['id'] ?? '' );
				$seen                 = $registry->get_seen( $id );
				$printer              = $this->with_cloud_printer_encoding_fields( $printer );
				$printer['status']    = $registry->status_for( $id );
				$printer['last_seen'] = $seen > 0 ? $seen : null;
				unset( $printer['poll_token_hash'], $printer['printnode_api_key'], $printer['star_api_key'] );

				return $printer;
			},
			$settings['printers']
		);

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * Replace cloud-print settings.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function update_cloud_print_settings( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = $request->get_body_params();
		}
		$printers = isset( $payload['printers'] ) && \is_array( $payload['printers'] ) ? array_values( $payload['printers'] ) : array();
		$assigns  = isset( $payload['assignments'] ) && \is_array( $payload['assignments'] ) ? array_values( $payload['assignments'] ) : array();

		$existing        = get_option( 'woocommerce_pos_settings_cloud_print', array() );
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
			$printer = $this->sanitize_cloud_printer( $printer );

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
				$api_base = \WCPOS\WooCommercePOS\Services\Star_Online_Client::api_base_from_cloudprnt_url( (string) $printer['star_cloudprnt_url'] );
				$group    = \WCPOS\WooCommercePOS\Services\Star_Online_Client::group_from_cloudprnt_url( (string) $printer['star_cloudprnt_url'] );
				if ( '' === $printer['star_api_key'] || null === $api_base || '' === $group || '' === $printer['star_device_id'] ) {
					return new WP_REST_Response(
						array(
							'code'    => 'wcpos_cloud_print_star_online_invalid',
							'message' => __( 'Star Online printers need an API key, a valid stario.online CloudPRNT URL, and a device.', 'woocommerce-pos' ),
						),
						400
					);
				}
			}

			if ( isset( $seen_ids[ $id ] ) ) {
				return new WP_REST_Response(
					array(
						'code'    => 'wcpos_cloud_print_duplicate_printer_id',
						'message' => __( 'Duplicate printer id.', 'woocommerce-pos' ),
					),
					400
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
			'assignments' => array_map( array( $this, 'sanitize_cloud_assignment' ), $assigns ),
		);
		update_option( 'woocommerce_pos_settings_cloud_print', $clean );

		// Drop runtime last-seen entries for printers that were removed.
		( new Cloud_Print_Registry() )->prune_seen( array_keys( $seen_ids ) );

		$response_printers = array_map(
			function ( $printer ) {
				$printer = $this->with_cloud_printer_encoding_fields( $printer );
				unset( $printer['poll_token_hash'], $printer['printnode_api_key'], $printer['star_api_key'] );

				return $printer;
			},
			$clean_printers
		);

		return new WP_REST_Response(
			array(
				'printers'    => $response_printers,
				'assignments' => $clean['assignments'],
				'generated'   => $generated,
			),
			200
		);
	}

	/**
	 * Sanitize a cloud printer entry.
	 *
	 * @param mixed $printer Printer.
	 *
	 * @return array
	 */
	private function sanitize_cloud_printer( $printer ): array {
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
			$clean           = $this->with_cloud_printer_encoding_fields(
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
	 * Add server-owned client encoding fields for Star CloudPRNT printers.
	 *
	 * These fields let POS clients synthesize read-only cloud printer targets
	 * without guessing how to render raw payloads before CloudPRNT delivery.
	 *
	 * @param array $printer Printer row.
	 *
	 * @return array
	 */
	private function with_cloud_printer_encoding_fields( array $printer ): array {
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
	 *
	 * @return array
	 */
	private function sanitize_cloud_assignment( $assignment ): array {
		$assignment = \is_array( $assignment ) ? $assignment : array();

		return array(
			'printer_id'  => sanitize_text_field( $assignment['printer_id'] ?? '' ),
			'store_id'    => isset( $assignment['store_id'] ) ? (int) $assignment['store_id'] : 0,
			'scope'       => \in_array( $assignment['scope'] ?? '', array( 'every', 'pos', 'online' ), true ) ? $assignment['scope'] : 'every',
			'template_id' => sanitize_text_field( (string) ( $assignment['template_id'] ?? '' ) ),
		);
	}


	/**
	 * Update payment gateways settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_payment_gateways_settings( WP_REST_Request $request ) {
		$old_settings     = woocommerce_pos_get_settings( 'payment_gateways' );
		$updated_settings = array_replace_recursive( $old_settings, $request->get_json_params() );

		$settings_service = SettingsService::instance();

		return $settings_service->save_settings( 'payment_gateways', $updated_settings );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		$old_settings = woocommerce_pos_get_settings( 'general' );
		$payload      = $request->get_json_params();
		$settings     = array_replace_recursive( $old_settings, $payload );
		if ( isset( $payload['store_tax_ids'] ) && \is_array( $payload['store_tax_ids'] ) ) {
			$settings['store_tax_ids'] = $payload['store_tax_ids'];
		}

		$settings_service = SettingsService::instance();

		return $settings_service->save_settings( 'general', $settings );
	}



	/**
	 * Update checkout settings.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$old_settings = woocommerce_pos_get_settings( 'checkout' );
		$settings     = array_replace_recursive( $old_settings, $request->get_json_params() );

		$settings_service = SettingsService::instance();

		return $settings_service->save_settings( 'checkout', $settings );
	}



	/**
	 * Update access settings.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array
	 */
	public function update_access_settings( WP_REST_Request $request ): array {
		global $wp_roles;
		$data = $request->get_json_params();

		// get all role slugs.
		$roles = array_keys( $wp_roles->roles );

		// get property from $data where key is in $roles.
		$update = array_intersect_key( $data, array_flip( $roles ) );

		// if $role is array with one property, update the capabilities.
		if ( 1 === \count( $update ) ) {
			$slugs = array_keys( $update );
			$slug  = $slugs[0];
			$role  = get_role( $slug );

			// flatten capabilities array from 'wc', 'wp', 'wcpos' grouping.
			$flattened_caps = array();
			foreach ( $update[ $slug ]['capabilities'] as $capabilities ) {
				$flattened_caps = array_merge( $flattened_caps, $capabilities );
			}

			// update capabilities for each $flattened_cap (should only be one).
			foreach ( $flattened_caps as $cap => $grant ) {
				// sanity check for admin role, read capability.
				if ( 'administrator' === $slug && 'read' === $cap ) {
					continue;
				}
				if ( $grant ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}

		return woocommerce_pos_get_settings( 'access' );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_tools_settings( WP_REST_Request $request ) {
		$old_settings = woocommerce_pos_get_settings( 'tools' );
		$settings     = array_replace_recursive( $old_settings, $request->get_json_params() );

		$settings_service = SettingsService::instance();

		return $settings_service->save_settings( 'tools', $settings );
	}

	/**
	 * Check read permissions.
	 *
	 * @TODO - who can read settings?
	 *
	 * @return bool
	 */
	public function read_permission_check(): bool {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * Check Cloud Print read permissions.
	 *
	 * POS clients need to read server-owned Cloud Printer targets so they can route
	 * receipts to printers configured by a manager. Updating the server-owned
	 * settings still requires manage_woocommerce_pos via update_permission_check().
	 *
	 * @return bool
	 */
	public function cloud_print_read_permission_check(): bool {
		return current_user_can( 'access_woocommerce_pos' );
	}

	/**
	 * Check update permissions.
	 *
	 * @return bool
	 */
	public function update_permission_check(): bool {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * Check access update permissions.
	 *
	 * @return bool
	 */
	public function update_access_permission_check(): bool {
		return current_user_can( 'edit_users' ) && current_user_can( 'promote_users' );
	}

	/**
	 * Filter payment gateways settings.
	 *
	 * @param mixed $options The gateway options.
	 */
	public function payment_gateways_settings( $options ) {
		foreach ( $options['gateways'] as $gateway_id => &$gateway_data ) {
			if ( ! \in_array( $gateway_id, array( 'pos_cash', 'pos_card' ), true ) ) {
				$gateway_data['enabled'] = false;
			}
		}
		if ( ! \in_array( $options['default_gateway'], array( 'pos_cash', 'pos_card' ), true ) ) {
			$options['default_gateway'] = 'pos_cash';
		}

		return $options;
	}

	/**
	 * Temporary fix for stale license status transient. Remove when possible.
	 *
	 * @param mixed $value The option value.
	 */
	public function remove_license_transient( $value ) {
		delete_transient( 'woocommerce_pos_pro_license_status' );

		return $value;
	}
}
