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
				name: 'WCPOSTemplateGallery',
				formats: ['iife'],
				fileName: () => 'js/template-gallery.js',
				cssFileName: 'css/template-gallery',
			},
			rollupOptions: {
				external: [
					'react',
					'react-dom',
					'react-dom/client',
					'@wordpress/api-fetch',
					'@wordpress/url',
				],
				output: {
					globals: {
						'react': 'React',
						'react-dom': 'ReactDOM',
						'react-dom/client': 'ReactDOM',
						'@wordpress/api-fetch': 'wp.apiFetch',
						'@wordpress/url': 'wp.url',
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
