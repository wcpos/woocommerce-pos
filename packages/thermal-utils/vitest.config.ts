/// <reference types="vitest" />
import { defineConfig } from 'vitest/config';

// The renderers use DOMParser / document, so the suite needs a DOM.
export default defineConfig({
	test: {
		environment: 'jsdom',
	},
});
