<?php
/**
 * Settings.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\V1;

use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;
use WCPOS\WooCommercePOS\Logger;
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
 *
 * The per-section routes are projected from the Section Registry rather than
 * hand-written: every registered Settings Section gets a GET/POST pair at
 * /settings/{slug} whose args come from the section's endpoint_args(), whose
 * reads call read(), and whose writes call write( merge( read(), $patch ) ).
 * Registering a section through the
 * `woocommerce_pos_register_settings_sections` action is therefore all an
 * extension needs to do to gain an HTTP surface.
 */
class Settings extends WP_REST_Controller {
	/**
	 * Sections whose read route uses a permission callback other than the
	 * default manage_woocommerce_pos check.
	 *
	 * Frozen legacy parity, not policy: POS clients need to read server-owned
	 * Cloud Printer targets configured by a manager, so cloud_print reads only
	 * require access_woocommerce_pos.
	 *
	 * @var array<string, string>
	 */
	private const READ_PERMISSION_CALLBACKS = array(
		'cloud_print' => 'cloud_print_read_permission_check',
	);

	/**
	 * Sections whose update route uses a permission callback other than the
	 * default manage_woocommerce_pos check.
	 *
	 * The access section mutates WordPress role capabilities, so its writes
	 * require edit_users + promote_users.
	 *
	 * @var array<string, string>
	 */
	private const UPDATE_PERMISSION_CALLBACKS = array(
		'access' => 'update_access_permission_check',
	);

	/**
	 * Route slugs that are not the dashed form of the section id. Published
	 * URLs are frozen public interface — tax_ids shipped with an underscore.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_ROUTE_SLUGS = array(
		'tax_ids' => 'tax_ids',
	);

	/**
	 * Sections whose update route answers a write failure with a flat
	 * { code, message } body at 400 instead of the WP_Error envelope. Frozen
	 * wire contract — cloud-print clients do not expect the extra data key.
	 *
	 * @var string[]
	 */
	private const FLAT_ERROR_SECTIONS = array( 'cloud_print' );

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
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$route_slugs = array();
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => '__return_true',
			)
		);

		foreach ( SettingsService::instance()->sections()->all() as $id => $section ) {
			$id = (string) $id;

			// A section id becomes part of a route regex, so anything outside the
			// documented id alphabet is refused rather than compiled into the
			// route table.
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $id ) ) {
				Logger::warning(
					\sprintf(
						'Settings section "%s" has no REST route: section ids must match [a-z0-9_-].',
						$id
					)
				);

				continue;
			}

			if ( isset( $route_slugs[ $this->route_slug( $id ) ] ) ) {
				Logger::warning( sprintf( 'Settings section "%s" has no REST route: route slug already registered.', $id ) );
				continue;
			}
			$route_slugs[ $this->route_slug( $id ) ] = true;
			$this->register_section_routes( $id, $section );
		}

		// Section-adjacent read-only lookups. These are not section CRUD, so they
		// stay hand-registered.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tax_ids/detection',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tax_ids_detection' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);
	}

	/**
	 * The route slug for a section id.
	 *
	 * @param string $id Section id.
	 *
	 * @return string
	 */
	public function route_slug( string $id ): string {
		return self::LEGACY_ROUTE_SLUGS[ $id ] ?? str_replace( '_', '-', $id );
	}

	/**
	 * Read a section's public view.
	 *
	 * @param string          $id      Section id.
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_section_settings( string $id, WP_REST_Request $request ) {
		$section = SettingsService::instance()->sections()->get( $id );

		if ( ! $section instanceof Settings_Section_Interface ) {
			return $this->section_not_registered_error();
		}

		return new WP_REST_Response( $section->read(), 200 );
	}

	/**
	 * Update a section.
	 *
	 * POST data is treated as PATCH, ie: partial, so it is merged over the
	 * existing view before the section persists it. Sections whose payload is a
	 * full replacement (access, cloud_print) say so by overriding merge().
	 *
	 * @param string          $id      Section id.
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function update_section_settings( string $id, WP_REST_Request $request ) {
		$section = SettingsService::instance()->sections()->get( $id );

		if ( ! $section instanceof Settings_Section_Interface ) {
			return $this->section_not_registered_error();
		}

		$payload = $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = $request->get_body_params();
		}

		$result = $section->write( $section->merge( $section->read(), (array) $payload ) );

		if ( is_wp_error( $result ) && \in_array( $id, self::FLAT_ERROR_SECTIONS, true ) ) {
			// Keep the historical error body shape {code, message} — clients do
			// not expect WP_Error's extra data envelope here.
			return new WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return $result;
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
	 * Sanitize a cloud assignment entry.
	 *
	 * Kept for backward compatibility — the Settings_CloudPrint_Test conformance
	 * gate exercises this method directly via ReflectionMethod. Delegates to
	 * Cloud_Print_Section::sanitize_assignment() when the section is registered.
	 *
	 * @param mixed $assignment Assignment.
	 *
	 * @return array
	 *
	 * @phpstan-ignore-next-line
	 */
	private function sanitize_cloud_assignment( $assignment ): array {
		$section = SettingsService::instance()->sections()->get( 'cloud_print' );

		if ( $section instanceof \WCPOS\WooCommercePOS\Services\Settings\Cloud_Print_Section ) {
			return $section->sanitize_assignment( $assignment );
		}

		$assignment           = \is_array( $assignment ) ? $assignment : array();
		$assignment['copies'] = min( 5, max( 1, (int) ( $assignment['copies'] ?? 1 ) ) );

		return $assignment;
	}

	/**
	 * Check read permissions for a section.
	 *
	 * @param string $id Section id.
	 *
	 * @return bool
	 */
	public function section_read_permission_check( string $id ): bool {
		$callback = self::READ_PERMISSION_CALLBACKS[ $id ] ?? 'read_permission_check';

		return (bool) \call_user_func( array( $this, $callback ) );
	}

	/**
	 * Check update permissions for a section.
	 *
	 * @param string $id Section id.
	 *
	 * @return bool
	 */
	public function section_update_permission_check( string $id ): bool {
		$callback = self::UPDATE_PERMISSION_CALLBACKS[ $id ] ?? 'update_permission_check';

		return (bool) \call_user_func( array( $this, $callback ) );
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
	 * Register the GET/POST pair for one Settings Section.
	 *
	 * @param string                     $id      Section id.
	 * @param Settings_Section_Interface $section The section.
	 *
	 * @return void
	 */
	private function register_section_routes( string $id, Settings_Section_Interface $section ): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . $this->route_slug( $id ),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $id ) {
						return $this->get_section_settings( $id, $request );
					},
					'permission_callback' => function () use ( $id ) {
						return $this->section_read_permission_check( $id );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $id ) {
						return $this->update_section_settings( $id, $request );
					},
					'permission_callback' => function () use ( $id ) {
						return $this->section_update_permission_check( $id );
					},
					'args'                => $section->endpoint_args(),
				),
			)
		);
	}

	/**
	 * The error returned when a route outlives its section.
	 *
	 * @return WP_Error
	 */
	private function section_not_registered_error(): WP_Error {
		return new WP_Error(
			'woocommerce_pos_settings_error',
			__( 'Settings section not registered.', 'woocommerce-pos' ),
			array( 'status' => 500 )
		);
	}
}
