import { t } from '../../translations';

import type { CloudAssignment, CloudPrinter } from '../../hooks/use-cloud-print-settings';

type FormatOption = { value: string; label: string };

const STAR_FORMATS: FormatOption[] = [
	{ value: 'starprnt', label: 'StarPRNT' },
	{ value: 'escpos', label: 'ESC/POS' },
	{ value: 'html', label: 'HTML' },
];

const EPSON_FORMATS: FormatOption[] = [{ value: 'epos-xml', label: 'Epson ePOS-Print' }];

function formatsForPrinter(printer: CloudPrinter | undefined): FormatOption[] {
	return printer?.provider === 'epson-sdp' ? EPSON_FORMATS : STAR_FORMATS;
}

function defaultFormatForPrinter(printer: CloudPrinter | undefined): string {
	return formatsForPrinter(printer)[0].value;
}

export function AssignmentEditor({
	printers,
	assignments,
	onChange,
}: {
	printers: CloudPrinter[];
	assignments: CloudAssignment[];
	onChange: (next: CloudAssignment[]) => void;
}) {
	const add = () => {
		const first = printers[0];
		if (!first) return;
		onChange([
			...assignments,
			{ printer_id: first.id, scope: 'pos', template_id: defaultFormatForPrinter(first) },
		]);
	};

	const update = (index: number, patch: Partial<CloudAssignment>) => {
		onChange(assignments.map((a, i) => (i === index ? { ...a, ...patch } : a)));
	};

	const changePrinter = (index: number, printerId: string) => {
		const printer = printers.find((p) => p.id === printerId);
		update(index, { printer_id: printerId, template_id: defaultFormatForPrinter(printer) });
	};

	const remove = (index: number) => onChange(assignments.filter((_, i) => i !== index));

	return (
		<div className="wcpos:mt-4">
			<h3 className="wcpos:text-sm wcpos:font-semibold">
				{t('cloud_print.auto_print', 'Auto-print rules')}
			</h3>
			{assignments.map((a, i) => {
				const printer = printers.find((p) => p.id === a.printer_id);
				const formats = formatsForPrinter(printer);
				const format = formats.some((option) => option.value === a.template_id)
					? a.template_id
					: defaultFormatForPrinter(printer);

				return (
					<div key={i} data-testid={`cloud-assignment-${i}`} className="wcpos:flex wcpos:gap-2 wcpos:mt-2">
						<select
							data-testid={`cloud-assignment-printer-${i}`}
							value={a.printer_id}
							onChange={(e) => changePrinter(i, e.target.value)}
						>
							{printers.map((p) => (
								<option key={p.id} value={p.id}>
									{p.name}
								</option>
							))}
						</select>
						<select
							data-testid={`cloud-assignment-scope-${i}`}
							value={a.scope}
							onChange={(e) => update(i, { scope: e.target.value as CloudAssignment['scope'] })}
						>
							<option value="every">{t('cloud_print.scope_every', 'Every order')}</option>
							<option value="pos">{t('cloud_print.scope_pos', 'POS orders only')}</option>
							<option value="online">{t('cloud_print.scope_online', 'Online orders only')}</option>
						</select>
						<select
							data-testid={`cloud-assignment-format-${i}`}
							value={format}
							onChange={(e) => update(i, { template_id: e.target.value })}
						>
							{formats.map((option) => (
								<option key={option.value} value={option.value}>
									{option.label}
								</option>
							))}
						</select>
						<button
							type="button"
							data-testid={`cloud-assignment-remove-${i}`}
							onClick={() => remove(i)}
						>
							{t('common.remove', 'Remove')}
						</button>
					</div>
				);
			})}
			<button type="button" data-testid="cloud-assignment-add" disabled={0 === printers.length} onClick={add}>
				{t('cloud_print.add_rule', 'Add rule')}
			</button>
		</div>
	);
}
