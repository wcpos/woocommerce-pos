import * as React from 'react';

import { useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { PLUGIN_LABELS, TAX_ID_TYPE_LABELS, TaxIdsDetection, TaxIdsSettings } from './types';
import { FormRow, FormSection } from '../../components/form';
import { TextInput } from '../../components/ui';
import { t } from '../../translations';

interface Props {
	writeMap: Record<string, string>;
	mutate: (next: Partial<TaxIdsSettings>) => void;
}

function CompatibilitySection({ writeMap, mutate }: Props) {
	const { data } = useSuspenseQuery({
		queryKey: ['tax_ids_detection'],
		queryFn: async () => {
			return apiFetch<TaxIdsDetection>({
				path: 'wcpos/v1/settings/tax_ids/detection?wcpos=1',
				method: 'GET',
			});
		},
	});

	const handleOverrideBlur = React.useCallback(
		(type: string, defaultKey: string) =>
			(event: React.FocusEvent<HTMLInputElement>) => {
				const value = event.target.value.trim();
				const current = writeMap[type] ?? '';
				if (value === current) return;

				const next = { ...writeMap };
				// Empty input or matching default = drop the override so the
				// auto-detected map wins.
				if (value === '' || value === defaultKey) {
					delete next[type];
				} else {
					next[type] = value;
				}
				mutate({ write_map: next });
			},
		[mutate, writeMap]
	);

	return (
		<>
			<FormSection title={t('tax_ids.compatibility_section_title')}>
				<FormRow label={t('tax_ids.detected_plugins')}>
					{data.plugins.length === 0 ? (
						<p className="wcpos:text-sm wcpos:text-gray-500">
							{t('tax_ids.no_plugins_detected')}
						</p>
					) : (
						<ul className="wcpos:text-sm wcpos:text-gray-700 wcpos:list-disc wcpos:pl-5">
							{data.plugins.map((id) => (
								<li key={id}>{PLUGIN_LABELS[id] ?? id}</li>
							))}
						</ul>
					)}
				</FormRow>
			</FormSection>

			<FormSection
				title={t('tax_ids.write_map_section_title')}
				description={t('tax_ids.write_map_intro')}
			>
				<div className="wcpos:overflow-x-auto">
					<table className="wcpos:w-full wcpos:text-sm">
						<thead>
							<tr className="wcpos:text-left wcpos:text-gray-600">
								<th className="wcpos:py-1.5 wcpos:pr-3 wcpos:font-medium">
									{t('tax_ids.write_map_type')}
								</th>
								<th className="wcpos:py-1.5 wcpos:pr-3 wcpos:font-medium">
									{t('tax_ids.write_map_default')}
								</th>
								<th className="wcpos:py-1.5 wcpos:font-medium">
									{t('tax_ids.write_map_override')}
								</th>
							</tr>
						</thead>
						<tbody>
							{data.types.map((type) => {
								const composedKey = data.composed_write_map[type] ?? '';
								const defaultKey = data.default_write_map[type] ?? '';
								const override = writeMap[type] ?? '';
								return (
									<tr key={type} className="wcpos:border-t wcpos:border-gray-100">
										<td className="wcpos:py-2 wcpos:pr-3 wcpos:font-medium wcpos:text-gray-900">
											{TAX_ID_TYPE_LABELS[type] ?? type}
										</td>
										<td className="wcpos:py-2 wcpos:pr-3 wcpos:text-gray-500 wcpos:font-mono">
											{composedKey || defaultKey}
										</td>
										<td className="wcpos:py-2">
											<TextInput
												defaultValue={override}
												placeholder={t('tax_ids.write_map_use_default')}
												onBlur={handleOverrideBlur(type, defaultKey)}
												className="wcpos:font-mono"
											/>
										</td>
									</tr>
								);
							})}
						</tbody>
					</table>
				</div>
			</FormSection>
		</>
	);
}

export default CompatibilitySection;
