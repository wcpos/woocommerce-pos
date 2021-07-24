import * as React from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { PanelRow, ToggleControl, ComboboxControl, CheckboxControl } from '@wordpress/components';
import { ErrorBoundary } from 'react-error-boundary';
import Error from '../error';

export interface GeneralSettingsProps {
	pos_only_products: boolean;
	decimal_qty: boolean;
	force_ssl: boolean;
	default_customer: number;
	logged_in_user: boolean;
}

interface GeneralProps {
	initialSettings: GeneralSettingsProps;
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
	const firstRender = React.useRef(true);

	React.useEffect(() => {
		async function updateSettings() {
			const data = await apiFetch({
				path: 'wcpos/v1/settings/general?wcpos=1',
				method: 'POST',
				data: settings,
			});
			console.log(data);
		}

		if (firstRender.current) {
			firstRender.current = false;
		} else {
			updateSettings();
		}
	}, [settings]);

	return (
		<ErrorBoundary FallbackComponent={Error}>
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
				<ComboboxControl
					label="Default POS customer"
					value={settings.default_customer}
					onChange={(args: any) => {
						console.log(args);
					}}
					options={[
						{ value: 0, label: 'Guest' },
						{ value: 1, label: 'Admin' },
					]}
					onFilterValueChange={(inputValue: string) => {
						console.log(inputValue);
					}}
				/>
			</PanelRow>
			<CheckboxControl
				label="Use cashier account"
				checked={settings.logged_in_user}
				onChange={(args) => console.log(args)}
			/>
		</ErrorBoundary>
	);
};

export default General;
