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
 * A malformed answer denies, never unscopes. Pro does not yet hook this filter.
 * There is no exemption for 0 or NULL: registers, sessions and closures use BIGINT NULL store_id.
 * A named resource out of reach answers 404 exactly as absent, unlike Report_Scope_Gate's visible 403.
 */
final class Store_Reach {
	public const FILTER = 'woocommerce_pos_closures_list_args';

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

	/** Retain the resolved restriction.
	 *
	 * @param int[]|null $store_ids Allowed stores, or null when unrestricted.
	 * @param bool       $malformed Whether the answer was unreadable.
	 */
	private function __construct( ?array $store_ids, bool $malformed ) {
		$this->store_ids = $store_ids;
		$this->malformed = $malformed;
	}

	/** Resolve the existing authorization seam, validating before coercion.
	 *
	 * @param \WP_REST_Request $request Request passed to the seam; required.
	 */
	public static function for_request( \WP_REST_Request $request ): self {
		$answer = apply_filters( self::FILTER, array(), $request );
		if ( ! is_array( $answer ) ) {
			Logger::warning(
				'Store reach refused: ' . self::FILTER . ' did not return an array',
				array( 'type' => gettype( $answer ) )
			);
			return new self( array(), true );
		}
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
		return new self( array_values( array_unique( array_map( 'intval', $values ) ) ), false );
	}

	/** Return allowed stores, or null when unrestricted. */
	public function store_ids(): ?array {
		return $this->store_ids;
	}

	/** Distinguish a malformed answer from a valid empty restriction. */
	public function is_malformed(): bool {
		return $this->malformed;
	}

	/** Check a stored row without exempting unassigned stores.
	 *
	 * @param mixed $store_id Stored store, possibly null.
	 */
	public function admits( $store_id ): bool {
		if ( null === $this->store_ids ) {
			return true;
		}
		if ( null === $store_id ) {
			return false;
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
			return $args;
		}
		$values = is_array( $args['store_id'] ) ? $args['store_id'] : array( $args['store_id'] );
		$requested_ints = array();
		foreach ( $values as $value ) {
			if ( null !== $value && 0 < (int) $value ) {
				$requested_ints[] = (int) $value;
			}
		}
		$args['store_id'] = array_values( array_intersect( $this->store_ids, $requested_ints ) );
		return $args;
	}
}
