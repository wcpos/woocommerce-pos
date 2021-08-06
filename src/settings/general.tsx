import * as React from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	PanelRow,
	ToggleControl,
	CheckboxControl,
	TextControl,
	Button,
	// @ts-ignore
	ComboboxControl,
} from '@wordpress/components';
import { get } from 'lodash';
import UserSelect from '../components/user-select';

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
	setNotice: (args: import('../settings').NoticeProps) => void;
	hydrate: import('../settings').HydrateProps;
}

// @ts-ignore
function reducer(state, action) {
	const { type, payload } = action;

	switch (type) {
		case 'update':
			// @ts-ignore
			return { ...state, ...payload };
		default:
			// @ts-ignore
			throw new Error('no action');
	}
}

const General = ({ setNotice, hydrate }: GeneralProps) => {
	const [settings, dispatch] = React.useReducer(reducer, get(hydrate, ['settings', 'general']));
	const [newBarcodeField, setNewBarcodeField] = React.useState<string>('');
	const [showNewBarcodeField, setShowNewBarcodeField] = React.useState<boolean>(false);
	const silent = React.useRef(true);

	React.useEffect(() => {
		async function updateSettings() {
			const data = await apiFetch({
				path: 'wcpos/v1/settings/general?wcpos=1',
				method: 'POST',
				data: settings,
			}).catch((err) => setNotice({ type: 'error', message: err.message }));

			if (data) {
				silent.current = true;
				dispatch({ type: 'update', payload: data });
			}
		}

		if (silent.current) {
			silent.current = false;
		} else {
			updateSettings();
		}
	}, [settings, dispatch, setNotice]);

	const barcodeFields = React.useMemo(() => {
		const fields = Object.values(get(hydrate, 'barcode_fields'));
		if (!fields.includes(settings.barcode_field)) {
			fields.push(settings.barcode_field);
		}
		return fields;
	}, [hydrate, settings]);

	return (
		<>
			<PanelRow>
				<ToggleControl
					label="Force SSL"
					help=""
					checked={settings.force_ssl}
					onChange={(force_ssl: boolean) => {
						dispatch({
							type: 'update',
							payload: { force_ssl },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Enable POS only products"
					checked={settings.pos_only_products}
					onChange={(pos_only_products: boolean) => {
						dispatch({
							type: 'update',
							payload: { pos_only_products },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Enable decimal quantities"
					help="Allows items to have decimal values in the quantity field, eg: 0.25"
					checked={settings.decimal_qty}
					onChange={(decimal_qty: boolean) => {
						dispatch({
							type: 'update',
							payload: { decimal_qty },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Automatically generate username from customer email"
					help=""
					checked={settings.generate_username}
					onChange={(generate_username: boolean) => {
						dispatch({
							type: 'update',
							payload: { generate_username },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<UserSelect
					selectedUserId={settings.default_customer}
					dispatch={dispatch}
					disabled={!settings.default_customer_is_cashier}
				/>
				<CheckboxControl
					label="Use cashier account"
					checked={settings.default_customer_is_cashier}
					onChange={(default_customer_is_cashier: boolean) => {
						dispatch({
							type: 'update',
							payload: { default_customer_is_cashier },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ComboboxControl
					label="Barcode Field"
					help="Select a meta field to use as the product barcode"
					value={settings.barcode_field}
					onChange={(value: string) => {
						if (value) {
							dispatch({
								type: 'update',
								payload: { barcode_field: value },
							});
						}
					}}
					options={barcodeFields.map((field: string) => ({ label: field, value: field }))}
					allowReset={true}
				/>
				{showNewBarcodeField ? (
					<>
						<TextControl
							value={newBarcodeField}
							onChange={(nextValue: string) => setNewBarcodeField(nextValue)}
						/>
						<Button
							disabled={!newBarcodeField}
							isPrimary
							onClick={() => {
								dispatch({
									type: 'update',
									payload: { barcode_field: newBarcodeField },
								});
							}}
						>
							{__('Add')}
						</Button>
						<Button
							onClick={() => {
								setShowNewBarcodeField(false);
							}}
						>
							{__('Cancel')}
						</Button>
					</>
				) : (
					<Button
						onClick={() => {
							setShowNewBarcodeField(true);
						}}
					>
						Add new meta field
					</Button>
				)}
			</PanelRow>
		</>
	);
};

export default General;
