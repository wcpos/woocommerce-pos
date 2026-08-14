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
			'packages/eslint/**',
		],
	},
	// fixupConfigRules restores plugin APIs removed in ESLint 10 (eslint-plugin-import
	// and eslint-plugin-react still target v9) until universe ships v10-ready deps.
	// TODO(#1257): drop the wrapper (and @eslint/compat) once eslint-config-universe
	// supports ESLint 10 natively — removal recipe is in the issue.
	...fixupConfigRules(universeWeb),
	{
		settings: {
			react: {
				version: '18.3',
			},
		},
		rules: {
			// eslint-plugin-node rules, disabled upstream; the plugin is namespaced `n` in flat config.
			'n/handle-callback-err': 'off',
			'n/no-callback-literal': 'off',
			// Keep newly enabled React Compiler diagnostics non-blocking until
			// the affected code is deliberately refactored.
			'react-hooks/static-components': 'warn',
			'react-hooks/use-memo': 'warn',
			'react-hooks/preserve-manual-memoization': 'warn',
			'react-hooks/immutability': 'warn',
			'react-hooks/globals': 'warn',
			'react-hooks/refs': 'warn',
			'react-hooks/set-state-in-effect': 'warn',
			'react-hooks/error-boundaries': 'warn',
			'react-hooks/purity': 'warn',
			'react-hooks/set-state-in-render': 'warn',
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
