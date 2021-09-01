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
			<FormRow>
				<FormRow.Col className="flex justify-self-end">
					<Toggle
						name="force-ssl"
						checked={settings.force_ssl}
						onChange={(force_ssl: boolean) => {
							dispatch({
								type: 'update',
								payload: { force_ssl },
							});
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="force-ssl"
					help="Force POS to send server requests over HTTPS (recommended)"
				>
					Force SSL
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="flex justify-self-end">
					<Toggle
						name="pos-only-products"
						checked={settings.pos_only_products}
						onChange={(pos_only_products: boolean) => {
							dispatch({
								type: 'update',
								payload: { pos_only_products },
							});
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="pos-only-products"
					help="Adds online and POS visibility settings to product admin"
				>
					Enable POS only products
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="flex justify-self-end">
					<Toggle
						name="decimal-qty"
						checked={settings.decimal_qty}
						onChange={(decimal_qty: boolean) => {
							dispatch({
								type: 'update',
								payload: { decimal_qty },
							});
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="decimal-qty"
					help="Allows items to have decimal values in the quantity field, eg: 0.25"
				>
					Enable decimal quantities
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="flex justify-self-end">
					<Toggle
						name="generate-username"
						checked={settings.generate_username}
						onChange={(generate_username: boolean) => {
							dispatch({
								type: 'update',
								payload: { generate_username },
							});
						}}
					/>
				</FormRow.Col>
				<FormRow.Label id="generate-username" className="col-span-2">
					Automatically generate username from customer email
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Label
					id="user-select"
					help="The default customer for POS orders, eg: Guest"
					className="text-right"
				>
					Default POS customer
				</FormRow.Label>
				<FormRow.Col>
					<UserSelect
						initialOption={get(hydrate, ['default_customer'])}
						disabled={settings.default_customer_is_cashier}
						onSelect={(value: string) => {
							if (value) {
								dispatch({
									type: 'update',
									payload: { default_customer: value },
								});
							}
						}}
					/>
				</FormRow.Col>
				<FormRow.Col>
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
				</FormRow.Col>
			</FormRow>
			<FormRow>
				<FormRow.Label
					help="Product meta field to be used as barcode, eg: _sku or _barcode"
					id="barcode-field"
					className="text-right"
				>
					Barcode Field
				</FormRow.Label>
				<FormRow.Col>
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
				</FormRow.Col>
			</FormRow>
		</>
	);
};

export default General;
