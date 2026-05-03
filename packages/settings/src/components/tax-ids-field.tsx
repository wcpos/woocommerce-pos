import * as React from 'react';

import { Button, Select, TextInput, type OptionProps } from './ui';

export interface TaxId {
	type: string;
	value: string;
	country?: string;
	label?: string;
}

export interface TaxIdsFieldHandle {
	addRow: () => void;
}

interface TaxIdsLabels {
	add: string;
	type: string;
	value: string;
	country: string;
	label: string;
	remove: string;
	empty: string;
}

interface TaxIdsFieldProps {
	value: TaxId[];
	onChange: (value: TaxId[]) => void;
	labels: TaxIdsLabels;
}

const TYPE_OPTIONS: OptionProps[] = [
	{ value: 'eu_vat', label: 'EU VAT' },
	{ value: 'gb_vat', label: 'GB VAT' },
	{ value: 'au_abn', label: 'AU ABN' },
	{ value: 'br_cpf', label: 'BR CPF' },
	{ value: 'br_cnpj', label: 'BR CNPJ' },
	{ value: 'in_gst', label: 'IN GSTIN' },
	{ value: 'it_cf', label: 'IT Codice Fiscale' },
	{ value: 'it_piva', label: 'IT Partita IVA' },
	{ value: 'es_nif', label: 'ES NIF' },
	{ value: 'ar_cuit', label: 'AR CUIT' },
	{ value: 'sa_vat', label: 'SA VAT' },
	{ value: 'ca_gst_hst', label: 'CA GST/HST' },
	{ value: 'us_ein', label: 'US EIN' },
	{ value: 'de_ust_id', label: 'DE USt-IdNr.' },
	{ value: 'de_steuernummer', label: 'DE Steuernummer' },
	{ value: 'de_hrb', label: 'DE HRB' },
	{ value: 'nl_kvk', label: 'NL KVK' },
	{ value: 'fr_siret', label: 'FR SIRET' },
	{ value: 'fr_siren', label: 'FR SIREN' },
	{ value: 'gb_company', label: 'GB Company number' },
	{ value: 'ch_uid', label: 'CH UID' },
	{ value: 'other', label: 'Other' },
];

const normalizeTaxId = (taxId: TaxId): TaxId => {
	const next: TaxId = {
		type: taxId.type || 'other',
		value: taxId.value || '',
	};

	if (taxId.country) {
		next.country = taxId.country;
	}
	if (taxId.label) {
		next.label = taxId.label;
	}

	return next;
};

let rowIdCounter = 0;
const generateRowId = (): string => `tax-id-row-${++rowIdCounter}`;

interface TaxIdRowProps {
	taxId: TaxId;
	labels: TaxIdsLabels;
	onChangeField: (patch: Partial<TaxId>) => void;
	onRemove: () => void;
}

/**
 * Single editable row. Inputs are controlled by local state so typing stays
 * smooth, with parent commits happening on blur.
 */
function TaxIdRow({ taxId, labels, onChangeField, onRemove }: TaxIdRowProps) {
	const [valueDraft, setValueDraft] = React.useState(taxId.value);
	const [countryDraft, setCountryDraft] = React.useState(taxId.country ?? '');
	const [labelDraft, setLabelDraft] = React.useState(taxId.label ?? '');

	React.useEffect(() => {
		setValueDraft(taxId.value);
	}, [taxId.value]);
	React.useEffect(() => {
		setCountryDraft(taxId.country ?? '');
	}, [taxId.country]);
	React.useEffect(() => {
		setLabelDraft(taxId.label ?? '');
	}, [taxId.label]);

	return (
		<tr className="wcpos:border-t wcpos:border-gray-100 wcpos:bg-white">
			<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
				<Select
					aria-label={labels.type}
					value={taxId.type}
					options={TYPE_OPTIONS}
					onChange={({ value: type }) => onChangeField({ type: String(type) })}
				/>
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
				<TextInput
					aria-label={labels.value}
					placeholder={labels.value}
					value={valueDraft}
					onChange={(event) => setValueDraft(event.target.value)}
					onBlur={() => {
						const trimmed = valueDraft.trim();
						if (trimmed !== taxId.value) {
							onChangeField({ value: trimmed });
						}
					}}
				/>
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
				<TextInput
					aria-label={labels.country}
					placeholder={labels.country}
					maxLength={2}
					value={countryDraft}
					onChange={(event) => setCountryDraft(event.target.value)}
					onBlur={() => {
						const trimmed = countryDraft.trim().toUpperCase();
						if (trimmed !== (taxId.country ?? '')) {
							onChangeField({ country: trimmed });
						}
					}}
				/>
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
				<TextInput
					aria-label={labels.label}
					placeholder={labels.label}
					value={labelDraft}
					onChange={(event) => setLabelDraft(event.target.value)}
					onBlur={() => {
						const trimmed = labelDraft.trim();
						if (trimmed !== (taxId.label ?? '')) {
							onChangeField({ label: trimmed });
						}
					}}
				/>
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle wcpos:text-right">
				<Button
					variant="ghost-destructive"
					onMouseDown={(event) => event.preventDefault()}
					onClick={onRemove}
				>
					{labels.remove}
				</Button>
			</td>
		</tr>
	);
}

/**
 * Editable list of tax IDs rendered as a compact table. Each row exposes
 * type / value / country / label fields plus a remove action.
 *
 * The "add row" affordance is exposed both as a built-in Add button
 * (rendered below the table) and via an imperative `addRow` handle so
 * consumers can also place an Add button in a section header.
 */
const TaxIdsField = React.forwardRef<TaxIdsFieldHandle, TaxIdsFieldProps>(
	function TaxIdsField({ value, onChange, labels }, ref) {
		const taxIds = React.useMemo(
			() => (Array.isArray(value) ? value.map(normalizeTaxId) : []),
			[value]
		);

		// Stable per-row IDs used as React keys so DOM nodes track the
		// underlying TaxId rather than its array position. IDs are local
		// to this component and never persisted.
		const [rowIds, setRowIds] = React.useState<string[]>(() =>
			taxIds.map(() => generateRowId())
		);
		const [draft, setDraft] = React.useState<{ id: string; taxId: TaxId } | null>(null);

		// Reconcile rowIds when the source array length changes from outside
		// (e.g., an external mutation we did not initiate here).
		React.useEffect(() => {
			setRowIds((prev) => {
				if (prev.length === taxIds.length) {
					return prev;
				}
				if (prev.length < taxIds.length) {
					const additions = Array.from(
						{ length: taxIds.length - prev.length },
						() => generateRowId()
					);
					return [...prev, ...additions];
				}
				return prev.slice(0, taxIds.length);
			});
		}, [taxIds.length]);

		const updateAt = React.useCallback(
			(index: number, patch: Partial<TaxId>) => {
				if (index >= taxIds.length && draft) {
					const nextTaxId = normalizeTaxId({
						...draft.taxId,
						...patch,
					});

					if (patch.value && nextTaxId.value) {
						setRowIds((prev) => [...prev, draft.id]);
						setDraft(null);
						onChange([...taxIds, nextTaxId]);
						return;
					}

					setDraft({ id: draft.id, taxId: nextTaxId });
					return;
				}

				const next = taxIds.map((taxId, currentIndex) => {
					if (currentIndex !== index) {
						return taxId;
					}

					return normalizeTaxId({
						...taxId,
						...patch,
					});
				});
				onChange(next);
			},
			[draft, onChange, taxIds]
		);

		const removeAt = React.useCallback(
			(index: number) => {
				if (index >= taxIds.length) {
					setDraft(null);
					return;
				}

				setRowIds((prev) => prev.filter((_, currentIndex) => currentIndex !== index));
				onChange(taxIds.filter((_, currentIndex) => currentIndex !== index));
			},
			[onChange, taxIds]
		);

		const addRow = React.useCallback(() => {
			setDraft((current) =>
				current ?? { id: generateRowId(), taxId: { type: 'other', value: '' } }
			);
		}, []);

		React.useImperativeHandle(ref, () => ({ addRow }), [addRow]);

		const displayRows = React.useMemo(() => {
			const rows = taxIds.map((taxId, idx) => ({
				id: rowIds[idx] ?? `tax-id-row-fallback-${idx}`,
				taxId,
			}));
			if (draft) {
				rows.push({ id: draft.id, taxId: draft.taxId });
			}
			return rows;
		}, [taxIds, rowIds, draft]);

		if (displayRows.length === 0) {
			return (
				<div className="wcpos:space-y-3">
					<p className="wcpos:rounded-md wcpos:border wcpos:border-dashed wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:px-3 wcpos:py-4 wcpos:text-center wcpos:text-sm wcpos:text-gray-500">
						{labels.empty}
					</p>
					<Button variant="outline" onClick={addRow}>
						{labels.add}
					</Button>
				</div>
			);
		}

		return (
			<div className="wcpos:space-y-3">
				<div className="wcpos:overflow-x-auto wcpos:rounded-md wcpos:border wcpos:border-gray-200">
					<table className="wcpos:w-full wcpos:text-sm">
						<thead>
							<tr className="wcpos:bg-gray-50 wcpos:text-left wcpos:text-xs wcpos:uppercase wcpos:tracking-wide wcpos:text-gray-500">
								<th
									className="wcpos:px-3 wcpos:py-2 wcpos:font-medium"
									style={{ width: '11rem' }}
								>
									{labels.type}
								</th>
								<th className="wcpos:px-3 wcpos:py-2 wcpos:font-medium">{labels.value}</th>
								<th
									className="wcpos:px-3 wcpos:py-2 wcpos:font-medium"
									style={{ width: '6rem' }}
								>
									{labels.country}
								</th>
								<th className="wcpos:px-3 wcpos:py-2 wcpos:font-medium">{labels.label}</th>
								<th
									className="wcpos:px-3 wcpos:py-2 wcpos:font-medium wcpos:text-right"
									style={{ width: '1%' }}
								>
									<span className="wcpos:sr-only">{labels.remove}</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{displayRows.map((row, index) => (
								<TaxIdRow
									key={row.id}
									taxId={row.taxId}
									labels={labels}
									onChangeField={(patch) => updateAt(index, patch)}
									onRemove={() => removeAt(index)}
								/>
							))}
						</tbody>
					</table>
				</div>
				<Button variant="outline" onClick={addRow}>
					{labels.add}
				</Button>
			</div>
		);
	}
);

export default TaxIdsField;
