import * as React from 'react';

import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { Chip } from '@wcpos/ui';

import { FormRow } from '../../components/form';
import Notice from '../../components/notice';
import { ListSkeleton } from '../../components/skeleton';
import { Button, TextInput } from '../../components/ui';
import useNotices from '../../hooks/use-notices';
import { t } from '../../translations';

interface Register {
	id: string;
	name: string;
	store_id: number | null;
	platform: string;
	last_seen_at_gmt: string;
	default_float: string | null;
	status: 'active' | 'retired';
}

type RegisterEdit = Partial<Pick<Register, 'name' | 'default_float' | 'status' | 'store_id'>>;

function Registers() {
	const queryClient = useQueryClient();
	const [adding, setAdding] = React.useState(false);
	const [name, setName] = React.useState('');
	const [defaultFloat, setDefaultFloat] = React.useState('');
	const [storeId, setStoreId] = React.useState('');
	const { setNotice } = useNotices();
	const rawStoreOptions = window.wcpos?.settings?.cloudPrintStoreOptions;
	const storeOptions = Array.isArray(rawStoreOptions) ? rawStoreOptions : [];
	const { data } = useSuspenseQuery({
		queryKey: ['registers'],
		queryFn: () =>
			apiFetch<Register[]>({
				path: '/wcpos/v2/registers?status=all',
				method: 'GET',
				headers: { 'X-WCPOS': '1' },
			}),
	});
	const showStore = storeOptions.length > 1 || data.some((row) => row.store_id !== null);
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
	const create = useMutation({
		mutationFn: () =>
			apiFetch<Register>({
				path: '/wcpos/v2/registers',
				method: 'POST',
				headers: { 'X-WCPOS': '1' },
				data: {
					name,
					default_float: defaultFloat === '' ? null : defaultFloat,
					// Pro reads store_id off the body (Free's controller ignores it).
					...(storeId === '' ? {} : { store_id: Number(storeId) }),
				},
			}),
		onMutate: () => setNotice(null),
		onSuccess: () => {
			setAdding(false);
			setName('');
			setDefaultFloat('');
			return queryClient.invalidateQueries({ queryKey: ['registers'] });
		},
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
			<Button onClick={() => setAdding(true)} disabled={adding}>
				{t('registers.add', 'Add register')}
			</Button>
			{adding && (
				<form
					className="wcpos:my-4 wcpos:max-w-md"
					onSubmit={(event) => {
						event.preventDefault();
						create.mutate();
					}}
				>
					<FormRow label={t('registers.name', 'Name')}>
						<TextInput
							data-testid="new-register-name"
							required
							maxLength={191}
							value={name}
							onChange={(event) => setName(event.target.value)}
							disabled={create.isPending}
						/>
					</FormRow>
					<FormRow label={t('registers.default_float', 'Default float')}>
						<TextInput
							data-testid="new-register-float"
							pattern="\d+(?:\.\d+)?"
							value={defaultFloat}
							onChange={(event) => setDefaultFloat(event.target.value)}
							disabled={create.isPending}
						/>
					</FormRow>
					{storeOptions.length > 0 && (
						<FormRow label={t('registers.store', 'Store')}>
							<select
								data-testid="new-register-store"
								aria-label={t('registers.store', 'Store')}
								className="wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs wcpos:border-gray-300"
								value={storeId}
								disabled={create.isPending}
								onChange={(event) => setStoreId(event.currentTarget.value)}
							>
								<option value="">{t('registers.store_unassigned', 'Unassigned')}</option>
								{storeOptions.map((store) => (
									<option key={store.id} value={store.id}>
										{store.name}
									</option>
								))}
							</select>
						</FormRow>
					)}
					<div className="wcpos:flex wcpos:gap-2">
						<Button type="submit" disabled={create.isPending}>
							{t('registers.create', 'Create register')}
						</Button>
						<Button
							type="button"
							onClick={() => {
								setName('');
								setDefaultFloat('');
								setAdding(false);
							}}
							disabled={create.isPending}
						>
							{t('common.cancel', 'Cancel')}
						</Button>
					</div>
				</form>
			)}
			{data.length === 0 ? (
				<Notice status="info">{t('registers.empty', 'No registers have connected yet.')}</Notice>
			) : (
				<div className="wcpos:overflow-x-auto">
					<table className="wcpos:w-full wcpos:text-sm wcpos:text-left">
						<thead>
							<tr className="wcpos:border-b wcpos:border-gray-200">
								{[
									t('registers.name', 'Name'),
									...(showStore ? [t('registers.store', 'Store')] : []),
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
									{showStore && (
										<td className="wcpos:p-3">
											<select
												aria-label={t('registers.store', 'Store')}
												className="wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs wcpos:transition-colors wcpos:duration-150 wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-offset-0 wcpos:border-gray-300 wcpos:focus:border-wp-admin-theme-color wcpos:focus:ring-wp-admin-theme-color wcpos:disabled:bg-gray-50 wcpos:disabled:text-gray-500 wcpos:disabled:cursor-not-allowed"
												value={
													storeOptions.some((store) => store.id === row.store_id)
														? (row.store_id ?? '')
														: ''
												}
												disabled={mutation.isPending}
												onChange={(event) =>
													mutation.mutate({
														id: row.id,
														fields: { store_id: Number(event.currentTarget.value) },
													})
												}
											>
												<option value="" disabled={row.store_id !== null}>
													— {t('registers.store_unassigned', 'Unassigned')}
												</option>
												{storeOptions.map((store) => (
													<option key={store.id} value={store.id}>
														{store.name}
													</option>
												))}
											</select>
										</td>
									)}
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
