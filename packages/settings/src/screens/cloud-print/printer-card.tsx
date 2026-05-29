import * as React from 'react';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Card,
	Chip,
	DropdownMenu,
	DropdownMenuItem,
	Tooltip,
	useSnackbar,
	type ChipVariant,
} from '@wcpos/ui';

import { PROVIDERS } from './providers';
import ConfirmDialog from '../sessions/confirm-dialog';
import { i18n, t } from '../../translations';

import type { CloudPrinter, CloudStatus } from '../../hooks/use-cloud-print-settings';

export interface PrinterCardProps {
	printer: CloudPrinter;
	onRename: (id: string, newName: string) => void;
	onRemove: (printer: CloudPrinter) => void;
	onOpenSetup: (printer: CloudPrinter) => void;
}

interface StatusMeta {
	variant: ChipVariant;
	label: string;
}

/**
 * Map a printer's connection status to a Chip variant + localized label.
 */
function getStatusMeta(status: CloudStatus | undefined, isPolling: boolean): StatusMeta {
	// Non-polling providers (PrintNode) push jobs and never check in, so the
	// poll-derived status is meaningless — show a neutral "Linked" chip instead
	// of a misleading "Waiting for printer". A real PrintNode status lands in P4.
	if (!isPolling) {
		return { variant: 'info', label: t('cloud_print.status_linked', 'Linked') };
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

const RELATIVE_UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
	['year', 60 * 60 * 24 * 365],
	['month', 60 * 60 * 24 * 30],
	['day', 60 * 60 * 24],
	['hour', 60 * 60],
	['minute', 60],
	['second', 1],
];

/**
 * Localized relative time (e.g. "2 minutes ago") for a unix-seconds timestamp,
 * picking the largest sensible unit. No hard-coded English.
 */
export function formatRelative(unixSeconds: number, locale?: string): string {
	const diffSeconds = Math.round(unixSeconds - Date.now() / 1000);
	// Fall back to the runtime default if the locale is missing or not a valid
	// BCP 47 tag (Intl throws on bad input).
	// WP locales use underscores (e.g. `en_US`); BCP 47 expects hyphens.
	const normalized = locale ? locale.replace(/_/g, '-') : undefined;
	let rtf: Intl.RelativeTimeFormat;
	try {
		rtf = new Intl.RelativeTimeFormat(normalized, { numeric: 'auto' });
	} catch {
		rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
	}
	const abs = Math.abs(diffSeconds);
	for (const [unit, secondsInUnit] of RELATIVE_UNITS) {
		if (abs >= secondsInUnit || unit === 'second') {
			const value = Math.round(diffSeconds / secondsInUnit);
			return rtf.format(value, unit);
		}
	}
	return rtf.format(0, 'second');
}

/**
 * A single cloud printer rendered as a card: provider badge, name (inline
 * editable), connection status, last check-in, immutable printer id, a
 * test-print action, and a kebab menu for setup/removal.
 *
 * "Waiting jobs" count is intentionally omitted in P2 to avoid an extra API
 * call; it can be added in a later phase.
 */
export function PrinterCard({ printer, onRename, onRemove, onOpenSetup }: PrinterCardProps) {
	const { addSnackbar } = useSnackbar();
	const provider = PROVIDERS[printer.provider];
	const status = getStatusMeta(printer.status, provider.isPolling);

	const [name, setName] = React.useState(printer.name);
	const [testing, setTesting] = React.useState(false);
	const [confirmOpen, setConfirmOpen] = React.useState(false);

	// Tracks the last name we committed via onRename so a single Enter press
	// (which blurs *and* calls commitName) can't fire two renames/saves.
	const lastCommittedName = React.useRef(printer.name);

	// Keep local name state in sync when the printer prop changes.
	React.useEffect(() => {
		setName(printer.name);
		lastCommittedName.current = printer.name;
	}, [printer.name]);

	const commitName = React.useCallback(() => {
		const trimmed = name.trim();
		if (!trimmed) {
			// Empty → revert to the existing name and don't call onRename.
			setName(printer.name);
			return;
		}
		if (trimmed !== printer.name && trimmed !== lastCommittedName.current) {
			lastCommittedName.current = trimmed;
			onRename(printer.id, trimmed);
		}
	}, [name, onRename, printer.id, printer.name]);

	const handleTestPrint = React.useCallback(async () => {
		setTesting(true);
		try {
			await apiFetch({
				path: 'wcpos/v1/print-jobs/test?wcpos=1',
				method: 'POST',
				data: { printer_id: printer.id },
			});
			addSnackbar({
				message: t('cloud_print.test_sent', 'Sent a test print to {name}.', { name: printer.name }),
				status: 'success',
			});
		} catch (err) {
			const status400 =
				typeof err === 'object' &&
				err !== null &&
				'data' in err &&
				typeof (err as { data?: { status?: number } }).data === 'object' &&
				(err as { data?: { status?: number } }).data?.status === 400;
			if (status400) {
				addSnackbar({
					message: t(
						'cloud_print.test_unavailable',
						"Test print isn't available for this printer yet."
					),
					status: 'info',
				});
			} else {
				const message =
					typeof err === 'object' &&
					err !== null &&
					'message' in err &&
					typeof (err as { message?: string }).message === 'string'
						? (err as { message: string }).message
						: t('cloud_print.test_failed', 'Test print failed.');
				addSnackbar({ message, status: 'error' });
			}
		} finally {
			setTesting(false);
		}
	}, [addSnackbar, printer.id, printer.name]);

	const lastSeen =
		printer.last_seen == null
			? t('cloud_print.never', 'never')
			: formatRelative(printer.last_seen, i18n.language || undefined);

	return (
		<Card data-testid={`printer-card-${printer.id}`}>
			<Card.Body>
				<div className="wcpos:flex wcpos:items-start wcpos:gap-3">
					<span
						className={
							provider.badge.className +
							' wcpos:flex wcpos:size-8 wcpos:shrink-0 wcpos:items-center wcpos:justify-center wcpos:rounded wcpos:text-sm wcpos:font-semibold'
						}
						aria-hidden="true"
					>
						{provider.badge.mark}
					</span>

					<div className="wcpos:min-w-0 wcpos:flex-1">
						<input
							type="text"
							data-testid={`printer-card-name-${printer.id}`}
							value={name}
							onChange={(event) => setName(event.target.value)}
							onBlur={commitName}
							onKeyDown={(event) => {
								if (event.key === 'Enter') {
									event.preventDefault();
									(event.target as HTMLInputElement).blur();
									commitName();
								}
							}}
							aria-label={t('cloud_print.printer_name', 'Printer name')}
							className="wcpos:w-full wcpos:rounded wcpos:border wcpos:border-transparent wcpos:bg-transparent wcpos:px-1 wcpos:py-0.5 wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900 wcpos:hover:border-gray-300 wcpos:focus:border-wp-admin-theme-color wcpos:focus:bg-white wcpos:focus:outline-none"
						/>
						<div className="wcpos:px-1 wcpos:text-xs wcpos:text-gray-500">{provider.label}</div>
					</div>

					<div className="wcpos:flex wcpos:items-center wcpos:gap-1">
						<Chip
							variant={status.variant}
							shape="pill"
							size="sm"
							data-testid={`printer-card-status-${printer.id}`}
						>
							{status.label}
						</Chip>
						<DropdownMenu
							align="end"
							label={t('cloud_print.printer_menu', 'Printer actions')}
							trigger={
								<Button
									variant="ghost"
									data-testid={`printer-card-menu-${printer.id}`}
									aria-label={t('cloud_print.printer_menu', 'Printer actions')}
								>
									⋮
								</Button>
							}
						>
							<DropdownMenuItem onSelect={() => onOpenSetup(printer)}>
								{t('cloud_print.menu_setup', 'Setup & token')}
							</DropdownMenuItem>
							<DropdownMenuItem onSelect={() => setConfirmOpen(true)}>
								{t('cloud_print.menu_remove', 'Remove printer')}
							</DropdownMenuItem>
						</DropdownMenu>
					</div>
				</div>

				<dl className="wcpos:mt-3 wcpos:grid wcpos:grid-cols-[auto_1fr] wcpos:gap-x-3 wcpos:gap-y-1 wcpos:text-xs">
					<dt className="wcpos:text-gray-500">
						{t('cloud_print.last_check_in', 'Last check-in')}
					</dt>
					<dd
						className="wcpos:text-gray-900"
						data-testid={`printer-card-last-seen-${printer.id}`}
					>
						{lastSeen}
					</dd>

					<dt className="wcpos:text-gray-500">{t('cloud_print.printer_id', 'Printer ID')}</dt>
					<dd className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:text-gray-900">
						<code className="wcpos:rounded wcpos:bg-gray-100 wcpos:px-1 wcpos:py-0.5">
							{printer.id}
						</code>
						<Tooltip
							text={t(
								'cloud_print.printer_id_tooltip',
								"The printer's address in its setup URL. Created automatically and can't be changed — to rename, edit the name above; to change the ID, remove and re-add the printer."
							)}
						>
							<span
								data-testid={`printer-card-id-info-${printer.id}`}
								className="wcpos:cursor-help wcpos:text-gray-400"
								aria-label={t('cloud_print.printer_id', 'Printer ID')}
							>
								ⓘ
							</span>
						</Tooltip>
					</dd>
				</dl>
			</Card.Body>

			<Card.Footer className="wcpos:flex wcpos:justify-end">
				<Button
					variant="outline"
					loading={testing}
					disabled={testing}
					onClick={handleTestPrint}
					data-testid={`printer-card-test-${printer.id}`}
				>
					{t('cloud_print.test_print', 'Test print')}
				</Button>
			</Card.Footer>

			<ConfirmDialog
				open={confirmOpen}
				title={t('cloud_print.remove_title', 'Remove printer?')}
				description={t(
					'cloud_print.remove_body',
					'This printer will stop receiving jobs. You can add it again later.'
				)}
				confirmLabel={t('common.remove', 'Remove')}
				cancelLabel={t('common.cancel', 'Cancel')}
				onConfirm={() => {
					setConfirmOpen(false);
					onRemove(printer);
				}}
				onClose={() => setConfirmOpen(false)}
			/>
		</Card>
	);
}

export default PrinterCard;
