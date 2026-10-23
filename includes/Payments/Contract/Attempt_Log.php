<?php
/**
 * An order's gateway attempts, as storage holds them.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/**
 * A read of `_wcpos_gateway_attempts`, built by {@see Attempts::of()}. Each entry is
 * `{ id, fate, method_id, cashier_id, origin, command_id, sent_at_gmt, resolved_at_gmt, awaiting, asks, attention_at_gmt }`,
 * in the order they were last staged; `awaiting` is null or `{ destination, at_gmt }`; `asks` counts the resolver's
 * indeterminate answers and `attention_at_gmt` is when it asked a human. Valid until the order is next saved.
 */
final class Attempt_Log {
	/**
	 * Entries by lowercase attempt id.
	 *
	 * @var array<string, array>
	 */
	private array $entries;

	/**
	 * The order's row ids, or the callable that reads them once a `paid` entry needs them.
	 *
	 * @var callable|string[]|null
	 */
	private $row_ids;

	/**
	 * The order's payment method: the one paid facts on the order can be attributed to.
	 *
	 * @var string
	 */
	private $method;

	/**
	 * Wrap decoded entries.
	 *
	 * @param array<string, array> $entries Entries by attempt id.
	 * @param callable|null        $row_ids fn(): string[], the order's payment row ids.
	 * @param string               $method  The order's payment method.
	 */
	public function __construct( array $entries, ?callable $row_ids = null, string $method = '' ) {
		$this->entries = $entries;
		$this->row_ids = $row_ids;
		$this->method  = $method;
	}

	/** Every entry, by attempt id. */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * One entry.
	 *
	 * @param string $id Attempt id.
	 */
	public function get( string $id ): ?array {
		return $this->entries[ strtolower( $id ) ] ?? null;
	}

	/**
	 * Each attempt's fate: `sent`, `settled`, `paid`, `cancelled` or `superseded`.
	 *
	 * @return array<string, string>
	 */
	public function fates(): array {
		return array_map( static fn( array $entry ): string => $entry['fate'], $this->entries );
	}

	/**
	 * The newest attempt whose outcome is not recorded yet, or null: money in motion (ADR 0040 rule 3). That is a `sent`
	 * one, or a `paid` one whose row is not stored (a write that stored the fate and lost the row).
	 */
	public function unresolved(): ?array {
		$open = array_filter( $this->entries, array( $this, 'owed' ) );
		return $open ? end( $open ) : null;
	}

	/**
	 * The attempts the sweep still asks about (S1 lists them in the live-leg index): `sent`, awaiting no one, and not
	 * yet handed to a human.
	 *
	 * @return string[] Attempt ids.
	 */
	public function to_index(): array {
		return array_map( 'strval', array_keys( $this->sweepable() ) );
	}

	/**
	 * The one attempt a resolver caller acts on, or null: the predicate per source is the eligibility, so nothing is
	 * selected and then rejected. `paid`: the newest awaiting the customer, or one whose row was lost. `sweep`: the
	 * newest the sweep lists (`to_index()`), preferring one the handler can answer for (its row lost, or its method
	 * the order's), so an attempt it cannot answer never starves an older one it can. `replay`, `cancel`, `submit`:
	 * the newest owed one nobody awaits.
	 *
	 * @param string $source `paid`, `sweep`, `replay`, `cancel` or `submit`.
	 */
	public function eligible( string $source ): ?array {
		$owed = array_filter( $this->entries, array( $this, 'owed' ) );
		if ( 'paid' === $source ) {
			return self::newest( array_filter( $owed, static fn( array $entry ): bool => null !== $entry['awaiting'] || 'paid' === $entry['fate'] ) );
		}
		if ( 'sweep' !== $source ) {
			return self::newest( array_filter( $owed, static fn( array $entry ): bool => null === $entry['awaiting'] || 'paid' === $entry['fate'] ) );
		}
		$listed = $this->sweepable();
		return self::newest( array_filter( $listed, fn( array $entry ): bool => 'paid' === $entry['fate'] || $this->method === $entry['method_id'] ) ) ?? self::newest( $listed );
	}

	/** The owed entries the sweep asks about: nobody awaits them (or their row was lost) and no human was asked yet. */
	private function sweepable(): array {
		return array_filter( $this->entries, fn( array $entry ): bool => $this->owed( $entry ) && ( 'paid' === $entry['fate'] || null === $entry['awaiting'] ) && null === $entry['attention_at_gmt'] );
	}

	/**
	 * The last of a set of entries, in the order they were staged.
	 *
	 * @param array $entries Entries.
	 */
	private static function newest( array $entries ): ?array {
		return $entries ? end( $entries ) : null;
	}

	/**
	 * Whether an entry's outcome is still owed a record: `sent`, or `paid` with no row of its id.
	 *
	 * @param array $entry Entry.
	 */
	private function owed( array $entry ): bool {
		if ( 'paid' !== $entry['fate'] || null === $this->row_ids ) {
			return 'sent' === $entry['fate'];
		}
		if ( is_callable( $this->row_ids ) ) {
			$this->row_ids = ( $this->row_ids )();
		}
		return ! in_array( $entry['id'], $this->row_ids, true );
	}

	/**
	 * The awaiting-customer state, derived from the newest entry carrying it: what the till sent, where and by whom.
	 * A covering write resolves the entry in S1 and its completion's S3 clears what it awaited.
	 *
	 * @return array{attempt_id: string, method_id: string, destination: ?string, sent_at_gmt: ?string, cashier_id: int, provenance?: array}|null
	 */
	public function awaiting(): ?array {
		$waiting = array_filter( $this->entries, static fn( array $entry ): bool => null !== $entry['awaiting'] );
		if ( ! $waiting ) {
			return null;
		}
		$entry = end( $waiting );
		$stamp = array(
			'attempt_id'  => $entry['id'],
			'method_id'   => $entry['method_id'],
			'destination' => $entry['awaiting']['destination'] ?? null,
			'sent_at_gmt' => $entry['awaiting']['at_gmt'] ?? null,
			'cashier_id'  => $entry['cashier_id'],
		);
		return $entry['origin'] ? $stamp + array( 'provenance' => $entry['origin'] ) : $stamp;
	}
}
