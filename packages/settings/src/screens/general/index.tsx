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
							tip={t('Force POS to send server requests over HTTPS (recommended)', {
								_tags: 'wp-admin-settings',
							})}
						>
							{t('Force SSL', { _tags: 'wp-admin-settings' })}
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
							tip={t('Adds online and POS visibility settings to product admin', {
								_tags: 'wp-admin-settings',
							})}
						>
							{t('Enable POS only products', { _tags: 'wp-admin-settings' })}
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
							tip={t('Allows items to have decimal values in the quantity field, eg: 0.25', {
								_tags: 'wp-admin-settings',
							})}
						>
							{t('Enable decimal quantities', { _tags: 'wp-admin-settings' })}
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
							{t('Automatically generate username from customer email', {
								_tags: 'wp-admin-settings',
							})}
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
					tip={t('The default customer for POS orders, eg: Guest', { _tags: 'wp-admin-settings' })}
				>
					{t('Default POS customer', { _tags: 'wp-admin-settings' })}
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
			<div>
				<CheckboxControl
					label={t('Use cashier account', { _tags: 'wp-admin-settings' })}
					checked={!!data?.default_customer_is_cashier}
					onChange={(default_customer_is_cashier) => {
						mutate({ default_customer_is_cashier });
					}}
				/>
			</div>
			<div className="wcpos:flex wcpos:sm:justify-end">
				<Label
					tip={t('Product meta field to be used as barcode, eg: _sku or _barcode', {
						_tags: 'wp-admin-settings',
					})}
				>
					{t('Barcode Field', { _tags: 'wp-admin-settings' })}
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
