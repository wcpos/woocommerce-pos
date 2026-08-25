<?php
/**
 * WCPOS sync augmentation pipeline.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * THE augmentation pipeline behind both sync read lanes.
 *
 * A served record reaches the client down one of two lanes:
 *
 * - the BATCH lane — the catalog proxy hands a whole wc/v3 LIST to the public
 *   `woocommerce_pos_sync_proxy_response` filter;
 * - the PER-OBJECT lane — {@see Product_Serializer} hands ONE serialized product
 *   to the public `woocommerce_pos_sync_serialized_product` filter.
 *
 * Every augmentation used to be wired to BOTH filters by hand, so each stamper
 * shipped as a pair of near-identical twins (`stamp_proxy_*` / `stamp_serialized_*`)
 * and a fix to one silently missed the other. The pipeline is the single place an
 * augmentation is declared; the two public filter names survive as thin
 * PROJECTIONS of it, so third-party code that hooks either name keeps working at
 * the priorities it always used.
 *
 * Two kinds of entry:
 *
 * - RECORD augmenters — `callable( array $payload, mixed $object, mixed $request ): array`.
 *   Declared ONCE and projected onto both lanes; on the batch lane the pipeline
 *   walks the list and hands each record over with a null `$object`, which is
 *   exactly the per-object lane's contract (an augmenter that needs the WC object
 *   resolves it lazily by id, so a page of simple products never pays for a load
 *   it does not use).
 * - LANE augmenters — pinned to one lane because they must see the WHOLE batch.
 *   The uuid, revision and digest stampers bulk-read their meta store in ONE query
 *   per page; folding them into the record loop would be N queries per page.
 *
 * Priorities are preserved verbatim from the hand-wiring: the pipeline adds ONE
 * projection callback per (lane, priority) group, so a record augmenter declared
 * at 10 still runs at 10 relative to any external hooker.
 *
 * `Meta_Normalizer` is deliberately NOT registered here: it also normalizes the
 * ORDER lane, so it keeps its own three-filter registrar and stays at priority 5,
 * ahead of everything this pipeline installs.
 */
final class Augmentation_Pipeline {
	/**
	 * The batch lane's public filter name.
	 */
	public const PROXY_FILTER = 'woocommerce_pos_sync_proxy_response';

	/**
	 * The per-object product lane's public filter name.
	 */
	public const SERIALIZED_FILTER = 'woocommerce_pos_sync_serialized_product';

	/**
	 * Lane id for the catalog-proxy batch response.
	 */
	public const LANE_PROXY = 'proxy';

	/**
	 * Lane id for the per-object serialized product payload.
	 */
	public const LANE_SERIALIZED = 'serialized';

	/**
	 * Registered record augmenters.
	 *
	 * @var array<int, array{callback: callable, priority: int, lanes: string[], resource: string}>
	 */
	private static $record_augmenters = array();

	/**
	 * Projection callbacks added to the public filters, kept for uninstall.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private static $projections = array();

	/**
	 * Declare a per-record augmenter.
	 *
	 * @param callable $callback callable( array $payload, mixed $object, mixed $request ): array.
	 * @param int      $priority Filter priority, matching the lane's public filter.
	 * @param string[] $lanes    Lanes this augmenter serves. Defaults to both.
	 * @param string   $resource Batch-lane resource slug this augmenter serves.
	 */
	public static function add_record_augmenter( callable $callback, int $priority = 10, array $lanes = array( self::LANE_PROXY, self::LANE_SERIALIZED ), string $resource = 'products' ): void {
		self::$record_augmenters[] = array(
			'callback' => $callback,
			'priority' => $priority,
			'lanes'    => $lanes,
			'resource' => $resource,
		);
	}

	/**
	 * Wire the default WCPOS augmentations onto both public filters.
	 *
	 * Called once from {@see \WCPOS\WooCommercePOS\Init} when the sync feature is
	 * enabled and the schema latch is healthy.
	 */
	public static function install(): void {
		self::reset();

		// Batch-lane-only augmenters. Each bulk-reads its meta store once per page,
		// so it MUST see the whole list; each keeps its own registrar because the
		// registrars are also the seams the tests and the ops endpoints drive.
		// Revision runs at 9 — BEFORE uuid/digest augmentation — so the stamped
		// bytes equal what the write path recomputes from a bare wc/v3 re-read.
		// The digest registrar also owns the ORDER pull lane's stamper, so every
		// served-record augmentation is wired from here and nowhere else.
		Revision::register_proxy_stamps();
		Proxy_Uuid_Stamper::register_proxy_stampers();
		Integrity_Digest::register_proxy_digest_stampers();

		// Per-object-lane-only identity stamp: the batch lane's identity twin is
		// Proxy_Uuid_Stamper above, which bulk-reads instead of loading per record.
		self::add_record_augmenter(
			array( Pos_Uuid::class, 'stamp_serialized_record' ),
			10,
			array( self::LANE_SERIALIZED )
		);

		// Declared ONCE, projected onto both lanes.
		self::add_record_augmenter( array( Variable_Prices::class, 'augment_record' ), 10 );
		// After the revision stamper at 9, so narrowing the child list cannot perturb the parent's
		// `_rxdb_revision` — the write path recomputes that from a bare wc/v3 re-read.
		self::add_record_augmenter( array( Variable_Children::class, 'augment_record' ), 10 );
		self::add_record_augmenter( array( Product_Images::class, 'augment_record' ), 10 );

		self::wire();
	}

	/**
	 * Project the current registry onto the two public filters.
	 *
	 * Adds ONE projection callback per (lane, priority) group, replacing any
	 * projections a previous call installed. Call this after declaring extra
	 * augmenters with {@see add_record_augmenter}.
	 */
	public static function wire(): void {
		self::unwire();
		foreach ( self::grouped( self::LANE_PROXY ) as $priority => $entries ) {
			$callback = static function ( $data, $resource = '', $request = null ) use ( $entries ) {
				return self::apply_to_list( $data, $resource, $request, $entries );
			};
			add_filter( self::PROXY_FILTER, $callback, $priority, 3 );
			self::$projections[] = array( self::PROXY_FILTER, $callback, $priority );
		}
		foreach ( self::grouped( self::LANE_SERIALIZED ) as $priority => $entries ) {
			$callback = static function ( $payload, $object = null, $request = null ) use ( $entries ) {
				return self::apply_to_record( $payload, $object, $request, $entries );
			};
			add_filter( self::SERIALIZED_FILTER, $callback, $priority, 3 );
			self::$projections[] = array( self::SERIALIZED_FILTER, $callback, $priority );
		}
	}

	/**
	 * Remove every projection this pipeline installed and forget the registry.
	 *
	 * The batch-lane registrars own their own unregister seams, so callers that
	 * need a full teardown call those too.
	 */
	public static function reset(): void {
		self::unwire();
		self::$record_augmenters = array();
	}

	/**
	 * Apply the per-object lane's record augmenters to ONE serialized payload.
	 *
	 * @param mixed $payload  Serialized product payload.
	 * @param mixed $object   Product or variation backing the payload.
	 * @param mixed $request  Serialization context.
	 * @param array $entries  Record augmenters to apply.
	 *
	 * @return mixed
	 */
	private static function apply_to_record( $payload, $object, $request, array $entries ) {
		if ( ! \is_array( $payload ) ) {
			return $payload;
		}
		foreach ( $entries as $entry ) {
			$result  = ( $entry['callback'] )( $payload, $object, $request );
			$payload = \is_array( $result ) ? $result : $payload;
		}

		return $payload;
	}

	/**
	 * Apply the batch lane's record augmenters across a proxied list.
	 *
	 * Entries are applied OUTERMOST — the whole list passes through one augmenter
	 * before the next — preserving the order the hand-wired twins ran in.
	 *
	 * @param mixed $data     Proxied response list.
	 * @param mixed $resource Proxy resource slug.
	 * @param mixed $request  Request context.
	 * @param array $entries  Record augmenters to apply.
	 *
	 * @return mixed
	 */
	private static function apply_to_list( $data, $resource, $request, array $entries ) {
		if ( ! \is_array( $data ) ) {
			return $data;
		}
		foreach ( $entries as $entry ) {
			if ( $entry['resource'] !== $resource ) {
				continue;
			}
			foreach ( $data as $index => $record ) {
				if ( ! \is_array( $record ) ) {
					continue;
				}
				// The batch lane has no loaded object: augmenters resolve it lazily
				// by id, exactly as they do on the per-object lane.
				$result = ( $entry['callback'] )( $record, null, $request );
				if ( \is_array( $result ) ) {
					$data[ $index ] = $result;
				}
			}
		}

		return $data;
	}

	/**
	 * Detach every projection callback this pipeline added, keeping the registry.
	 */
	private static function unwire(): void {
		foreach ( self::$projections as $projection ) {
			remove_filter( $projection[0], $projection[1], $projection[2] );
		}
		self::$projections = array();
	}

	/**
	 * Group one lane's record augmenters by priority, ascending, registration-stable.
	 *
	 * @param string $lane Lane id.
	 *
	 * @return array<int, array<int, array{callback: callable, priority: int, lanes: string[], resource: string}>>
	 */
	private static function grouped( string $lane ): array {
		$groups = array();
		foreach ( self::$record_augmenters as $entry ) {
			if ( ! \in_array( $lane, $entry['lanes'], true ) ) {
				continue;
			}
			$groups[ $entry['priority'] ][] = $entry;
		}
		ksort( $groups );

		return $groups;
	}
}
