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

export interface CheckoutSettingsProps {
	auto_print_receipt: boolean;
}

interface CheckoutProps {
	initialSettings: CheckoutSettingsProps;
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

const Checkout = ({ initialSettings }: CheckoutProps) => {
	const [settings, dispatch] = React.useReducer(reducer, initialSettings);

	return (
		<ErrorBoundary FallbackComponent={Error}>
			<PanelRow>
				<ToggleControl
					label="Automatically print receipt after checkout"
					checked={settings.auto_print_receipt}
					onChange={() => {
						dispatch({
							type: 'update',
							payload: { auto_print_receipt: !settings.auto_print_receipt },
						});
					}}
				/>
			</PanelRow>
		</ErrorBoundary>
	);
};

export default Checkout;
