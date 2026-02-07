import * as React from 'react';

import { isString } from 'lodash';
import { ErrorBoundary } from 'react-error-boundary';

import Error from '../../components/error';
import Label from '../../components/label';
import { Toggle } from '../../components/ui';
import { FormRow, FormSection } from '../../components/form';
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
			<FormSection>
				<FormRow label={t('checkout.completed_order_status')}>
					<Label tip={t('checkout.completed_order_status_tip')}>
						<ErrorBoundary FallbackComponent={Error}>
							<React.Suspense fallback={null}>
								<OrderStatusSelect
									selectedStatus={isString(data?.order_status) ? data?.order_status || '' : ''}
									mutate={mutate}
								/>
							</React.Suspense>
						</ErrorBoundary>
					</Label>
				</FormRow>
				<FormRow>
					<Label tip={t('checkout.notification_emails_tip')}>
						<Toggle
							checked={!!data?.admin_emails}
							onChange={(admin_emails: boolean) => {
								mutate({ admin_emails });
							}}
							label={t('checkout.send_admin_emails')}
						/>
					</Label>
				</FormRow>
				<FormRow>
					<Label tip={t('checkout.notification_emails_tip')}>
						<Toggle
							checked={!!data?.customer_emails}
							onChange={(customer_emails: boolean) => {
								mutate({ customer_emails });
							}}
							label={t('checkout.send_customer_emails')}
						/>
					</Label>
				</FormRow>
			</FormSection>

			<div className="wcpos:px-4 wcpos:pb-5">
				<h2 className="wcpos:text-base">{t('checkout.gateways')}</h2>
				<p>
					{t(
						'checkout.gateways_description'
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
