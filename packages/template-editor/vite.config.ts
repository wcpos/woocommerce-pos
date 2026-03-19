/// <reference types="vitest" />
import { defineConfig } from 'vite';
import type { Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

/**
 * Vite 8 / Rollup 4 wraps CJS dependencies in a shim that preserves
 * require() calls for external modules. In IIFE format there is no
 * require(), so we replace them with the corresponding globals.
 */
/**
 * Use globalThis prefix to avoid `var React = React` self-reference
 * when the CJS shim declares a local variable with the same name.
 */
const externalGlobals: Record<string, string> = {
	'react': 'globalThis.React',
	'react-dom': 'globalThis.ReactDOM',
	'react-dom/client': 'globalThis.ReactDOM',
};

function fixCjsExternals(): Plugin {
	return {
		name: 'fix-cjs-externals',
		renderChunk(code) {
			let result = code;
			for (const [mod, global] of Object.entries(externalGlobals)) {
				// Match require(`mod`), require('mod'), require("mod")
				const escaped = mod.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&');
				const pattern = new RegExp(
					`require\\s*\\(\\s*[\`'"]${escaped}[\`'"]\\s*\\)`,
					'g',
				);
				result = result.replace(pattern, global);
			}
			return result !== code ? result : null;
		},
	};
}

export default defineConfig(({ mode }) => {
	const isProd = mode === 'production';
	const outDir = isProd
		? path.resolve(__dirname, '../../assets')
		: path.resolve(__dirname, '../../build');

	return {
		plugins: [react(), tailwindcss(), fixCjsExternals()],
		build: {
			outDir,
			emptyOutDir: false,
			target: 'es2022',
			lib: {
				entry: path.resolve(__dirname, 'src/index.tsx'),
				name: 'WCPOSTemplateEditor',
				formats: ['iife'],
				fileName: () => 'js/template-editor.js',
				cssFileName: 'css/template-editor',
			},
			rollupOptions: {
				external: Object.keys(externalGlobals),
				output: {
					globals: externalGlobals,
				},
			},
		},
		resolve: {
			alias: {
				'@': path.resolve(__dirname, 'src'),
			},
		},
		define: {
			'process.env.NODE_ENV': JSON.stringify(mode),
		},
		test: {
			globals: true,
			environment: 'jsdom',
			css: true,
		},
	};
});
