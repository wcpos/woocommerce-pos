import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

export type CloudProvider = 'star-cloudprnt' | 'epson-sdp' | 'printnode' | 'star-online';
// Star/Epson polling providers report `waiting | connected | offline`, plus
// `blocked` when the WCPOS Cloud Print relay reports the site is refusing its
// requests; PrintNode reports its real upstream state `online | offline | unknown`.
export type CloudStatus = 'waiting' | 'connected' | 'offline' | 'online' | 'unknown' | 'blocked';

// Site-level WCPOS Cloud Print relay registration. `printer_base_url` is only
// present once the site has registered (e.g. https://cloudprint.wcpos.com/p/<site_key>).
// `available: false` means the site opted out server-side (filter) and the
// settings app must not attempt self-registration.
export interface CloudPrintRelay {
	enabled: boolean;
	available?: boolean;
	printer_base_url?: string;
}

// PrintNode print format. RAW (ESC/POS) is only meaningful for thermal templates.
export type PrintnodeFormat = 'pdf' | 'raw';

export interface CloudPrinter {
	id: string;
	name: string;
	provider: CloudProvider;
	store_id: number;
	// read-only (GET only):
	status?: CloudStatus;
	// Relay block signal (e.g. "cloudflare-challenge", "http-403"); only set
	// when status is 'blocked'.
	status_detail?: string | null;
	last_seen?: number | null;
	// write-only (POST only; never returned):
	// Poll token is server-generated, stored hashed, and returned once on save —
	// never read back via GET, so it is not part of the read shape.
	regenerate_token?: boolean;
	printnode_api_key?: string; // printnode only
	printnode_printer_id?: number; // printnode only
	// read+write (printnode only): job format, defaults to 'pdf' server-side.
	printnode_format?: PrintnodeFormat;
	star_api_key?: string; // star-online only
	star_cloudprnt_url?: string; // star-online only
	star_device_id?: string; // star-online only
	star_client_type?: string; // star-online only
	// read-only POS client encoding contract (star-cloudprnt only).
	columns?: 32 | 42 | 48;
	language?: 'esc-pos' | 'star-prnt' | 'star-line';
	autoCut?: boolean;
	fullReceiptRaster?: boolean;
}

export interface CloudAssignment {
	printer_id: string;
	store_id?: number;
	scope: 'every' | 'pos' | 'online';
	template_id: string;
	copies?: number;
	// When to print: as soon as the order exists, or once it's paid.
	// Server normalizes missing values to 'paid' on read.
	trigger?: 'created' | 'paid';
}

export interface CloudPrintSettings {
	printers: CloudPrinter[];
	assignments: CloudAssignment[];
	// Server-owned; written only via the relay register/disable endpoints.
	relay?: CloudPrintRelay;
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
			apiFetch({
				path: ENDPOINT,
				method: 'POST',
				data: next,
			}) as Promise<CloudPrintSettingsResponse>,
		onSuccess: (saved) =>
			queryClient.setQueryData(
				['cloud-print'],
				(prev: CloudPrintSettings | undefined): CloudPrintSettings => ({
					printers: saved.printers,
					assignments: saved.assignments,
					// The save endpoint ignores client relay data; carry the last
					// known registration forward if the response omits it.
					relay: saved.relay ?? prev?.relay,
				})
			),
	});

	// `save` resolves with the server response, whose `generated` map carries any
	// one-time poll tokens for newly registered printers.
	return { settings: data, save: mutation.mutateAsync };
}

const RELAY_REGISTER_ENDPOINT = 'wcpos/v1/print-jobs/relay/register?wcpos=1';

/**
 * Self-register with the WCPOS Cloud Print relay. The endpoint returns the
 * new relay state, which is folded into the cached cloud-print settings.
 * There is no disable counterpart — the relay is on by default and sites
 * opt out server-side via the `woocommerce_pos_cloud_print_relay_enabled`
 * filter.
 */
export function useCloudPrintRelay() {
	const queryClient = useQueryClient();

	const applyRelay = (relay: CloudPrintRelay) =>
		queryClient.setQueryData(
			['cloud-print'],
			(prev: CloudPrintSettings | undefined): CloudPrintSettings | undefined =>
				prev ? { ...prev, relay } : prev
		);

	const register = useMutation({
		mutationFn: () =>
			apiFetch({ path: RELAY_REGISTER_ENDPOINT, method: 'POST' }) as Promise<CloudPrintRelay>,
		onSuccess: applyRelay,
	});

	return { register };
}
