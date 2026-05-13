import { describe, expect, it } from 'vitest';

import en from './locales/en/wp-admin-template-editor.json';

describe('template editor locale interpolation placeholders', () => {
	it('uses ICU-style placeholders for field search and editor status strings', () => {
		expect(en['editor.no_field_matches']).toBe('No fields match "{query}".');
		expect(en['editor.status.line_col']).toBe('Ln {line}, Col {col}');
		expect(en['editor.status.lines']).toBe('{count} lines');
	});

	it('does not describe the hidden textarea sync as autosave', () => {
		expect(en['editor.status.saved']).toBe('Synced to form');
	});
});
