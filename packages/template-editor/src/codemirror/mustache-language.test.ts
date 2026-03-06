import { describe, it, expect } from 'vitest';
import { mustacheOverlay } from './mustache-language';

describe('mustacheOverlay', () => {
	it('exports a ViewPlugin instance', () => {
		expect(mustacheOverlay).toBeDefined();
	});
});
