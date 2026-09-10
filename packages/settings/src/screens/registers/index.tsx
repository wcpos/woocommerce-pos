import * as React from 'react';

import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { Chip } from '@wcpos/ui';

import Notice from '../../components/notice';
import { ListSkeleton } from '../../components/skeleton';
import { Button, TextInput } from '../../components/ui';
import useNotices from '../../hooks/use-notices';
import { t } from '../../translations';

interface Register {
	id: string;
	name: string;
	platform: string;
	last_seen_at_gmt: string;
	default_float: string | null;
	status: 'active' | 'retired';
}

type RegisterEdit = Partial<Pick<Register, 'name' | 'default_float' | 'status'>>;

function Registers() {
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();
	const { data } = useSuspenseQuery({
		queryKey: ['registers'],
		queryFn: () =>
			apiFetch<Register[]>({
				path: '/wcpos/v2/registers?status=all',
				method: 'GET',
				headers: { 'X-WCPOS': '1' },
			}),
	});
	const mutation = useMutation({
		mutationFn: ({ id, fields }: { id: string; fields: RegisterEdit }) =>
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
	const saveInput = (
		event: React.FocusEvent<HTMLInputElement>,
		row: Register,
		field: 'name' | 'default_float'
	) => {
		const input = event.currentTarget;
		if (!input.reportValidity()) return;
		const value = field === 'default_float' && input.value === '' ? null : input.value;
		if (value !== row[field]) mutation.mutate({ id: row.id, fields: { [field]: value } });
	};

	return (
		<div className="wcpos:p-4">
			{data.length === 0 ? (
				<Notice status="info">{t('registers.empty', 'No registers have connected yet.')}</Notice>
			) : (
				<div className="wcpos:overflow-x-auto">
					<table className="wcpos:w-full wcpos:text-sm wcpos:text-left">
						<thead>
							<tr className="wcpos:border-b wcpos:border-gray-200">
								{[
									t('registers.name', 'Name'),
									t('registers.platform', 'Platform'),
									t('registers.last_seen', 'Last seen'),
									t('registers.default_float', 'Default float'),
									t('registers.status', 'Status'),
								].map((label) => (
									<th key={label} scope="col" className="wcpos:p-3">
										{label}
									</th>
								))}
							</tr>
						</thead>
						<tbody>
							{data.map((row) => (
								<tr
									key={row.id}
									className="wcpos:border-b wcpos:border-gray-100"
									data-testid={`register-${row.id}`}
								>
									<td className="wcpos:p-3">
										<TextInput
											key={row.name}
											aria-label={t('registers.name', 'Name')}
											defaultValue={row.name}
											required
											maxLength={191}
											disabled={mutation.isPending}
											onBlur={(event) => saveInput(event, row, 'name')}
											onKeyDown={(event) => {
												if (event.key === 'Enter') event.currentTarget.blur();
											}}
										/>
									</td>
									<td className="wcpos:p-3">{row.platform || '—'}</td>
									<td className="wcpos:p-3">{new Date(row.last_seen_at_gmt).toLocaleString()}</td>
									<td className="wcpos:p-3">
										<TextInput
											key={row.default_float ?? 'empty'}
											aria-label={t('registers.default_float', 'Default float')}
											type="number"
											min="0"
											step="0.0001"
											defaultValue={row.default_float ?? ''}
											disabled={mutation.isPending}
											onBlur={(event) => saveInput(event, row, 'default_float')}
											onKeyDown={(event) => {
												if (event.key === 'Enter') event.currentTarget.blur();
											}}
										/>
									</td>
									<td className="wcpos:p-3">
										<div className="wcpos:flex wcpos:items-center wcpos:gap-3">
											<Chip variant={row.status === 'active' ? 'success' : 'neutral'}>
												{row.status === 'active'
													? t('registers.active', 'Active')
													: t('registers.retired', 'Retired')}
											</Chip>
											<Button
												disabled={mutation.isPending}
												onClick={() =>
													mutation.mutate({
														id: row.id,
														fields: { status: row.status === 'active' ? 'retired' : 'active' },
													})
												}
											>
												{row.status === 'active'
													? t('registers.retire', 'Retire')
													: t('registers.reactivate', 'Reactivate')}
											</Button>
										</div>
									</td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
		</div>
	);
}

export default function RegistersWithSuspense() {
	return (
		<React.Suspense fallback={<ListSkeleton rows={5} />}>
			<Registers />
		</React.Suspense>
	);
}
