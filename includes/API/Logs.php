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
	 * User meta key for last-viewed timestamp.
	 */
	const LAST_VIEWED_META_KEY = '_wcpos_logs_last_viewed';

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
		$per_page = max( 1, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 50 ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1 ) );

		if ( 'database' === $this->get_handler_type() ) {
			$entries = $this->get_db_entries( $level );
		} else {
			$entries = $this->get_file_entries();

			// Filter by level if specified (DB does this in SQL).
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
	 * Detect which log handler type is active.
	 *
	 * @return string 'file' or 'database'
	 */
	private function get_handler_type(): string {
		/**
		 * Filter the detected log handler type.
		 *
		 * @param string|null $type 'file' or 'database', or null for auto-detection.
		 */
		$type = apply_filters( 'woocommerce_pos_log_handler_type', null );
		if ( $type ) {
			return $type;
		}

		$handler = get_option( 'woocommerce_default_log_handler', '' );

		if ( false !== strpos( $handler, 'DB' ) || false !== strpos( $handler, 'Database' ) ) {
			return 'database';
		}

		return 'file';
	}

	/**
	 * Get log entries from the database handler.
	 *
	 * @param string|null $level Optional level filter.
	 *
	 * @return array
	 */
	private function get_db_entries( ?string $level = null ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_log';

		// Check table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return array();
		}

		$where = $wpdb->prepare( 'WHERE source = %s', 'woocommerce-pos' );

		if ( $level ) {
			$severity = \WC_Log_Levels::get_level_severity( $level );
			$where   .= $wpdb->prepare( ' AND level = %d', $severity );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is safe prefix, $where is prepared above.
		$sql = "SELECT timestamp, level, message FROM {$table} {$where} ORDER BY timestamp DESC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is safely constructed above with $wpdb->prepare().
		$results = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $results ) ) {
			return array();
		}

		$level_map = array_flip(
			array(
				'emergency' => 800,
				'alert'     => 700,
				'critical'  => 600,
				'error'     => 500,
				'warning'   => 400,
				'notice'    => 300,
				'info'      => 200,
				'debug'     => 100,
			)
		);

		return array_map(
			function ( $row ) use ( $level_map ) {
				$message = $row['message'];
				$context = '';

				$context_pos = strpos( $message, ' | Context: ' );
				if ( false !== $context_pos ) {
					$context = substr( $message, $context_pos + 12 );
					$message = substr( $message, 0, $context_pos );
				}

				return array(
					'timestamp' => $row['timestamp'],
					'level'     => $level_map[ (int) $row['level'] ] ?? 'debug',
					'message'   => $message,
					'context'   => $context,
				);
			},
			$results
		);
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
	 * Stores the current timestamp in user meta.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response {
		$timestamp = gmdate( 'c' );
		update_user_meta( get_current_user_id(), self::LAST_VIEWED_META_KEY, $timestamp );

		return new WP_REST_Response(
			array(
				'success'   => true,
				'timestamp' => $timestamp,
			)
		);
	}

	/**
	 * Get unread error/warning counts for a user.
	 *
	 * This is a static method so it can be called from the Settings page
	 * to inject initial counts into the inline script.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array{error: int, warning: int}
	 */
	public static function get_unread_counts( int $user_id ): array {
		$last_viewed    = get_user_meta( $user_id, self::LAST_VIEWED_META_KEY, true );
		$last_viewed_ts = $last_viewed ? strtotime( $last_viewed ) : null;

		$instance = new self();
		$entries  = ( 'database' === $instance->get_handler_type() )
			? $instance->get_db_entries()
			: $instance->get_file_entries();

		$counts = array(
			'error'   => 0,
			'warning' => 0,
		);

		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry['level'], array( 'error', 'warning', 'critical', 'emergency', 'alert' ), true ) ) {
				continue;
			}

			// If no last_viewed, all entries are "unread".
			// Use strtotime() to normalize ISO 8601 and MySQL datetime formats.
			$entry_ts = strtotime( $entry['timestamp'] );
			if ( $last_viewed_ts && $entry_ts && $entry_ts <= $last_viewed_ts ) {
				continue;
			}

			$level_key = in_array( $entry['level'], array( 'error', 'critical', 'emergency', 'alert' ), true )
				? 'error'
				: 'warning';

			++$counts[ $level_key ];
		}

		return $counts;
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
