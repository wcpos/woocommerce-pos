<?php
/**
 * Admin View: Product Metabox.
 */
if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<div class="misc-pub-section" id="pos-visibility">
	<?php _e( 'POS visibility', 'woocommerce-pos' ); ?>:
	<strong id="pos-visibility-display"><?php echo $this->options[ $selected ]; ?></strong>
	<a href="#pos-visibility" id="pos-visibility-show" class="hide-if-no-js"
	   style="display: inline;">
       <?php
		// translators: wordpress
		_e( 'Edit' );
		?>
  </a>

	<div id="pos-visibility-select" class="hide-if-js" style="display: none;">
		<?php
		foreach ( $this->options as $value => $label ) {
			$checked = $value == $selected ? 'checked="checked"' : '';
			?>
			<label style="display:block;margin: 5px 0;">
				<input type="radio" name="_pos_visibility"
				       value="<?php echo $value; ?>" <?php echo $checked; ?>> <?php echo $label; ?>
			</label>
		<?php } ?>
		<p>
			<a href="#pos-visibility" id="pos-visibility-save"
			   class="hide-if-no-js button">
               <?php
				// translators: wordpress
				_e( 'OK' );
				?>
                </a>
			<a href="#pos-visibility" id="pos-visibility-cancel"
			   class="hide-if-no-js">
               <?php
				// translators: wordpress
				_e( 'Cancel' );
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

