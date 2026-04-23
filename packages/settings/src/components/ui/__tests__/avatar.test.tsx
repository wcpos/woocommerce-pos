import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import { Avatar, getInitials } from '@wcpos/ui';

describe('getInitials', () => {
	it('returns two letters for multi-word names', () => {
		expect(getInitials('Ada Lovelace')).toBe('AL');
		expect(getInitials('mary jane watson')).toBe('MW');
	});

	it('returns one letter for single-word names', () => {
		expect(getInitials('cher')).toBe('C');
	});

	it('handles blank names with a fallback glyph', () => {
		expect(getInitials('')).toBe('?');
		expect(getInitials('   ')).toBe('?');
	});
});

describe('Avatar', () => {
	it('renders the image when src is provided', () => {
		render(<Avatar name="Ada Lovelace" src="https://example.test/a.png" />);
		const img = screen.getByAltText('Ada Lovelace') as HTMLImageElement;
		expect(img.tagName).toBe('IMG');
		expect(img.src).toBe('https://example.test/a.png');
	});

	it('falls back to initials when no src is provided', () => {
		render(<Avatar name="Ada Lovelace" />);
		expect(screen.getByText('AL')).toBeInTheDocument();
		expect(screen.getByLabelText('Ada Lovelace')).toBeInTheDocument();
	});

	it('falls back to initials if the image fails to load', () => {
		render(<Avatar name="Ada Lovelace" src="https://example.test/broken.png" />);
		const img = screen.getByAltText('Ada Lovelace');
		fireEvent.error(img);
		expect(screen.getByText('AL')).toBeInTheDocument();
	});

	it('shows an active-now status dot when status="active"', () => {
		render(<Avatar name="Ada" status="active" statusLabel="Active now" />);
		expect(screen.getByLabelText('Active now')).toBeInTheDocument();
	});
});
