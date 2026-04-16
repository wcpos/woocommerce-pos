import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import useSettingsApi from '../../../hooks/use-settings-api';
import Tools from '../index';

vi.mock('../../../hooks/use-settings-api', () => ({
	default: vi.fn(),
}));

const mockUseSettingsApi = vi.mocked(useSettingsApi);

describe('Tools screen', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('renders the tracking consent control', () => {
		mockUseSettingsApi.mockReturnValue({
			data: {
				use_jwt_as_param: false,
				tracking_consent: 'denied',
			},
			mutate: vi.fn(),
		});

		render(<Tools />);

		expect(screen.getByRole('switch', { name: 'Authorize via URL param' })).toBeInTheDocument();
		expect(screen.getByRole('switch', { name: 'Allow anonymous usage data' })).toBeInTheDocument();
	});

	it('enables tracking consent when the toggle is switched on', () => {
		const mutate = vi.fn();
		mockUseSettingsApi.mockReturnValue({
			data: {
				use_jwt_as_param: false,
				tracking_consent: 'denied',
			},
			mutate,
		});

		render(<Tools />);
		fireEvent.click(screen.getByRole('switch', { name: 'Allow anonymous usage data' }));

		expect(mutate).toHaveBeenCalledWith({ tracking_consent: 'allowed' });
	});

	it('disables tracking consent when the toggle is switched off', () => {
		const mutate = vi.fn();
		mockUseSettingsApi.mockReturnValue({
			data: {
				use_jwt_as_param: false,
				tracking_consent: 'allowed',
			},
			mutate,
		});

		render(<Tools />);
		fireEvent.click(screen.getByRole('switch', { name: 'Allow anonymous usage data' }));

		expect(mutate).toHaveBeenCalledWith({ tracking_consent: 'denied' });
	});
});
