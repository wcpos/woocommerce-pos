<?php
/**
 * Store authorization for till reads.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * Read the store seam from an empty base, never caller query parameters.
 * A malformed answer denies, never unscopes. Pro answers it with the stores the current user is authorized for.
 * An explicit 'unassigned' => true (boolean true only; anything else is ignored) also admits rows
 * with no store, NULL or 0, while the reach is otherwise restricted. 0 and NULL are unassigned, never a store.
 * A named resource out of reach answers 404 exactly as absent, unlike Report_Scope_Gate's visible 403.
 */
final class Store_Reach {
	public const FILTER = 'wcpos_closures_list_args';

	/** Allowed stores; null means unrestricted.
	 *
	 * @var int[]|null
	 */
	private $store_ids;

	/** Whether the seam returned an unreadable answer.
	 *
	 * @var bool
	 */
	private $malformed;

	/** Whether restricted reach also admits rows with no store.
	 *
	 * @var bool
	 */
	private $unassigned;

	/** Retain the resolved restriction.
	 *
	 * @param int[]|null $store_ids Allowed stores, or null when unrestricted.
	 * @param bool       $malformed Whether the answer was unreadable.
	 * @param bool       $unassigned Whether unassigned rows are reachable.
	 */
	private function __construct( ?array $store_ids, bool $malformed, bool $unassigned = false ) {
		$this->store_ids = $store_ids;
		$this->malformed = $malformed;
		$this->unassigned = $unassigned;
	}

	/** Resolve the existing authorization seam, validating before coercion.
	 *
	 * @param \WP_REST_Request $request Request passed to the seam; required.
	 */
	public static function for_request( \WP_REST_Request $request ): self {
		$answer = apply_filters( self::FILTER, array(), $request );
		$answer = apply_filters_deprecated( 'woocommerce_pos_closures_list_args', array( $answer, $request ), '2.0.0', 'wcpos_closures_list_args' );
		if ( ! is_array( $answer ) ) {
			Logger::warning(
				'Store reach refused: ' . self::FILTER . ' did not return an array',
				array( 'type' => gettype( $answer ) )
			);
			return new self( array(), true );
		}
		$unassigned = true === ( $answer['unassigned'] ?? null );
		if ( ! array_key_exists( 'store_id', $answer ) ) {
			return new self( null, false );
		}
		$values = is_array( $answer['store_id'] ) ? $answer['store_id'] : array( $answer['store_id'] );
		foreach ( $values as $value ) {
			$valid = ( is_int( $value ) && 0 < $value )
				|| ( is_string( $value ) && 1 === preg_match( '/^[1-9]\d{0,17}$/D', $value ) );
			if ( ! $valid ) {
				Logger::warning(
					'Store reach refused: ' . self::FILTER . ' gave an unreadable store restriction',
					array( 'type' => gettype( $value ) )
				);
				return new self( array(), true );
			}
		}
		return new self( array_values( array_unique( array_map( 'intval', $values ) ) ), false, $unassigned );
	}

	/** Return allowed stores, or null when unrestricted. */
	public function store_ids(): ?array {
		return $this->store_ids;
	}

	/** Distinguish a malformed answer from a valid empty restriction. */
	public function is_malformed(): bool {
		return $this->malformed;
	}

	/** Check a stored row against the explicit store and unassigned reach.
	 *
	 * @param mixed $store_id Stored store, possibly null.
	 */
	public function admits( $store_id ): bool {
		if ( null === $this->store_ids ) {
			return true;
		}
		if ( null === $store_id || 0 === (int) $store_id ) {
			return $this->unassigned;
		}
		return in_array( (int) $store_id, $this->store_ids, true );
	}

	/** Intersect list arguments with reachable stores before querying.
	 *
	 * @param array $args List arguments.
	 */
	public function narrow( array $args ): array {
		if ( null === $this->store_ids ) {
			return $args;
		}
		if ( ! array_key_exists( 'store_id', $args ) ) {
			$args['store_id'] = $this->store_ids();
			if ( $this->unassigned ) {
				$args['store_id'][] = null;
			}
			return $args;
		}
		$values = is_array( $args['store_id'] ) ? $args['store_id'] : array( $args['store_id'] );
		$requested_ints = array();
		$unassigned = false;
		foreach ( $values as $value ) {
			if ( $this->unassigned && ( null === $value || ( is_numeric( $value ) && 0.0 === (float) $value ) ) ) {
				$unassigned = true;
			} elseif ( null !== $value && 0 < (int) $value ) {
				$requested_ints[] = (int) $value;
			}
		}
		$args['store_id'] = array();
		foreach ( $this->store_ids as $id ) {
			if ( in_array( $id, $requested_ints, true ) ) {
				$args['store_id'][] = $id;
			}
		}
		if ( $unassigned ) {
			$args['store_id'][] = null;
		}
		return $args;
	}
	/** SQL for a narrowed store list: ints are stores, a null member is the unassigned rows.
	 *
	 * @param array $store_ids Narrowed list from narrow().
	 * @return string
	 */
	public static function store_predicate( array $store_ids ): string {
		$parts = array();
		$ids = array_map(
			'intval',
			array_filter(
				$store_ids,
				static function ( $id ) {
					return null !== $id;
				}
			)
		);
		if ( $ids ) {
			$parts[] = 'store_id IN (' . implode( ',', $ids ) . ')';
		}
		if ( in_array( null, $store_ids, true ) ) {
			$parts[] = 'store_id IS NULL OR store_id = 0';
		}
		return $parts ? '(' . implode( ' OR ', $parts ) . ')' : '1=0';
	}
}
