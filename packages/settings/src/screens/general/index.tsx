import * as React from 'react';

import { isString, isNumber } from 'lodash';

import { PrivacyInfoModal } from '@wcpos/consent';
import { Button, Callout } from '@wcpos/ui';

import BarcodeSelect from './barcode-select';
import { TaxIdsSection } from './tax-ids-section';
import UserSelect from './user-select';
import { FormRow, FormSection } from '../../components/form';
import Label from '../../components/label';
import { Skeleton } from '../../components/skeleton';
import TaxIdsField, {
	type TaxId,
	type TaxIdsFieldHandle,
} from '../../components/tax-ids-field';
import { Toggle, Checkbox, TextInput, TextArea } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

const STORE_TAX_IDS_DOCS_URL = 'https://wcpos.com/docs/store-tax-ids';

export interface StoreDefaults {
	store_name: string;
	store_phone: string;
	store_email: string;
	policies_and_conditions: string;
}

export interface GeneralSettingsProps {
	pos_only_products: boolean;
	decimal_qty: boolean;
	force_ssl: boolean;
	generate_username: boolean;
	default_customer: number;
	default_customer_is_cashier: boolean;
	barcode_field: string;
	restore_stock_on_delete: boolean;
	tracking_consent: 'undecided' | 'allowed' | 'denied';
	store_name: string;
	store_phone: string;
	store_email: string;
	policies_and_conditions: string;
	store_tax_ids: TaxId[];
	store_defaults: StoreDefaults;
}

function General() {
	const { data, mutate } = useSettingsApi('general');
	const [privacyInfoOpen, setPrivacyInfoOpen] = React.useState(false);
	const taxIdsFieldRef = React.useRef<TaxIdsFieldHandle>(null);

	const storeDefaults: StoreDefaults = {
		store_name: '',
		store_phone: '',
		store_email: '',
		policies_and_conditions: '',
		...(data?.store_defaults ?? {}),
	};

	const storeTaxIdsDescription = (
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
		<>
			<FormSection
				title={t('settings.store_details_section_title')}
				description={t('settings.store_details_section_description')}
				divider
			>
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
						value={isString(data?.policies_and_conditions) ? data.policies_and_conditions : ''}
						placeholder={storeDefaults.policies_and_conditions}
						onChange={(event) => mutate({ policies_and_conditions: event.target.value })}
					/>
				</FormRow>
			</FormSection>
			<FormSection
				title={t('settings.store_tax_ids_section_title')}
				description={storeTaxIdsDescription}
				headerRight={
					<Button variant="outline" onClick={() => taxIdsFieldRef.current?.addRow()}>
						{t('settings.store_tax_ids_add')}
					</Button>
				}
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
					ref={taxIdsFieldRef}
					value={Array.isArray(data?.store_tax_ids) ? data.store_tax_ids : []}
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
			</FormSection>
			<FormSection title={t('settings.products_section_title')} divider>
				<FormRow>
					<Label tip={t('settings.pos_only_products_tip')}>
						<Toggle
							checked={!!data?.pos_only_products}
							onChange={(pos_only_products: boolean) => {
								mutate({ pos_only_products });
							}}
							label={t('settings.pos_only_products')}
						/>
					</Label>
				</FormRow>
				<FormRow>
					<Label tip={t('settings.decimal_quantities_tip')}>
						<Toggle
							checked={!!data?.decimal_qty}
							onChange={(decimal_qty: boolean) => {
								mutate({ decimal_qty });
							}}
							label={t('settings.decimal_quantities')}
						/>
					</Label>
				</FormRow>
				<FormRow>
					<Label tip={t('settings.restore_stock_on_delete_tip')}>
						<Toggle
							checked={!!data?.restore_stock_on_delete}
							onChange={(restore_stock_on_delete: boolean) => {
								mutate({ restore_stock_on_delete });
							}}
							label={t('settings.restore_stock_on_delete')}
						/>
					</Label>
				</FormRow>
				<FormRow label={t('settings.barcode_field')}>
					<Label tip={t('settings.barcode_field_tip')}>
						<React.Suspense fallback={<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />}>
							<BarcodeSelect
								selected={isString(data?.barcode_field) ? data?.barcode_field || '' : ''}
								onSelect={(value) => {
									mutate({ barcode_field: value || '_sku' });
								}}
							/>
						</React.Suspense>
					</Label>
				</FormRow>
			</FormSection>
			<FormSection title={t('settings.customers_section_title')} divider>
				<FormRow>
					<Toggle
						checked={!!data?.generate_username}
						onChange={(generate_username: boolean) => {
							mutate({ generate_username });
						}}
						label={t('settings.generate_username')}
					/>
				</FormRow>
				<FormRow label={t('settings.default_customer')}>
					<Label tip={t('settings.default_customer_tip')}>
						<React.Suspense fallback={<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />}>
							<UserSelect
								disabled={!!data?.default_customer_is_cashier}
								selected={isNumber(data?.default_customer) ? data?.default_customer || 0 : 0}
								onSelect={(value: number) => {
									mutate({ default_customer: value });
								}}
							/>
						</React.Suspense>
					</Label>
					<div className="wcpos:mt-2">
						<Checkbox
							label={t('settings.use_cashier_account')}
							checked={!!data?.default_customer_is_cashier}
							onChange={(e) => {
								mutate({ default_customer_is_cashier: e.target.checked });
							}}
						/>
					</div>
				</FormRow>
				<React.Suspense
					fallback={<Skeleton className="wcpos:h-32 wcpos:w-full wcpos:rounded-md wcpos:mt-4" />}
				>
					<TaxIdsSection />
				</React.Suspense>
			</FormSection>
			<FormSection title={t('settings.privacy_section_title')}>
				<FormRow>
					<Label>
						<Toggle
							checked={data?.tracking_consent === 'allowed'}
							onChange={(enabled: boolean) => {
								mutate({ tracking_consent: enabled ? 'allowed' : 'denied' });
							}}
							label={t('settings.allow_anonymous_usage_data')}
						/>
					</Label>
				</FormRow>
				<p className="wcpos:text-sm wcpos:text-gray-500">
					{t('settings.allow_anonymous_usage_data_tip')}{' '}
					<button
						type="button"
						onClick={() => setPrivacyInfoOpen(true)}
						className="wcpos:underline wcpos:text-wp-admin-theme-color wcpos:cursor-pointer wcpos:bg-transparent wcpos:border-0 wcpos:p-0"
					>
						{t('settings.privacy_learn_more')}
					</button>
				</p>
			</FormSection>
			<PrivacyInfoModal open={privacyInfoOpen} onClose={() => setPrivacyInfoOpen(false)} />
		</>
	);
}

export default General;
