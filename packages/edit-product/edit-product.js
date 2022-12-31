/**
 * Handles the POS visibility settings on the Product Edit page
 */
(function ($) {
	const pagenow = window.pagenow;

	// admin list product page
	function quick_edit() {
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
				const edit_row = $('#edit-' + post_id);

				// get the data
				const val = $('#woocommerce_pos_inline_' + post_id).data('visibility');

				// populate the data
				$('select[name="_pos_visibility"]', edit_row).val(val);
			}
		};
	}

	// admin single product page
	function meta_box() {
		let display = $('#pos-visibility-display'),
			show = $('#pos-visibility-show'),
			select = $('#pos-visibility-select'),
			cancel = $('#pos-visibility-cancel'),
			save = $('#pos-visibility-save'),
			current = $('input[name="_pos_visibility"]:checked');

		function toggleSelect() {
			select.slideToggle('fast');
			show.toggle();
		}

		function updateDisplay() {
			const val = current.parent('label').text();
			display.text(val);
		}

		show.click(function (e) {
			e.preventDefault();
			toggleSelect();
		});

		cancel.click(function (e) {
			e.preventDefault();
			current.prop('checked', true);
			updateDisplay();
			toggleSelect();
		});

		save.click(function (e) {
			e.preventDefault();
			current = $('input[name="_pos_visibility"]:checked');
			updateDisplay();
			toggleSelect();
		});
	}

	// init
	if (pagenow && pagenow === 'edit-product') {
		quick_edit();
	}
	if (pagenow && pagenow === 'product') {
		meta_box();
	}
})(window.jQuery);
