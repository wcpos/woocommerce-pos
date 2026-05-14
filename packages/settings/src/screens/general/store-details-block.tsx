import * as React from 'react';

import { isString } from 'lodash';

import { Callout, TaxIdsField, type TaxId } from '@wcpos/ui';

import { FormRow, FormSection } from '../../components/form';
import { TextInput, TextArea } from '../../components/ui';
import { captureUpgradeCtaClicked, captureUpgradeCtaViewed } from '../../lib/analytics';
import { t } from '../../translations';

import type { StoreDefaults } from './index';

const UPGRADE_URL = 'https://wcpos.com/pro';
const UPGRADE_PLACEMENT = 'general_store_details';
const STORE_TAX_IDS_DOCS_URL = 'https://docs.wcpos.com/settings/wp-admin/store-tax-ids';

export interface StoreDetailsBlockProps {
	data?: {
		store_name?: unknown;
		store_phone?: unknown;
		store_email?: unknown;
		policies_and_conditions?: unknown;
		store_tax_ids?: unknown;
	};
	mutate: (data: Record<string, unknown>) => void;
	storeDefaults: StoreDefaults;
}

/**
 * Default Store details block. Pro replaces this via the
 * `general.store_details_block` registry slot when stores exist.
 */
export function StoreDetailsBlock({ data, mutate, storeDefaults }: StoreDetailsBlockProps) {
	React.useEffect(() => {
		captureUpgradeCtaViewed(UPGRADE_PLACEMENT);
	}, []);

	return (
		<FormSection
			title={t('settings.store_details_section_title')}
			description={t('settings.store_details_section_description')}
			divider
		>
			<Callout
				status="info"
				title={t('settings.store_details_upgrade_callout_title')}
				className="wcpos:mt-4"
			>
				<p className="wcpos:m-0">
					{t('settings.store_details_upgrade_to_pro')}
				</p>
				<p className="wcpos:mt-2 wcpos:mb-0">
					<a
						href={UPGRADE_URL}
						target="_blank"
						rel="noreferrer noopener"
						onClick={() => captureUpgradeCtaClicked(UPGRADE_PLACEMENT, UPGRADE_URL)}
					>
						{t('common.upgrade_to_pro')}
					</a>
				</p>
			</Callout>
			<FormRow label={t('settings.store_name')}>
				<TextInput
					value={isString(data?.store_name) ? data.store_name : ''}
					placeholder={storeDefaults.store_name}
					onChange={(event) => mutate({ store_name: event.target.value })}
				/>
			</FormRow>
			<FormRow label={t('settings.store_phone')}>
				<TextInput
					value={isString(data?.store_phone) ? data.store_phone : ''}
					placeholder={storeDefaults.store_phone}
					onChange={(event) => mutate({ store_phone: event.target.value })}
				/>
			</FormRow>
			<FormRow label={t('settings.store_email')}>
				<TextInput
					type="email"
					value={isString(data?.store_email) ? data.store_email : ''}
					placeholder={storeDefaults.store_email}
					onChange={(event) => mutate({ store_email: event.target.value })}
				/>
			</FormRow>
			<FormRow
				label={t('settings.refund_returns_policy')}
				description={t('settings.refund_returns_policy_tip')}
			>
				<TextArea
					rows={4}
					value={
						isString(data?.policies_and_conditions) ? data.policies_and_conditions : ''
					}
					placeholder={storeDefaults.policies_and_conditions}
					onChange={(event) => mutate({ policies_and_conditions: event.target.value })}
				/>
			</FormRow>
			<div className="wcpos:mt-4">
				<h4 className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0 wcpos:mb-1">
					{t('settings.store_tax_ids_section_title')}
				</h4>
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-3">
					{t('settings.store_tax_ids_section_description')}{' '}
					<a
						href={STORE_TAX_IDS_DOCS_URL}
						target="_blank"
						rel="noreferrer noopener"
						className="wcpos:text-wp-admin-theme-color wcpos:underline"
					>
						{t('settings.store_tax_ids_learn_more')}
					</a>
				</p>
				<TaxIdsField
					value={Array.isArray(data?.store_tax_ids) ? (data.store_tax_ids as TaxId[]) : null}
					onChange={(store_tax_ids) => {
						mutate({ store_tax_ids });
					}}
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
			</div>
		</FormSection>
	);
}
