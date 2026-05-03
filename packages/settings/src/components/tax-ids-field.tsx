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

interface TaxIdsFieldProps {
	value: TaxId[];
	onChange: (value: TaxId[]) => void;
	labels: {
		add: string;
		type: string;
		value: string;
		country: string;
		label: string;
		remove: string;
		empty: string;
	};
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
		const [draftTaxId, setDraftTaxId] = React.useState<TaxId | null>(null);
		const taxIds = React.useMemo(
			() => (Array.isArray(value) ? value.map(normalizeTaxId) : []),
			[value]
		);
		const displayTaxIds = React.useMemo(
			() => (draftTaxId ? [...taxIds, draftTaxId] : taxIds),
			[draftTaxId, taxIds]
		);

		const updateAt = React.useCallback(
			(index: number, patch: Partial<TaxId>) => {
				if (index >= taxIds.length && draftTaxId) {
					const nextTaxId = normalizeTaxId({
						...draftTaxId,
						...patch,
					});

					if (patch.value && nextTaxId.value) {
						setDraftTaxId(null);
						onChange([...taxIds, nextTaxId]);
						return;
					}

					setDraftTaxId(nextTaxId);
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
			[draftTaxId, onChange, taxIds]
		);

		const removeAt = React.useCallback(
			(index: number) => {
				if (index >= taxIds.length) {
					setDraftTaxId(null);
					return;
				}

				onChange(taxIds.filter((_, currentIndex) => currentIndex !== index));
			},
			[onChange, taxIds]
		);

		const addRow = React.useCallback(() => {
			setDraftTaxId((current) => current ?? { type: 'other', value: '' });
		}, []);

		React.useImperativeHandle(ref, () => ({ addRow }), [addRow]);

		if (displayTaxIds.length === 0) {
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
							{displayTaxIds.map((taxId, index) => (
								<tr
									key={index}
									className="wcpos:border-t wcpos:border-gray-100 wcpos:bg-white"
								>
									<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
										<Select
											aria-label={labels.type}
											value={taxId.type}
											options={TYPE_OPTIONS}
											onChange={({ value: type }) =>
												updateAt(index, { type: String(type) })
											}
										/>
									</td>
									<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
										<TextInput
											aria-label={labels.value}
											placeholder={labels.value}
											defaultValue={taxId.value}
											onBlur={(event) =>
												updateAt(index, { value: event.target.value.trim() })
											}
										/>
									</td>
									<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
										<TextInput
											aria-label={labels.country}
											placeholder={labels.country}
											maxLength={2}
											defaultValue={taxId.country ?? ''}
											onBlur={(event) =>
												updateAt(index, {
													country: event.target.value.trim().toUpperCase(),
												})
											}
										/>
									</td>
									<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle">
										<TextInput
											aria-label={labels.label}
											placeholder={labels.label}
											defaultValue={taxId.label ?? ''}
											onBlur={(event) =>
												updateAt(index, { label: event.target.value.trim() })
											}
										/>
									</td>
									<td className="wcpos:px-3 wcpos:py-2 wcpos:align-middle wcpos:text-right">
										<Button variant="ghost-destructive" onClick={() => removeAt(index)}>
											{labels.remove}
										</Button>
									</td>
								</tr>
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
