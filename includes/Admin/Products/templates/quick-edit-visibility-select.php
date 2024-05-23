<?php
/**
 * Admin View: Quick Edit Product.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<fieldset class="inline-edit-col-left">
	<div class="inline-edit-col">
		<div class="inline-edit-group">
			<label class="inline-edit-status alignleft">
				<span class="title"><?php _e( 'POS visibility', 'woocommerce-pos' ); ?></span>
				<select name="_pos_visibility">
					<?php
					foreach ( $options as $name => $label ) {
						echo '<option value="' . $name . '">' . $label . '</option>';
					}
					?>
				</select>
			</label>
		</div>
	</div>
	<script>
		document.addEventListener('DOMContentLoaded', function() {
			// we create a copy of the WP inline edit post function
			const wp_inline_edit = window.inlineEditPost.edit;

			// and then we overwrite the function with our own code
			window.inlineEditPost.edit = function (id) {
				// "call" the original WP edit function
				// we don't want to leave WordPress hanging
				wp_inline_edit.apply(this, arguments);

				// now we take care of our business

				// get the post ID
				let post_id = 0;
				if (typeof id === 'object') {
					post_id = parseInt(this.getId(id), 10);
				}

				if (post_id > 0) {
					// define the edit row
					const edit_row = document.getElementById('edit-' + post_id);

					// get the data
					let val = '';
					let elem = document.getElementById('woocommerce_pos_inline_' + post_id);
					if (elem) {
						val = elem.dataset.visibility;
					}

					// populate the data
					const select = edit_row.querySelector('select[name="_pos_visibility"]');
					if (select) {
						select.value = val;
					}
				}
			};
		});
	</script>
</fieldset>
