/// <reference types="vitest" />
import { defineConfig } from 'vite';
import type { Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import svgr from 'vite-plugin-svgr';
import path from 'path';

/**
 * Vite 8 / Rollup 4 wraps CJS dependencies in a shim that preserves
 * require() calls for external modules. In IIFE format there is no
 * require(), so we replace them with the corresponding globals.
 */
const externalGlobals: Record<string, string> = {
  'react': 'React',
  'react-dom': 'ReactDOM',
  'react-dom/client': 'ReactDOM',
  'lodash': 'lodash',
  '@wordpress/api-fetch': 'wp.apiFetch',
  '@wordpress/url': 'wp.url',
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
    plugins: [
      react(),
      tailwindcss(),
      svgr({ include: '**/*.svg' }),
      fixCjsExternals(),
    ],
    build: {
      outDir,
      emptyOutDir: false,
      target: 'es2022',
      lib: {
        entry: path.resolve(__dirname, 'src/index.tsx'),
        name: 'WCPOSSettings',
        formats: ['iife'],
        fileName: () => 'js/settings.js',
        cssFileName: 'css/settings',
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
      setupFiles: './src/test-setup.ts',
      css: true,
    },
  };
});
