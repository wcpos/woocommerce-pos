import * as React from 'react';
import { CustomCheckboxContainer, CustomCheckboxInput } from '@reach/checkbox';
import classNames from 'classnames';
import { CheckIcon } from '@heroicons/react/solid';

interface CheckboxProps {
	checked: boolean;
	onChange: (value: boolean) => void;
	disabled?: boolean;
}

const Checkbox = ({ checked = false, onChange, disabled = false }: CheckboxProps) => {
	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
		!disabled && onChange(event.target.checked);
	};

	return (
		<CustomCheckboxContainer
			checked={checked}
			onChange={handleChange}
			className={classNames(
				'w-6 h-6 border rounded focus:outline-none align-middle',
				checked && !disabled ? 'border-transparent bg-wp-admin-theme-color' : 'border-gray-300',
				disabled ? 'bg-gray-300 cursor-not-allowed' : ''
			)}
		>
			<CustomCheckboxInput onChange={handleChange} checked={checked} />
			<span aria-hidden className={classNames(checked ? 'inline' : 'hidden')}>
				<CheckIcon className="fill-current text-white" />
			</span>
		</CustomCheckboxContainer>
	);
};

export default Checkbox;
