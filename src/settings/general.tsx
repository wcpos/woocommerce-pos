import * as React from 'react';
import { get } from 'lodash';
import UserSelect from '../components/user-select';
import useSettingsApi from '../hooks/use-settings-api';
import Toggle from '../components/toggle';
import FormRow from '../components/form-row';
import BarcodeSelect from '../components/barcode-select';
import Checkbox from '../components/checkbox';

export interface GeneralSettingsProps {
	pos_only_products: boolean;
	decimal_qty: boolean;
	force_ssl: boolean;
	generate_username: boolean;
	default_customer: number;
	default_customer_is_cashier: boolean;
	barcode_field: string;
}

interface GeneralProps {
	hydrate: import('../settings').HydrateProps;
}

const General = ({ hydrate }: GeneralProps) => {
	const { settings, dispatch } = useSettingsApi('general', get(hydrate, ['settings', 'general']));

	return (
		<>
			<FormRow label="Force SSL">
				<Toggle
					checked={settings.force_ssl}
					onChange={(force_ssl: boolean) => {
						dispatch({
							type: 'update',
							payload: { force_ssl },
						});
					}}
				/>
			</FormRow>
			<FormRow label="Enable POS only products">
				<Toggle
					checked={settings.pos_only_products}
					onChange={(pos_only_products: boolean) => {
						dispatch({
							type: 'update',
							payload: { pos_only_products },
						});
					}}
				/>
			</FormRow>
			<FormRow label="Enable decimal quantities">
				<Toggle
					checked={settings.decimal_qty}
					onChange={(decimal_qty: boolean) => {
						dispatch({
							type: 'update',
							payload: { decimal_qty },
						});
					}}
				/>
			</FormRow>
			<FormRow label="Automatically generate username from customer email">
				<Toggle
					checked={settings.generate_username}
					onChange={(generate_username: boolean) => {
						dispatch({
							type: 'update',
							payload: { generate_username },
						});
					}}
				/>
			</FormRow>
			<FormRow
				label="Default POS customer"
				help="Product meta field to be used as barcode, eg: _sku or _barcode"
				id="user-select"
				extra={
					<label>
						<Checkbox
							checked={settings.default_customer_is_cashier}
							onChange={(default_customer_is_cashier) => {
								dispatch({
									type: 'update',
									payload: { default_customer_is_cashier },
								});
							}}
						/>
						Use cashier account
					</label>
				}
			>
				<UserSelect
					selectedUserId={settings.default_customer}
					initialOption={get(hydrate, ['default_customer'])}
					dispatch={dispatch}
					disabled={settings.default_customer_is_cashier}
				/>
			</FormRow>
			<FormRow
				label="Barcode Field"
				help="Product meta field to be used as barcode, eg: _sku or _barcode"
				id="barcode-field"
			>
				<BarcodeSelect
					options={Object.values(get(hydrate, 'barcode_fields'))}
					selected={settings.barcode_field}
					onSelect={(value: string) => {
						if (value) {
							dispatch({
								type: 'update',
								payload: { barcode_field: value },
							});
						}
					}}
				/>
			</FormRow>
		</>
	);
};

export default General;
