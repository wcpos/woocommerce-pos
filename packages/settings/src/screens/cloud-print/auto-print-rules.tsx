import { Button, Callout, Select } from '@wcpos/ui';

import { t } from '../../translations';

import type { CloudAssignment, CloudPrinter } from '../../hooks/use-cloud-print-settings';

export interface AutoPrintRulesProps {
	printers: CloudPrinter[];
	assignments: CloudAssignment[];
	// Receipt-template options supplied by the screen (it calls
	// `useReceiptTemplateOptions`). Passed in as a prop so this component stays
	// suspense-free and easily testable.
	templateOptions: { value: string; label: string }[];
	onChange: (next: CloudAssignment[]) => void;
}

type ScopeValue = CloudAssignment['scope'];

function scopeOptions(): { value: ScopeValue; label: string }[] {
	return [
		{ value: 'every', label: t('cloud_print.scope_every', 'every order') },
		{ value: 'pos', label: t('cloud_print.scope_pos', 'every in-store (POS) order') },
		{ value: 'online', label: t('cloud_print.scope_online', 'every online order') },
	];
}

// Templates live in WP-admin as the `wcpos_template` custom post type, so link
// to its list screen rather than a route inside this React app.
const TEMPLATES_URL = 'edit.php?post_type=wcpos_template';

export function AutoPrintRules({
	printers,
	assignments,
	templateOptions,
	onChange,
}: AutoPrintRulesProps) {
	const printerOptions = printers.map((p) => ({ value: p.id, label: p.name }));

	const update = (index: number, patch: Partial<CloudAssignment>) => {
		onChange(assignments.map((a, i) => (i === index ? { ...a, ...patch } : a)));
	};

	const add = () => {
		const first = printers[0];
		if (!first) return;
		onChange([
			...assignments,
			{
				printer_id: first.id,
				scope: 'every',
				template_id: templateOptions[0]?.value ?? '',
			},
		]);
	};

	const remove = (index: number) => onChange(assignments.filter((_, idx) => idx !== index));

	return (
		<div className="wcpos:mt-4 wcpos:flex wcpos:flex-col wcpos:gap-3">
			<Callout status="info">
				💡 {t(
					'cloud_print.rules_tip',
					'This is optional — leave it empty to print receipts only manually from the POS.'
				)}
			</Callout>

			<p className="wcpos:text-sm wcpos:text-gray-500">
				{t(
					'cloud_print.rules_subline_pre',
					'Each rule prints the chosen template automatically when an order matches. Templates come from '
				)}
				<a href={TEMPLATES_URL} target="_blank" rel="noreferrer">
					{t('cloud_print.templates_link', 'POS › Templates')}
				</a>
				{t(
					'cloud_print.rules_subline_post',
					'. More than one rule can run for the same order.'
				)}
			</p>

			{assignments.length === 0 ? (
				<p data-testid="rules-empty" className="wcpos:text-sm wcpos:text-gray-500">
					{t('cloud_print.no_rules', 'No rules yet.')}
				</p>
			) : (
				<div className="wcpos:flex wcpos:flex-col wcpos:gap-2">
					{assignments.map((a, i) => (
						<div
							key={i}
							data-testid={`rule-${i}`}
							className="wcpos:flex wcpos:flex-wrap wcpos:items-center wcpos:gap-x-1.5 wcpos:gap-y-1 wcpos:text-sm"
						>
							<span>{t('cloud_print.rule_print', 'Automatically print')}</span>
							<Select
								data-testid={`rule-scope-${i}`}
								aria-label={t('cloud_print.rule_scope_label', 'Which orders to print')}
								className="wcpos:w-auto"
								value={a.scope}
								options={scopeOptions()}
								onChange={({ value }) =>
									update(i, { scope: value as ScopeValue })
								}
							/>
							<span>{t('cloud_print.rule_to', 'to')}</span>
							<Select
								data-testid={`rule-printer-${i}`}
								aria-label={t('cloud_print.rule_printer_label', 'Printer')}
								className="wcpos:w-auto"
								value={a.printer_id}
								options={printerOptions}
								onChange={({ value }) =>
									update(i, { printer_id: String(value) })
								}
							/>
							<span>{t('cloud_print.rule_using', 'using the')}</span>
							<Select
								data-testid={`rule-template-${i}`}
								aria-label={t('cloud_print.rule_template_label', 'Receipt template')}
								className="wcpos:w-auto"
								value={a.template_id}
								options={templateOptions}
								onChange={({ value }) =>
									update(i, { template_id: String(value) })
								}
							/>
							<span>{t('cloud_print.rule_template_suffix', 'template.')}</span>
							<Button
								variant="text"
								data-testid={`rule-remove-${i}`}
								className="wcpos:ml-1"
								onClick={() => remove(i)}
							>
								{t('common.remove', 'Remove')}
							</Button>
						</div>
					))}
				</div>
			)}

			<div>
				<Button
					variant="outline"
					data-testid="rules-add"
					disabled={0 === printers.length}
					onClick={add}
				>
					{t('cloud_print.add_rule', '+ Add rule')}
				</Button>
			</div>
		</div>
	);
}
