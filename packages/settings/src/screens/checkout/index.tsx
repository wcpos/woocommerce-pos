import * as React from 'react';

import { ToggleControl } from '@wordpress/components';
import { isString } from 'lodash';
import { ErrorBoundary } from 'react-error-boundary';

import Error from '../../components/error';
import Label from '../../components/label';
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
			<div className="wcpos:px-4 wcpos:py-5 wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4">
				<div className="wcpos:flex wcpos:sm:justify-end">
					<Label
						tip={t('Change the default order status for POS sales', { _tags: 'wp-admin-settings' })}
					>
						{t('Completed order status', { _tags: 'wp-admin-settings' })}
					</Label>
				</div>
				<div>
					<ErrorBoundary FallbackComponent={Error}>
						<React.Suspense fallback={null}>
							<OrderStatusSelect
								selectedStatus={isString(data?.order_status) ? data?.order_status || '' : ''}
								mutate={mutate}
							/>
						</React.Suspense>
					</ErrorBoundary>
				</div>
				<div></div>
				<div></div>
				<div className="wcpos:col-span-2">
					<ToggleControl
						label={
							<Label
								tip={t('Send WooCommerce notification emails for POS orders', {
									_tags: 'wp-admin-settings',
								})}
							>
								{t('Send admin emails', { _tags: 'wp-admin-settings' })}
							</Label>
						}
						checked={!!data?.admin_emails}
						onChange={(admin_emails: boolean) => {
							mutate({ admin_emails });
						}}
					/>
				</div>
				<div></div>
				<div className="wcpos:col-span-2">
					<ToggleControl
						label={
							<Label
								tip={t('Send WooCommerce notification emails for POS orders', {
									_tags: 'wp-admin-settings',
								})}
							>
								{t('Send customer emails', { _tags: 'wp-admin-settings' })}
							</Label>
						}
						checked={!!data?.customer_emails}
						onChange={(customer_emails: boolean) => {
							mutate({ customer_emails });
						}}
					/>
				</div>
			</div>

			<div className="wcpos:px-4 wcpos:pb-5">
				<h2 className="wcpos:text-base">{t('Gateways', { _tags: 'wp-admin-settings' })}</h2>
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
