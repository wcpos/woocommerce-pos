import * as React from 'react';
import { get, map } from 'lodash';
import Gateways from '../components/gateways';
import FormRow from '../components/form-row';
import Toggle from '../components/toggle';
import Select from '../components/select';
import useSettingsApi from '../hooks/use-settings-api';

export interface CheckoutSettingsProps {
	auto_print_receipt: boolean;
	order_status: string;
	admin_emails: boolean;
	customer_emails: boolean;
	default_gateway: string;
	gateways: any[];
}

interface OrderStatusProps {
	label: string;
	value: string;
}

interface CheckoutProps {
	hydrate: import('../settings').HydrateProps;
}

const Checkout = ({ hydrate }: CheckoutProps) => {
	const { settings, dispatch } = useSettingsApi('checkout', get(hydrate, ['settings', 'checkout']));

	const orderStatusOptions = React.useMemo(() => {
		const statuses = get(hydrate, 'order_statuses', []);
		return map(statuses, (label: string, value: string) => ({ label, value }));
	}, [hydrate]) as unknown as OrderStatusProps[];

	return (
		<>
			<FormRow>
				<FormRow.Label
					id="order-status"
					className="text-right"
					help="Change the default order status for POS sales"
				>
					Completed order status
				</FormRow.Label>
				<FormRow.Col>
					<Select
						name="order-status"
						options={orderStatusOptions}
						selected={settings.order_status}
						onChange={(order_status: string) => {
							dispatch({
								type: 'update',
								payload: { order_status },
							});
						}}
					/>
				</FormRow.Col>
			</FormRow>
			<FormRow>
				<FormRow.Col className="flex justify-self-end">
					<Toggle
						name="admin-emails"
						checked={settings.admin_emails}
						onChange={(admin_emails: boolean) => {
							dispatch({
								type: 'update',
								payload: { admin_emails },
							});
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="admin-emails"
					className="col-span-2"
					help="Send WooCommerce notification emails for POS orders"
				>
					Send admin emails
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="flex justify-self-end">
					<Toggle
						name="customer-emails"
						checked={settings.customer_emails}
						onChange={(customer_emails: boolean) => {
							dispatch({
								type: 'update',
								payload: { customer_emails },
							});
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="customer-emails"
					className="col-span-2"
					help="Send WooCommerce notification emails for POS orders"
				>
					Send customer emails
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="flex justify-self-end">
					<Toggle
						name="auto-print"
						checked={settings.auto_print_receipt}
						onChange={(auto_print_receipt: boolean) => {
							dispatch({
								type: 'update',
								payload: { auto_print_receipt },
							});
						}}
					/>
				</FormRow.Col>
				<FormRow.Label id="auto-print" className="col-span-2">
					Automatically print receipt after checkout
				</FormRow.Label>
			</FormRow>
			<h2 className="text-base">Gateways</h2>
			<p>
				Installed gateways are listed below. Drag and drop gateways to control their display order
				at the Point of Sale. Payment Gateways enabled here will be available at the Point of Sale.
			</p>
			<Gateways
				gateways={settings.gateways}
				defaultGateway={settings.default_gateway}
				dispatch={dispatch}
			/>
		</>
	);
};

export default Checkout;
