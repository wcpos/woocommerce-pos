module.exports = {
  purge: [],
  darkMode: false, // or 'media' or 'class'
  theme: {
    extend: {
      colors: {
        'wp-admin-theme-color': 'var(--wp-admin-theme-color)',
        'wp-admin-theme-color-darker-10': 'var(--wp-admin-theme-color-darker-10)',
        'wp-admin-theme-color-darker-20': 'var(--wp-admin-theme-color-darker-20)',
      }
    },
  },
  variants: {},
  plugins: [],
  corePlugins: {
    // preflight: false
  }
}