<?php
/**
 * WCPOS POS-visibility change observer.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Journals the moment a record enters or leaves the POS servable set.
 *
 * # Why this exists
 *
 * The catalogue change stream ({@see \WCPOS\WooCommercePOS\API\V2\Changes_Controller::sequence_log})
 * drops update rows for records {@see Pos_Visibility} hides, because the catalog lane will never
 * serve them: announcing one costs every till a targeted pull that comes back empty, inflates the
 * replay backlog the client re-baselines on, and moves a head no till can act on.
 *
 * Dropping them is only safe because the TRANSITION is announced. A record that just became hidden
 * is still resident on every till, and the one message about it a client must still receive is
 * "drop this" — so hiding appends a TOMBSTONE and un-hiding appends an ordinary update row. The
 * stream never filters tombstones, so the removal always lands.
 *
 * To the stream, hiding a record is indistinguishable from trashing it and un-hiding it from
 * untrashing it, which is why both directions go through the journal's existing
 * `record_post_deleted()` / `record_post_untrashed()` handlers rather than re-deriving the object
 * type, the revision and the parent-product re-announcement. A client needs no new vocabulary: the
 * `deleted` row runs through the same local-work-protected delete path a real delete does.
 *
 * # Why it observes OPTIONS rather than the settings API
 *
 * `Visibility_Section::update_visibility_settings()` is only one of the writers. The POS app PATCHes
 * the whole section through the settings REST endpoint, and another plugin or wp-cli can call
 * `update_option()` directly. Every one of those paths funnels through `update_option()`, so
 * observing the options is what makes this self-healing in the same way
 * {@see Config_Fingerprint} recomputes from live options instead of trusting a hook counter.
 *
 * The diff is taken over the RESOLVED hidden set — `Pos_Visibility::hidden_ids()`, the same call the
 * stream filters on — not over the raw stored id lists. That is what makes the `pos_only_products`
 * feature toggle work: flipping it moves the entire hidden set without touching a single id list.
 * It also means an extension filtering the visibility settings is honoured here exactly as it is on
 * every read lane.
 */
final class Visibility_Observer {
	/**
	 * The journal rows are appended to.
	 *
	 * @var Sync_Journal
	 */
	private Sync_Journal $journal;

	/**
	 * The POS servable-set authority.
	 *
	 * @var Pos_Visibility
	 */
	private Pos_Visibility $visibility;

	/**
	 * The resolved hidden set as it stood before an in-flight option write, keyed by option name.
	 *
	 * Keyed per option because a no-op write fires `pre_update_option_{$option}` and then NO
	 * `update_option_{$option}`; an unkeyed snapshot would be consumed by whichever option wrote
	 * next and diffed against the wrong baseline.
	 *
	 * @var array<string, int[]>
	 */
	private array $hidden_before = array();

	/**
	 * Constructor.
	 *
	 * @param null|Sync_Journal   $journal    Journal to append to.
	 * @param null|Pos_Visibility $visibility Servable-set authority.
	 */
	public function __construct( ?Sync_Journal $journal = null, ?Pos_Visibility $visibility = null ) {
		$this->journal    = $journal ?? new Sync_Journal();
		$this->visibility = $visibility ?? new Pos_Visibility();
	}

	/**
	 * Watch every option that can move the POS servable set.
	 */
	public function register_hooks(): void {
		foreach ( Pos_Visibility::source_options() as $option ) {
			add_filter( "pre_update_option_{$option}", array( $this, 'snapshot_hidden_ids' ), 10, 3 );
			add_action( "update_option_{$option}", array( $this, 'record_updated_option' ), 10, 3 );
			add_action( "add_option_{$option}", array( $this, 'record_added_option' ), 10, 2 );
		}
	}

	/**
	 * Capture the hidden set before the write lands.
	 *
	 * Runs on `pre_update_option_{$option}`, which fires BEFORE the option row and its cache are
	 * updated — so `hidden_ids()` here still resolves the pre-write state. A pass-through filter:
	 * the value is returned untouched.
	 *
	 * @param mixed  $value     The value about to be written.
	 * @param mixed  $old_value The value being replaced.
	 * @param string $option    Option name.
	 *
	 * @return mixed
	 */
	public function snapshot_hidden_ids( $value, $old_value = null, $option = '' ) {
		if ( \is_string( $option ) && '' !== $option ) {
			$this->hidden_before[ $option ] = $this->visibility->hidden_ids( Pos_Visibility::CATALOG );
		}

		return $value;
	}

	/**
	 * Journal the transitions an option update caused.
	 *
	 * @param mixed  $old_value The replaced value.
	 * @param mixed  $value     The written value.
	 * @param string $option    Option name.
	 */
	public function record_updated_option( $old_value = null, $value = null, $option = '' ): void {
		if ( ! \is_string( $option ) || ! \array_key_exists( $option, $this->hidden_before ) ) {
			return;
		}

		$before = $this->hidden_before[ $option ];
		// Consume the snapshot: a later `update_option_{$option}` that somehow arrives without its
		// own `pre_update_option_{$option}` must not diff against a stale baseline.
		unset( $this->hidden_before[ $option ] );

		$this->record_transitions( $before );
	}

	/**
	 * Journal the transitions adding the option caused.
	 *
	 * `add_option_{$option}` fires after the insert and has no pre-write counterpart, but it needs
	 * none: with the option absent the hidden set is necessarily empty — an unconfigured visibility
	 * option hides nothing, and an unconfigured general option leaves the `pos_only_products`
	 * feature off, which reports an empty set whatever the id lists hold.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  The inserted value.
	 */
	public function record_added_option( $option = '', $value = null ): void {
		$this->record_transitions( array() );
	}

	/**
	 * Append one journal row per record that entered or left the servable set.
	 *
	 * @param int[] $before The hidden set before the write.
	 */
	private function record_transitions( array $before ): void {
		$after = $this->visibility->hidden_ids( Pos_Visibility::CATALOG );

		foreach ( array_diff( $after, $before ) as $id ) {
			$this->record_transition( (int) $id, true );
		}

		foreach ( array_diff( $before, $after ) as $id ) {
			$this->record_transition( (int) $id, false );
		}
	}

	/**
	 * Record one record's servability change as the trash/untrash event it is.
	 *
	 * The post type is read from the post itself rather than from the id list the id came from: the
	 * lists are merchant-supplied, and a stale or mistyped id must not make the journal announce a
	 * change to whatever unrelated record now holds that number. An id with no post — deleted since
	 * it was hidden — resolves to no type and is skipped.
	 *
	 * @param int  $id     Post id whose POS servability changed.
	 * @param bool $hidden True when the record just left the servable set.
	 */
	private function record_transition( int $id, bool $hidden ): void {
		$post_type = get_post_type( $id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}

		if ( $hidden ) {
			$this->journal->record_post_deleted( $id );

			return;
		}

		$this->journal->record_post_untrashed( $id );
	}
}
