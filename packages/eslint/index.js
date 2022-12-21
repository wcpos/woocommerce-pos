module.exports = {
	extends: ['eslint-config-universe', 'plugin:react-hooks/recommended'],
	plugins: ['@typescript-eslint', 'jest', 'react-native', 'react-hooks'],
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
		'import/order': [
			'error',
			{
				alphabetize: {
					order: 'asc' /* sort in ascending order. Options: ['ignore', 'asc', 'desc'] */,
					caseInsensitive: true /* ignore case. Options: [true, false] */,
				},
				pathGroups: [
					{
						pattern: 'react+(-native|)',
						group: 'external',
						position: 'before',
					},
					{
						pattern: '@wcpos/**',
						group: 'external',
						position: 'after',
					},
				],
				pathGroupsExcludedImportTypes: ['react', 'react-native'],
				groups: ['builtin', 'external', ['parent', 'sibling', 'index'], 'type'],
				'newlines-between': 'always',
			},
		],
	},
};
