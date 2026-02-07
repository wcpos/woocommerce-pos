/// <reference types="vitest" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import svgr from 'vite-plugin-svgr';
import path from 'path';

export default defineConfig(({ mode }) => {
  const isProd = mode === 'production';
  const outDir = isProd
    ? path.resolve(__dirname, '../../assets')
    : path.resolve(__dirname, '../../build');

  return {
    plugins: [
      react(),
      tailwindcss(),
      svgr({ include: '**/*.svg' }),
    ],
    build: {
      outDir,
      emptyOutDir: false,
      lib: {
        entry: path.resolve(__dirname, 'src/index.tsx'),
        name: 'WCPOSSettings',
        formats: ['iife'],
        fileName: () => 'js/settings.js',
        cssFileName: 'css/settings',
      },
      rollupOptions: {
        external: [
          'react',
          'react-dom',
          'react-dom/client',
          'lodash',
          '@wordpress/api-fetch',
          '@wordpress/url',
        ],
        output: {
          globals: {
            'react': 'React',
            'react-dom': 'ReactDOM',
            'react-dom/client': 'ReactDOM',
            'lodash': 'lodash',
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
      setupFiles: './src/test-setup.ts',
      css: true,
    },
  };
});
