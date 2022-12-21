require('@rushstack/eslint-patch/modern-module-resolution');

module.exports = {
	root: true,
	env: {
		'react-native/react-native': true,
		'jest/globals': true,
	},
	extends: [
		'airbnb',
		'plugin:@typescript-eslint/recommended',
		'plugin:prettier/recommended',
		// 'prettier',
	],
	parser: '@typescript-eslint/parser',
	parserOptions: {
		project: './tsconfig.json',
		sourceType: 'module',
	},
	plugins: [
		'@typescript-eslint',
		'jest',
		'react-native',
		'react-hooks',
		// 'prettier'
	],
	rules: {
		'prettier/prettier': [
			'error',
			{
				useTabs: true,
				singleQuote: true,
				trailingComma: 'es5',
				printWidth: 100,
			},
		],
		'react/jsx-filename-extension': [1, { extensions: ['.jsx', '.tsx'] }],
		'react/function-component-definition': 0,
		'import/extensions': ['error', 'never'],
		'spaced-comment': ['error', 'always', { markers: ['/'] }],
		'lines-between-class-members': ['error', 'always', { exceptAfterSingleLine: true }],
		'react/prop-types': 'off',
		'react/destructuring-assignment': 'off',
		'react/jsx-props-no-spreading': 'off',
		'react/static-property-placement': 'off',
		'react/jsx-indent': [2, 'tab'],
		'react/jsx-indent-props': [2, 'tab'],
		'react/jsx-wrap-multilines': [
			'error',
			{
				declaration: 'parens',
				assignment: 'parens',
				return: 'parens',
				arrow: 'parens',
				condition: 'ignore',
				logical: 'ignore',
				prop: 'ignore',
			},
		],
		'react-hooks/rules-of-hooks': 'warn',
		'react-hooks/exhaustive-deps': 'warn',
		// 'prettier/prettier': 'error',
		camelcase: 'off',
		'@typescript-eslint/explicit-module-boundary-types': 'off',
		'no-use-before-define': 'off',
		'@typescript-eslint/no-use-before-define': 'warn',
		'import/prefer-default-export': 'off',
		'react/require-default-props': 0,
		// 'no-underscore-dangle': ['error', { allowAfterThis: true }],
		'no-underscore-dangle': 0,
		'no-nested-ternary': 0,
		// @TODO - fix this when updated https://github.com/benmosher/eslint-plugin-import/issues/1174
		'import/no-extraneous-dependencies': 0,
		'no-restricted-exports': 'warn',
		// @TODO - fix this when updated - eslint has a bug with this rule
		'@typescript-eslint/no-explicit-any': 'off',
	},
	settings: {
		'import/extensions': ['.js', '.jsx', '.ts', '.tsx'],
		'import/parsers': {
			'@typescript-eslint/parser': ['.ts', '.tsx'],
		},
		'import/resolver': {
			node: {
				extensions: ['.js', '.jsx', '.ts', '.tsx'],
			},
		},
		react: {
			version: 'detect',
		},
	},
};
