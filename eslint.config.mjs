import { defineConfig } from 'eslint/config';
import universeWeb from 'eslint-config-universe/flat/web.js';

/**
 * Shared flat config for all workspace packages (settings, analytics, consent,
 * template-editor, template-gallery). Each package runs `eslint src` and ESLint
 * resolves this file by walking up from the package directory.
 *
 * Rule overrides ported from @wcpos/eslint-config-wordpress (eslintrc-style,
 * incompatible with ESLint >= 10).
 *
 * ESLint is pinned to 9.x at the workspace root: eslint-config-universe's
 * plugin stack (eslint-plugin-import, eslint-plugin-react) still uses APIs
 * removed in ESLint 10 and crashes at lint time under 10.x.
 */
export default defineConfig([
	{
		ignores: ['**/build/**', '**/dist/**', 'vendor/**', 'packages/eslint/**'],
	},
	universeWeb,
	{
		settings: {
			react: {
				// eslint-plugin-react's version auto-detect uses context.getFilename,
				// removed in ESLint 10; pin the version instead of detecting
				version: '18.3',
			},
		},
		rules: {
			// eslint-plugin-node rules, disabled upstream; the plugin is namespaced `n` in flat config
			'n/handle-callback-err': 'off',
			'n/no-callback-literal': 'off',
			// React Compiler diagnostics added in eslint-plugin-react-hooks v7 recommended.
			// The previous lint gate (react-hooks v5) only enforced rules-of-hooks, so keep
			// these as warnings until the flagged code is refactored deliberately.
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
