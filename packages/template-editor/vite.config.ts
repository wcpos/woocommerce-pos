/// <reference types="vitest" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig(({ mode }) => {
	const isProd = mode === 'production';
	const outDir = isProd
		? path.resolve(__dirname, '../../assets')
		: path.resolve(__dirname, '../../build');

	return {
		plugins: [react(), tailwindcss()],
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
				external: [
					'react',
					'react-dom',
					'react-dom/client',
				],
				output: {
					globals: {
						'react': 'React',
						'react-dom': 'ReactDOM',
						'react-dom/client': 'ReactDOM',
					},
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
