import * as React from 'react';

import { ErrorBoundary } from 'react-error-boundary';

import Error from '../../components/error';
import FormRow from '../../components/form-row';
import Toggle from '../../components/toggle';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';
import Gateways from './gateways';
import OrderStatusSelect from './order-status-select';

export interface CheckoutSettingsProps {
	auto_print_receipt: boolean;
	order_status: string;
	admin_emails: boolean;
	customer_emails: boolean;
	default_gateway: string;
	gateways: any[];
}

const Checkout = () => {
	const { data, mutate } = useSettingsApi('checkout');

	return (
		<>
			<FormRow>
				<FormRow.Label
					id="order-status"
					className="wcpos-text-right"
					help={t('Change the default order status for POS sales', { _tags: 'wp-admin-settings' })}
				>
					{t('Completed order status', { _tags: 'wp-admin-settings' })}
				</FormRow.Label>
				<FormRow.Col>
					<ErrorBoundary FallbackComponent={Error}>
						<React.Suspense fallback={null}>
							<OrderStatusSelect selectedStatus={data?.order_status} mutate={mutate} />
						</React.Suspense>
					</ErrorBoundary>
				</FormRow.Col>
			</FormRow>
			<FormRow>
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="admin-emails"
						checked={!!data?.admin_emails}
						onChange={(admin_emails: boolean) => {
							mutate({ admin_emails });
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="admin-emails"
					className="wcpos-col-span-2"
					help={t('Send WooCommerce notification emails for POS orders', {
						_tags: 'wp-admin-settings',
					})}
				>
					{t('Send admin emails', { _tags: 'wp-admin-settings' })}
				</FormRow.Label>
			</FormRow>
			<FormRow>
				<FormRow.Col className="wcpos-flex wcpos-justify-self-end">
					<Toggle
						name="customer-emails"
						checked={!!data?.customer_emails}
						onChange={(customer_emails: boolean) => {
							mutate({ customer_emails });
						}}
					/>
				</FormRow.Col>
				<FormRow.Label
					id="customer-emails"
					className="wcpos-col-span-2"
					help={t('Send WooCommerce notification emails for POS orders', {
						_tags: 'wp-admin-settings',
					})}
				>
					{t('Send customer emails', { _tags: 'wp-admin-settings' })}
				</FormRow.Label>
			</FormRow>

			<div className="wcpos-px-4 wcpos-pb-5">
				<h2 className="wcpos-text-base">{t('Gateways', { _tags: 'wp-admin-settings' })}</h2>
				<p>
					{t(
						'Installed gateways are listed below. Drag and drop gateways to control their display order at the Point of Sale. Payment Gateways enabled here will be available at the Point of Sale.',
						{ _tags: 'wp-admin-settings' }
					)}
				</p>
				<ErrorBoundary FallbackComponent={Error}>
					<React.Suspense fallback={null}>
						<Gateways />
					</React.Suspense>
				</ErrorBoundary>
			</div>
		</>
	);
};

export default Checkout;
