import * as React from 'react';
import { useId } from '@reach/auto-id';

interface ToggleProps {
	checked: boolean;
	onChange(checked: boolean): void;
	disabled?: boolean;
	name: string;
}

const Toggle = ({ checked, onChange, disabled, name }: ToggleProps) => {
	const id = useId(name);

	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
		!disabled && onChange(event.target.checked);
	};

	return (
		<label htmlFor={id} className="relative block w-12 h-6 select-none cursor-pointer text-left">
			<input
				checked={checked}
				onChange={handleChange}
				type="checkbox"
				name={name}
				id={id}
				className="hidden"
			/>
			<span
				className={`${
					checked ? 'bg-wp-admin-theme-color' : 'bg-gray-200'
				} absolute left-0 top-0 h-full w-full bg-gray-100 rounded-full transition-colors`}
			></span>
			<span
				className={`${
					checked ? 'translate-x-6 border-wp-admin-theme-color' : 'border-gray-200'
				} h-6 w-6 border-2 absolute rounded-full bg-white transform duration-300 ease-in-out`}
			></span>
		</label>
	);
};

export default Toggle;
