import * as React from 'react';
import apiFetch from '@wordpress/api-fetch';
import {
	Combobox,
	ComboboxInput,
	ComboboxPopover,
	ComboboxList,
	ComboboxOption,
} from '@reach/combobox';
import { SelectorIcon } from '@heroicons/react/solid';
import { throttle } from 'lodash';

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
	const [users, setUsers] = React.useState<UserOptionProps[]>([]);
	const [term, setTerm] = React.useState<string>('');
	const [selectedUser, setSelectedUser] = React.useState(initialOption);

	React.useEffect(() => {
		async function getUsers() {
			setUsers([{ value: 0, label: 'Loading' }]);

			const users = await apiFetch({
				path: `wp/v2/users?search=${term}`,
				method: 'GET',
			})
				.catch((err) => {
					console.log(err);
				})
				.finally(() => {
					// setUsers([]);
				});

			if (Array.isArray(users)) {
				const userOptions = users.map((user) => {
					return {
						value: user.id,
						label: user.name,
					};
				});
				// userOptions.unshift({ value: 0, label: 'Guest' });
				setUsers(userOptions);
			}
		}

		getUsers();
	}, [term, setUsers]);

	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => setTerm(event.target.value);

	return (
		<Combobox
			aria-labelledby="user-select"
			onSelect={(val: any) => {
				// https://github.com/reach/reach-ui/issues/502
			}}
			openOnFocus={true}
		>
			<div className="relative">
				<ComboboxInput
					id="user-select"
					name="user-select"
					placeholder={selectedUser.label}
					disabled={disabled}
					onChange={throttle(handleChange, 100)}
					className="w-full px-2 pr-10 rounded border border-gray-300 leading-8 focus:border-wp-admin-theme-color"
				/>
				<SelectorIcon
					className="absolute p-1.5 m-px top-0 right-0 w-8 h-8 text-gray-400 pointer-events-none"
					aria-hidden="true"
				/>
			</div>
			<ComboboxPopover className="mt-1 overflow-auto text-base bg-white border-0 rounded-md shadow-lg max-h-60 ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
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
						<div className="p-2">No user found</div>
					)}
				</ComboboxList>
			</ComboboxPopover>
		</Combobox>
	);
};

export default UserSelect;
