import * as React from 'react';

import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { FormRow } from '../../components/form';
import Notice from '../../components/notice';
import { ListSkeleton } from '../../components/skeleton';
import { TextInput } from '../../components/ui';
import useNotices from '../../hooks/use-notices';
import { captureUpgradeCtaClicked } from '../../lib/analytics';
import { t } from '../../translations';

const UPGRADE_URL = 'https://wcpos.com/pro';

interface Register {
	id: string;
	name: string;
	default_float: string | null;
}

/** Free holds one register: edit its name and default float. */
function Registers() {
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();
	const { data } = useSuspenseQuery({
		queryKey: ['registers'],
		queryFn: () =>
			apiFetch<Register[]>({
				path: '/wcpos/v2/registers',
				method: 'GET',
				headers: { 'X-WCPOS': '1' },
			}),
	});
	const mutation = useMutation({
		mutationFn: ({ id, fields }: { id: string; fields: Partial<Register> }) =>
			apiFetch<Register>({
				path: `/wcpos/v2/registers/${id}`,
				method: 'PATCH',
				headers: { 'X-WCPOS': '1' },
				data: fields,
			}),
		onSuccess: () => queryClient.invalidateQueries({ queryKey: ['registers'] }),
		onError: (error: Error) =>
			setNotice({
				type: 'error',
				message: error.message || t('registers.save_failed', 'Register could not be saved.'),
			}),
	});
	const register = data[0];
	const saveInput = (
		event: React.FocusEvent<HTMLInputElement>,
		field: 'name' | 'default_float'
	) => {
		const input = event.currentTarget;
		if (!register || !input.reportValidity()) return;
		const value = field === 'default_float' && input.value === '' ? null : input.value;
		if (value !== register[field]) mutation.mutate({ id: register.id, fields: { [field]: value } });
	};
	const blurOnEnter = (event: React.KeyboardEvent<HTMLInputElement>) => {
		if (event.key === 'Enter') event.currentTarget.blur();
	};

	return (
		<div className="wcpos:p-4 wcpos:max-w-md">
			{register ? (
				<div
					className="wcpos:rounded-md wcpos:border wcpos:border-gray-200 wcpos:p-4"
					data-testid={`register-${register.id}`}
				>
					<FormRow label={t('registers.name', 'Name')}>
						<TextInput
							key={register.name}
							aria-label={t('registers.name', 'Name')}
							defaultValue={register.name}
							required
							maxLength={191}
							disabled={mutation.isPending}
							onBlur={(event) => saveInput(event, 'name')}
							onKeyDown={blurOnEnter}
						/>
					</FormRow>
					<FormRow label={t('registers.default_float', 'Default float')}>
						<TextInput
							key={register.default_float ?? 'empty'}
							aria-label={t('registers.default_float', 'Default float')}
							type="number"
							min="0"
							step="0.0001"
							defaultValue={register.default_float ?? ''}
							disabled={mutation.isPending}
							onBlur={(event) => saveInput(event, 'default_float')}
							onKeyDown={blurOnEnter}
						/>
					</FormRow>
				</div>
			) : (
				<Notice status="info">{t('registers.empty', 'No registers have connected yet.')}</Notice>
			)}
			<p className="wcpos:mt-4 wcpos:text-sm">
				{t(
					'registers.one_register',
					'One register per site. Two devices share it; more registers need WCPOS Pro.'
				)}{' '}
				<a
					href={UPGRADE_URL}
					target="_blank"
					rel="noreferrer noopener"
					onClick={() => captureUpgradeCtaClicked('registers_screen', UPGRADE_URL)}
				>
					{t('common.upgrade_to_pro')}
				</a>
			</p>
		</div>
	);
}

/** Pro registers `registers.screen` to replace this whole screen with its registers list. */
function getRegistersScreenOverride(): React.ComponentType | null {
	const component = (window as any)?.wcpos?.settings?.getComponent?.('registers.screen');
	return component ? (component as React.ComponentType) : null;
}

export default function RegistersWithSuspense() {
	const [Override] = React.useState(getRegistersScreenOverride);
	return (
		<React.Suspense fallback={<ListSkeleton rows={5} />}>
			{Override ? <Override /> : <Registers />}
		</React.Suspense>
	);
}
