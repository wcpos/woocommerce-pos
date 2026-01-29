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
 */
?>

<div class="misc-pub-section" id="pos-visibility">
	<?php esc_html_e( 'POS visibility', 'woocommerce-pos' ); ?>:
	<strong id="pos-visibility-display"><?php echo esc_html( $this->options[ $selected ] ); // @phpstan-ignore-line ?></strong>
	<a href="#pos-visibility" id="pos-visibility-show" class="hide-if-no-js"
	   style="display: inline;">
	   <?php
		// translators: wordpress.
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
				// translators: wordpress.
				esc_html_e( 'OK', 'woocommerce-pos' );
				?>
				</a>
			<a href="#pos-visibility" id="pos-visibility-cancel"
			   class="hide-if-no-js">
			   <?php
				// translators: wordpress.
				esc_html_e( 'Cancel', 'woocommerce-pos' );
				?>
	  </a>
		</p>
	</div>
	<script>
		(function () {
			const display = document.getElementById('pos-visibility-display'),
				show = document.getElementById('pos-visibility-show'),
				select = document.getElementById('pos-visibility-select'),
				cancel = document.getElementById('pos-visibility-cancel'),
				save = document.getElementById('pos-visibility-save');

			let current = document.querySelector('input[name="_pos_visibility"]:checked');

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
			});
		})();
	</script>
</div>

