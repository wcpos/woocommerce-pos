import * as React from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	ExternalLink,
	Modal,
	PanelBody,
	PanelRow,
	Placeholder,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { ErrorBoundary } from 'react-error-boundary';
import Error from '../error';

export interface GeneralSettingsProps {
	pos_only_products: boolean;
	decimal_qty: boolean;
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
				path: 'wcpos/v1/settings?wcpos=1',
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
		<PanelBody title={__('General', 'woocommerce-pos')} initialOpen={true}>
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
			</ErrorBoundary>
		</PanelBody>
	);
};

export default General;
