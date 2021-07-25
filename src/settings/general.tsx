import * as React from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { PanelRow, ToggleControl, CheckboxControl } from '@wordpress/components';
import { ErrorBoundary } from 'react-error-boundary';
import Error from '../error';
import UserSelect from '../common/user-select';

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
	console.log(settings);

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
				<UserSelect
					selectedUserId={settings.default_customer}
					dispatch={dispatch}
					disabled={!settings.logged_in_user}
				/>
				<CheckboxControl
					label="Use cashier account"
					checked={settings.logged_in_user}
					onChange={(args) => console.log(args)}
				/>
			</PanelRow>
		</ErrorBoundary>
	);
};

export default General;
