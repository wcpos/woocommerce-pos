import * as React from 'react';

import { Button, Select, TextInput, type OptionProps } from './ui';

export interface TaxId {
	type: string;
	value: string;
	country?: string;
	label?: string;
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
	{ value: 'de_ust_id', label: 'DE USt-IdNr.' },
	{ value: 'de_steuernummer', label: 'DE Steuernummer' },
	{ value: 'de_hrb', label: 'DE HRB' },
	{ value: 'it_piva', label: 'IT Partita IVA' },
	{ value: 'it_cf', label: 'IT Codice Fiscale' },
	{ value: 'es_nif', label: 'ES NIF' },
	{ value: 'fr_siret', label: 'FR SIRET' },
	{ value: 'fr_siren', label: 'FR SIREN' },
	{ value: 'nl_kvk', label: 'NL KVK' },
	{ value: 'gb_company', label: 'GB Company number' },
	{ value: 'ch_uid', label: 'CH UID' },
	{ value: 'au_abn', label: 'AU ABN' },
	{ value: 'au_acn', label: 'AU ACN' },
	{ value: 'ca_gst_hst', label: 'CA GST/HST' },
	{ value: 'us_ein', label: 'US EIN' },
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

function TaxIdsField({ value, onChange, labels }: TaxIdsFieldProps) {
	const taxIds = React.useMemo(() => (Array.isArray(value) ? value.map(normalizeTaxId) : []), [value]);

	const updateAt = React.useCallback(
		(index: number, patch: Partial<TaxId>) => {
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
		[onChange, taxIds]
	);

	const removeAt = React.useCallback(
		(index: number) => {
			onChange(taxIds.filter((_, currentIndex) => currentIndex !== index));
		},
		[onChange, taxIds]
	);

	return (
		<div className="wcpos:space-y-3">
			{taxIds.length === 0 && (
				<p className="wcpos:rounded-md wcpos:border wcpos:border-dashed wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-500">
					{labels.empty}
				</p>
			)}
			{taxIds.map((taxId, index) => (
				<div
					key={`${index}-${taxId.type}-${taxId.value}`}
					className="wcpos:rounded-lg wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:p-3 wcpos:shadow-sm"
				>
					<div className="wcpos:grid wcpos:grid-cols-1 wcpos:gap-2 wcpos:md:grid-cols-[minmax(150px,1fr)_minmax(180px,1.5fr)]">
						<Select
							aria-label={labels.type}
							value={taxId.type}
							options={TYPE_OPTIONS}
							onChange={({ value: type }) => updateAt(index, { type: String(type) })}
						/>
						<TextInput
							aria-label={labels.value}
							placeholder={labels.value}
							defaultValue={taxId.value}
							onBlur={(event) => updateAt(index, { value: event.target.value.trim() })}
						/>
						<TextInput
							aria-label={labels.country}
							placeholder={labels.country}
							maxLength={2}
							defaultValue={taxId.country ?? ''}
							onBlur={(event) =>
								updateAt(index, { country: event.target.value.trim().toUpperCase() })
							}
						/>
						<TextInput
							aria-label={labels.label}
							placeholder={labels.label}
							defaultValue={taxId.label ?? ''}
							onBlur={(event) => updateAt(index, { label: event.target.value.trim() })}
						/>
					</div>
					<div className="wcpos:mt-2 wcpos:flex wcpos:justify-end">
						<Button variant="ghost-destructive" onClick={() => removeAt(index)}>
							{labels.remove}
						</Button>
					</div>
				</div>
			))}
			<Button
				variant="outline"
				onClick={() => onChange([...taxIds, { type: 'other', value: '' }])}
			>
				{labels.add}
			</Button>
		</div>
	);
}

export default TaxIdsField;
