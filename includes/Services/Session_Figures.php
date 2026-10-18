<?php
/**
 * Shared figures for one till session.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Money;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;

/**
 * One instance shares the session snapshot; movements and closure serialize on the register.
 * Captured session legs are counted by their closure_id and late without this closure's stamp.
 * Refunds are counted by _wcpos_closure and late without this closure's stamp, even with no ledger leg.
 * Movements are counted before the closure snapshot and late when the closure already exists at write.
 * Late rows correct, never edit, the closure.
 */
final class Session_Figures {
	/** Session row.
	 *
	 * @var array
	 */
	private $session;
	/** Instance-local results, never shared across requests.
	 *
	 * @var array
	 */
	private $memo = array();

	/** Order locks held until the closure transaction ends.
	 *
	 * @var int[]
	 */
	private $locked_orders = array();

	/** Create a lazy view of a session.
	 *
	 * @param array $session Session row.
	 */
	public static function for_session( array $session ): self {
		$figures = new self();
		$figures->session = $session;
		return $figures;
	}

	/** Read the movement log once. */
	public function movements(): array {
		$this->memo['movements'] = $this->memo['movements'] ?? ( new Cash_Movement_Store() )->list( $this->session['id'] );
		return $this->memo['movements'];
	}

	/** Derive four-place expected balances from the shared snapshot. */
	public function expected(): array {
		if ( isset( $this->memo['expected'] ) ) {
			return $this->memo['expected'];
		}
		$rows = array_merge( array(), ...array_values( $this->legs() ) );
		foreach ( $this->refunds() as $refund ) {
			$rows = array_merge( $rows, $refund['rows'] );
		}
		$cash = array( $this->session['counted_float'] );
		foreach ( $this->movements() as $row ) {
			if ( null === $row['voided_by'] && in_array( $row['type'], array( 'paid_in', 'paid_out' ), true ) ) {
				$cash[] = ( 'paid_out' === $row['type'] ? '-' : '' ) . $row['amount'];
			}
		}
		$this->memo['expected'] = self::sum_rows( $rows, $cash );
		return $this->memo['expected'];
	}

	/**
	 * Break expected cash into the drawer equation's terms, from the same snapshot as expected().
	 *
	 * Identity, exact in DECIMAL(65,4): float + cash_sales.amount + Σ paid_in.amount
	 * − paid_out.amount − cash_refunds.amount === expected()['cash'].
	 *
	 * - float: the counted opening float.
	 * - cash_sales: captured cash-kind session legs that are not refund tenders; count is their distinct orders.
	 * - paid_in: each non-voided paid-in, in movement order, with the movement id the detail's
	 *   movements list carries; an empty reason reads as null.
	 * - paid_out: the non-voided paid-outs; note is the reason only when there is exactly one.
	 * - cash_refunds: whatever expected() debits from cash for refunds, as a positive amount:
	 *   cash-kind refund-tender legs plus each bound refund's cash rows. A refund's cash rows are
	 *   its succeeded allocations on cash-kind parent rows and its unallocated remainder, which
	 *   refund_tender_rows() attributes to cash; its allocations on card or other rows stay out,
	 *   as they stay out of expected cash. count is the refund-tender legs plus the refunds with
	 *   at least one cash row, each refund once however its cash is split.
	 * - no_sales: non-voided no-sale movements; voids: void movements.
	 */
	public function terms(): array {
		if ( isset( $this->memo['terms'] ) ) {
			return $this->memo['terms'];
		}
		$sales = array();
		$sale_orders = array();
		$refunds = array();
		$refund_count = 0;
		foreach ( $this->legs() as $order_id => $rows ) {
			foreach ( $rows as $row ) {
				if ( 'cash' !== self::tender_key( $row ) ) {
					continue;
				}
				if ( self::is_refund_tender( $row ) ) {
					$refunds[] = self::negate( $row['amount'] );
					++$refund_count;
				} else {
					$sales[] = $row['amount'];
					$sale_orders[ $order_id ] = true;
				}
			}
		}
		foreach ( $this->refunds() as $refund ) {
			$cash = false;
			foreach ( $refund['rows'] as $row ) {
				if ( 'cash' === self::tender_key( $row ) ) {
					$refunds[] = self::negate( $row['amount'] );
					$cash = true;
				}
			}
			$refund_count += $cash ? 1 : 0;
		}
		$paid_in = array();
		$paid_out = array();
		$no_sales = 0;
		$voids = 0;
		foreach ( $this->movements() as $row ) {
			if ( 'void' === $row['type'] ) {
				++$voids;
			}
			if ( null !== $row['voided_by'] ) {
				continue;
			}
			$reason = '' === trim( (string) $row['reason'] ) ? null : $row['reason'];
			if ( 'paid_in' === $row['type'] ) {
				$paid_in[] = array(
					'id' => $row['id'],
					'amount' => Closure_Store::sum( array( $row['amount'] ) ),
					'reason' => $reason,
				);
			} elseif ( 'paid_out' === $row['type'] ) {
				$paid_out[] = array( $row['amount'], $reason );
			} elseif ( 'no_sale' === $row['type'] ) {
				++$no_sales;
			}
		}
		$this->memo['terms'] = array(
			'float' => Closure_Store::sum( array( $this->session['counted_float'] ) ),
			'cash_sales' => array(
				'amount' => Closure_Store::sum( $sales ),
				'count' => count( $sale_orders ),
			),
			'paid_in' => $paid_in,
			'paid_out' => array(
				'amount' => Closure_Store::sum( array_column( $paid_out, 0 ) ),
				'count' => count( $paid_out ),
				'note' => 1 === count( $paid_out ) ? $paid_out[0][1] : null,
			),
			'cash_refunds' => array(
				'amount' => Closure_Store::sum( $refunds ),
				'count' => $refund_count,
			),
			'no_sales' => $no_sales,
			'voids' => $voids,
		);
		return $this->memo['terms'];
	}

	/** Flip a decimal string's sign without float arithmetic.
	 *
	 * @param string $amount Decimal string.
	 */
	private static function negate( string $amount ): string {
		return '-' === substr( $amount, 0, 1 ) ? substr( $amount, 1 ) : '-' . $amount;
	}

	/** Count distinct orders with captured session legs. */
	public function sales_count(): int {
		return count( $this->legs() );
	}

	/** Order membership of the same captured-leg snapshot used by the figures. */
	public function order_ids(): array {
		return array_keys( $this->legs() );
	}

	/** Ledger row membership, including each tender of a split payment. */
	public function ledger_row_ids(): array {
		return array_column( array_merge( array(), ...array_values( $this->legs() ) ), 'id' );
	}

	/** Refund membership of the same memoised snapshot used by expected balances. */
	public function refund_ids(): array {
		return array_keys( $this->refund_records() );
	}

	/** Versions of the captured ledger rows used by the shared snapshot. */
	public function ledger_rows(): array {
		return array_map(
			static function ( $row ) {
				return array(
					'id' => $row['id'],
					'updated_at_gmt' => $row['updated_at_gmt'] ?? null,
					'status' => $row['status'],
					// Detect financial edits without exposing tender amounts to blind counters.
					'version' => hash_hmac(
						'sha256',
						wp_json_encode(
							array(
								$row['id'],
								$row['status'],
								$row['amount'] ?? null,
								$row['refunded_amount'] ?? null,
								self::tender_key( $row ),
								$row['updated_at_gmt'] ?? null,
							)
						),
						wp_salt( 'auth' )
					),
				);
			},
			array_merge( array(), ...array_values( $this->legs() ) )
		);
	}

	/** Refund versions from the same memoised objects used by expected balances. */
	public function refund_versions(): array {
		return array_values(
			array_map(
				function ( $refund ) {
					$modified = $refund->get_date_modified( 'edit' );
					$rows = $this->refund_parent_rows( $refund );
					// Version only this refund's split; unrelated parent-ledger edits must not invalidate it.
					$allocation = null === $rows ? null : array(
						'amount' => $refund->get_amount(),
						'rows' => self::refund_tender_rows( $refund, $rows ),
					);
					return array(
						'id' => $refund->get_id(),
						'updated_at_gmt' => $modified ? gmdate( 'Y-m-d H:i:s', $modified->getTimestamp() ) : null,
						'allocation_version' => null === $allocation ? null : hash_hmac( 'sha256', wp_json_encode( $allocation ), wp_salt( 'auth' ) ),
					);
				},
				$this->refund_records()
			)
		);
	}

	/** Split unsigned amounts for closure period and perpetual totals. */
	public function period_amounts(): array {
		$totals = array(
			'sales' => array(),
			'refunds' => array(),
		);
		foreach ( $this->legs() as $rows ) {
			foreach ( $rows as $row ) {
				$totals[ self::is_refund_tender( $row ) ? 'refunds' : 'sales' ][] = ltrim( $row['amount'], '-' );
			}
		}
		foreach ( $this->refunds() as $refund ) {
			foreach ( $refund['rows'] as $row ) {
				$totals['refunds'][] = ltrim( $row['amount'], '-' );
			}
		}
		return $totals;
	}

	/** Count refund records and the raw refund leg forms used by X-reports. */
	public function refund_count(): int {
		$count = count( $this->refunds() );
		foreach ( $this->legs() as $rows ) {
			foreach ( $rows as $row ) {
				if ( self::is_refund_tender( $row ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/** Record the legs and refunds counted by this closure.
	 *
	 * @param string $closure_id Closure UUID.
	 * @throws \RuntimeException When a lock, order read or stamp fails.
	 */
	public function stamp( string $closure_id ): void {
		global $wpdb;
		$orders = $this->legs();
		ksort( $orders, SORT_NUMERIC );
		foreach ( $orders as $order_id => $legs ) {
			if ( ! Order_Lock::instance()->acquire( $order_id ) ) {
				throw new \RuntimeException( 'Closure stamp lock failed.' );
			}
			$this->locked_orders[] = $order_id;
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				throw new \RuntimeException( 'Closure stamp: order could not be read.' );
			}
			Ledger::instance()->stamp_closure( $order, array_column( $legs, 'id' ), $closure_id );
		}
		// The refund writers ask this stamp, not the clock, whether a refund was inside the closure.
		foreach ( $this->refunds() as $refund ) {
			$refund['refund']->update_meta_data( '_wcpos_closure', $closure_id );
			$refund['refund']->save_meta_data();
			// An unstamped counted refund would later read as a late arrival: fail the closure instead.
			$stored = wc_get_order( $refund['refund']->get_id() );
			if ( $stored instanceof \WC_Order_Refund ) {
				$stored->read_meta_data( true );
			}
			if ( '' !== $wpdb->last_error || ! $stored instanceof \WC_Order_Refund || (string) $stored->get_meta( '_wcpos_closure', true ) !== (string) $closure_id ) {
				throw new \RuntimeException( 'Closure refund stamp failed.' );
			}
		}
	}

	/** Release only after the caller has committed or rolled back; safe to repeat.
	 *
	 * @param bool $rolled_back Whether the caller rolled the stamp back. The database forgets
	 *                          a rolled-back stamp but WooCommerce's order cache does not: a
	 *                          later closure would then read the stamped copy, write nothing
	 *                          and fail its own verification, so the stamped orders and refunds
	 *                          are evicted here.
	 */
	public function release( bool $rolled_back = false ): void {
		if ( $rolled_back ) {
			$ids = $this->locked_orders;
			foreach ( $this->memo['refunds'] ?? array() as $entry ) {
				if ( isset( $entry['refund'] ) && $entry['refund'] instanceof \WC_Order_Refund ) {
					$ids[] = $entry['refund']->get_id();
				}
			}
			foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
				self::forget_order( $id );
			}
		}
		foreach ( $this->locked_orders as $order_id ) {
			Order_Lock::instance()->release( $order_id );
		}
		$this->locked_orders = array();
	}

	/** Evict one order or refund from every WooCommerce cache so the next read hits storage.
	 *
	 * @param int $id Order or refund id.
	 */
	private static function forget_order( int $id ): void {
		// WC_Data caches raw metadata separately from posts and OrderCache, even on CPT.
		wp_cache_delete( \WC_Order::generate_meta_cache_key( $id, 'orders' ), 'orders' );
		clean_post_cache( $id );
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
		}
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class ) ) {
			$data_store = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
			if ( method_exists( $data_store, 'clear_cached_data' ) ) {
				$data_store->clear_cached_data( array( $id ) );
			}
			if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class ) ) {
				$meta_store = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class );
				if ( method_exists( $meta_store, 'clear_cached_data' ) ) {
					$meta_store->clear_cached_data( array( $id ) );
				}
			}
		}
	}

	/** Whether this closure counted the row, never inferred from clocks.
	 *
	 * @param mixed $stamp Row's closure stamp.
	 * @param array $closure Closure row.
	 */
	public static function counted( $stamp, array $closure ): bool {
		return is_string( $stamp ) && '' !== $stamp && $stamp === (string) $closure['id'];
	}

	/** Captured session legs that this closure did not count.
	 *
	 * @param \WC_Order $order Order object.
	 * @param array     $closure Closure row.
	 */
	public static function late_legs( \WC_Order $order, array $closure ): array {
		// A caller can still hold the object loaded before the closure stamped it.
		$order = clone $order;
		$order->read_meta_data( true );
		return array_values(
			array_filter(
				Ledger::instance()->read( $order ),
				static function ( $leg ) use ( $closure ) {
					return 'captured' === ( $leg['status'] ?? null ) && ( $leg['session_id'] ?? null ) === $closure['session_id'] && ! self::counted( $leg['closure_id'] ?? null, $closure );
				}
			)
		);
	}

	/** Map a tender to its drawer/report key.
	 *
	 * @param array $row Tender row.
	 */
	public static function tender_key( array $row ): string {
		// Every cash-kind gateway (pos_cash, cod, an extension's cash tender) is the drawer.
		$method = $row['method'] ?? $row['method_id'];
		return 'cash' === ( $row['kind'] ?? '' ) ? 'cash' : ( array( 'pos_card' => 'card' )[ $method ] ?? $method );
	}

	/** Raw Ledger::save() rows can carry refund kind or negative amounts, unlike validated legs.
	 *
	 * @param array $row Tender row.
	 */
	public static function is_refund_tender( array $row ): bool {
		return 'refund' === ( $row['kind'] ?? '' ) || '-' === substr( (string) $row['amount'], 0, 1 );
	}

	/** Derive correction deltas without a session, float or movements.
	 *
	 * @param array $rows Tender rows in appearance order.
	 */
	public static function tender_totals( array $rows ): array {
		return self::sum_rows( $rows, array( '0' ) );
	}

	/** Group tenders with cash first and sum exactly through SQL DECIMAL(65,4).
	 *
	 * @param array $rows Tender rows in appearance order.
	 * @param array $cash Opening float and signed movements, or zero.
	 */
	private static function sum_rows( array $rows, array $cash ): array {
		$totals = array( 'cash' => $cash );
		foreach ( $rows as $row ) {
			$totals[ self::tender_key( $row ) ][] = $row['amount'];
		}
		return array_map( array( Closure_Store::class, 'sum' ), $totals );
	}

	/** Attribute a refund to succeeded ledger allocations, with the remainder in cash.
	 *
	 * @param \WC_Order_Refund $refund Refund to attribute.
	 * @param array|null       $ledger_rows Already-read parent ledger, or null to read it here.
	 * @throws \RuntimeException When the parent order cannot be read.
	 */
	public static function refund_tender_rows( \WC_Order_Refund $refund, ?array $ledger_rows = null ): array {
		if ( null === $ledger_rows ) {
			$order = wc_get_order( $refund->get_parent_id() );
			if ( ! $order ) {
				// Silently attributing nothing would let a closure count a zero refund and stamp it.
				throw new \RuntimeException( 'Refund parent order could not be read.' );
			}
			$ledger_rows = Ledger::instance()->read( $order );
		}
		$rows = array();
		$remaining = Money::minor( $refund->get_amount() );
		// A succeeded allocation is a fact about the refund, kept even if its row was voided later.
		foreach ( $ledger_rows as $row ) {
			foreach ( $row['refunds'] ?? array() as $allocation ) {
				if ( 'succeeded' === $allocation['status'] && (int) $allocation['id'] === $refund->get_id() && $remaining > 0 && Money::minor( $allocation['amount'] ) > 0 ) {
					// Rows written before the handler-result checks may over- or mis-allocate: attribute at most the refund, never a negative.
					$amount = min( Money::minor( $allocation['amount'] ), $remaining );
					$remaining -= $amount;
					$rows[] = array(
						'method_id' => $row['method_id'],
						'kind' => $row['kind'],
						'amount' => Money::format( -$amount ),
						'refund_id' => $refund->get_id(),
					);
				}
			}
		}
		if ( $remaining > 0 ) {
			$rows[] = array(
				'method_id' => 'pos_cash',
				'kind' => 'cash',
				'amount' => Money::format( -$remaining ),
				'refund_id' => $refund->get_id(),
			);
		}
		return $rows;
	}

	/** Resolve tenders only for callers consuming financial figures.
	 *
	 * @throws \RuntimeException On read failure.
	 */
	public function refunds(): array {
		if ( isset( $this->memo['refunds'] ) ) {
			return $this->memo['refunds'];
		}
		$result = array();
		foreach ( $this->refund_records() as $id => $refund ) {
			$rows = $this->refund_parent_rows( $refund );
			if ( null === $rows ) {
				throw new \RuntimeException( 'Refund parent order could not be read.' );
			}
			$result[ $id ] = array(
				'refund' => $refund,
				'rows' => self::refund_tender_rows( $refund, $rows ),
			);
		}
		$this->memo['refunds'] = $result;
		return $result;
	}

	/** Freeze the parent ledger shared by allocation versions and tender attribution.
	 *
	 * @param \WC_Order_Refund $refund Refund whose parent supplies the allocations.
	 */
	private function refund_parent_rows( \WC_Order_Refund $refund ): ?array {
		$id = $refund->get_parent_id();
		if ( ! array_key_exists( $id, $this->memo['refund_parents'] ?? array() ) ) {
			$order = wc_get_order( $id );
			$this->memo['refund_parents'][ $id ] = $order instanceof \WC_Order ? Ledger::instance()->read( $order ) : null;
		}
		return $this->memo['refund_parents'][ $id ];
	}

	/** Read refund identities and versions without resolving their tenders.
	 *
	 * @throws \RuntimeException On read failure.
	 */
	private function refund_records(): array {
		if ( isset( $this->memo['refund_records'] ) ) {
			return $this->memo['refund_records'];
		}
		$session = $this->session;
		global $wpdb;
		$hpos = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$key = $hpos ? 'order_id' : 'post_id';
		$orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
		$type = $hpos ? 'type' : 'post_type';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Storage-selected identifiers.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.{$key} FROM {$table} m INNER JOIN {$orders} o ON o.id = m.{$key} WHERE m.meta_key = %s AND LOWER(m.meta_value) = %s AND o.{$type} = %s ORDER BY m.{$key} DESC", '_wcpos_session', strtolower( $session['id'] ), 'shop_order_refund' ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Session refunds read failed.' );
		}
		$result = array();
		foreach ( $ids as $id ) {
			$refund = wc_get_order( $id );
			if ( ! $refund instanceof \WC_Order_Refund ) {
				throw new \RuntimeException( 'Session refund could not be read.' );
			}
			$result[ $id ] = $refund;
		}
		$this->memo['refund_records'] = $result;
		return $result;
	}

	/** Read all captured session rows in either storage mode; fiscal totals cannot truncate.
	 *
	 * @throws \RuntimeException On read failure.
	 */
	public function legs(): array {
		if ( isset( $this->memo['legs'] ) ) {
			return $this->memo['legs'];
		}
		$session = $this->session;
		global $wpdb;
		$hpos = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$key = $hpos ? 'order_id' : 'post_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Storage-selected identifiers.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$key} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY {$key} DESC", Ledger::META_KEY, '%' . $wpdb->esc_like( '"session_id":"' . $session['id'] . '"' ) . '%' ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Session ledger read failed.' );
		}
		$result = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				throw new \RuntimeException( 'Session order could not be read.' );
			}
			foreach ( Ledger::instance()->read( $order ) as $row ) {
				if ( ( $row['session_id'] ?? null ) === $session['id'] && 'captured' === ( $row['status'] ?? null ) ) {
					$result[ $id ][] = $row;
				}
			}
		}
		$this->memo['legs'] = $result;
		return $this->memo['legs'];
	}
}
