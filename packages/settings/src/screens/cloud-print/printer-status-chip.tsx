import * as React from 'react';

import apiFetch from '@wordpress/api-fetch';

import { Chip, type ChipVariant } from '@wcpos/ui';

import { PROVIDERS } from './providers';
import { t } from '../../translations';

import type {
	CloudPrintSettings,
	CloudPrinter,
	CloudStatus,
} from '../../hooks/use-cloud-print-settings';

const ENDPOINT = 'wcpos/v1/settings/cloud-print?wcpos=1';
const STATUS_REFRESH_MS = 30000;

interface StatusMeta {
	variant: ChipVariant;
	label: string;
}

/**
 * Map a printer's connection status to a Chip variant + localized label.
 *
 * Polling providers (Star/Epson) report `connected | waiting | offline`.
 * PrintNode reports its real upstream state `online | offline | unknown`
 * (or `undefined` before the first status read).
 */
function getStatusMeta(status: CloudStatus | undefined, isPolling: boolean): StatusMeta {
	if (!isPolling) {
		switch (status) {
			case 'online':
				return { variant: 'success', label: t('cloud_print.status_online', 'Online') };
			case 'offline':
				return { variant: 'error', label: t('cloud_print.status_offline', 'Offline') };
			case 'unknown':
			default:
				return { variant: 'info', label: t('cloud_print.status_unknown', 'Unknown') };
		}
	}
	switch (status) {
		case 'connected':
			return { variant: 'success', label: t('cloud_print.status_connected', 'Connected') };
		case 'waiting':
			return {
				variant: 'warning',
				label: t('cloud_print.status_waiting', 'Waiting for printer'),
			};
		case 'offline':
		default:
			return { variant: 'error', label: t('cloud_print.status_offline', 'Offline') };
	}
}

async function fetchPrinterStatus(printerId: string): Promise<CloudStatus | undefined> {
	const settings = (await apiFetch({
		path: ENDPOINT,
		method: 'GET',
	})) as CloudPrintSettings;

	return settings.printers.find((printer) => printer.id === printerId)?.status;
}

export function PrinterStatusChip({ printer }: { printer: CloudPrinter }) {
	const provider = PROVIDERS[printer.provider];
	const [status, setStatus] = React.useState<CloudStatus | undefined>(printer.status);

	React.useEffect(() => {
		let cancelled = false;
		setStatus(printer.status);

		const refresh = async () => {
			try {
				const next = await fetchPrinterStatus(printer.id);
				if (!cancelled) {
					setStatus(next);
				}
			} catch {
				// Keep displaying the last known status if a background refresh fails.
			}
		};

		const intervalId = window.setInterval(() => {
			void refresh();
		}, STATUS_REFRESH_MS);

		return () => {
			cancelled = true;
			window.clearInterval(intervalId);
		};
	}, [printer.id, printer.status]);

	const meta = getStatusMeta(status, provider.isPolling);

	return (
		<Chip
			variant={meta.variant}
			shape="pill"
			size="sm"
			data-testid={`printer-card-status-${printer.id}`}
		>
			{meta.label}
		</Chip>
	);
}
