import { t } from '../../translations';

import type { CloudAssignment, CloudPrinter } from '../../hooks/use-cloud-print-settings';

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
		onChange([...assignments, { printer_id: first.id, scope: 'pos', format: 'starprnt' }]);
	};

	const update = (index: number, patch: Partial<CloudAssignment>) => {
		onChange(assignments.map((a, i) => (i === index ? { ...a, ...patch } : a)));
	};

	const remove = (index: number) => onChange(assignments.filter((_, i) => i !== index));

	return (
		<div className="wcpos:mt-4">
			<h3 className="wcpos:text-sm wcpos:font-semibold">
				{t('cloud_print.auto_print', 'Auto-print rules')}
			</h3>
			{assignments.map((a, i) => (
				<div key={i} data-testid={`cloud-assignment-${i}`} className="wcpos:flex wcpos:gap-2 wcpos:mt-2">
					<select value={a.printer_id} onChange={(e) => update(i, { printer_id: e.target.value })}>
						{printers.map((p) => (
							<option key={p.id} value={p.id}>
								{p.name}
							</option>
						))}
					</select>
					<select
						value={a.scope}
						onChange={(e) => update(i, { scope: e.target.value as CloudAssignment['scope'] })}
					>
						<option value="every">{t('cloud_print.scope_every', 'Every order')}</option>
						<option value="pos">{t('cloud_print.scope_pos', 'POS orders only')}</option>
						<option value="online">{t('cloud_print.scope_online', 'Online orders only')}</option>
					</select>
					<select value={a.format} onChange={(e) => update(i, { format: e.target.value })}>
						<option value="starprnt">StarPRNT</option>
						<option value="escpos">ESC/POS</option>
						<option value="epos-xml">Epson ePOS-Print</option>
						<option value="html">HTML</option>
					</select>
					<button
						type="button"
						data-testid={`cloud-assignment-remove-${i}`}
						onClick={() => remove(i)}
					>
						{t('common.remove', 'Remove')}
					</button>
				</div>
			))}
			<button type="button" data-testid="cloud-assignment-add" disabled={0 === printers.length} onClick={add}>
				{t('cloud_print.add_rule', 'Add rule')}
			</button>
		</div>
	);
}
