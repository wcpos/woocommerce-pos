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

type StatusSubscriber = (settings: CloudPrintSettings) => void;

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

async function fetchCloudPrintSettings(): Promise<CloudPrintSettings> {
	return (await apiFetch({
		path: ENDPOINT,
		method: 'GET',
	})) as CloudPrintSettings;
}

const statusSubscribers = new Set<StatusSubscriber>();
let statusRefreshIntervalId: number | undefined;
let statusRefreshInFlight = false;

async function refreshPrinterStatuses() {
	if (statusRefreshInFlight) {
		return;
	}

	statusRefreshInFlight = true;
	try {
		const settings = await fetchCloudPrintSettings();
		statusSubscribers.forEach((subscriber) => subscriber(settings));
	} catch {
		// Keep displaying the last known status if a background refresh fails.
	} finally {
		statusRefreshInFlight = false;
	}
}

function subscribeToPrinterStatusUpdates(subscriber: StatusSubscriber) {
	statusSubscribers.add(subscriber);

	if (statusRefreshIntervalId === undefined) {
		statusRefreshIntervalId = window.setInterval(() => {
			void refreshPrinterStatuses();
		}, STATUS_REFRESH_MS);
	}

	return () => {
		statusSubscribers.delete(subscriber);

		if (statusSubscribers.size === 0 && statusRefreshIntervalId !== undefined) {
			window.clearInterval(statusRefreshIntervalId);
			statusRefreshIntervalId = undefined;
		}
	};
}

export function PrinterStatusChip({ printer }: { printer: CloudPrinter }) {
	const provider = PROVIDERS[printer.provider];
	const [status, setStatus] = React.useState<CloudStatus | undefined>(printer.status);

	React.useEffect(() => {
		setStatus(printer.status);

		return subscribeToPrinterStatusUpdates((settings) => {
			setStatus(
				settings.printers.find((nextPrinter) => nextPrinter.id === printer.id)?.status
			);
		});
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
