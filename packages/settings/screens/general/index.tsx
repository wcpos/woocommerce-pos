import * as React from 'react';

import Checkbox from '../../components/checkbox';
import FormRow from '../../components/form-row';
import Toggle from '../../components/toggle';
import useSettingsApi from '../../hooks/use-settings-api';
import BarcodeSelect from './barcode-select';
import UserSelect from './user-select';

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
		<>
			<FormRow>
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="force-ssl"
						checked={data.force_ssl}
						onChange={(force_ssl: boolean) => {
							mutate({ force_ssl });
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
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="pos-only-products"
						checked={data.pos_only_products}
						onChange={(pos_only_products: boolean) => {
							mutate({ pos_only_products });
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
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="decimal-qty"
						checked={data.decimal_qty}
						onChange={(decimal_qty: boolean) => {
							mutate({ decimal_qty });
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
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="generate-username"
						checked={data.generate_username}
						onChange={(generate_username: boolean) => {
							mutate({ generate_username });
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
					className="wcpos-text-right"
				>
					Default POS customer
				</FormRow.Label>
				<FormRow.Col>
					<React.Suspense fallback={<></>}>
						<UserSelect
							// initialOption={get(hydrate, ['default_customer'])}
							disabled={data.default_customer_is_cashier}
							onSelect={(value: number) => {
								if (value) {
									// dispatch({
									// 	type: 'update',
									// 	payload: { default_customer: value },
									// });
								}
							}}
						/>
					</React.Suspense>
				</FormRow.Col>
				<FormRow.Col>
					<label>
						<Checkbox
							checked={data.default_customer_is_cashier}
							onChange={(default_customer_is_cashier) => {
								// dispatch({
								// 	type: 'update',
								// 	payload: { default_customer_is_cashier },
								// });
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
					className="wcpos-text-right"
				>
					Barcode Field
				</FormRow.Label>
				<FormRow.Col>
					<React.Suspense fallback={<></>}>
						<BarcodeSelect
							// options={Object.values(get(hydrate, 'barcode_fields'))}
							selected={data.barcode_field}
							onSelect={(value: string) => {
								if (value) {
									// dispatch({
									// 	type: 'update',
									// 	payload: { barcode_field: value },
									// });
								}
							}}
						/>
					</React.Suspense>
				</FormRow.Col>
			</FormRow>
		</>
	);
};

export default General;
