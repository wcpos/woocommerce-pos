import * as React from 'react';

import { Button, Chip, Modal, Notice, TextInput } from '@wcpos/ui';

import { PROVIDERS } from './providers';
import type { StarDeviceOption } from './fetch-star-devices';
import { t } from '../../translations';

import type { CloudPrinter, CloudProvider } from '../../hooks/use-cloud-print-settings';

/**
 * The fields collected by the wizard to register a new printer. There is no
 * `id` — the server derives it. PrintNode-only fields are included solely when
 * the chosen provider is `printnode`.
 */
export type NewPrinterInput = {
	name: string;
	provider: CloudProvider;
	printnode_api_key?: string;
	printnode_printer_id?: number;
	star_api_key?: string;
	star_cloudprnt_url?: string;
	star_device_id?: string;
};

/** A PrintNode printer as surfaced by the fetch-my-printers proxy. */
export type PrintNodePrinterOption = { id: number; name: string; state: string };

export interface AddPrinterWizardProps {
	open: boolean;
	mode: 'add' | 'setup';
	/** Required and used only when `mode === 'setup'`. */
	setupPrinter?: CloudPrinter | null;
	onClose: () => void;
	/**
	 * Create a new printer. Resolves with the created printer (server-derived
	 * id) and, for polling providers, the one-time plaintext token. Rejects on
	 * save failure.
	 */
	onCreate: (input: NewPrinterInput) => Promise<{ printer: CloudPrinter; token?: string }>;
	/**
	 * Optional: list the PrintNode account's printers for the given API key so
	 * the user can pick one instead of typing its id. When omitted, only the
	 * manual printer-id input is shown.
	 */
	fetchPrintNodePrinters?: (apiKey: string) => Promise<PrintNodePrinterOption[]>;
	fetchStarDevices?: (cloudprntUrl: string, apiKey: string) => Promise<StarDeviceOption[]>;
}

/** The ordered provider choices shown on step 0. */
const PROVIDER_ORDER: CloudProvider[] = ['printnode', 'star-online', 'star-cloudprnt', 'epson-sdp'];

/** Per-provider one-liner shown under each choice on step 0. */
function providerDescription(provider: CloudProvider): string {
	switch (provider) {
		case 'star-online':
			return t(
				'cloud_print.choice_star_online_desc',
				'A Star printer linked to your stario.online account. Examples: mC-Print3, TSP143IV, mC-Label3.'
			);
		case 'star-cloudprnt':
			return t(
				'cloud_print.choice_star_desc',
				'Star printer with CloudPRNT built in (mC-Print, TSP143IV…)'
			);
		case 'epson-sdp':
			return t(
				'cloud_print.choice_epson_desc',
				'Epson TM printer with Server Direct Print support'
			);
		case 'printnode':
		default:
			return t(
				'cloud_print.choice_printnode_desc',
				'Use a regular USB / network thermal printer through a PrintNode account'
			);
	}
}


function providerBestIf(provider: CloudProvider): string {
	switch (provider) {
		case 'printnode':
			return t('cloud_print.best_printnode', 'Best if you have an ordinary USB or network receipt printer (not a cloud printer) and can run the small PrintNode app on a computer at the store.');
		case 'star-online':
			return t('cloud_print.best_star_online', "Best if you have a Star printer and a stario.online account — Star's cloud handles delivery for you.");
		case 'star-cloudprnt':
			return t('cloud_print.best_star_direct', 'Best if you have a Star printer and want a free connection with no third-party account — the printer talks to your store directly.');
		case 'epson-sdp':
		default:
			return t('cloud_print.best_epson', 'Best if you have an Epson "intelligent" TM printer (TM-T88VI/VII, TM-m30III, TM-i series).');
	}
}

/**
 * The absolute REST root, ending in `/`. WordPress exposes this on
 * `window.wpApiSettings.root`; fall back to the conventional path.
 */
function getRestRoot(): string {
	const root = window.wpApiSettings?.root;
	if (root) {
		return root;
	}
	return `${window.location.origin}/wp-json/`;
}

/**
 * Build the poll URL a printer uses to fetch jobs. `root` already ends in `/`,
 * so the path is appended without a leading slash to avoid a double slash.
 */
function buildPollUrl(provider: CloudProvider, printerId: string, token: string): string {
	const endpoint = PROVIDERS[provider].pollEndpoint;
	const root = getRestRoot();
	return `${root}wcpos/v1/print-jobs/${endpoint}?printer_id=${encodeURIComponent(
		printerId
	)}&pt=${encodeURIComponent(token)}`;
}

/** A label + read-only value with a copy-to-clipboard button. */
function CopyRow({
	label,
	help,
	value,
	copyValue,
	valueTestId,
	copyTestId,
}: {
	label: string;
	help?: string;
	value: React.ReactNode;
	copyValue?: string;
	valueTestId: string;
	copyTestId: string;
}) {
	const [copied, setCopied] = React.useState(false);

	const handleCopy = React.useCallback(() => {
		if (!copyValue) {
			return;
		}
		void navigator.clipboard?.writeText(copyValue);
		setCopied(true);
		window.setTimeout(() => setCopied(false), 1500);
	}, [copyValue]);

	return (
		<div className="wcpos:flex wcpos:flex-col wcpos:gap-1">
			<div className="wcpos:flex wcpos:items-baseline wcpos:gap-1">
				<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">{label}</span>
				{help && <span className="wcpos:text-xs wcpos:text-gray-500">— {help}</span>}
			</div>
			<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
				<code
					data-testid={valueTestId}
					className="wcpos:min-w-0 wcpos:flex-1 wcpos:overflow-x-auto wcpos:whitespace-nowrap wcpos:rounded wcpos:bg-gray-100 wcpos:px-2 wcpos:py-1 wcpos:text-xs wcpos:text-gray-900"
				>
					{value}
				</code>
				{copyValue && (
					<Button
						variant="outline"
						onClick={handleCopy}
						data-testid={copyTestId}
						aria-label={t('cloud_print.copy', 'Copy')}
					>
						{copied ? t('cloud_print.copied', 'Copied to clipboard') : t('cloud_print.copy', 'Copy')}
					</Button>
				)}
			</div>
		</div>
	);
}

type CreatedState = { printer: CloudPrinter; token?: string };

/**
 * A modal wizard for connecting a cloud printer.
 *
 * In `add` mode it walks: choose provider → name (+ PrintNode credentials) →
 * create → show the one-time poll URL/token (polling providers) or a "linked"
 * confirmation (PrintNode). In `setup` mode it opens directly on the final
 * step for an existing printer, with the token masked because it is only ever
 * shown once at creation.
 */
export function AddPrinterWizard({
	open,
	mode,
	setupPrinter,
	onClose,
	onCreate,
	fetchPrintNodePrinters,
	fetchStarDevices,
}: AddPrinterWizardProps): JSX.Element | null {
	const [step, setStep] = React.useState(0);
	const [provider, setProvider] = React.useState<CloudProvider>('star-cloudprnt');
	const [name, setName] = React.useState('');
	const [apiKey, setApiKey] = React.useState('');
	const [printerId, setPrinterId] = React.useState('');
	const [cloudprntUrl, setCloudprntUrl] = React.useState('');
	const [pending, setPending] = React.useState(false);
	const [error, setError] = React.useState<string | null>(null);
	const [created, setCreated] = React.useState<CreatedState | null>(null);
	const [pnPrinters, setPnPrinters] = React.useState<PrintNodePrinterOption[] | null>(null);
	const [pnFetching, setPnFetching] = React.useState(false);
	const [pnFetchError, setPnFetchError] = React.useState<string | null>(null);
	const [starDevices, setStarDevices] = React.useState<StarDeviceOption[] | null>(null);
	const [starFetching, setStarFetching] = React.useState(false);
	const [starFetchError, setStarFetchError] = React.useState<string | null>(null);


	const resetProviderScopedState = React.useCallback(() => {
		setApiKey('');
		setPrinterId('');
		setCloudprntUrl('');
		setPending(false);
		setError(null);
		setCreated(null);
		setPnPrinters(null);
		setPnFetching(false);
		setPnFetchError(null);
		setStarDevices(null);
		setStarFetching(false);
		setStarFetchError(null);
	}, []);

	const prevProvider = React.useRef(provider);
	React.useEffect(() => {
		if (!open) {
			prevProvider.current = provider;
			return;
		}
		if (prevProvider.current === provider) {
			return;
		}
		resetProviderScopedState();
		prevProvider.current = provider;
	}, [open, provider, resetProviderScopedState]);

	const handleFetchPrintNodePrinters = async () => {
		if (!fetchPrintNodePrinters) {
			return;
		}
		setPnFetching(true);
		setPnFetchError(null);
		try {
			const list = await fetchPrintNodePrinters(apiKey.trim());
			setPnPrinters(list);
			// Drop a previously-selected id that isn't in the new list (e.g. after
			// changing the API key) so Continue can't stay enabled with a stale id.
			// Manual entry still works — the field is independently editable.
			setPrinterId((current) => (list.some((p) => String(p.id) === current) ? current : ''));
			if (list.length === 0) {
				setPnFetchError(
					t('cloud_print.printnode_no_printers', 'No printers found on this PrintNode account.')
				);
			}
		} catch {
			setPnPrinters(null);
			setPnFetchError(
				t(
					'cloud_print.printnode_fetch_failed',
					"Couldn't list your PrintNode printers. Check the API key and that the desktop client is running."
				)
			);
		} finally {
			setPnFetching(false);
		}
	};


	const handleFetchStarDevices = async () => {
		if (!fetchStarDevices) {
			return;
		}
		setStarFetching(true);
		setStarFetchError(null);
		try {
			const list = await fetchStarDevices(cloudprntUrl.trim(), apiKey.trim());
			setStarDevices(list);
			setPrinterId((current) => (list.some((d) => d.id === current) ? current : ''));
			if (list.length === 0) {
				setStarFetchError(t('cloud_print.star_online_no_devices', 'No devices found in this Star Online group.'));
			}
		} catch {
			setStarDevices(null);
			setStarFetchError(t('cloud_print.star_online_fetch_failed', "Couldn't list your Star Online devices. Check the CloudPRNT URL and API key."));
		} finally {
			setStarFetching(false);
		}
	};

	// Reset internal state when the modal transitions closed → open so each
	// open starts fresh / on the correct step for the mode.
	const prevOpen = React.useRef(false);
	React.useEffect(() => {
		if (open && !prevOpen.current) {
			if (mode === 'setup' && setupPrinter) {
				setProvider(setupPrinter.provider);
				setStep(2);
			} else {
				setProvider('star-cloudprnt');
				setStep(0);
			}
			setName('');
			resetProviderScopedState();
		}
		prevOpen.current = open;
	}, [open, mode, resetProviderScopedState, setupPrinter]);

	if (!open) {
		return null;
	}

	const isPrintNode = provider === 'printnode';
	const isStarOnline = provider === 'star-online';
	const trimmedName = name.trim();
	const step1Ready =
		trimmedName !== '' &&
		(!isPrintNode || (apiKey.trim() !== '' && printerId.trim() !== '')) &&
		(!isStarOnline || (apiKey.trim() !== '' && cloudprntUrl.trim() !== '' && printerId.trim() !== ''));

	const handleCreate = async () => {
		const input: NewPrinterInput = { name: trimmedName, provider };
		if (isPrintNode) {
			input.printnode_api_key = apiKey.trim();
			input.printnode_printer_id = Number(printerId.trim());
		}
		if (isStarOnline) {
			input.star_api_key = apiKey.trim();
			input.star_cloudprnt_url = cloudprntUrl.trim();
			input.star_device_id = printerId.trim();
		}
		setPending(true);
		setError(null);
		try {
			const result = await onCreate(input);
			setCreated(result);
			setStep(2);
		} catch {
			setError(t('cloud_print.create_failed', "Couldn't create the printer. Please try again."));
		} finally {
			setPending(false);
		}
	};

	const title =
		step === 2
			? t('cloud_print.connect_title', 'Connect your printer')
			: t('cloud_print.add_title', 'Add a cloud printer');

	// Resolve the printer/provider used to render the final step.
	const finalPrinter = mode === 'setup' ? setupPrinter ?? null : created?.printer ?? null;
	const finalProvider = mode === 'setup' && setupPrinter ? setupPrinter.provider : provider;
	const finalIsPolling = PROVIDERS[finalProvider].isPolling;

	return (
		<Modal open={open} onClose={() => onClose()} title={title} className="wcpos:max-w-xl">
			<div className="wcpos:flex wcpos:flex-col wcpos:gap-4">
				{error && (
					<Notice status="error" isDismissible={false}>
						<span data-testid="wizard-error">{error}</span>
					</Notice>
				)}

				{/* Step 0: provider choice. */}
				{step === 0 && (
					<div className="wcpos:flex wcpos:flex-col wcpos:gap-2">
						<p className="wcpos:text-sm wcpos:text-gray-700">
							{t('cloud_print.step_provider_q', 'What kind of printer are you connecting?')}
						</p>
						{PROVIDER_ORDER.map((id) => {
							const meta = PROVIDERS[id];
							const selected = provider === id;
							return (
								<button
									key={id}
									type="button"
									data-testid={`provider-choice-${id}`}
									aria-pressed={selected}
									onClick={() => setProvider(id)}
									className={
										'wcpos:flex wcpos:items-start wcpos:gap-3 wcpos:rounded-md wcpos:border wcpos:p-3 wcpos:text-left wcpos:transition-colors ' +
										(selected
											? 'wcpos:border-wp-admin-theme-color wcpos:bg-wp-admin-theme-color-lightest'
											: 'wcpos:border-gray-300 wcpos:hover:bg-gray-50')
									}
								>
									<span
										className={
											meta.badge.className +
											' wcpos:flex wcpos:size-8 wcpos:shrink-0 wcpos:items-center wcpos:justify-center wcpos:rounded wcpos:text-sm wcpos:font-semibold'
										}
										aria-hidden="true"
									>
										{meta.badge.mark}
									</span>
									<span className="wcpos:min-w-0">
										<span className="wcpos:block wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900">
											{meta.label}
										</span>
										<span className="wcpos:block wcpos:text-xs wcpos:text-gray-500">
											{providerDescription(id)}
										</span>
										<span className="wcpos:mt-1 wcpos:block wcpos:text-xs wcpos:text-gray-600">
											{providerBestIf(id)}
										</span>
									</span>
								</button>
							);
						})}
					</div>
				)}

				{/* Step 1: name (+ PrintNode credentials). */}
				{step === 1 && (
					<div className="wcpos:flex wcpos:flex-col wcpos:gap-3">
						<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
							<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
								{t('cloud_print.printer_name', 'Printer name')}
							</span>
							<TextInput
								data-testid="wizard-name-input"
								value={name}
								placeholder={t('cloud_print.printer_name_ph', 'e.g. Kitchen printer')}
								onChange={(event) => setName(event.target.value)}
							/>
							<span className="wcpos:text-xs wcpos:text-gray-500">
								{t(
									'cloud_print.printer_name_help',
									'A friendly name so you can recognise it later. The technical ID is created for you.'
								)}
							</span>
						</label>

						{isPrintNode && (
							<>
								<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
									<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
										{t('cloud_print.printnode_api_key', 'PrintNode API key')}
									</span>
									<TextInput
										data-testid="wizard-printnode-api-key"
										value={apiKey}
										placeholder={t(
											'cloud_print.printnode_api_key_ph',
											'Paste your PrintNode account API key'
										)}
										onChange={(event) => setApiKey(event.target.value)}
									/>
									{fetchPrintNodePrinters && (
										<div className="wcpos:flex wcpos:flex-col wcpos:gap-1 wcpos:pt-1">
											<Button
												variant="outline"
												data-testid="wizard-printnode-fetch"
												disabled={apiKey.trim() === '' || pnFetching}
												loading={pnFetching}
												onClick={handleFetchPrintNodePrinters}
											>
												{t('cloud_print.printnode_fetch', 'Fetch my printers')}
											</Button>
											{pnFetchError && (
												<span
													data-testid="wizard-printnode-fetch-error"
													className="wcpos:text-xs wcpos:text-red-600"
												>
													{pnFetchError}
												</span>
											)}
										</div>
									)}
								</label>

								{pnPrinters && pnPrinters.length > 0 && (
									<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
										<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
											{t('cloud_print.printnode_pick_printer', 'Choose a printer')}
										</span>
										<select
											data-testid="wizard-printnode-printer-select"
											value={printerId}
											onChange={(event) => setPrinterId(event.target.value)}
											className="wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:px-2 wcpos:py-1 wcpos:text-sm"
										>
											<option value="">
												{t('cloud_print.printnode_pick_placeholder', 'Select a printer…')}
											</option>
											{pnPrinters.map((p) => (
												<option key={p.id} value={String(p.id)}>
													{p.name} ({p.state})
												</option>
											))}
										</select>
									</label>
								)}

								<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
									<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
										{t('cloud_print.printnode_printer_id', 'PrintNode printer ID')}
									</span>
									<TextInput
										data-testid="wizard-printnode-printer-id"
										type="number"
										value={printerId}
										onChange={(event) => setPrinterId(event.target.value)}
									/>
								</label>
								<Notice status="info" isDismissible={false}>
									{t(
										'cloud_print.printnode_note',
										"Install the small PrintNode client on a computer next to the printer, then paste your account's API key. We'll list that account's printers next."
									)}
								</Notice>
							</>
						)}

						{isStarOnline && (
							<>
								<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
									<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">{t('cloud_print.star_cloudprnt_url', 'CloudPRNT URL')}</span>
									<TextInput data-testid="wizard-star-cloudprnt-url" value={cloudprntUrl} placeholder="https://eu-device.stario.online/cloudprnt/your-group" onChange={(event) => setCloudprntUrl(event.target.value)} />
								</label>
								<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
									<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">{t('cloud_print.star_api_key', 'Star Online API key')}</span>
									<TextInput data-testid="wizard-star-api-key" value={apiKey} placeholder={t('cloud_print.star_api_key_ph', 'Paste your Star-Api-Key')} onChange={(event) => setApiKey(event.target.value)} />
									{fetchStarDevices && (
										<div className="wcpos:flex wcpos:flex-col wcpos:gap-1 wcpos:pt-1">
											<Button variant="outline" data-testid="wizard-star-fetch" disabled={apiKey.trim() === '' || cloudprntUrl.trim() === '' || starFetching} loading={starFetching} onClick={handleFetchStarDevices}>{t('cloud_print.star_fetch', 'Fetch my devices')}</Button>
											{starFetchError && <span data-testid="wizard-star-fetch-error" className="wcpos:text-xs wcpos:text-red-600">{starFetchError}</span>}
										</div>
									)}
								</label>
								{starDevices && starDevices.length > 0 && (
									<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
										<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">{t('cloud_print.star_pick_device', 'Choose a device')}</span>
										<select data-testid="wizard-star-device-select" value={printerId} onChange={(event) => setPrinterId(event.target.value)} className="wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:px-2 wcpos:py-1 wcpos:text-sm">
											<option value="">{t('cloud_print.star_pick_placeholder', 'Select a device…')}</option>
											{starDevices.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.state})</option>)}
										</select>
									</label>
								)}
								<label className="wcpos:flex wcpos:flex-col wcpos:gap-1">
									<span className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">{t('cloud_print.star_device_id', 'AccessIdentifier')}</span>
									<TextInput data-testid="wizard-star-device-id" value={printerId} onChange={(event) => setPrinterId(event.target.value)} />
								</label>
							</>
						)}

					</div>
				)}

				{/* Step 2: final / setup. */}
				{step === 2 && (
					<div className="wcpos:flex wcpos:flex-col wcpos:gap-3">
						{finalIsPolling ? (
							<>
								<div>
									<Chip variant="success" icon="✓">
										{t('cloud_print.created', 'Printer created')}
									</Chip>
								</div>
								{mode === 'setup' ? (
									<Notice status="info" isDismissible={false}>
										{t(
											'cloud_print.token_lost',
											"The token was shown only once when this printer was created and can't be displayed again. To get a new one, remove and re-add the printer."
										)}
									</Notice>
								) : (
									<p className="wcpos:text-sm wcpos:text-gray-700">
										{t(
											'cloud_print.token_once_intro',
											"Copy these two values into your printer's setup screen. The token is shown only once."
										)}
									</p>
								)}

								{(() => {
									const printerForUrl = finalPrinter;
									if (!printerForUrl) {
										return null;
									}
									if (mode === 'setup') {
										// No real token to display — append a literal mask after the
										// (empty, so un-encoded) token placeholder.
										const baseUrl = `${buildPollUrl(finalProvider, printerForUrl.id, '')}••••`;
										return (
											<CopyRow
												label={t('cloud_print.poll_url', 'Poll URL')}
												help={t('cloud_print.poll_url_help', 'paste into the printer\'s "server URL"')}
												value={baseUrl}
												valueTestId="wizard-poll-url"
												copyTestId="wizard-copy-url"
											/>
										);
									}
									const token = created?.token ?? '';
									if (!token) {
										// Polling providers always return a one-time token (Phase 1
										// contract). Guard the theoretical empty case so we never show
										// a broken `…&pt=` URL or an empty, copyable token row.
										return (
											<Notice status="warning" isDismissible={false}>
												{t(
													'cloud_print.token_missing',
													'This printer was created, but no setup token was returned. Remove and re-add it to get one.'
												)}
											</Notice>
										);
									}
									const url = buildPollUrl(finalProvider, printerForUrl.id, token);
									return (
										<>
											<CopyRow
												label={t('cloud_print.poll_url', 'Poll URL')}
												help={t('cloud_print.poll_url_help', 'paste into the printer\'s "server URL"')}
												value={url}
												copyValue={url}
												valueTestId="wizard-poll-url"
												copyTestId="wizard-copy-url"
											/>
											<CopyRow
												label={t('cloud_print.poll_token', 'Poll token')}
												help={t('cloud_print.poll_token_help', 'store it like a password')}
												value={token}
												copyValue={token}
												valueTestId="wizard-poll-token"
												copyTestId="wizard-copy-token"
											/>
										</>
									);
								})()}
							</>
						) : (
							<>
								<div>
									<Chip variant="success" icon="✓">
										{t('cloud_print.linked_provider', 'Linked to {provider}', { provider: PROVIDERS[finalProvider].label })}
									</Chip>
								</div>
								<p className="wcpos:text-sm wcpos:text-gray-700">
									{finalProvider === 'star-online'
										? t(
												'cloud_print.star_online_delivery',
												'No URLs or tokens to copy — Star Online handles delivery.'
											)
										: t(
												'cloud_print.printnode_delivery',
												'No URLs or tokens to copy — PrintNode handles delivery.'
											)}
								</p>
							</>
						)}
					</div>
				)}

				{/* Footer. */}
				<div className="wcpos:flex wcpos:justify-end wcpos:gap-2 wcpos:pt-2">
					{step === 1 && (
						<Button
							variant="secondary"
							data-testid="wizard-back"
							onClick={() => setStep(0)}
						>
							{t('common.back', 'Back')}
						</Button>
					)}
					{step === 0 && (
						<Button
							variant="primary"
							data-testid="wizard-continue"
							onClick={() => setStep(1)}
						>
							{t('common.continue', 'Continue')}
						</Button>
					)}
					{step === 1 && (
						<Button
							variant="primary"
							data-testid="wizard-continue"
							disabled={!step1Ready}
							loading={pending}
							onClick={handleCreate}
						>
							{t('common.continue', 'Continue')}
						</Button>
					)}
					{step === 2 && (
						<Button variant="primary" data-testid="wizard-done" onClick={() => onClose()}>
							{t('common.done', 'Done')}
						</Button>
					)}
				</div>
			</div>
		</Modal>
	);
}

export default AddPrinterWizard;
