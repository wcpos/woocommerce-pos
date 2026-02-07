import * as React from 'react';

import { isString, isNumber } from 'lodash';

import BarcodeSelect from './barcode-select';
import UserSelect from './user-select';
import Label from '../../components/label';
import { Toggle, Checkbox } from '../../components/ui';
import { FormRow, FormSection } from '../../components/form';
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
}

const General = () => {
	const { data, mutate } = useSettingsApi('general');

	return (
		<FormSection>
			{/* <FormRow label={t('settings.force_ssl')}>
				<Label tip={t('settings.force_ssl_tip')}>
					<Toggle
						checked={!!data?.force_ssl}
						onChange={(force_ssl: boolean) => {
							mutate({ force_ssl });
						}}
					/>
				</Label>
			</FormRow> */}
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
					<React.Suspense fallback={<></>}>
						<UserSelect
							disabled={!!data?.default_customer_is_cashier}
							selected={isNumber(data?.default_customer) ? data?.default_customer || 0 : 0}
							onSelect={(value: number) => {
								mutate({ default_customer: value });
							}}
						/>
					</React.Suspense>
				</Label>
			</FormRow>
			<FormRow>
				<Checkbox
					label={t('settings.use_cashier_account')}
					checked={!!data?.default_customer_is_cashier}
					onChange={(e) => {
						mutate({ default_customer_is_cashier: e.target.checked });
					}}
				/>
			</FormRow>
			<FormRow label={t('settings.barcode_field')}>
				<Label tip={t('settings.barcode_field_tip')}>
					<React.Suspense fallback={<></>}>
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
	);
};

export default General;
