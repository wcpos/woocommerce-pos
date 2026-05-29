import * as React from 'react';

import { Button, Callout, FormSection, useSnackbar } from '@wcpos/ui';

import { AddPrinterWizard, type NewPrinterInput } from './add-printer-wizard';
import { AutoPrintRules } from './auto-print-rules';
import { PrinterCard } from './printer-card';
import {
	type CloudPrintSettings,
	type CloudPrintSettingsResponse,
	type CloudPrinter,
	useCloudPrintSettings,
} from '../../hooks/use-cloud-print-settings';
import { useReceiptTemplateOptions } from '../../hooks/use-receipt-templates';
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

const SAVE_SNACKBAR_ID = 'cloud-print-save';

type WizardState = {
	open: boolean;
	mode: 'add' | 'setup';
	printer: CloudPrinter | null;
};

function CloudPrint() {
	const { settings, save } = useCloudPrintSettings();
	const templateOptions = useReceiptTemplateOptions();
	const { addSnackbar } = useSnackbar();

	const [draft, setDraft] = React.useState<CloudPrintSettings>(settings);
	const [wizard, setWizard] = React.useState<WizardState>({
		open: false,
		mode: 'add',
		printer: null,
	});

	const draftRef = React.useRef(draft);
	const committedRef = React.useRef(settings);
	const pendingSaveCountRef = React.useRef(0);
	const saveQueueRef = React.useRef<Promise<void>>(Promise.resolve());
	const saveVersionRef = React.useRef(0);

	const applyDraft = React.useCallback((next: CloudPrintSettings) => {
		draftRef.current = next;
		setDraft(next);
	}, []);

	React.useEffect(() => {
		if (0 === pendingSaveCountRef.current) {
			committedRef.current = settings;
			applyDraft(settings);
		}
	}, [applyDraft, settings]);

	const saveDraft = React.useCallback(
		async (next: CloudPrintSettings): Promise<CloudPrintSettingsResponse> => {
			const version = saveVersionRef.current + 1;
			saveVersionRef.current = version;
			pendingSaveCountRef.current += 1;
			applyDraft(next);
			addSnackbar({
				id: SAVE_SNACKBAR_ID,
				message: t('cloud_print.saving', 'Saving…'),
				status: 'saving',
			});

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
				const committed = {
					printers: saved.printers,
					assignments: saved.assignments,
				};
				committedRef.current = committed;
				if (version === saveVersionRef.current) {
					applyDraft(committed);
				}
				addSnackbar({
					id: SAVE_SNACKBAR_ID,
					message: t('cloud_print.saved', 'Saved'),
					status: 'success',
				});
				return saved;
			} catch (error) {
				const message = getSaveErrorMessage(error);
				if (version === saveVersionRef.current) {
					applyDraft(committedRef.current);
				}
				addSnackbar({ id: SAVE_SNACKBAR_ID, message, status: 'error' });
				throw error;
			} finally {
				pendingSaveCountRef.current -= 1;
			}
		},
		[addSnackbar, applyDraft, save]
	);

	// Register a new printer. The new printer carries `id: ''` so the server
	// derives the immutable slug (derivation only runs when id === ''). Rejects
	// on failure so the wizard surfaces its own error and stays on step 1.
	// Assumes a single create in flight at a time (the wizard is modal and blocks
	// a second create until step 2), so the lone newly-derived id identifies the
	// printer this call created.
	const handleCreate = React.useCallback(
		async (input: NewPrinterInput): Promise<{ printer: CloudPrinter; token?: string }> => {
			const current = draftRef.current;
			const prevIds = new Set(current.printers.map((p) => p.id));
			const newPrinter: CloudPrinter = {
				id: '',
				name: input.name,
				provider: input.provider,
				store_id: 0,
				...(input.provider === 'printnode'
					? {
							printnode_api_key: input.printnode_api_key,
							printnode_printer_id: input.printnode_printer_id,
					  }
					: {}),
			};
			const saved = await saveDraft({
				...current,
				printers: [...current.printers, newPrinter],
			});
			const created =
				saved.printers.find((p) => !prevIds.has(p.id)) ??
				saved.printers[saved.printers.length - 1];
			const token = saved.generated?.[created.id];
			return { printer: created, token };
		},
		[saveDraft]
	);

	const handleRename = React.useCallback(
		(id: string, newName: string) => {
			const current = draftRef.current;
			void saveDraft({
				...current,
				printers: current.printers.map((p) => (p.id === id ? { ...p, name: newName } : p)),
			}).catch(() => undefined);
		},
		[saveDraft]
	);

	const handleRemove = React.useCallback(
		(printer: CloudPrinter) => {
			const current = draftRef.current;
			void saveDraft({
				printers: current.printers.filter((p) => p.id !== printer.id),
				assignments: current.assignments.filter((a) => a.printer_id !== printer.id),
			}).catch(() => undefined);
		},
		[saveDraft]
	);

	const handleRulesChange = React.useCallback(
		(assignments: CloudPrintSettings['assignments']) => {
			const current = draftRef.current;
			void saveDraft({ ...current, assignments }).catch(() => undefined);
		},
		[saveDraft]
	);

	return (
		<div className="wcpos:flex wcpos:flex-col wcpos:gap-6 wcpos:px-4 wcpos:pb-5">
			<Callout status="info" title={t('cloud_print.intro_title', 'What is cloud printing?')}>
				<p>
					{t(
						'cloud_print.intro_p1',
						"Cloud printing lets your store send a receipt to a printer over the internet — the printer fetches each job itself, so it doesn't need to be connected to the POS device."
					)}
				</p>
				<p className="wcpos:mt-2">
					{t(
						'cloud_print.intro_p2',
						'Use it when a receipt printer is on a different network from the till, or when you want orders to print automatically. For example:'
					)}
				</p>
				<ul className="wcpos:mt-1 wcpos:list-disc wcpos:pl-5">
					<li>{t('cloud_print.intro_li1', 'send every order straight to the kitchen printer, or')}</li>
					<li>{t('cloud_print.intro_li2', 'print a packing slip for every online sale.')}</li>
				</ul>
				<p className="wcpos:mt-2">
					{t('cloud_print.intro_p3', 'Cashiers can also print to a cloud printer manually from the POS.')}
				</p>
			</Callout>

			<FormSection
				title={t('cloud_print.printers', 'Your cloud printers')}
				description={t('cloud_print.printers_description', 'Printers that fetch jobs from this store.')}
			>
				{draft.printers.length === 0 ? (
					<p
						data-testid="cloud-print-empty"
						className="wcpos:text-sm wcpos:text-gray-500"
					>
						{t('cloud_print.no_printers', 'No cloud printers yet.')}
					</p>
				) : (
					<div
						data-testid="cloud-print-list"
						className="wcpos:grid wcpos:gap-4 wcpos:grid-cols-1 wcpos:sm:grid-cols-2 wcpos:lg:grid-cols-3"
					>
						{draft.printers.map((printer) => (
							<PrinterCard
								key={printer.id}
								printer={printer}
								onRename={handleRename}
								onRemove={handleRemove}
								onOpenSetup={(p) => setWizard({ open: true, mode: 'setup', printer: p })}
							/>
						))}
					</div>
				)}

				<div className="wcpos:mt-4">
					<Button
						variant="outline"
						data-testid="cloud-print-add"
						onClick={() => setWizard({ open: true, mode: 'add', printer: null })}
					>
						{t('cloud_print.add_a_printer', '+ Add a printer')}
					</Button>
				</div>
			</FormSection>

			<FormSection title={t('cloud_print.auto_print', 'Auto-print rules')}>
				<AutoPrintRules
					printers={draft.printers}
					assignments={draft.assignments}
					templateOptions={templateOptions}
					onChange={handleRulesChange}
				/>
			</FormSection>

			<AddPrinterWizard
				open={wizard.open}
				mode={wizard.mode}
				setupPrinter={wizard.printer}
				onClose={() => setWizard((w) => ({ ...w, open: false }))}
				onCreate={handleCreate}
			/>
		</div>
	);
}

export default CloudPrint;
