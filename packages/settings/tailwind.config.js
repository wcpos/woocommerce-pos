/** @type {import('tailwindcss').Config} */
module.exports = {
	prefix: 'wcpos-',
	content: ['src/**/*.tsx'],
	darkMode: 'media',
	theme: {
		extend: {
			colors: {
				'wp-admin-theme-color': 'var(--wp-admin-theme-color, #007cba)',
				'wp-admin-theme-color-darker-10': 'var(--wp-admin-theme-color-darker-10, #006ba1)',
				'wp-admin-theme-color-darker-20': 'var(--wp-admin-theme-color-darker-20, #005a87)',
				'wp-admin-theme-color-lightest': '#e5f1f8',
				'wp-admin-theme-black': '#1d2327',
			},
		},
	},
	variants: {},
	plugins: [],
	corePlugins: {
		preflight: false,
	},
	// important: '#woocommerce-pos-settings'
};
