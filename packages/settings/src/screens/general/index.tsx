import * as React from 'react';

import { ToggleControl, CheckboxControl } from '@wordpress/components';
import { isString, isNumber } from 'lodash';

import BarcodeSelect from './barcode-select';
import UserSelect from './user-select';
import Label from '../../components/label';
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
		<div className="wcpos:px-4 wcpos:py-5 wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4">
			{/* <div></div>
			<div className="wcpos:col-span-2">
				<ToggleControl
					label={
						<Label
							tip={t('settings.force_ssl_tip')}
						>
							{t('settings.force_ssl')}
						</Label>
					}
					checked={!!data?.force_ssl}
					onChange={(force_ssl: boolean) => {
						mutate({ force_ssl });
					}}
				/>
			</div> */}
			<div></div>
			<div className="wcpos:col-span-2">
				<ToggleControl
					label={
						<Label
							tip={t('settings.pos_only_products_tip')}
						>
							{t('settings.pos_only_products')}
						</Label>
					}
					checked={!!data?.pos_only_products}
					onChange={(pos_only_products: boolean) => {
						mutate({ pos_only_products });
					}}
				/>
			</div>
			<div></div>
			<div className="wcpos:col-span-2">
				<ToggleControl
					label={
						<Label
							tip={t('settings.decimal_quantities_tip')}
						>
							{t('settings.decimal_quantities')}
						</Label>
					}
					checked={!!data?.decimal_qty}
					onChange={(decimal_qty: boolean) => {
						mutate({ decimal_qty });
					}}
				/>
			</div>
			<div></div>
			<div className="wcpos:col-span-2">
				<ToggleControl
					label={
						<Label>
							{t('settings.generate_username')}
						</Label>
					}
					checked={!!data?.generate_username}
					onChange={(generate_username: boolean) => {
						mutate({ generate_username });
					}}
				/>
			</div>
			<div className="wcpos:flex wcpos:sm:justify-end">
				<Label
					tip={t('settings.default_customer_tip')}
				>
					{t('settings.default_customer')}
				</Label>
			</div>
			<div>
				<React.Suspense fallback={<></>}>
					<UserSelect
						disabled={!!data?.default_customer_is_cashier}
						selected={isNumber(data?.default_customer) ? data?.default_customer || 0 : 0}
						onSelect={(value: number) => {
							mutate({ default_customer: value });
						}}
					/>
				</React.Suspense>
			</div>
			<div className="wcpos:flex wcpos:items-center">
				<CheckboxControl
					label={t('settings.use_cashier_account')}
					checked={!!data?.default_customer_is_cashier}
					onChange={(default_customer_is_cashier) => {
						mutate({ default_customer_is_cashier });
					}}
				/>
			</div>
			<div className="wcpos:flex wcpos:sm:justify-end">
				<Label
					tip={t('settings.barcode_field_tip')}
				>
					{t('settings.barcode_field')}
				</Label>
			</div>
			<div>
				<React.Suspense fallback={<></>}>
					<BarcodeSelect
						selected={isString(data?.barcode_field) ? data?.barcode_field || '' : ''}
						onSelect={(value) => {
							mutate({ barcode_field: value || '_sku' });
						}}
					/>
				</React.Suspense>
			</div>
			<div></div>
		</div>
	);
};

export default General;
