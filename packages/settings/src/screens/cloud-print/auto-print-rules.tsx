import { Button, Callout, Select } from '@wcpos/ui';

import { templateOptionsForProvider } from './providers';
import { t } from '../../translations';

import type {
	CloudAssignment,
	CloudPrinter,
	CloudProvider,
} from '../../hooks/use-cloud-print-settings';
import type { TemplateEngine } from '../../hooks/use-receipt-templates';

export interface StoreOption {
	id: number;
	name: string;
}

export interface AutoPrintRulesProps {
	printers: CloudPrinter[];
	assignments: CloudAssignment[];
	// Receipt-template options supplied by the screen (it calls
	// `useReceiptTemplateOptions`). Passed in as a prop so this component stays
	// suspense-free and easily testable.
	templateOptions: { value: string; label: string; engine: TemplateEngine }[];
	storeOptions?: StoreOption[];
	onChange: (next: CloudAssignment[]) => void;
}

type ScopeValue = CloudAssignment['scope'];
type SentenceSelectOption = { value: string | number; label: string };
type AppliesOption = { value: string; label: string; disabled?: boolean };

function appliesValue(a: CloudAssignment): string {
	return a.store_id && a.store_id > 0 ? `store:${a.store_id}` : a.scope;
}

function appliesOptions(storeOptions: StoreOption[], current: CloudAssignment): AppliesOption[] {
	const base: AppliesOption[] = [
		{ value: 'every', label: t('cloud_print.scope_every', 'every order') },
		{ value: 'pos', label: t('cloud_print.scope_pos', 'every in-store (POS) order') },
		{ value: 'online', label: t('cloud_print.scope_online', 'every online order') },
	];
	const stores: AppliesOption[] = storeOptions.map((store) => ({
		value: `store:${store.id}`,
		label: t('cloud_print.scope_store', 'orders for {name}', { name: store.name }),
	}));

	if (
		current.store_id &&
		current.store_id > 0 &&
		!storeOptions.some((store) => store.id === current.store_id)
	) {
		stores.push({
			value: `store:${current.store_id}`,
			label: t('cloud_print.scope_store_unknown', 'Unknown store (#{id})', {
				id: current.store_id,
			}),
			disabled: true,
		});
	}

	return [...base, ...stores];
}

function parseApplies(value: string): { store_id: number; scope: ScopeValue } {
	if (value.startsWith('store:')) {
		const storeId = Number.parseInt(value.slice('store:'.length), 10);
		return {
			store_id: Number.isFinite(storeId) ? storeId : 0,
			scope: 'every',
		};
	}

	return { store_id: 0, scope: value as ScopeValue };
}

// Templates live in WP-admin as the `wcpos_template` custom post type, so link
// to its list screen rather than a route inside this React app.
const TEMPLATES_URL = 'edit.php?post_type=wcpos_template';

function selectedLabelLength(
	options: SentenceSelectOption[],
	value: string | number
): number {
	return (
		options.find((option) => String(option.value) === String(value))?.label.length ?? 0
	);
}

function sentenceSelectWidth(
	options: SentenceSelectOption[],
	value: string | number
): React.CSSProperties {
	return {
		width: `calc(${Math.max(selectedLabelLength(options, value), 1)}ch + 3.5rem)`,
	};
}

export function AutoPrintRules({
	printers,
	assignments,
	templateOptions,
	storeOptions = [],
	onChange,
}: AutoPrintRulesProps) {
	const printerOptions = printers.map((p) => ({ value: p.id, label: p.name }));

	// The provider for a printer id (falls back to PrintNode-style "all
	// templates" when the printer can't be found, e.g. a stale assignment).
	const providerFor = (printerId: string): CloudProvider =>
		printers.find((p) => p.id === printerId)?.provider ?? 'printnode';

	// Template options valid for the printer driving a given assignment row.
	const optionsForPrinter = (printerId: string) =>
		templateOptionsForProvider(templateOptions, providerFor(printerId));

	const update = (index: number, patch: Partial<CloudAssignment>) => {
		onChange(assignments.map((a, i) => (i === index ? { ...a, ...patch } : a)));
	};

	const add = () => {
		const first = printers[0];
		if (!first) return;
		const firstOptions = optionsForPrinter(first.id);
		onChange([
			...assignments,
			{
				printer_id: first.id,
				store_id: 0,
				scope: 'every',
				template_id: firstOptions[0]?.value ?? '',
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
					{assignments.map((a, i) => {
						const options = appliesOptions(storeOptions, a);
						const value = appliesValue(a);

						return (
							<div
								key={i}
								data-testid={`rule-${i}`}
								className="wcpos:flex wcpos:flex-wrap wcpos:items-center wcpos:gap-x-1.5 wcpos:gap-y-1 wcpos:text-sm"
							>
								<span>{t('cloud_print.rule_print', 'Automatically print')}</span>
								<Select
									inline
									data-testid={`rule-scope-${i}`}
									aria-label={t('cloud_print.rule_scope_label', 'Which orders to print')}
									className="wcpos:max-w-full"
									style={sentenceSelectWidth(options, value)}
									value={value}
									options={options}
									onChange={({ value: nextValue }) =>
										update(i, parseApplies(String(nextValue)))
									}
								/>
								<span>{t('cloud_print.rule_to', 'to')}</span>
								<Select
									inline
									data-testid={`rule-printer-${i}`}
									aria-label={t('cloud_print.rule_printer_label', 'Printer')}
									className="wcpos:max-w-full"
									style={sentenceSelectWidth(printerOptions, a.printer_id)}
									value={a.printer_id}
									options={printerOptions}
									onChange={({ value }) =>
										update(i, { printer_id: String(value) })
									}
								/>
								<span>{t('cloud_print.rule_using', 'using the')}</span>
								<Select
									inline
									data-testid={`rule-template-${i}`}
									aria-label={t('cloud_print.rule_template_label', 'Receipt template')}
									className="wcpos:max-w-full"
									style={sentenceSelectWidth(optionsForPrinter(a.printer_id), a.template_id)}
									value={a.template_id}
									options={optionsForPrinter(a.printer_id)}
									onChange={({ value }) =>
										update(i, { template_id: String(value) })
									}
								/>
								<span>{t('cloud_print.rule_template_suffix', 'template.')}</span>
								<Button
									variant="ghost-destructive"
									data-testid={`rule-remove-${i}`}
									className="wcpos:ml-1"
									onClick={() => remove(i)}
								>
									{t('common.remove', 'Remove')}
								</Button>
							</div>
						);
					})}
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
