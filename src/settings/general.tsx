import * as React from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	PanelRow,
	ToggleControl,
	CheckboxControl,
	Notice,
	TextControl,
	Button,
} from '@wordpress/components';
import { ErrorBoundary } from 'react-error-boundary';
import Error from '../error';
import UserSelect from '../components/user-select';
import BarcodeFieldSelect from '../components/barcode-field-select';

export interface GeneralSettingsProps {
	pos_only_products: boolean;
	decimal_qty: boolean;
	force_ssl: boolean;
	default_customer: number;
	default_customer_is_cashier: boolean;
	barcode_field: string;
}

interface GeneralProps {
	initialSettings: GeneralSettingsProps;
}

interface NoticeProps {
	type?: 'error' | 'info' | 'success';
	message: string;
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

const General = ({ initialSettings }: GeneralProps) => {
	const [settings, dispatch] = React.useReducer(reducer, initialSettings);
	const [notice, setNotice] = React.useState<NoticeProps | null>(null);
	const [newBarcodeField, setNewBarcodeField] = React.useState<string>('');
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

	return (
		<ErrorBoundary FallbackComponent={Error}>
			{notice && (
				<Notice status={notice.type} onRemove={() => setNotice(null)}>
					{notice.message}
				</Notice>
			)}
			<PanelRow>
				<ToggleControl
					label="Enable POS only products"
					checked={settings.pos_only_products}
					onChange={() => {
						dispatch({
							type: 'update',
							payload: { pos_only_products: !settings.pos_only_products },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Enable decimal quantities"
					help="Allows items to have decimal values in the quantity field, eg: 0.25"
					checked={settings.decimal_qty}
					onChange={() => {
						dispatch({
							type: 'update',
							payload: { decimal_qty: !settings.decimal_qty },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Force SSL"
					help=""
					checked={settings.force_ssl}
					onChange={() => {
						dispatch({
							type: 'update',
							payload: { force_ssl: !settings.force_ssl },
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
					onChange={(value: boolean) => {
						dispatch({
							type: 'update',
							payload: { default_customer_is_cashier: value },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<BarcodeFieldSelect selectedBarcodeField={settings.barcode_field} dispatch={dispatch} />
				<TextControl
					label="Add new meta field"
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
						console.log('cancel');
					}}
				>
					{__('Cancel')}
				</Button>
			</PanelRow>
		</ErrorBoundary>
	);
};

export default General;
