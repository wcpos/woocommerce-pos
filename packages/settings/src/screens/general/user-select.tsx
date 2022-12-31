import * as React from 'react';

import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl } from '@wordpress/components';

import useNotices from '../../hooks/use-notices';
import { t } from '../../translations';

interface UserSelectProps {
	disabled?: boolean;
	selected: number;
	onSelect: (value: number) => void;
}

/**
 * Note: ComboboxControl wants a string for the value, api returns a number for user.id
 */
interface User {
	id: number;
	name: string;
}

const UserSelect = ({ disabled = false, selected, onSelect }: UserSelectProps) => {
	const guestUser: User = { id: 0, name: t('Guest', { _tags: 'wp-admin-settings' }) };
	const { setNotice } = useNotices();

	const { data } = useQuery<User[]>({
		queryKey: ['users'],
		queryFn: async () => {
			const response = await apiFetch<Record<string, unknown>>({
				path: `wp/v2/users`,
				method: 'GET',
			}).catch((err) => {
				console.error(err);
				return err;
			});

			// if we have an error response, set the notice
			if (response?.code && response?.message) {
				setNotice({ type: 'error', message: response?.message });
			}

			if (Array.isArray(response)) {
				response.unshift(guestUser);
				return response;
			}

			return [];
		},
		placeholderData: [guestUser],
	});

	const options = React.useMemo(() => {
		return (data || []).map((user) => {
			return {
				value: String(user.id),
				label: user.name,
			};
		});
	}, [data]);

	return (
		<ComboboxControl
			value={String(selected || 0)}
			options={options}
			onChange={(value) => {
				const id = value ? Number(value) : 0;
				onSelect(id);
			}}
			allowReset={false}
		/>
	);
};

export default UserSelect;
