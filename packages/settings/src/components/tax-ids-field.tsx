import * as React from 'react';

import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableHeaderRow,
	TableRow,
} from '@wcpos/ui';

import { Button, CountrySelect, Select, TextInput, type OptionProps } from './ui';

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
	countryPlaceholder?: string;
	countrySearchPlaceholder?: string;
	countryNoResults?: string;
	countryClear?: string;
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

const TAX_ID_TYPE_EXAMPLES: Record<string, string> = {
	eu_vat: 'DE123456789',
	gb_vat: 'GB123456789',
	au_abn: '12345678901',
	br_cpf: '123.456.789-09',
	br_cnpj: '12.345.678/0001-95',
	in_gst: '22AAAAA0000A1Z5',
	it_cf: 'RSSMRA80A01H501U',
	it_piva: '12345678901',
	es_nif: 'B12345674',
	ar_cuit: '20-12345678-3',
	sa_vat: '300123456700003',
	ca_gst_hst: '123456789RT0001',
	us_ein: '12-3456789',
	de_ust_id: 'DE123456789',
	de_steuernummer: '12/345/67890',
	de_hrb: 'HRB 12345',
	nl_kvk: '12345678',
	fr_siret: '12345678901234',
	fr_siren: '123456789',
	gb_company: '12345678',
	ch_uid: 'CHE-123.456.789',
};

// Country → most-common tax/business-ID printed on receipts. EU countries
// without a country-specific entry fall through to `eu_vat`.
const COUNTRY_TO_TAX_ID_TYPE: Record<string, string> = {
	AR: 'ar_cuit',
	AU: 'au_abn',
	BR: 'br_cnpj',
	CA: 'ca_gst_hst',
	CH: 'ch_uid',
	DE: 'de_ust_id',
	ES: 'es_nif',
	FR: 'fr_siret',
	GB: 'gb_vat',
	IN: 'in_gst',
	IT: 'it_piva',
	NL: 'nl_kvk',
	SA: 'sa_vat',
	US: 'us_ein',
};

const TAX_ID_TYPE_TO_COUNTRY: Record<string, string> = {
	ar_cuit: 'AR',
	au_abn: 'AU',
	br_cpf: 'BR',
	br_cnpj: 'BR',
	ca_gst_hst: 'CA',
	ch_uid: 'CH',
	de_hrb: 'DE',
	de_steuernummer: 'DE',
	de_ust_id: 'DE',
	es_nif: 'ES',
	fr_siren: 'FR',
	fr_siret: 'FR',
	gb_company: 'GB',
	gb_vat: 'GB',
	in_gst: 'IN',
	it_cf: 'IT',
	it_piva: 'IT',
	nl_kvk: 'NL',
	sa_vat: 'SA',
	us_ein: 'US',
};

const EU_VAT_COUNTRIES = new Set([
	'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR',
	'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
	'SE', 'SI', 'SK',
]);

function defaultTaxIdTypeFor(country: string | undefined): string {
	if (!country) return 'other';
	const cc = country.toUpperCase();
	if (COUNTRY_TO_TAX_ID_TYPE[cc]) return COUNTRY_TO_TAX_ID_TYPE[cc];
	if (EU_VAT_COUNTRIES.has(cc)) return 'eu_vat';
	return 'other';
}

function defaultCountryForTaxIdType(type: string, currentCountry?: string): string | undefined {
	if (type === 'eu_vat') {
		const current = currentCountry?.toUpperCase();
		if (current && EU_VAT_COUNTRIES.has(current)) {
			return current;
		}

		const storeCountry = window?.wcpos?.settings?.storeCountry?.toUpperCase();
		if (storeCountry && EU_VAT_COUNTRIES.has(storeCountry)) {
			return storeCountry;
		}

		return undefined;
	}

	return TAX_ID_TYPE_TO_COUNTRY[type];
}

function placeholderForTaxIdType(type: string, fallback: string): string {
	return TAX_ID_TYPE_EXAMPLES[type] ?? fallback;
}

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

const syncTaxIdPatch = (current: TaxId, patch: Partial<TaxId>): Partial<TaxId> => {
	if (typeof patch.country === 'string' && patch.country) {
		return {
			...patch,
			type: defaultTaxIdTypeFor(patch.country),
		};
	}

	if (typeof patch.type === 'string') {
		const country = defaultCountryForTaxIdType(patch.type, current.country);
		if (country) {
			return {
				...patch,
				country,
			};
		}

		if (patch.type === 'eu_vat') {
			return {
				...patch,
				country: undefined,
			};
		}
	}

	return patch;
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
	const [labelDraft, setLabelDraft] = React.useState(taxId.label ?? '');
	const countries = window?.wcpos?.settings?.countries ?? {};

	React.useEffect(() => {
		setValueDraft(taxId.value);
	}, [taxId.value]);
	React.useEffect(() => {
		setLabelDraft(taxId.label ?? '');
	}, [taxId.label]);

	return (
		<TableRow>
			<TableCell>
				<Select
					aria-label={labels.type}
					value={taxId.type}
					options={TYPE_OPTIONS}
					onChange={({ value: type }) => onChangeField({ type: String(type) })}
				/>
			</TableCell>
			<TableCell>
				<TextInput
					aria-label={labels.value}
					placeholder={placeholderForTaxIdType(taxId.type, labels.value)}
					value={valueDraft}
					onChange={(event) => setValueDraft(event.target.value)}
					onBlur={() => {
						const trimmed = valueDraft.trim();
						if (trimmed !== taxId.value) {
							onChangeField({ value: trimmed });
						}
					}}
				/>
			</TableCell>
			<TableCell>
				<CountrySelect
					aria-label={labels.country}
					countries={countries}
					value={taxId.country ?? ''}
					onChange={(country) => onChangeField({ country })}
					placeholder={labels.countryPlaceholder ?? labels.country}
					searchPlaceholder={labels.countrySearchPlaceholder}
					noResultsLabel={labels.countryNoResults}
					clearable
					clearLabel={labels.countryClear}
				/>
			</TableCell>
			<TableCell>
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
			</TableCell>
			<TableCell className="wcpos:text-right">
				<Button
					variant="ghost-destructive"
					onMouseDown={(event) => event.preventDefault()}
					onClick={onRemove}
				>
					{labels.remove}
				</Button>
			</TableCell>
		</TableRow>
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
					const syncedPatch = syncTaxIdPatch(draft.taxId, patch);
					const nextTaxId = normalizeTaxId({
						...draft.taxId,
						...syncedPatch,
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

					const syncedPatch = syncTaxIdPatch(taxId, patch);
					return normalizeTaxId({
						...taxId,
						...syncedPatch,
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
			setDraft((current) => {
				if (current) return current;
				const storeCountry = window?.wcpos?.settings?.storeCountry;
				const taxId: TaxId = {
					type: defaultTaxIdTypeFor(storeCountry),
					value: '',
				};
				if (storeCountry) {
					taxId.country = storeCountry;
				}
				return { id: generateRowId(), taxId };
			});
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
				<Table>
					<TableHeader>
						<TableHeaderRow>
							<TableHead style={{ width: '11rem' }}>{labels.type}</TableHead>
							<TableHead>{labels.value}</TableHead>
							<TableHead style={{ width: '12rem' }}>{labels.country}</TableHead>
							<TableHead>{labels.label}</TableHead>
							<TableHead className="wcpos:text-right" style={{ width: '1%' }}>
								<span className="wcpos:sr-only">{labels.remove}</span>
							</TableHead>
						</TableHeaderRow>
					</TableHeader>
					<TableBody>
						{displayRows.map((row, index) => (
							<TaxIdRow
								key={row.id}
								taxId={row.taxId}
								labels={labels}
								onChangeField={(patch) => updateAt(index, patch)}
								onRemove={() => removeAt(index)}
							/>
						))}
					</TableBody>
				</Table>
				<Button variant="outline" onClick={addRow}>
					{labels.add}
				</Button>
			</div>
		);
	}
);

export default TaxIdsField;
