import * as React from 'react';

import { isString, isNumber } from 'lodash';

import BarcodeSelect from './barcode-select';
import PrivacyInfoModal from './privacy-info-modal';
import UserSelect from './user-select';
import { FormRow, FormSection } from '../../components/form';
import Label from '../../components/label';
import { Skeleton } from '../../components/skeleton';
import { Toggle, Checkbox } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

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
}

function General() {
	const { data, mutate } = useSettingsApi('general');
	const [privacyInfoOpen, setPrivacyInfoOpen] = React.useState(false);

	return (
		<>
			<FormSection title={t('settings.products_section_title')}>
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
			<FormSection title={t('settings.customers_section_title')}>
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
			</FormSection>
			<FormSection title={t('settings.privacy_section_title')}>
				<FormRow>
					<Label tip={t('settings.allow_anonymous_usage_data_tip')}>
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
