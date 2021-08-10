import * as React from 'react';
import { __ } from '@wordpress/i18n';
import { PanelRow, ToggleControl, SelectControl } from '@wordpress/components';
import { get, map } from 'lodash';
import Gateways from '../components/gateways';
import useSettingsApi from '../hooks/use-settings-api';

export interface CheckoutSettingsProps {
	auto_print_receipt: boolean;
	order_status: string;
	admin_emails: boolean;
	customer_emails: boolean;
	default_gateway: string;
	gateways: any[];
}

interface CheckoutProps {
	hydrate: import('../settings').HydrateProps;
}

const Checkout = ({ hydrate }: CheckoutProps) => {
	const { settings, dispatch } = useSettingsApi('checkout', get(hydrate, ['settings', 'checkout']));

	const orderStatusOptions = React.useMemo(() => {
		const statuses = get(hydrate, 'order_statuses', []);
		return map(statuses, (label: string, value: string) => ({ label, value }));
	}, [hydrate]);

	return (
		<>
			<PanelRow>
				<SelectControl
					label="Completed order status"
					value={settings.order_status}
					// @ts-ignore
					options={orderStatusOptions}
					onChange={(order_status: string) => {
						dispatch({
							type: 'update',
							payload: { order_status },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Send admin emails"
					checked={settings.admin_emails}
					onChange={(admin_emails: boolean) => {
						dispatch({
							type: 'update',
							payload: { admin_emails },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Send customer emails"
					checked={settings.customer_emails}
					onChange={(customer_emails: boolean) => {
						dispatch({
							type: 'update',
							payload: { customer_emails },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Automatically print receipt after checkout"
					checked={settings.auto_print_receipt}
					onChange={(auto_print_receipt: boolean) => {
						dispatch({
							type: 'update',
							payload: { auto_print_receipt },
						});
					}}
				/>
			</PanelRow>
			<PanelRow className="flexColumn">
				<h2>Gateways</h2>
				<p>
					Installed gateways are listed below. Drag and drop gateways to control their display order
					at the Point of Sale. Payment Gateways enabled here will be available at the Point of
					Sale.
				</p>
				<Gateways
					gateways={settings.gateways}
					defaultGateway={settings.default_gateway}
					dispatch={dispatch}
				/>
			</PanelRow>
		</>
	);
};

export default Checkout;
