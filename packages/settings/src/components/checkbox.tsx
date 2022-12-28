import * as React from 'react';

import CheckIcon from '@heroicons/react/24/solid/CheckIcon';
import { CustomCheckboxContainer, CustomCheckboxInput } from '@reach/checkbox';
import classNames from 'classnames';

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
				'wcpos-w-6 wcpos-h-6 wcpos-border wcpos-rounded focus:wcpos-outline-none wcpos-align-middle',
				checked && !disabled
					? 'wcpos-border-transparent wcpos-bg-wp-admin-theme-color'
					: 'wcpos-border-gray-300',
				disabled ? 'wcpos-bg-gray-300 wcpos-cursor-not-allowed' : ''
			)}
		>
			<CustomCheckboxInput onChange={handleChange} checked={checked} />
			<span aria-hidden className={classNames(checked ? 'wcpos-inline' : 'wcpos-hidden')}>
				<CheckIcon className="wcpos-fill-current wcpos-text-white" />
			</span>
		</CustomCheckboxContainer>
	);
};

export default Checkbox;
