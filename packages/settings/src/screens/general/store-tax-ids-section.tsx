import * as React from 'react';

import { Callout, TaxIdsField, type TaxId } from '@wcpos/ui';

import { FormSection } from '../../components/form';
import { t } from '../../translations';

const STORE_TAX_IDS_DOCS_URL = 'https://wcpos.com/docs/store-tax-ids';

export interface StoreTaxIdsSectionProps {
	value: TaxId[] | null | undefined;
	onChange: (value: TaxId[]) => void;
}

export function StoreTaxIdsSection({ value, onChange }: StoreTaxIdsSectionProps) {
	const description = (
		<>
			{t('settings.store_tax_ids_section_description')}{' '}
			<a
				href={STORE_TAX_IDS_DOCS_URL}
				target="_blank"
				rel="noreferrer noopener"
				className="wcpos:text-wp-admin-theme-color wcpos:underline"
			>
				{t('settings.store_tax_ids_learn_more')}
			</a>
		</>
	);

	return (
		<FormSection
			title={t('settings.store_tax_ids_section_title')}
			description={description}
			divider
		>
			<Callout
				status="info"
				title={t('settings.store_tax_ids_callout_title')}
				className="wcpos:mb-4"
			>
				{t('settings.store_tax_ids_tip')}
			</Callout>
			<TaxIdsField
				value={value}
				onChange={onChange}
				labels={{
					add: t('settings.store_tax_ids_add'),
					type: t('settings.store_tax_ids_type'),
					value: t('settings.store_tax_ids_value'),
					country: t('settings.store_tax_ids_country'),
					countryPlaceholder: t('settings.store_tax_ids_country_placeholder'),
					countrySearchPlaceholder: t('settings.store_tax_ids_country_search'),
					countryNoResults: t('settings.store_tax_ids_country_no_results'),
					countryClear: t('settings.store_tax_ids_country_none'),
					label: t('settings.store_tax_ids_label'),
					remove: t('settings.store_tax_ids_remove'),
					empty: t('settings.store_tax_ids_empty'),
				}}
			/>
		</FormSection>
	);
}
