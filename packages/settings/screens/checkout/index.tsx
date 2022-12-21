import * as React from 'react';

import FormRow from '../../components/form-row';
import Select from '../../components/select';
import Toggle from '../../components/toggle';
import useSettingsApi from '../../hooks/use-settings-api';
import Gateways from './gateways';

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

const Checkout = () => {
	const { data, mutate } = useSettingsApi('checkout');

	// const orderStatusOptions = React.useMemo(() => {
	// 	const statuses = get(hydrate, 'order_statuses', []);
	// 	return map(statuses, (label: string, value: string) => ({ label, value }));
	// }, [hydrate]) as unknown as OrderStatusProps[];

	return (
		<>
			<FormRow>
				<FormRow.Label
					id="order-status"
					className="wcpos-text-right"
					help="Change the default order status for POS sales"
				>
					Completed order status
				</FormRow.Label>
				<FormRow.Col>
					<Select
						name="order-status"
						options={[]}
						selected={data.order_status}
						onChange={(order_status: string) => {
							mutate({ order_status });
						}}
					/>
				</FormRow.Col>
			</FormRow>
			<FormRow>
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="admin-emails"
						checked={data.admin_emails}
						onChange={(admin_emails: boolean) => {
							mutate({ admin_emails });
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="admin-emails"
					className="wcpos-col-span-2"
					help="Send WooCommerce notification emails for POS orders"
				>
					Send admin emails
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="customer-emails"
						checked={data.customer_emails}
						onChange={(customer_emails: boolean) => {
							mutate({ customer_emails });
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="customer-emails"
					className="wcpos-col-span-2"
					help="Send WooCommerce notification emails for POS orders"
				>
					Send customer emails
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="auto-print"
						checked={data.auto_print_receipt}
						onChange={(auto_print_receipt: boolean) => {
							mutate({ auto_print_receipt });
						}}
					/>
				</FormRow.Col>
				<FormRow.Label id="auto-print" className="wcpos-col-span-2">
					Automatically print receipt after checkout
				</FormRow.Label>
			</FormRow>
			<h2 className="wcpos-text-base">Gateways</h2>
			<p>
				Installed gateways are listed below. Drag and drop gateways to control their display order
				at the Point of Sale. Payment Gateways enabled here will be available at the Point of Sale.
			</p>
			{/* <Gateways
				gateways={data.gateways}
				defaultGateway={settings.default_gateway}
				dispatch={dispatch}
			/> */}
		</>
	);
};

export default Checkout;
