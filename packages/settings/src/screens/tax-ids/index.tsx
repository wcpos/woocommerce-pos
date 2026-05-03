import * as React from 'react';

import CompatibilitySection from './compatibility-section';
import { TaxIdsSettings } from './types';
import { FormRow, FormSection } from '../../components/form';
import Label from '../../components/label';
import { TextInput, Toggle } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

function TaxIds() {
	const { data, mutate } = useSettingsApi('tax_ids') as {
		data: TaxIdsSettings;
		mutate: (next: Partial<TaxIdsSettings>) => void;
	};

	const threshold = data?.b2b_required_threshold ?? null;

	const updateThreshold = React.useCallback(
		(patch: Partial<{ country: string; amount: number; currency: string }>) => {
			const merged = {
				country: threshold?.country ?? '',
				amount: threshold?.amount ?? 0,
				currency: threshold?.currency ?? '',
				...patch,
			};
			// A threshold is only valid with a country set; clearing the country
			// disables the gate entirely.
			if (!merged.country) {
				mutate({ b2b_required_threshold: null });
				return;
			}
			mutate({ b2b_required_threshold: merged });
		},
		[mutate, threshold]
	);

	return (
		<>
			<FormSection title={t('tax_ids.general_section_title')}>
				<FormRow>
					<Label tip={t('tax_ids.enabled_tip')}>
						<Toggle
							checked={!!data?.enabled}
							onChange={(enabled: boolean) => {
								mutate({ enabled });
							}}
							label={t('tax_ids.enabled')}
						/>
					</Label>
				</FormRow>
				<FormRow>
					<Label tip={t('tax_ids.capture_on_customer_tip')}>
						<Toggle
							checked={!!data?.capture_on_customer}
							onChange={(capture_on_customer: boolean) => {
								mutate({ capture_on_customer });
							}}
							label={t('tax_ids.capture_on_customer')}
						/>
					</Label>
				</FormRow>
				<FormRow>
					<Label tip={t('tax_ids.capture_on_cart_tip')}>
						<Toggle
							checked={!!data?.capture_on_cart}
							onChange={(capture_on_cart: boolean) => {
								mutate({ capture_on_cart });
							}}
							label={t('tax_ids.capture_on_cart')}
						/>
					</Label>
				</FormRow>
				<FormRow>
					<Label tip={t('tax_ids.show_on_receipt_tip')}>
						<Toggle
							checked={!!data?.show_on_receipt}
							onChange={(show_on_receipt: boolean) => {
								mutate({ show_on_receipt });
							}}
							label={t('tax_ids.show_on_receipt')}
						/>
					</Label>
				</FormRow>
				<FormRow label={t('tax_ids.b2b_threshold')} description={t('tax_ids.b2b_threshold_tip')}>
					<div className="wcpos:grid wcpos:grid-cols-1 wcpos:sm:grid-cols-3 wcpos:gap-2">
						<TextInput
							placeholder={t('tax_ids.b2b_threshold_country')}
							maxLength={2}
							defaultValue={threshold?.country ?? ''}
							onBlur={(e) => {
								const country = e.target.value.trim().toUpperCase();
								if (country === (threshold?.country ?? '')) return;
								updateThreshold({ country });
							}}
						/>
						<TextInput
							type="number"
							placeholder={t('tax_ids.b2b_threshold_amount')}
							defaultValue={threshold?.amount ?? ''}
							disabled={!threshold?.country}
							onBlur={(e) => {
								const amount = Number(e.target.value);
								if (Number.isNaN(amount)) return;
								if (amount === (threshold?.amount ?? 0)) return;
								updateThreshold({ amount });
							}}
						/>
						<TextInput
							placeholder={t('tax_ids.b2b_threshold_currency')}
							maxLength={3}
							defaultValue={threshold?.currency ?? ''}
							disabled={!threshold?.country}
							onBlur={(e) => {
								const currency = e.target.value.trim().toUpperCase();
								if (currency === (threshold?.currency ?? '')) return;
								updateThreshold({ currency });
							}}
						/>
					</div>
				</FormRow>
			</FormSection>
			<React.Suspense fallback={null}>
				<CompatibilitySection writeMap={data?.write_map ?? {}} mutate={mutate} />
			</React.Suspense>
		</>
	);
}

export default TaxIds;
