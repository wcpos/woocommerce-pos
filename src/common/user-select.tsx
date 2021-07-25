import * as React from 'react';
import apiFetch from '@wordpress/api-fetch';
// @ts-ignore
import { ComboboxControl } from '@wordpress/components';
import { debounce, isInteger } from 'lodash';

interface UserSelectProps {
	selectedUserId: number;
	dispatch: any;
	disabled?: boolean;
}

interface UserOptionProps {
	value?: number;
	label?: string;
}

const UserSelect = ({ selectedUserId = 0, dispatch, disabled = false }: UserSelectProps) => {
	const [users, setUsers] = React.useState<UserOptionProps[]>([{ value: 0, label: 'Guest ' }]);
	const [search, setSearch] = React.useState('');

	React.useEffect(() => {
		async function getUsers() {
			setUsers([{ value: undefined, label: 'Loading' }]);

			const users = await apiFetch({
				path: `wp/v2/users?search=${search}`,
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
				userOptions.unshift({ value: 0, label: 'Guest' });
				setUsers(userOptions);
			}
		}

		getUsers();
	}, [search, setUsers]);

	const handleFilterChange = (inputValue: string) => {
		setSearch(inputValue);
	};

	return (
		<ComboboxControl
			label="Default POS customer"
			value={selectedUserId}
			onChange={(id: number) => {
				const default_customer = isInteger(id) ? id : 0;
				dispatch({
					type: 'update',
					payload: { default_customer },
				});
			}}
			options={users}
			onFilterValueChange={debounce(handleFilterChange, 300)}
			allowReset={true}
			disabled
		/>
	);
};

export default UserSelect;
