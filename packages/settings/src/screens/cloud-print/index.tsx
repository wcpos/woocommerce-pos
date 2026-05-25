import * as React from 'react';

import { AssignmentEditor } from './assignment-editor';
import { PrinterForm } from './printer-form';
import {
	type CloudPrintSettings,
	type CloudPrintSettingsResponse,
	useCloudPrintSettings,
	type CloudPrinter,
} from '../../hooks/use-cloud-print-settings';
import { t } from '../../translations';

function getSaveErrorMessage(error: unknown): string {
	if (error instanceof Error) {
		return error.message;
	}
	if (
		'object' === typeof error &&
		null !== error &&
		'message' in error &&
		'string' === typeof error.message
	) {
		return error.message;
	}
	return t('cloud_print.save_failed', 'Save failed.');
}

function CloudPrint() {
	const { settings, save } = useCloudPrintSettings();
	const [draft, setDraft] = React.useState<CloudPrintSettings>(settings);
	const [saveError, setSaveError] = React.useState<string | null>(null);
	const draftRef = React.useRef(draft);
	const saveQueueRef = React.useRef<Promise<void>>(Promise.resolve());
	const saveVersionRef = React.useRef(0);
	// One-time poll token returned by the server after registering a printer.
	const [newToken, setNewToken] = React.useState<{ id: string; token: string } | null>(null);

	const applyDraft = React.useCallback((next: CloudPrintSettings) => {
		draftRef.current = next;
		setDraft(next);
	}, []);

	const saveDraft = React.useCallback(
		async (next: CloudPrintSettings): Promise<CloudPrintSettingsResponse> => {
			const previous = draftRef.current;
			const version = saveVersionRef.current + 1;
			saveVersionRef.current = version;
			applyDraft(next);
			setSaveError(null);

			const queuedSave = saveQueueRef.current
				.catch(() => undefined)
				.then(async () => {
					const saved = await save(next);
					return saved;
				});

			saveQueueRef.current = queuedSave.then(
				() => undefined,
				() => undefined
			);

			try {
				const saved = await queuedSave;
				if (version === saveVersionRef.current) {
					setSaveError(null);
				}
				return saved;
			} catch (error) {
				const message = getSaveErrorMessage(error);
				if (version === saveVersionRef.current) {
					applyDraft(previous);
					setSaveError(message);
				}
				throw error;
			}
		},
		[applyDraft, save]
	);

	const addPrinter = async (printer: CloudPrinter) => {
		try {
			const current = draftRef.current;
			const res = await saveDraft({ ...current, printers: [...current.printers, printer] });
			const generatedEntries = Object.entries(res?.generated ?? {});
			const generated =
				generatedEntries.find(([id]) => id === printer.id) ?? generatedEntries[0];
			if (generated) {
				const [id, token] = generated;
				setNewToken({ id, token });
			}
		} catch {
			// The failed optimistic save has been rolled back and the screen shows the save error.
		}
	};

	const removePrinter = (id: string) => {
		const current = draftRef.current;
		void saveDraft({
			printers: current.printers.filter((p) => p.id !== id),
			assignments: current.assignments.filter((a) => a.printer_id !== id),
		}).catch(() => undefined);
	};

	return (
		<div className="wcpos:px-4 wcpos:pb-5">
			<h2 className="wcpos:text-base">{t('cloud_print.printers', 'Cloud printers')}</h2>
			<p>{t('cloud_print.printers_description', 'Printers that pull jobs from this site.')}</p>

			{saveError && (
				<p data-testid="cloud-print-save-error" className="wcpos:text-sm wcpos:text-red-600">
					{saveError}
				</p>
			)}

			{newToken && (
				<p data-testid="cloud-print-new-token" className="wcpos:text-sm">
					{t(
						'cloud_print.token_once',
						'Copy this poll token into the printer config now — it is shown only once:'
					)}{' '}
					<code>{newToken.token}</code>
				</p>
			)}

			{draft.printers.length === 0 ? (
				<p data-testid="cloud-print-empty">{t('cloud_print.no_printers', 'No cloud printers yet.')}</p>
			) : (
				<ul data-testid="cloud-print-list" className="wcpos:flex wcpos:flex-col wcpos:gap-2">
					{draft.printers.map((printer) => (
						<li
							key={printer.id}
							data-testid={`cloud-printer-${printer.id}`}
							className="wcpos:flex wcpos:gap-2"
						>
							<span className="wcpos:font-semibold">{printer.name}</span>
							<span className="wcpos:text-gray-500">({printer.protocol})</span>
							<button
								type="button"
								data-testid={`cloud-printer-remove-${printer.id}`}
								onClick={() => removePrinter(printer.id)}
							>
								{t('common.remove', 'Remove')}
							</button>
						</li>
					))}
				</ul>
			)}

			<PrinterForm onAdd={addPrinter} />
			<AssignmentEditor
				printers={draft.printers}
				assignments={draft.assignments}
				onChange={(assignments) => {
					const current = draftRef.current;
					void saveDraft({ ...current, assignments }).catch(() => undefined);
				}}
			/>
		</div>
	);
}

export default CloudPrint;
