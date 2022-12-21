import * as React from 'react';

import ChevronUpDownIcon from '@heroicons/react/24/solid/ChevronUpDownIcon';
import {
	Combobox,
	ComboboxInput,
	ComboboxPopover,
	ComboboxList,
	ComboboxOption,
} from '@reach/combobox';
import { useQueryClient, useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { throttle, get } from 'lodash';

interface UserOptionProps {
	value: number;
	label: string;
}

interface UserSelectProps {
	disabled?: boolean;
	initialOption: UserOptionProps;
	onSelect: (value: number) => void;
}

const UserSelect = ({ disabled = false, initialOption, onSelect }: UserSelectProps) => {
	// const [users, setUsers] = React.useState<UserOptionProps[]>([]);
	const [term, setTerm] = React.useState<string>('');
	const guestUser = { id: 0, name: 'Guest' };
	// const [selectedUser, setSelectedUser] = React.useState(initialOption);

	const { isLoading, isError, data, error } = useQuery({
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

	const users = data.map((user) => {
		return {
			value: user.id,
			label: user.name,
		};
	});

	// React.useEffect(() => {
	// 	async function getUsers() {
	// 		setUsers([{ value: 0, label: 'Loading' }]);

	// 		const users = await apiFetch({
	// 			path: `wp/v2/users?search=${term}`,
	// 			method: 'GET',
	// 		})
	// 			.catch((err) => {
	// 				console.log(err);
	// 			})
	// 			.finally(() => {
	// 				// setUsers([]);
	// 			});

	// 		if (Array.isArray(users)) {
	// 			const userOptions = users.map((user) => {
	// 				return {
	// 					value: user.id,
	// 					label: user.name,
	// 				};
	// 			});
	// 			// userOptions.unshift({ value: 0, label: 'Guest' });
	// 			setUsers(userOptions);
	// 		}
	// 	}

	// 	getUsers();
	// }, [term, setUsers]);

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
					placeholder={get(users, '0.label', '')}
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
									setSelectedUser(option);
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
