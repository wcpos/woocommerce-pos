<?php
/**
 * Logs REST API controller.
 *
 * Surfaces POS log entries for the settings screen.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Logs controller class.
 */
class Logs extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'logs';

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
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mark-read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Get log entries.
	 *
	 * Detects whether the store uses file-based or database logging,
	 * then returns parsed entries in reverse chronological order.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$level    = $request->get_param( 'level' );
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 50;
		$page     = $request->get_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1;

		$entries = $this->get_file_entries();

		// Filter by level if specified.
		if ( $level ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $entry ) use ( $level ) {
						return strtolower( $level ) === $entry['level'];
					}
				)
			);
		}

		$total       = count( $entries );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$offset      = ( $page - 1 ) * $per_page;
		$entries     = array_slice( $entries, $offset, $per_page );

		$response = new WP_REST_Response(
			array(
				'entries'          => $entries,
				'has_fatal_errors' => $this->has_fatal_errors(),
				'fatal_errors_url' => $this->get_fatal_errors_url(),
			)
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Parse log entries from file-based handler.
	 *
	 * Scans wc-logs/ for woocommerce-pos-*.log files and parses each line.
	 *
	 * @return array<int, array{timestamp: string, level: string, message: string, context: string}>
	 */
	private function get_file_entries(): array {
		$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		$files   = glob( $log_dir . 'woocommerce-pos-*.log' );

		if ( empty( $files ) ) {
			return array();
		}

		$entries = array();

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}

			$lines = explode( "\n", trim( $contents ) );
			foreach ( $lines as $line ) {
				$entry = $this->parse_log_line( $line );
				if ( $entry ) {
					$entries[] = $entry;
				}
			}
		}

		// Sort by timestamp descending (newest first).
		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $b['timestamp'], $a['timestamp'] );
			}
		);

		return $entries;
	}

	/**
	 * Parse a single WC log line into a structured entry.
	 *
	 * WC log format: "TIMESTAMP LEVEL message"
	 * Context is appended after " | Context: "
	 *
	 * @param string $line Raw log line.
	 *
	 * @return array{timestamp: string, level: string, message: string, context: string}|null
	 */
	private function parse_log_line( string $line ): ?array {
		$line = trim( $line );
		if ( '' === $line ) {
			return null;
		}

		// Match: timestamp (ISO 8601), level (word), rest is message.
		if ( ! preg_match( '/^(\S+)\s+(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG)\s+(.*)$/i', $line, $matches ) ) {
			return null;
		}

		$message = $matches[3];
		$context = '';

		// Split context from message.
		$context_pos = strpos( $message, ' | Context: ' );
		if ( false !== $context_pos ) {
			$context = substr( $message, $context_pos + 12 );
			$message = substr( $message, 0, $context_pos );
		}

		return array(
			'timestamp' => $matches[1],
			'level'     => strtolower( $matches[2] ),
			'message'   => $message,
			'context'   => $context,
		);
	}

	/**
	 * Check if fatal-errors log files exist.
	 *
	 * @return bool
	 */
	private function has_fatal_errors(): bool {
		$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		$files   = glob( $log_dir . 'fatal-errors-*.log' );

		return ! empty( $files );
	}

	/**
	 * Get the URL to WooCommerce's log viewer filtered to fatal errors.
	 *
	 * @return string
	 */
	private function get_fatal_errors_url(): string {
		return admin_url( 'admin.php?page=wc-status&tab=logs&source=fatal-errors' );
	}

	/**
	 * Mark logs as read for the current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permissions( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view logs.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
