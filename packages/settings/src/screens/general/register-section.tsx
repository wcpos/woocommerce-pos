import * as React from 'react';

import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { MoneyInput, TimeInput, Toggle } from '@wcpos/ui';

import { FormRow, FormSection } from '../../components/form';
import { FormSkeleton } from '../../components/skeleton';
import useNotices from '../../hooks/use-notices';
import { captureUpgradeCtaClicked } from '../../lib/analytics';
import { t } from '../../translations';

import type { GeneralSettingsProps } from './index';

// Product defaults: wcpos/roadmap#464 board, sections 6 and 7 (2026-10-21).
const DEFAULT_VARIANCE_LIMIT = '5.00';
const DEFAULT_CLOSE_TIME = '17:30';
const DEFAULT_OPENING_FLOAT = '200.00';
const UPGRADE_URL = 'https://wcpos.com/pro';
const sentence = 'wcpos:flex wcpos:flex-wrap wcpos:items-center wcpos:gap-2 wcpos:text-sm';
// The register stores the float with four decimals; the control shows the store's price decimals.
const formatFloat = (value: string) =>
	Number(value).toFixed(window.wcpos?.settings?.currencyDecimals ?? 2);

export interface RegisterSectionProps {
	data?: Partial<GeneralSettingsProps>;
	mutate: (data: Record<string, unknown>) => void;
}
interface Register {
	id: string;
	default_float: string | null;
}
const registersQuery = {
	queryKey: ['registers'],
	queryFn: () =>
		apiFetch<Register[]>({
			path: '/wcpos/v2/registers',
			method: 'GET',
			headers: { 'X-WCPOS': '1' },
		}),
};
const blurOnEnter = (event: React.KeyboardEvent<HTMLInputElement>) => {
	if (event.key === 'Enter') event.currentTarget.blur();
};

function OpeningFloat({
	save,
	pending,
	symbol,
}: {
	save: (register: Register, value: string) => void;
	pending: boolean;
	symbol: string;
}) {
	const { data } = useSuspenseQuery(registersQuery);
	const register = data[0];
	return (
		<FormRow description={t('settings.opening_float_help')}>
			<div className={sentence}>
				<label htmlFor="opening-float">{t('settings.opening_float')}</label>
				<div className="wcpos:w-32">
					<MoneyInput
						id="opening-float"
						symbol={symbol}
						required
						key={register?.default_float}
						defaultValue={formatFloat(register?.default_float ?? DEFAULT_OPENING_FLOAT)}
						disabled={!register || pending}
						onKeyDown={blurOnEnter}
						onBlur={(event) => {
							if (!event.currentTarget.value) {
								event.currentTarget.value = formatFloat(
									register?.default_float ?? DEFAULT_OPENING_FLOAT
								);
								return;
							}
							if (register && event.currentTarget.reportValidity())
								save(register, event.currentTarget.value);
						}}
					/>
				</div>
			</div>
		</FormRow>
	);
}

export function RegisterSection({ data, mutate }: RegisterSectionProps) {
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();
	const symbol = window.wcpos?.settings?.currencySymbol ?? '';
	const reportError = (error: Error) =>
		setNotice({ type: 'error', message: error.message || t('settings.register_save_failed') });
	const mutation = useMutation({
		mutationFn: ({ id, default_float }: { id: string; default_float: string }) =>
			apiFetch<Register>({
				path: `/wcpos/v2/registers/${id}`,
				method: 'PATCH',
				headers: { 'X-WCPOS': '1' },
				data: { default_float },
			}),
		onSuccess: () => queryClient.invalidateQueries({ queryKey: ['registers'] }),
		onError: reportError,
	});
	const saveFloat = (register: Register, value: string) => {
		if (Number(value) !== Number(register.default_float))
			mutation.mutate({ id: register.id, default_float: value });
	};

	return (
		<FormSection title={t('settings.register_section_title')} divider>
			<FormRow>
				<Toggle
					checked={!!data?.register_sessions}
					label={t('settings.open_close_register')}
					description={t('settings.open_close_register_help')}
					onChange={(register_sessions) => {
						mutate({ register_sessions });
						if (register_sessions) {
							queryClient
								.fetchQuery(registersQuery)
								.then(([register]) => {
									if (register?.default_float === null) saveFloat(register, DEFAULT_OPENING_FLOAT);
								})
								.catch(reportError);
						}
					}}
				/>
			</FormRow>
			{data?.register_sessions && (
				<>
					<React.Suspense fallback={<FormSkeleton rows={1} />}>
						<OpeningFloat save={saveFloat} pending={mutation.isPending} symbol={symbol} />
					</React.Suspense>

					{(
						[
							['variance_threshold', 'ask_manager', 'by_more_than', DEFAULT_VARIANCE_LIMIT],
							['expected_close_time', 'close_reminder', 'close_at', DEFAULT_CLOSE_TIME],
						] as const
					).map(([key, label, connector, defaultValue]) => {
						const input = {
							id: key,
							required: true,
							'aria-label': t('settings.' + label),
							defaultValue: data[key] ?? defaultValue,
							onKeyDown: blurOnEnter,
							onBlur: (event: React.FocusEvent<HTMLInputElement>) => {
								if (!event.currentTarget.value) {
									event.currentTarget.value = data[key] ?? defaultValue;
									return;
								}
								if (event.currentTarget.reportValidity())
									mutate({ [key]: event.currentTarget.value });
							},
						};
						return (
							<FormRow
								key={key}
								description={data[key] != null ? t('settings.' + label + '_help') : undefined}
							>
								<div className={sentence}>
									<Toggle
										checked={data[key] != null}
										label={t('settings.' + label)}
										onChange={(enabled) => mutate({ [key]: enabled ? defaultValue : null })}
									/>
									{data[key] != null && (
										<>
											<label htmlFor={key}>{t('settings.' + connector)}</label>
											<div className="wcpos:w-36">
												{key === 'variance_threshold' ? (
													<MoneyInput key={data[key]} {...input} symbol={symbol} />
												) : (
													<TimeInput key={data[key]} {...input} />
												)}
											</div>
										</>
									)}
								</div>
							</FormRow>
						);
					})}
				</>
			)}
			<p className="wcpos:text-xs wcpos:text-gray-500">
				<a
					href={UPGRADE_URL}
					target="_blank"
					rel="noreferrer noopener"
					onClick={() => captureUpgradeCtaClicked('general_register', UPGRADE_URL)}
				>
					{t('settings.more_registers_pro')}
				</a>
			</p>
		</FormSection>
	);
}
