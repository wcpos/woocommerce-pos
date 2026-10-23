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
	 * Wrap decoded entries.
	 *
	 * @param array<string, array> $entries Entries by attempt id.
	 */
	public function __construct( array $entries ) {
		$this->entries = $entries;
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

	/** The newest attempt whose outcome is not known yet (fate `sent`), or null: money in motion (ADR 0040 rule 3). */
	public function unresolved(): ?array {
		$sent = array_filter( $this->entries, static fn( array $entry ): bool => 'sent' === $entry['fate'] );
		return $sent ? end( $sent ) : null;
	}

	/**
	 * The attempts the sweep still asks about (S1 lists them in the live-leg index): `sent`, awaiting no one, and not
	 * yet handed to a human.
	 *
	 * @return string[] Attempt ids.
	 */
	public function to_index(): array {
		return array_map( 'strval', array_keys( array_filter( $this->entries, static fn( array $entry ): bool => 'sent' === $entry['fate'] && null === $entry['awaiting'] && null === $entry['attention_at_gmt'] ) ) );
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
