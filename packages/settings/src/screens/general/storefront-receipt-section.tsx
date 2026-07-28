import * as React from 'react';

import { FormRow } from '../../components/form';
import Label from '../../components/label';
import { Skeleton } from '../../components/skeleton';
import { Select, Toggle } from '../../components/ui';
import { useReceiptTemplateOptions } from '../../hooks/use-receipt-templates';
import { t } from '../../translations';

export interface StorefrontReceiptSectionProps {
	enabled: boolean;
	template: string;
	onChange: (patch: {
		storefront_receipt_enabled?: boolean;
		storefront_receipt_template?: string;
	}) => void;
}

interface TemplateSelectProps {
	value: string;
	onChange: (template: string) => void;
}

/**
 * Template picker for the storefront receipt. An empty value ('') maps to
 * "use active receipt template", keeping the storefront in sync with the POS
 * unless the merchant pins a specific template. The options are loaded via a
 * suspense query, so callers must wrap this in a <Suspense> boundary.
 */
function TemplateSelect({ value, onChange }: TemplateSelectProps) {
	const templateOptions = useReceiptTemplateOptions(true);
	const isUnavailable = value !== '' && !templateOptions.some((option) => option.value === value);

	const options = React.useMemo(
		() => [
			{ value: '', label: t('settings.storefront_receipt_template_active') },
			...(isUnavailable
				? [
						{
							value,
							label: t('settings.storefront_receipt_template_unavailable', { value }),
							disabled: true,
						},
					]
				: []),
			...templateOptions.map(({ value: v, label }) => ({ value: v, label })),
		],
		[isUnavailable, templateOptions, value]
	);

	return (
		<Select
			value={value}
			options={options}
			onChange={({ value: next }) => onChange(String(next))}
			aria-label={t('settings.storefront_receipt_template')}
		/>
	);
}

/**
 * Storefront receipt settings for the General > Customers section. Lets
 * customers download their order receipt (as a PDF, rendered with the store's
 * receipt template) from My Account > Orders. Off by default (opt-in).
 */
export function StorefrontReceiptSection({
	enabled,
	template,
	onChange,
}: StorefrontReceiptSectionProps) {
	return (
		<>
			<FormRow>
				<Label tip={t('settings.storefront_receipt_enabled_tip')}>
					<Toggle
						checked={enabled}
						onChange={(storefront_receipt_enabled: boolean) =>
							onChange({ storefront_receipt_enabled })
						}
						label={t('settings.storefront_receipt_enabled')}
					/>
				</Label>
			</FormRow>
			{enabled && (
				<FormRow label={t('settings.storefront_receipt_template')}>
					<Label tip={t('settings.storefront_receipt_template_tip')}>
						<React.Suspense
							fallback={<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />}
						>
							<TemplateSelect
								value={template}
								onChange={(storefront_receipt_template) =>
									onChange({ storefront_receipt_template })
								}
							/>
						</React.Suspense>
					</Label>
				</FormRow>
			)}
		</>
	);
}
