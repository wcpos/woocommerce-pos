import { fixupConfigRules } from '@eslint/compat';
import { defineConfig } from 'eslint/config';
import universeWeb from 'eslint-config-universe/flat/web.js';

/**
 * Shared ESLint flat config for all JS/TS packages in this workspace.
 *
 * Replaces the eslintrc-style `@wcpos/eslint-config-wordpress` shared config,
 * which ESLint 10 can no longer load. Each package runs `eslint src` and picks
 * this file up from the repo root.
 */
export default defineConfig([
	{
		ignores: [
			'**/node_modules/',
			'**/dist/',
			'**/build/',
			'vendor/',
			'vendor_prefixed/',
			'.wiki/',
		],
	},
	// fixupConfigRules restores plugin APIs removed in ESLint 10 (eslint-plugin-import
	// and eslint-plugin-react still target v9) until universe ships v10-ready deps.
	...fixupConfigRules(universeWeb),
	{
		settings: {
			react: {
				version: '18.3',
			},
		},
		rules: {
			// `void somePromise()` statements are our idiom for intentionally
			// un-awaited promises.
			'no-void': ['warn', { allowAsStatement: true }],
			// Prefer function declarations for named components
			'react/function-component-definition': [
				'error',
				{
					namedComponents: 'function-declaration',
					unnamedComponents: 'arrow-function',
				},
			],
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
						order: 'asc',
						caseInsensitive: true,
					},
					pathGroups: [
						{
							pattern: 'react',
							group: 'external',
							position: 'before',
						},
						{
							pattern: '@wcpos/**',
							group: 'external',
							position: 'after',
						},
					],
					pathGroupsExcludedImportTypes: ['react'],
					groups: ['builtin', 'external', ['parent', 'sibling', 'index'], 'type'],
					'newlines-between': 'always',
				},
			],
		},
	},
]);
