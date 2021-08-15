import * as React from 'react';
import { Switch } from '@headlessui/react';

interface ToggleProps {
	checked: boolean;
	onChange(checked: boolean): void;
}

const Toggle = ({ checked, onChange }: ToggleProps) => {
	return (
		<Switch
			checked={checked}
			onChange={onChange}
			className={`${
				checked ? 'bg-wp-admin-theme-color' : 'bg-gray-200'
			} mt-1 sm:mt-0 sm:col-span-2 relative inline-flex items-center h-6 rounded-full w-11 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-wp-admin-theme-color`}
		>
			<span
				className={`${
					checked ? 'translate-x-6' : 'translate-x-1'
				} inline-block w-4 h-4 transform bg-white rounded-full transition-transform`}
			/>
		</Switch>
	);
};

export default Toggle;
