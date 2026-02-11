import * as React from 'react';

import { isString } from 'lodash';
import { ErrorBoundary } from 'react-error-boundary';

import Gateways from './gateways';
import OrderStatusSelect from './order-status-select';
import Error from '../../components/error';
import { FormRow, FormSection } from '../../components/form';
import Label from '../../components/label';
import { Toggle, Checkbox } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

interface EmailSettings {
	enabled: boolean;
	[key: string]: boolean;
}

export interface CheckoutSettingsProps {
	auto_print_receipt: boolean;
	order_status: string;
	admin_emails: EmailSettings;
	customer_emails: EmailSettings;
	cashier_emails: EmailSettings;
	default_gateway: string;
	gateways: any[];
}

const adminEmailTypes = [
	{ key: 'new_order', label: 'checkout.email_new_order' },
	{ key: 'cancelled_order', label: 'checkout.email_cancelled_order' },
	{ key: 'failed_order', label: 'checkout.email_failed_order' },
] as const;

const customerEmailTypes = [
	{ key: 'customer_on_hold_order', label: 'checkout.email_on_hold_order' },
	{ key: 'customer_processing_order', label: 'checkout.email_processing_order' },
	{ key: 'customer_completed_order', label: 'checkout.email_completed_order' },
	{ key: 'customer_refunded_order', label: 'checkout.email_refunded_order' },
	{ key: 'customer_failed_order', label: 'checkout.email_failed_order' },
] as const;

const cashierEmailTypes = [{ key: 'new_order', label: 'checkout.email_new_order' }] as const;

function EmailGroup({
	settingsKey,
	label,
	tip,
	emailTypes,
	data,
	mutate,
}: {
	settingsKey: 'admin_emails' | 'customer_emails' | 'cashier_emails';
	label: string;
	tip: string;
	emailTypes: ReadonlyArray<{ key: string; label: string }>;
	data: any;
	mutate: (data: Record<string, any>) => void;
}) {
	const settings: EmailSettings | undefined = data?.[settingsKey];
	const enabled = settings?.enabled ?? false;

	return (
		<FormRow>
			<Label tip={tip}>
				<Toggle
					checked={enabled}
					onChange={(checked: boolean) => {
						mutate({ [settingsKey]: { enabled: checked } });
					}}
					label={label}
				/>
			</Label>
			{enabled && (
				<div className="wcpos:ml-12 wcpos:mt-2 wcpos:flex wcpos:flex-col wcpos:gap-2">
					{emailTypes.map(({ key, label: labelKey }) => (
						<Checkbox
							key={key}
							checked={settings?.[key] ?? true}
							onChange={(e) => {
								mutate({ [settingsKey]: { [key]: e.target.checked } });
							}}
							label={t(labelKey)}
						/>
					))}
				</div>
			)}
		</FormRow>
	);
}

function Checkout() {
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
				<EmailGroup
					settingsKey="admin_emails"
					label={t('checkout.admin_emails')}
					tip={t('checkout.admin_emails_tip')}
					emailTypes={adminEmailTypes}
					data={data}
					mutate={mutate}
				/>
				<EmailGroup
					settingsKey="customer_emails"
					label={t('checkout.customer_emails')}
					tip={t('checkout.customer_emails_tip')}
					emailTypes={customerEmailTypes}
					data={data}
					mutate={mutate}
				/>
				<EmailGroup
					settingsKey="cashier_emails"
					label={t('checkout.cashier_emails')}
					tip={t('checkout.cashier_emails_tip')}
					emailTypes={cashierEmailTypes}
					data={data}
					mutate={mutate}
				/>
			</FormSection>

			<div className="wcpos:px-4 wcpos:pb-5">
				<h2 className="wcpos:text-base">{t('checkout.gateways')}</h2>
				<p>{t('checkout.gateways_description')}</p>
				<ErrorBoundary FallbackComponent={Error}>
					<React.Suspense fallback={null}>
						<Gateways />
					</React.Suspense>
				</ErrorBoundary>
			</div>
		</>
	);
}

export default Checkout;
