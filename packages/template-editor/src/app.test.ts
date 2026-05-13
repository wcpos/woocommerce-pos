import { describe, expect, it } from 'vitest';

import { getEditorLayoutStyle } from './app';

describe('template editor layout', () => {
	it('gives the editor row a definite bounded height so side panels scroll internally', () => {
		expect(getEditorLayoutStyle()).toEqual({
			height: 'calc(100vh - 320px)',
			minHeight: 440,
			maxHeight: 720,
		});
	});
});
