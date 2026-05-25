import * as React from 'react';

import { AssignmentEditor } from './assignment-editor';
import { PrinterForm } from './printer-form';
import {
	type CloudPrintSettings,
	useCloudPrintSettings,
	type CloudPrinter,
} from '../../hooks/use-cloud-print-settings';
import { t } from '../../translations';

function CloudPrint() {
	const { settings, save } = useCloudPrintSettings();
	const [draft, setDraft] = React.useState<CloudPrintSettings>(settings);
	// One-time poll token returned by the server after registering a printer.
	const [newToken, setNewToken] = React.useState<{ id: string; token: string } | null>(null);

	const saveDraft = (next: CloudPrintSettings) => {
		setDraft(next);
		return save(next);
	};

	const addPrinter = async (printer: CloudPrinter) => {
		const res = await saveDraft({ ...draft, printers: [...draft.printers, printer] });
		const token = res?.generated?.[printer.id];
		if (token) {
			setNewToken({ id: printer.id, token });
		}
	};

	const removePrinter = (id: string) => {
		saveDraft({
			printers: draft.printers.filter((p) => p.id !== id),
			assignments: draft.assignments.filter((a) => a.printer_id !== id),
		});
	};

	return (
		<div className="wcpos:px-4 wcpos:pb-5">
			<h2 className="wcpos:text-base">{t('cloud_print.printers', 'Cloud printers')}</h2>
			<p>{t('cloud_print.printers_description', 'Printers that pull jobs from this site.')}</p>

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
				onChange={(assignments) => saveDraft({ ...draft, assignments })}
			/>
		</div>
	);
}

export default CloudPrint;
