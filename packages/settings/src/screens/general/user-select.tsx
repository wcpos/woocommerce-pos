import * as React from 'react';

import ChevronUpDownIcon from '@heroicons/react/24/solid/ChevronUpDownIcon';
import {
	Combobox,
	ComboboxInput,
	ComboboxPopover,
	ComboboxList,
	ComboboxOption,
} from '@reach/combobox';
import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { throttle, get } from 'lodash';

import { t } from '../../translations';

interface UserOptionProps {
	value: number;
	label: string;
}

interface UserSelectProps {
	disabled?: boolean;
	selected: number;
	onSelect: (value: number) => void;
}

interface User {
	id: number;
	name: string;
}

const UserSelect = ({ disabled = false, selected, onSelect }: UserSelectProps) => {
	const [term, setTerm] = React.useState<string>('');
	const guestUser: User = { id: 0, name: t('Guest', { _tags: 'wp-admin-settings' }) };

	const { data } = useQuery<User[]>({
		queryKey: ['users'],
		queryFn: async () => {
			const response = await apiFetch({
				path: `wp/v2/users?search=${term}`,
				method: 'GET',
			}).catch((err) => {
				throw new Error(err.message);
			});

			if (Array.isArray(response)) {
				response.unshift(guestUser);
				return response;
			}

			return [];
		},
		placeholderData: [guestUser],
	});

	const users = (data || []).map((user) => {
		return {
			value: user.id,
			label: user.name,
		};
	});

	const selectedUser = users.find((user) => user.value === selected);

	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => setTerm(event.target.value);

	return (
		<Combobox
			aria-labelledby="user-select"
			onSelect={(val: any) => {
				// https://github.com/reach/reach-ui/issues/502
			}}
			openOnFocus={true}
		>
			<div className="wcpos-relative">
				<ComboboxInput
					id="user-select"
					name="user-select"
					placeholder={(selectedUser && selectedUser.label) || ''}
					disabled={disabled}
					onChange={throttle(handleChange, 100)}
					className="wcpos-w-full wcpos-px-2 wcpos-pr-10 wcpos-rounded wcpos-border wcpos-border-gray-300 wcpos-leading-8 focus:wcpos-border-wp-admin-theme-color"
				/>
				<ChevronUpDownIcon
					className="wcpos-absolute wcpos-p-1.5 wcpos-m-px wcpos-top-0 wcpos-right-0 wcpos-w-8 wcpos-h-8 wcpos-text-gray-400 wcpos-pointer-events-none"
					aria-hidden="true"
				/>
			</div>
			<ComboboxPopover className="wcpos-mt-1 wcpos-overflow-auto wcpos-text-base wcpos-bg-white border-0 wcpos-rounded-md wcpos-shadow-lg wcpos-max-h-60 wcpos-ring-1 wcpos-ring-black wcpos-ring-opacity-5 focus:wcpos-outline-none sm:wcpos-text-sm">
				<ComboboxList>
					{users.length > 0 ? (
						users.map((option) => (
							<ComboboxOption
								key={option.value}
								value={option.label}
								onClick={() => {
									onSelect(option.value);
								}}
							/>
						))
					) : (
						<div className="wcpos-p-2">No user found</div>
					)}
				</ComboboxList>
			</ComboboxPopover>
		</Combobox>
	);
};

export default UserSelect;
