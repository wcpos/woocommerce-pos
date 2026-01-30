import * as React from 'react';

import { renderHook } from '@testing-library/react';

import { useSnackbar } from '../use-snackbar';
import { SnackbarProvider } from '../provider';

// Mock the SnackbarList to avoid pulling in deeper dependencies
jest.mock('../snackbar-list', () => ({
	SnackbarList: () => null,
}));

describe('useSnackbar', () => {
	it('returns context with addSnackbar when used inside SnackbarProvider', () => {
		const wrapper = ({ children }: { children: React.ReactNode }) => (
			<SnackbarProvider>{children}</SnackbarProvider>
		);

		const { result } = renderHook(() => useSnackbar(), { wrapper });
		expect(result.current).toHaveProperty('addSnackbar');
		expect(typeof result.current.addSnackbar).toBe('function');
	});
});
