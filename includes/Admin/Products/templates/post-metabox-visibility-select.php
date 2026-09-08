<?php
/**
 * Admin View: Product Metabox.
 *
 * @package WCPOS\WooCommercePOS
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Variables passed from Single_Product::post_submitbox_misc_actions().
 *
 * @var \WCPOS\WooCommercePOS\Admin\Products\Single_Product $this
 * @var string $selected
 * @var string $prior_catalog_label
 */
?>

<div class="misc-pub-section" id="pos-visibility">
	<?php /* translators: Product POS visibility or barcode label in WooCommerce admin. */ esc_html_e( 'POS visibility', 'woocommerce-pos' ); ?>:
	<strong id="pos-visibility-display"><?php echo esc_html( $this->options[ $selected ] ); // @phpstan-ignore-line ?></strong>
	<a href="#pos-visibility" id="pos-visibility-show" class="hide-if-no-js"
	   style="display: inline;">
	   <?php
		// translators: Link text for editing product POS visibility in the WooCommerce product publish box.
		esc_html_e( 'Edit', 'woocommerce-pos' );
		?>
  </a>

	<div id="pos-visibility-select" class="hide-if-js" style="display: none;">
		<?php
		foreach ( $this->options as $value => $label ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- @phpstan-ignore-line
			$checked = $value == $selected ? 'checked="checked"' : ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			?>
			<label style="display:block;margin: 5px 0;">
				<input type="radio" name="_pos_visibility"
					   value="<?php echo esc_attr( $value ); ?>" <?php echo esc_attr( $checked ); ?>> <?php echo esc_html( $label ); ?>
			</label>
		<?php } ?>
		<p>
			<a href="#pos-visibility" id="pos-visibility-save"
			   class="hide-if-no-js button">
			   <?php
				// translators: Button text confirming product POS visibility changes in the WooCommerce product publish box.
				esc_html_e( 'OK', 'woocommerce-pos' );
				?>
				</a>
			<a href="#pos-visibility" id="pos-visibility-cancel"
			   class="hide-if-no-js">
			   <?php
				// translators: Link text cancelling product POS visibility changes in the WooCommerce product publish box.
				esc_html_e( 'Cancel', 'woocommerce-pos' );
				?>
	  </a>
		</p>
	</div>
	<p id="pos-visibility-catalog-note" class="description" style="display:none"><?php esc_html_e( 'POS Only products are never shown in the online catalog or search.', 'woocommerce-pos' ); ?></p>
	<p id="pos-visibility-catalog-restore-note" class="description" style="display:none" data-prior-label="<?php echo esc_attr( $prior_catalog_label ); ?>">
		<?php
		/* translators: %s: a WooCommerce catalog visibility option, e.g. "Shop and search results". */
		echo esc_html( sprintf( __( 'Catalog visibility goes back to “%s” when you update. Change it afterwards if you want something else.', 'woocommerce-pos' ), $prior_catalog_label ) );
		?>
	</p>
	<script>
		(function () {
			const display = document.getElementById('pos-visibility-display'),
				show = document.getElementById('pos-visibility-show'),
				select = document.getElementById('pos-visibility-select'),
				cancel = document.getElementById('pos-visibility-cancel'),
				save = document.getElementById('pos-visibility-save');

			let current = document.querySelector('input[name="_pos_visibility"]:checked');

			// Lock WooCommerce's Catalog visibility control while POS Only is the
			// confirmed choice. Display only: the radios keep the merchant's stored
			// value so the server can record it as the prior before forcing Hidden.
			// While the product IS POS Only its catalog visibility cannot be edited
			// here at all (the server re-asserts Hidden on every save), so deselecting
			// POS Only shows what it goes back to instead of offering the control.
			const lockedOnLoad = !!current && current.value === 'pos_only';
			function syncCatalogVisibility() {
				const catalog = document.getElementById('catalog-visibility'),
					catalogDisplay = document.getElementById('catalog-visibility-display'),
					catalogSelect = document.getElementById('catalog-visibility-select'),
					hidden = document.getElementById('_visibility_hidden'),
					note = document.getElementById('pos-visibility-catalog-note'),
					restoreNote = document.getElementById('pos-visibility-catalog-restore-note');
				// WooCommerce core markup; bail if any of it moved.
				if (!current || !catalog || !catalogDisplay || !catalogSelect || !hidden || !note || !restoreNote) return;
				const edit = catalog.querySelector('a.edit-catalog-visibility');
				if (!('posPrev' in catalogDisplay.dataset)) {
					catalogDisplay.dataset.posPrev = catalogDisplay.textContent;
				}
				catalog.appendChild(note);
				catalog.appendChild(restoreNote);
				if (current.value === 'pos_only') {
					catalogDisplay.textContent = hidden.dataset.label;
					if (edit) edit.style.display = 'none';
					catalogSelect.style.display = 'none';
					note.style.display = 'block';
					restoreNote.style.display = 'none';
				} else if (lockedOnLoad) {
					catalogDisplay.textContent = restoreNote.dataset.priorLabel;
					if (edit) edit.style.display = 'none';
					catalogSelect.style.display = 'none';
					note.style.display = 'none';
					restoreNote.style.display = 'block';
				} else {
					catalogDisplay.textContent = catalogDisplay.dataset.posPrev;
					if (edit) edit.style.display = '';
					catalogSelect.style.display = '';
					note.style.display = 'none';
					restoreNote.style.display = 'none';
				}
			}

			function toggleSelect() {
				select.style.display = select.style.display === 'none' ? 'block' : 'none';
				show.style.display = show.style.display === 'none' ? 'block' : 'none';
			}

			function updateDisplay() {
				const val = current.parentNode.textContent;
				display.textContent = val;
			}

			show.addEventListener('click', function (e) {
				e.preventDefault();
				toggleSelect();
			});

			cancel.addEventListener('click', function (e) {
				e.preventDefault();
				current.checked = true;
				updateDisplay();
				toggleSelect();
			});

			save.addEventListener('click', function (e) {
				e.preventDefault();
				current = document.querySelector('input[name="_pos_visibility"]:checked');
				updateDisplay();
				toggleSelect();
				syncCatalogVisibility();
			});
			syncCatalogVisibility();
		})();
	</script>
</div>
