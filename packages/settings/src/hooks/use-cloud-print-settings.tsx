import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

export interface CloudPrinter {
	id: string;
	name: string;
	protocol: 'star-cloudprnt' | 'epson-sdp';
	store_id: number;
	// Poll token is server-generated, stored hashed, and returned once on save —
	// never read back via GET, so it is not part of the printer shape.
}

export interface CloudAssignment {
	printer_id: string;
	scope: 'every' | 'pos' | 'online';
	format: string;
}

export interface CloudPrintSettings {
	printers: CloudPrinter[];
	assignments: CloudAssignment[];
}

export interface CloudPrintSettingsResponse extends CloudPrintSettings {
	generated?: Record<string, string>;
}

const ENDPOINT = 'wcpos/v1/settings/cloud-print?wcpos=1';

/**
 * Read/write the cloud-print settings. The REST endpoint replaces the whole
 * object, so `save` sends the full settings each time.
 */
export function useCloudPrintSettings() {
	const queryClient = useQueryClient();

	const { data } = useSuspenseQuery<CloudPrintSettings>({
		queryKey: ['cloud-print'],
		queryFn: () => apiFetch({ path: ENDPOINT, method: 'GET' }) as Promise<CloudPrintSettings>,
	});

	const mutation = useMutation({
		mutationFn: (next: CloudPrintSettings) =>
			apiFetch({ path: ENDPOINT, method: 'POST', data: next }) as Promise<CloudPrintSettingsResponse>,
		onSuccess: (saved) =>
			queryClient.setQueryData(['cloud-print'], {
				printers: saved.printers,
				assignments: saved.assignments,
			}),
	});

	// `save` resolves with the server response, whose `generated` map carries any
	// one-time poll tokens for newly registered printers.
	return { settings: data, save: mutation.mutateAsync };
}
