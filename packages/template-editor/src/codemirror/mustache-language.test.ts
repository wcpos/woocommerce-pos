import { describe, it, expect } from 'vitest';
import { mustacheLanguage } from './mustache-language';

describe('mustacheLanguage', () => {
	it('exports a StreamLanguage instance', () => {
		expect(mustacheLanguage).toBeDefined();
		expect(mustacheLanguage.name).toBe('mustache');
	});
});
