import { beforeEach, describe, expect, it, vi } from 'vitest';

const setQueryDataMock = vi.fn();
const mutateAsyncMock = vi.fn();
const useSuspenseQueryMock = vi.fn();
const useMutationMock = vi.fn();

vi.mock('@tanstack/react-query', () => ({
	useQueryClient: () => ({ setQueryData: setQueryDataMock }),
	useSuspenseQuery: (opts: unknown) => useSuspenseQueryMock(opts),
	useMutation: (opts: unknown) => useMutationMock(opts),
}));

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn() }));

import { useCloudPrintSettings } from './use-cloud-print-settings';

describe('useCloudPrintSettings', () => {
	beforeEach(() => {
		setQueryDataMock.mockReset();
		mutateAsyncMock.mockReset();
		useSuspenseQueryMock.mockReset();
		useMutationMock.mockReset();
		useSuspenseQueryMock.mockReturnValue({
			data: { printers: [], assignments: [] },
		});
		useMutationMock.mockReturnValue({ mutateAsync: mutateAsyncMock });
	});

	it('does not refresh the whole cloud-print settings tree on an interval', () => {
		useCloudPrintSettings();

		const [options] = useSuspenseQueryMock.mock.calls[0];
		expect(options).not.toHaveProperty('refetchInterval');
	});
});
