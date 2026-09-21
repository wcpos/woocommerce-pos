<?php
/**
 * Declarative POS report registry.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/** Device declarations and third-party server producers share one catalogue. */
final class Reports_Registry {
	/** A quarter is the longest comparison period; matches the client's reach cap. */
	public const HISTORY_DAYS = 92;
	/** Default read capability. */
	public const DEFAULT_CAPABILITY = 'view_woocommerce_pos_reports';
	/** Request-local declarations, registered by init-time filters.
	 *
	 * @var array|null
	 */
	private static $reports;
	/** Guard against a registration filter that reads back the registry.
	 *
	 * @var bool
	 */
	private static $building = false;

	/** Apply and normalize registrations once per request. */
	public static function all(): array {
		if ( null !== self::$reports ) {
			return self::$reports;
		}
		if ( self::$building ) {
			// Re-entered from a woocommerce_pos_reports callback — for instance one that reads
			// the report field tree to shape its extras, since that tree consults this registry.
			// Answer with the empty set rather than recursing until the stack is exhausted.
			return array();
		}
		self::$building = true;
		try {
			$reports = self::apply( self::builtins() );
		} finally {
			// A registration filter that throws must not leave the guard raised, or every
			// later call in this request would silently answer with an empty registry.
			self::$building = false;
		}
		self::$reports = array();
		foreach ( (array) $reports as $key => $report ) {
			$reason = self::invalid_reason( $key, $report );
			if ( '' !== $reason ) {
				Logger::log( sprintf( 'Report "%s" registration dropped: %s', $key, $reason ) );
				continue;
			}
			$report += array(
				'group_by' => array(),
				'capability' => self::DEFAULT_CAPABILITY,
				'extras' => array(),
				'template' => null,
				'tile' => null,
				'callback' => null,
			);
			$report['source'] = is_callable( $report['callback'] ) ? 'server' : 'device';
			$report['scopes'] = array_values( array_unique( $report['scopes'] ) );
			$report['group_by'] = array_values( $report['group_by'] );
			self::$reports[ $key ] = $report;
		}
		return self::$reports;
	}

	/**
	 * Apply the public registration filter.
	 *
	 * @param array $reports Built-in declarations.
	 */
	private static function apply( array $reports ): array {
		/**
		 * Filters the registry of POS reports.
		 *
		 * Runs once per request for the Reports catalogue, document route and field tree.
		 * Add an entry keyed by a lowercase report key ([a-z0-9_]+). Each entry declares
		 * title, non-empty scopes (session/range), optional group_by key/label options,
		 * capability (default view_woocommerce_pos_reports), extras (top-level field-tree
		 * fragments), template slug, and tile (number total key and line caption).
		 * A callback receives a resolved scope array, never a request: mode, store_id,
		 * register_id/name, business_day, timezone, group_by, and either session_id,
		 * session_number, opened_at/closed_at UTC instants, or from/to local days and
		 * from_utc/to_utc UTC instants with an exclusive end bound. The callback scopes
		 * its own query to that store/register and returns an array containing report's
		 * tabular core plus optional extras beside report. The plugin owns the envelope,
		 * report key/title/scope, and validates the merged document against the report schema.
		 * Without a callback a report is device-computed; the server never computes built-ins.
		 * Source is derived, not registrant-controlled. The requesting user's capabilities
		 * and the Free scope gate are checked before invoking a server producer.
		 *
		 * @param array $reports Registrations keyed by report key.
		 * @since 1.11.0
		 * @hook woocommerce_pos_reports
		 */
		$reports = apply_filters( 'woocommerce_pos_reports', $reports );
		return (array) $reports;
	}

	/** Check registration types before consumers use them.
	 *
	 * @param mixed $key Report key.
	 * @param mixed $report Declaration.
	 */
	private static function invalid_reason( $key, $report ): string {
		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_]+$/D', $key ) ) {
			return 'invalid key';
		}
		if ( ! is_array( $report ) || ! is_string( $report['title'] ?? null ) || '' === trim( $report['title'] ) ) {
			return 'missing title';
		}
		if ( ! is_array( $report['scopes'] ?? null ) || empty( $report['scopes'] ) ) {
			return 'empty scopes';
		}
		foreach ( $report['scopes'] as $scope ) {
			if ( ! in_array( $scope, array( 'session', 'range' ), true ) ) {
				return 'unknown scope';
			}
		}
		if ( array_key_exists( 'callback', $report ) && ! is_callable( $report['callback'] ) ) {
			return 'callback is not callable';
		}
		foreach ( array( 'group_by', 'extras' ) as $field ) {
			if ( isset( $report[ $field ] ) && ! is_array( $report[ $field ] ) ) {
				return 'invalid ' . $field;
			}
		}
		foreach ( $report['group_by'] ?? array() as $option ) {
			if ( ! is_array( $option ) || ! is_string( $option['key'] ?? null ) || ! is_string( $option['label'] ?? null ) ) {
				return 'invalid group_by option';
			}
		}
		if ( isset( $report['capability'] ) && ( ! is_string( $report['capability'] ) || '' === $report['capability'] ) ) {
			return 'invalid capability';
		}
		if ( isset( $report['template'] ) && ! is_string( $report['template'] ) ) {
			return 'invalid template';
		}
		if ( isset( $report['tile'] ) && ( ! is_array( $report['tile'] ) || ! is_string( $report['tile']['number'] ?? null ) || ! is_string( $report['tile']['line'] ?? null ) ) ) {
			return 'invalid tile';
		}
		return '';
	}

	/** Built-ins describe device producers; none has a server callable. */
	private static function builtins(): array {
		$groups = array(
			'payment_method' => __( 'Payment method', 'woocommerce-pos' ),
			'cashier' => __( 'Cashier', 'woocommerce-pos' ),
			'register' => __( 'Register', 'woocommerce-pos' ),
			'tax_rate' => __( 'Tax rate', 'woocommerce-pos' ),
			'item' => __( 'Item', 'woocommerce-pos' ),
			'category' => __( 'Category', 'woocommerce-pos' ),
		);
		$options = array();
		foreach ( $groups as $key => $label ) {
			$options[] = array(
				'key' => $key,
				'label' => $label,
			);
		}
		return array(
			'sales' => array(
				'title' => __( 'Sales', 'woocommerce-pos' ),
				'scopes' => array( 'range' ),
				'group_by' => $options,
				'columns' => self::columns( array( 'sales', 'gross', 'refunds', 'net', 'tax' ) ),
				'group_columns' => array(
					'payment_method' => self::columns( array( 'method', 'payments', 'taken', 'refunded', 'net' ) ),
					'cashier' => self::columns( array( 'name', 'sales', 'gross', 'refunds', 'net', 'tax' ) ),
					'register' => self::columns( array( 'name', 'sales', 'gross', 'refunds', 'net', 'tax' ) ),
					'tax_rate' => self::columns( array( 'rate', 'sales', 'taxable_base', 'tax', 'gross' ) ),
					'item' => self::columns( array( 'item', 'qty_sold', 'qty_refunded', 'net' ) ),
					'category' => self::columns( array( 'category', 'qty', 'net', 'tax' ) ),
				),
				'tile' => array(
					'number' => 'net',
					'line' => __( 'Net sales', 'woocommerce-pos' ),
				),
			),
			'cash_movements' => array(
				'title' => __( 'Cash movements', 'woocommerce-pos' ),
				'scopes' => array( 'session', 'range' ),
				'columns' => self::columns( array( 'time', 'type', 'reason', 'actor', 'amount' ) ),
				'totals' => array( 'in', 'out', 'net' ),
				'tile' => array(
					'number' => 'net',
					'line' => __( 'Net cash movements', 'woocommerce-pos' ),
				),
			),
		);
	}

	/** Fixed column declarations shared by built-in groupings.
	 *
	 * @param array $keys Ordered column keys.
	 */
	private static function columns( array $keys ): array {
		$labels = array(
			'sales' => __( 'Sales', 'woocommerce-pos' ),
			'gross' => __( 'Gross', 'woocommerce-pos' ),
			'refunds' => __( 'Refunds', 'woocommerce-pos' ),
			'net' => __( 'Net', 'woocommerce-pos' ),
			'tax' => __( 'Tax', 'woocommerce-pos' ),
			'method' => __( 'Method', 'woocommerce-pos' ),
			'payments' => __( 'Payments', 'woocommerce-pos' ),
			'taken' => __( 'Taken', 'woocommerce-pos' ),
			'refunded' => __( 'Refunded', 'woocommerce-pos' ),
			'name' => __( 'Name', 'woocommerce-pos' ),
			'rate' => __( 'Rate', 'woocommerce-pos' ),
			'taxable_base' => __( 'Taxable base', 'woocommerce-pos' ),
			'item' => __( 'Item', 'woocommerce-pos' ),
			'qty_sold' => __( 'Qty sold', 'woocommerce-pos' ),
			'qty_refunded' => __( 'Qty refunded', 'woocommerce-pos' ),
			'category' => __( 'Category', 'woocommerce-pos' ),
			'qty' => __( 'Qty', 'woocommerce-pos' ),
			'time' => __( 'Time', 'woocommerce-pos' ),
			'type' => __( 'Type', 'woocommerce-pos' ),
			'reason' => __( 'Reason', 'woocommerce-pos' ),
			'actor' => __( 'Actor', 'woocommerce-pos' ),
			'amount' => __( 'Amount', 'woocommerce-pos' ),
		);
		$columns = array();
		foreach ( $keys as $key ) {
			$type = 'money';
			if ( in_array( $key, array( 'method', 'name', 'item', 'category', 'type', 'reason', 'actor' ), true ) ) {
				$type = 'text';
			} elseif ( in_array( $key, array( 'sales', 'payments', 'qty_sold', 'qty_refunded', 'qty' ), true ) ) {
				$type = 'number';
			} elseif ( 'time' === $key || 'rate' === $key ) {
				$type = 'time' === $key ? 'datetime' : 'percent';
			}
			$columns[] = array(
				'key' => $key,
				'label' => $labels[ $key ],
				'type' => $type,
				'align' => in_array( $type, array( 'text', 'datetime' ), true ) ? 'left' : 'right',
			);
		}
		return $columns;
	}
}
