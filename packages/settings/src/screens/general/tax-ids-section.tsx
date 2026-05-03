import * as React from 'react';

import { useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import classNames from 'classnames';

import { TextInput } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

/**
 * Friendly labels for tax-ID types. Matches Tax_Id_Types constants on the
 * server. Keep in sync with includes/Services/Tax_Id_Types.php.
 *
 * Business-register types (de_ust_id, nl_kvk, etc.) are intentionally omitted
 * — the server filters them out of the detection endpoint, so they will never
 * appear in this UI.
 */
const TAX_ID_TYPE_LABELS: Record<string, string> = {
	eu_vat: 'EU VAT',
	gb_vat: 'GB VAT',
	sa_vat: 'SA VAT',
	au_abn: 'AU ABN',
	br_cpf: 'BR CPF',
	br_cnpj: 'BR CNPJ',
	in_gst: 'IN GSTIN',
	it_cf: 'IT Codice Fiscale',
	it_piva: 'IT Partita IVA',
	es_nif: 'ES NIF',
	ar_cuit: 'AR CUIT',
	ca_gst_hst: 'CA GST/HST',
	us_ein: 'US EIN',
	other: 'Other',
};

const PLUGIN_LABELS: Record<string, string> = {
	wc_eu_vat_number: 'WooCommerce EU VAT Number',
	aelia_eu_vat: 'Aelia EU VAT Assistant',
	wpfactory_eu_vat: 'WPFactory EU/UK VAT Manager',
	germanized: 'WooCommerce Germanized',
	br_market: 'Brazilian Market on WooCommerce',
	es_nif: 'NIF/CIF Spain for WooCommerce',
};

interface TaxIdsSettings {
	write_map: Record<string, string>;
}

interface TaxIdsDetection {
	plugins: string[];
	default_write_map: Record<string, string>;
	composed_write_map: Record<string, string>;
	types: string[];
}

const DOCS_URL = 'https://wcpos.com/docs/tax-ids';

type Source = 'plugin' | 'default' | 'override';

/**
 * Resolve where the in-use field name for a tax-ID type came from.
 *
 * Priority: user override > detected plugin (composed) > built-in default.
 * "Plugin" is determined by composed differing from default — i.e. a detector
 * claimed the row.
 */
function resolveSource(
	type: string,
	override: string | undefined,
	composed: string,
	defaultKey: string
): Source {
	if (override && override !== '') return 'override';
	if (composed && composed !== defaultKey) return 'plugin';
	return 'default';
}

function effectiveKey(
	override: string | undefined,
	composed: string,
	defaultKey: string
): string {
	if (override && override !== '') return override;
	return composed || defaultKey;
}

function SourceBadge({ source }: { source: Source }) {
	const styles: Record<Source, string> = {
		plugin: 'wcpos:bg-green-50 wcpos:text-green-800 wcpos:border-green-200',
		default: 'wcpos:bg-gray-100 wcpos:text-gray-700 wcpos:border-gray-200',
		override: 'wcpos:bg-amber-50 wcpos:text-amber-800 wcpos:border-amber-200',
	};
	const labels: Record<Source, string> = {
		plugin: t('tax_ids.source_plugin'),
		default: t('tax_ids.source_built_in'),
		override: t('tax_ids.source_custom'),
	};
	return (
		<span
			className={classNames(
				'wcpos:inline-flex wcpos:items-center wcpos:px-2 wcpos:py-0.5 wcpos:rounded wcpos:text-[11px] wcpos:font-medium wcpos:uppercase wcpos:tracking-wide wcpos:border',
				styles[source]
			)}
		>
			{labels[source]}
		</span>
	);
}

function DocsLink() {
	return (
		<a
			href={DOCS_URL}
			target="_blank"
			rel="noreferrer noopener"
			className="wcpos:text-wp-admin-theme-color wcpos:underline"
		>
			{t('tax_ids.learn_more')}
		</a>
	);
}

interface DetectionBannerProps {
	plugins: string[];
	overrideCount: number;
}

function DetectionBanner({ plugins, overrideCount }: DetectionBannerProps) {
	const detected = plugins.length > 0;
	const detectedNames = plugins.map((id) => PLUGIN_LABELS[id] ?? id);

	return (
		<div
			className={classNames(
				'wcpos:p-3 wcpos:rounded wcpos:mb-3 wcpos:border-l-[3px]',
				detected
					? 'wcpos:bg-blue-50 wcpos:border-l-wp-admin-theme-color'
					: 'wcpos:bg-gray-50 wcpos:border-l-gray-400'
			)}
		>
			<div className="wcpos:flex wcpos:items-start wcpos:gap-2">
				<span
					className={classNames(
						'wcpos:inline-flex wcpos:items-center wcpos:px-2 wcpos:py-0.5 wcpos:rounded wcpos:text-[11px] wcpos:font-medium wcpos:uppercase wcpos:tracking-wide wcpos:border wcpos:mt-0.5 wcpos:flex-shrink-0',
						detected
							? 'wcpos:bg-green-50 wcpos:text-green-800 wcpos:border-green-200'
							: 'wcpos:bg-gray-100 wcpos:text-gray-700 wcpos:border-gray-200'
					)}
				>
					{detected ? `✓ ${t('tax_ids.banner_auto_detected')}` : t('tax_ids.banner_no_plugin')}
				</span>
				<div className="wcpos:flex-1 wcpos:text-sm">
					{detected ? (
						<>
							<div className="wcpos:font-medium">
								{detectedNames.join(', ')}{' '}
								<span className="wcpos:font-normal">{t('tax_ids.banner_is_installed')}</span>
							</div>
							<div className="wcpos:text-xs wcpos:text-gray-600 wcpos:mt-1">
								{t('tax_ids.banner_detected_explainer')}
								{overrideCount > 0 && (
									<>
										{' '}
										{t('tax_ids.banner_overrides_active', { count: overrideCount })}
									</>
								)}
							</div>
						</>
					) : (
						<>
							<div className="wcpos:font-medium">{t('tax_ids.banner_no_plugin_title')}</div>
							<div className="wcpos:text-xs wcpos:text-gray-600 wcpos:mt-1">
								{t('tax_ids.banner_no_plugin_explainer')}
							</div>
						</>
					)}
				</div>
			</div>
		</div>
	);
}

interface OverrideRowProps {
	type: string;
	composed: string;
	defaultKey: string;
	override: string | undefined;
	suggestionsId: string;
	onCommit: (type: string, value: string, defaultKey: string) => void;
}

function OverrideRow({
	type,
	composed,
	defaultKey,
	override,
	suggestionsId,
	onCommit,
}: OverrideRowProps) {
	const inUse = effectiveKey(override, composed, defaultKey);
	const source = resolveSource(type, override, composed, defaultKey);

	return (
		<tr
			className={classNames(
				'wcpos:border-t wcpos:border-gray-100',
				source === 'override' && 'wcpos:bg-amber-50/40'
			)}
		>
			<td className="wcpos:py-2 wcpos:pr-3 wcpos:font-medium wcpos:text-gray-900">
				{TAX_ID_TYPE_LABELS[type] ?? type}
			</td>
			<td className="wcpos:py-2 wcpos:pr-3 wcpos:font-mono wcpos:text-xs wcpos:text-gray-600">
				{inUse}
			</td>
			<td className="wcpos:py-2 wcpos:pr-3">
				<SourceBadge source={source} />
			</td>
			<td className="wcpos:py-2">
				<TextInput
					list={suggestionsId}
					defaultValue={override ?? ''}
					placeholder={t('tax_ids.write_map_use_default')}
					className="wcpos:font-mono wcpos:text-xs"
					onBlur={(e) => onCommit(type, e.target.value.trim(), defaultKey)}
				/>
			</td>
		</tr>
	);
}

export function TaxIdsSection() {
	const { data, mutate } = useSettingsApi('tax_ids') as {
		data: TaxIdsSettings;
		mutate: (next: Partial<TaxIdsSettings>) => void;
	};
	const writeMap = data?.write_map ?? {};

	const { data: detection } = useSuspenseQuery({
		queryKey: ['tax_ids_detection'],
		queryFn: async () => {
			return apiFetch<TaxIdsDetection>({
				path: 'wcpos/v1/settings/tax_ids/detection?wcpos=1',
				method: 'GET',
			});
		},
	});

	const overrideCount = Object.keys(writeMap).filter((key) => writeMap[key]).length;

	const handleCommit = React.useCallback(
		(type: string, value: string, defaultKey: string) => {
			const current = writeMap[type] ?? '';
			if (value === current) return;
			const next = { ...writeMap };
			// Empty input or matching default = drop the override so the
			// auto-detected map wins.
			if (value === '' || value === defaultKey) {
				delete next[type];
			} else {
				next[type] = value;
			}
			mutate({ write_map: next });
		},
		[mutate, writeMap]
	);

	// Datalist suggestions are the unique set of default keys + composed keys —
	// gives users a typeahead of "what other plugins use" without being binding.
	const suggestions = React.useMemo(() => {
		const keys = new Set<string>();
		Object.values(detection.default_write_map).forEach((k) => k && keys.add(k));
		Object.values(detection.composed_write_map).forEach((k) => k && keys.add(k));
		return Array.from(keys).sort();
	}, [detection.composed_write_map, detection.default_write_map]);

	const suggestionsId = 'wcpos-tax-id-meta-keys';

	return (
		<div className="wcpos:mt-4 wcpos:pt-4 wcpos:border-t wcpos:border-gray-200">
			<h4 className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0 wcpos:mb-1">
				{t('tax_ids.section_title')}
			</h4>
			<p className="wcpos:text-xs wcpos:text-gray-500 wcpos:mb-3">
				{t('tax_ids.section_intro')} <DocsLink />
			</p>

			<DetectionBanner plugins={detection.plugins} overrideCount={overrideCount} />

			<details className="wcpos:border wcpos:border-gray-200 wcpos:rounded">
				<summary
					className={classNames(
						'wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:cursor-pointer wcpos:list-none',
						'wcpos:flex wcpos:justify-between wcpos:items-center',
						'wcpos:hover:bg-gray-50'
					)}
				>
					<span>
						<strong>{t('tax_ids.advanced_title')}</strong>{' '}
						<span className="wcpos:text-xs wcpos:text-gray-500">
							{t('tax_ids.advanced_subtitle')}
						</span>
					</span>
					{overrideCount > 0 && (
						<span className="wcpos:text-xs wcpos:text-gray-500">
							{t('tax_ids.advanced_override_count', { count: overrideCount })}
						</span>
					)}
				</summary>
				<div className="wcpos:border-t wcpos:border-gray-200 wcpos:px-3 wcpos:py-3">
					<p className="wcpos:text-xs wcpos:text-gray-500 wcpos:mb-3">
						{t('tax_ids.advanced_intro')}
					</p>
					<div className="wcpos:overflow-x-auto">
						<table className="wcpos:w-full wcpos:text-sm">
							<thead>
								<tr className="wcpos:text-left wcpos:text-gray-500 wcpos:text-xs wcpos:uppercase wcpos:tracking-wide">
									<th className="wcpos:py-2 wcpos:pr-3 wcpos:font-medium">
										{t('tax_ids.col_type')}
									</th>
									<th className="wcpos:py-2 wcpos:pr-3 wcpos:font-medium">
										{t('tax_ids.col_field')}
									</th>
									<th className="wcpos:py-2 wcpos:pr-3 wcpos:font-medium">
										{t('tax_ids.col_source')}
									</th>
									<th className="wcpos:py-2 wcpos:font-medium">
										{t('tax_ids.col_override')}
									</th>
								</tr>
							</thead>
							<tbody>
								{detection.types.map((type) => (
									<OverrideRow
										key={type}
										type={type}
										composed={detection.composed_write_map[type] ?? ''}
										defaultKey={detection.default_write_map[type] ?? ''}
										override={writeMap[type]}
										suggestionsId={suggestionsId}
										onCommit={handleCommit}
									/>
								))}
							</tbody>
						</table>
					</div>
					<datalist id={suggestionsId}>
						{suggestions.map((s) => (
							<option key={s} value={s} />
						))}
					</datalist>
					<div className="wcpos:mt-3 wcpos:text-xs wcpos:text-gray-500 wcpos:flex wcpos:flex-wrap wcpos:gap-x-3 wcpos:gap-y-1 wcpos:items-center">
						<span className="wcpos:flex wcpos:items-center wcpos:gap-1.5">
							<SourceBadge source="plugin" /> {t('tax_ids.legend_plugin')}
						</span>
						<span className="wcpos:flex wcpos:items-center wcpos:gap-1.5">
							<SourceBadge source="default" /> {t('tax_ids.legend_built_in')}
						</span>
						<span className="wcpos:flex wcpos:items-center wcpos:gap-1.5">
							<SourceBadge source="override" /> {t('tax_ids.legend_custom')}
						</span>
					</div>
				</div>
			</details>
		</div>
	);
}

export default TaxIdsSection;
