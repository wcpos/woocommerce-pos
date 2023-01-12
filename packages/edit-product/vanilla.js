(function () {
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
				const edit_row = document.getElementById('edit-' + post_id);

				// get the data
				const val = document.getElementById('woocommerce_pos_inline_' + post_id).dataset.visibility;

				// populate the data
				const select = edit_row.querySelector('select[name="_pos_visibility"]');
				select.value = val;
			}
		};
	}

	// admin single product page
	function meta_box() {
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
	}

	// init
	if (pagenow && pagenow === 'edit-product') {
		quick_edit();
	}
	if (pagenow && pagenow === 'product') {
		meta_box();
	}
})();
