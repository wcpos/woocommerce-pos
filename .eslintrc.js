module.exports = {
  root: true,
  "parser": "@typescript-eslint/parser",
  "parserOptions": {
		"project": "./tsconfig.json",
		"sourceType": "module"
  },
  "plugins": [
    "@typescript-eslint",
    "react-hooks",
		"jest",
		"prettier"
  ],
  "extends": [
    "plugin:react/recommended",
    "plugin:@typescript-eslint/recommended",
    'plugin:prettier/recommended',
		'prettier',
  ],
  "rules": {
    "react-hooks/rules-of-hooks": "error",
    "react-hooks/exhaustive-deps": "warn",
    "react/prop-types": "off"
  },
  "settings": {
    'import/extensions': ['.js', '.jsx', '.ts', '.tsx'],
		'import/parsers': {
			'@typescript-eslint/parser': ['.ts', '.tsx'],
		},
		'import/resolver': {
			node: {
				extensions: ['.js', '.jsx', '.ts', '.tsx'],
			},
		},
    "react": {
      "pragma": "React",
      "version": "detect"
    }
  }
}