<?php
/**
 * POS Only implies WooCommerce catalog visibility "Hidden".
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WC_Product;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;

/**
 * Keeps WooCommerce's catalog visibility in step with the POS Only set (#1862).
 *
 * # Why this exists
 *
 * "POS Only" and WooCommerce's own Catalog visibility are two controls that sit
 * next to each other in the product Publish box, and merchants set the first
 * without the second — then find the product in search results, related
 * products, blocks and sitemaps. {@see Products::hide_pos_only_products()} keeps
 * POS Only products out of front-end queries by `post__not_in`; this class makes
 * the WooCommerce-native signal agree, so every surface that honours
 * `product_visibility` terms (shop loops, search, related products, the Product
 * Collection block, Store API listings, the wc/v3 REST API) hides them too.
 *
 * # The invariant
 *
 * A product in {@see Pos_Visibility::pos_only_ids()} has catalog visibility
 * `hidden`. The catalog visibility it had before WCPOS forced it is recorded in
 * {@see PRIOR_META} and put back when the product leaves the set; a product that
 * a merchant explicitly hid gets `hidden` back. If no prior value was recorded,
 * `visible` is the fallback — a product moving online should be on the shop,
 * not silently missing from it (#1862). While a product is in the set its
 * catalog visibility cannot be changed through WooCommerce's CRUD (seam 2
 * below), so choosing a different visibility is a second edit after leaving
 * POS Only; the edit screen says so. A value changed by a term-level write
 * that bypasses the CRUD (a taxonomy import) is left alone on exit.
 *
 * # Two seams, both unconditional
 *
 * 1. Membership transitions: the same option hooks {@see Sync\Visibility_Observer}
 *    watches (the id lists and the `pos_only_products` toggle), diffed before and
 *    after each write. Every writer ends in that option — edit screen, quick and
 *    bulk edit, the settings REST endpoint, raw `update_option()`, the feature
 *    toggle, and deleting the option — so entering forces and leaving restores.
 * 2. Product saves: `woocommerce_before_product_object_save` re-asserts `hidden`
 *    on the in-flight object for a product already in the set. That covers every
 *    writer that carries WooCommerce's own catalog control (the three admin forms,
 *    wc/v3 REST writes, CSV import, other plugins) without a second save.
 *
 * A duplicate is cloned from its source, so a copy of a POS Only product would
 * be born `hidden`, outside the set, with nothing to restore it; the duplicate
 * gets the source's prior value instead, and the prior meta is not copied.
 *
 * # Lifecycle
 *
 * The forced value is WooCommerce state that outlives WCPOS, so deactivation
 * restores every POS Only product ({@see restore_all()}) — before this
 * invariant, deactivating made them visible again by removing the query
 * filter, and that must stay true — and activation, which also runs once per
 * upgrade, re-applies it ({@see force_all()}). Both are one idempotent pass
 * over the set; uninstall needs nothing, deactivation always precedes it.
 *
 * Unlike the observer this is NOT gated on the sync-schema latch: it is a
 * storefront invariant, not a sync concern. Stakes are Medium — the write is a
 * reversible product property — so two admins racing is an accepted risk.
 */
final class Catalog_Visibility {
	/**
	 * Catalog visibility a product had before WCPOS forced it hidden.
	 *
	 * @var string
	 */
	public const PRIOR_META = '_woocommerce_pos_prior_catalog_visibility';

	/**
	 * Per-option snapshots of the POS Only set taken before a write; keyed by
	 * option because a no-op `update_option()` fires the pre hook but not the
	 * post hook, and an unkeyed snapshot would then be consumed by the wrong write.
	 *
	 * @var array<string, int[]>
	 */
	private array $before = array();

	/**
	 * The set resolver.
	 *
	 * @var Pos_Visibility
	 */
	private Pos_Visibility $visibility;

	/**
	 * True while a restore is saving, so the before-save seam does not re-hide
	 * a product that is still in the set (deactivation restores the whole set
	 * without touching the option). Static because the hooked instance and the
	 * one the deactivator constructs are different objects.
	 *
	 * @var bool
	 */
	private static bool $restoring = false;

	/**
	 * The POS Only set for this request as an id-keyed lookup, so a bulk import
	 * that saves thousands of products neither re-reads the option nor scans the
	 * list per product. Dropped whenever a source option is about to change.
	 *
	 * @var null|array<int, true>
	 */
	private ?array $members = null;

	/**
	 * Constructor.
	 *
	 * @param Pos_Visibility|null $visibility Resolver for the POS Only set.
	 */
	public function __construct( ?Pos_Visibility $visibility = null ) {
		$this->visibility = $visibility ?? new Pos_Visibility();
	}

	/**
	 * Watch the two visibility source options and every product save.
	 */
	public function register_hooks(): void {
		add_action( 'delete_option', array( $this, 'snapshot_before_delete' ), 10, 1 );
		foreach ( Pos_Visibility::source_options() as $option ) {
			add_filter( "pre_update_option_{$option}", array( $this, 'snapshot' ), 10, 3 );
			add_action( "update_option_{$option}", array( $this, 'updated_option' ), 10, 3 );
			add_action( "add_option_{$option}", array( $this, 'changed_option' ), 10, 1 );
			add_action( "delete_option_{$option}", array( $this, 'changed_option' ), 10, 1 );
		}
		add_action( 'woocommerce_before_product_object_save', array( $this, 'before_product_save' ), 10, 1 );
		add_action( 'woocommerce_product_duplicate_before_save', array( $this, 'before_duplicate_save' ), 10, 2 );
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_prior_meta' ) );
	}

	/**
	 * Force `hidden` on a product entering the POS Only set, remembering what it had.
	 *
	 * Idempotent: a product already hidden with a recorded prior is left alone.
	 *
	 * @param int $product_id Product ID.
	 */
	public function force_hidden( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
			return;
		}

		if ( $product->meta_exists( self::PRIOR_META ) && 'hidden' === $product->get_catalog_visibility() ) {
			return;
		}
		$this->hide( $product );
		$product->save();
	}

	/**
	 * Put a product leaving the POS Only set back to its recorded visibility.
	 *
	 * @param int $product_id Product ID.
	 */
	public function restore( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
			return;
		}

		$changed = false;
		$prior   = $product->get_meta( self::PRIOR_META );
		if ( ! \is_string( $prior ) || ! array_key_exists( $prior, wc_get_product_visibility_options() ) ) {
			$prior = 'visible';
		}
		if ( $product->meta_exists( self::PRIOR_META ) ) {
			$product->delete_meta_data( self::PRIOR_META );
			$changed = true;
		}
		if ( 'hidden' === $product->get_catalog_visibility() ) {
			$product->set_catalog_visibility( $prior );
			$changed = true;
		}
		if ( $changed ) {
			self::$restoring = true;
			try {
				$product->save();
			} finally {
				self::$restoring = false;
			}
		}
	}

	/**
	 * Re-assert `hidden` on the object about to be written when it is POS Only.
	 *
	 * Runs on the same save, so no extra write and no ordering dependency on the
	 * writer. A product ENTERING the set is not here yet when the edit screen
	 * saves it (WCPOS writes the option after WooCommerce's save), which is what
	 * lets the transition path record the merchant's real prior value.
	 *
	 * @param mixed $product The product being saved.
	 */
	public function before_product_save( $product ): void {
		if ( self::$restoring || ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
			return;
		}
		if ( 'hidden' === $product->get_catalog_visibility() && $product->meta_exists( self::PRIOR_META ) ) {
			return;
		}
		if ( isset( $this->members()[ $product->get_id() ] ) ) {
			$this->hide( $product );
		}
	}

	/**
	 * Apply the invariant to every product currently in the set (activation, upgrade).
	 */
	public function force_all(): void {
		foreach ( $this->visibility->pos_only_ids() as $id ) {
			$this->force_hidden( $id );
		}
	}

	/**
	 * Put every product in the set back to its recorded visibility (deactivation).
	 */
	public function restore_all(): void {
		foreach ( $this->visibility->pos_only_ids() as $id ) {
			$this->restore( $id );
		}
	}

	/**
	 * Give a duplicate the source's pre-POS Only visibility instead of `hidden`.
	 *
	 * The copy is not in the set (new id) and the prior meta is not copied, so
	 * nothing else would ever un-hide it. Runs before WooCommerce's first save
	 * of the duplicate, so no extra write.
	 *
	 * @param mixed $duplicate The product being created.
	 * @param mixed $source    The product it is cloned from.
	 */
	public function before_duplicate_save( $duplicate, $source ): void {
		if ( ! $duplicate instanceof WC_Product || ! $source instanceof WC_Product || ! $source->meta_exists( self::PRIOR_META ) ) {
			return;
		}
		$prior = $source->get_meta( self::PRIOR_META );
		if ( ! \is_string( $prior ) || ! array_key_exists( $prior, wc_get_product_visibility_options() ) ) {
			$prior = 'visible';
		}
		$duplicate->set_catalog_visibility( $prior );
		$duplicate->delete_meta_data( self::PRIOR_META );
	}

	/**
	 * Snapshot the set before the option changes; pass the value through.
	 *
	 * @param mixed  $value     Incoming value.
	 * @param mixed  $old_value Previous value.
	 * @param string $option    Option name.
	 *
	 * @return mixed
	 */
	public function snapshot( $value, $old_value, string $option ) {
		$this->members           = null;
		$this->before[ $option ] = $this->visibility->pos_only_ids();

		return $value;
	}

	/**
	 * Apply a completed option update.
	 *
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     Written value.
	 * @param string $option    Option name.
	 */
	public function updated_option( $old_value, $value, string $option ): void {
		$this->apply( $option );
	}

	/**
	 * Snapshot on the generic PRE-delete action (the per-option form fires after).
	 *
	 * @param string $option Option name.
	 */
	public function snapshot_before_delete( string $option ): void {
		if ( \in_array( $option, Pos_Visibility::source_options(), true ) ) {
			$this->members           = null;
			$this->before[ $option ] = $this->visibility->pos_only_ids();
		}
	}

	/**
	 * Apply an option addition or deletion.
	 *
	 * @param string $option Option name.
	 */
	public function changed_option( string $option ): void {
		$this->apply( $option );
	}

	/**
	 * A duplicate is a new id outside the set; it must not inherit the prior value.
	 *
	 * @param string[] $excluded Meta keys excluded from duplication.
	 *
	 * @return string[]
	 */
	public function exclude_prior_meta( array $excluded ): array {
		$excluded[] = self::PRIOR_META;

		return $excluded;
	}

	/**
	 * The POS Only set as an id-keyed lookup, resolved once per request until a
	 * source option changes.
	 *
	 * @return array<int, true>
	 */
	private function members(): array {
		if ( null === $this->members ) {
			$this->members = array_fill_keys( $this->visibility->pos_only_ids(), true );
		}

		return $this->members;
	}

	/**
	 * Record the prior value once and set `hidden` on the object, without saving.
	 *
	 * @param WC_Product $product The product.
	 */
	private function hide( WC_Product $product ): void {
		if ( ! $product->meta_exists( self::PRIOR_META ) ) {
			$product->update_meta_data( self::PRIOR_META, $product->get_catalog_visibility() );
		}
		if ( 'hidden' !== $product->get_catalog_visibility() ) {
			$product->set_catalog_visibility( 'hidden' );
		}
	}

	/**
	 * Consume this option's snapshot and act on membership transitions only.
	 *
	 * A missing snapshot (an `add_option` with no pre hook) reads as an empty
	 * set, so only the force loop can run — unlike the observer, which bails,
	 * because here a spurious force is idempotent and a missed one is a leak.
	 *
	 * @param string $option Option name.
	 */
	private function apply( string $option ): void {
		$before = $this->before[ $option ] ?? array();
		unset( $this->before[ $option ] );
		$this->members = null;
		$after         = $this->visibility->pos_only_ids();
		foreach ( array_diff( $after, $before ) as $id ) {
			$this->force_hidden( $id );
		}
		foreach ( array_diff( $before, $after ) as $id ) {
			$this->restore( $id );
		}
	}
}
