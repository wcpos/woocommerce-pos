import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

export type CloudProvider = 'star-cloudprnt' | 'epson-sdp' | 'printnode';
// Star/Epson polling providers report `waiting | connected | offline`; PrintNode
// reports its real upstream state `online | offline | unknown`.
export type CloudStatus = 'waiting' | 'connected' | 'offline' | 'online' | 'unknown';

// PrintNode print format. RAW (ESC/POS) is only meaningful for thermal templates.
export type PrintnodeFormat = 'pdf' | 'raw';

export interface CloudPrinter {
	id: string;
	name: string;
	provider: CloudProvider;
	store_id: number;
	// read-only (GET only):
	status?: CloudStatus;
	last_seen?: number | null;
	// write-only (POST only; never returned):
	// Poll token is server-generated, stored hashed, and returned once on save —
	// never read back via GET, so it is not part of the read shape.
	regenerate_token?: boolean;
	printnode_api_key?: string; // printnode only
	printnode_printer_id?: number; // printnode only
	// read+write (printnode only): job format, defaults to 'pdf' server-side.
	printnode_format?: PrintnodeFormat;
}

export interface CloudAssignment {
	printer_id: string;
	scope: 'every' | 'pos' | 'online';
	template_id: string;
}

export interface CloudPrintSettings {
	printers: CloudPrinter[];
	assignments: CloudAssignment[];
}

export interface CloudPrintSettingsResponse extends CloudPrintSettings {
	generated?: Record<string, string>;
}

const ENDPOINT = 'wcpos/v1/settings/cloud-print?wcpos=1';
const STATUS_REFRESH_MS = 30000;

/**
 * Read/write the cloud-print settings. The REST endpoint replaces the whole
 * object, so `save` sends the full settings each time.
 */
export function useCloudPrintSettings() {
	const queryClient = useQueryClient();

	const { data } = useSuspenseQuery<CloudPrintSettings>({
		queryKey: ['cloud-print'],
		queryFn: () => apiFetch({ path: ENDPOINT, method: 'GET' }) as Promise<CloudPrintSettings>,
		refetchInterval: STATUS_REFRESH_MS,
		refetchIntervalInBackground: true,
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
