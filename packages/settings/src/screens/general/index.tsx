import * as React from 'react';

import { isNumber, isString } from 'lodash';

import { PrivacyInfoModal } from '@wcpos/consent';
import { type TaxId } from '@wcpos/ui';

import BarcodeSelect from './barcode-select';
import { StoreDetailsBlock, type StoreDetailsBlockProps } from './store-details-block';
import { StorefrontReceiptSection } from './storefront-receipt-section';
import { syncConsent } from '../../lib/analytics';
import { TaxIdsSection } from './tax-ids-section';
import UserSelect from './user-select';
import { FormRow, FormSection } from '../../components/form';
import Label from '../../components/label';
import { Skeleton } from '../../components/skeleton';
import { Toggle, Checkbox } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

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
	storefront_receipt_enabled: boolean;
	storefront_receipt_template: string;
	tracking_consent: 'undecided' | 'allowed' | 'denied';
	store_name: string;
	store_phone: string;
	store_email: string;
	policies_and_conditions: string;
	store_tax_ids: TaxId[];
	store_defaults: StoreDefaults;
}

/**
 * Mirror the server's wp_validate_boolean() so a successfully loaded but
 * legacy-persisted force_ssl value (e.g. the string "false") normalizes to
 * the same boolean the POS uses server-side.
 */
function validateBoolean(value: unknown): boolean {
	if (typeof value === 'boolean') {
		return value;
	}
	if (typeof value === 'string') {
		const normalized = value.trim().toLowerCase();
		return normalized !== '' && normalized !== 'false' && normalized !== '0';
	}
	return Boolean(value);
}

/**
 * Pro registers `general.store_details_block` to replace the default block
 * (e.g. swap in a stores list when one or more stores have been created).
 */
function getStoreDetailsBlockOverride(): React.ComponentType<StoreDetailsBlockProps> | null {
	const component = (window as any)?.wcpos?.settings?.getComponent?.('general.store_details_block');
	// Accept any non-null value: plain function components, React.memo()
	// and React.forwardRef() wrappers (both runtime objects with $$typeof),
	// and React.lazy() wrappers.
	return component ? (component as React.ComponentType<StoreDetailsBlockProps>) : null;
}

function General() {
	const { data, mutate } = useSettingsApi('general');
	const [privacyInfoOpen, setPrivacyInfoOpen] = React.useState(false);

	// The settings query resolves to an error object (code + message) instead
	// of throwing, so a failed request never carries force_ssl. Treat that as
	// "not loaded" and fall back to the server default; any other successful
	// response (including a legacy string force_ssl) counts as loaded.
	const settingsLoaded = Boolean(data) && !(data?.code && data?.message);

	const storeDefaults: StoreDefaults = {
		store_name: '',
		store_phone: '',
		store_email: '',
		policies_and_conditions: '',
		...(data?.store_defaults ?? {}),
	};

	// The pro override can't be resolved at module scope (it registers on
	// `window.wcpos` after this module loads), so look it up once and memoize —
	// the identity is stable, no component is created per render.
	const ResolvedStoreDetailsBlock = React.useMemo(
		() => getStoreDetailsBlockOverride() ?? StoreDetailsBlock,
		[]
	);

	return (
		<>
			{/* eslint-disable-next-line react-hooks/static-components -- resolved from the pro registry; identity is stable (memoized above) */}
			<ResolvedStoreDetailsBlock data={data} mutate={mutate} storeDefaults={storeDefaults} />
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
						<React.Suspense
							fallback={<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />}
						>
							<BarcodeSelect
								selected={isString(data?.barcode_field) ? data?.barcode_field || '' : ''}
								onSelect={(value) => {
									mutate({ barcode_field: value || '_global_unique_id' });
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
						<React.Suspense
							fallback={<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />}
						>
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
				<StorefrontReceiptSection
					enabled={!!data?.storefront_receipt_enabled}
					template={
						isString(data?.storefront_receipt_template) ? data?.storefront_receipt_template : ''
					}
					onChange={(patch) => {
						mutate(patch);
					}}
				/>
				<React.Suspense
					fallback={<Skeleton className="wcpos:h-32 wcpos:w-full wcpos:rounded-md wcpos:mt-4" />}
				>
					<TaxIdsSection />
				</React.Suspense>
			</FormSection>
			<FormSection title={t('settings.privacy_section_title')} divider>
				<FormRow>
					<Label>
						<Toggle
							checked={data?.tracking_consent === 'allowed'}
							onChange={(enabled: boolean) => {
								const choice = enabled ? 'allowed' : 'denied';

								// Apply it to the live client first. The page does not
								// reload on save, so a client initialised while consent
								// was allowed would otherwise keep capturing for the
								// rest of the session after the user opted out.
								syncConsent(choice);
								mutate({ tracking_consent: choice });
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
			<FormSection title={t('settings.advanced_section_title')} divider>
				<FormRow>
					<Label tip={t('settings.force_ssl_tip')}>
						{/* force_ssl defaults to true server-side. Show that default and block
						    clicks until settings load (a fetch error resolves to an object with
						    no force_ssl), then normalize the loaded value the way the server does
						    so a legacy string "false" stays editable rather than looking unset. */}
						<Toggle
							checked={settingsLoaded ? validateBoolean(data?.force_ssl) : true}
							disabled={!settingsLoaded}
							onChange={(force_ssl: boolean) => {
								mutate({ force_ssl });
							}}
							label={t('settings.force_ssl')}
						/>
					</Label>
				</FormRow>
			</FormSection>
			<PrivacyInfoModal open={privacyInfoOpen} onClose={() => setPrivacyInfoOpen(false)} />
		</>
	);
}

export default General;
