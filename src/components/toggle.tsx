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
		<label
			htmlFor={id}
			className="wcpos-relative wcpos-block wcpos-w-12 wcpos-h-6 wcpos-select-none wcpos-cursor-pointer wcpos-text-left"
		>
			<input
				checked={checked}
				onChange={handleChange}
				type="checkbox"
				name={name}
				id={id}
				className="wcpos-hidden"
			/>
			<span
				className={`${
					checked ? 'wcpos-bg-wp-admin-theme-color' : 'wcpos-bg-gray-200'
				} wcpos-absolute wcpos-left-0 wcpos-top-0 wcpos-h-full wcpos-w-full wcpos-bg-gray-100 wcpos-rounded-full wcpos-transition-colors`}
			></span>
			<span
				className={`${
					checked
						? 'wcpos-translate-x-6 wcpos-border-wp-admin-theme-color'
						: 'wcpos-border-gray-200'
				} wcpos-h-6 wcpos-w-6 wcpos-border-2 wcpos-absolute wcpos-rounded-full wcpos-bg-white wcpos-transform wcpos-duration-300 wcpos-ease-in-out`}
			></span>
		</label>
	);
};

export default Toggle;
