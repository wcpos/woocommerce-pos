import * as React from 'react';

import ChevronUpDownIcon from '@heroicons/react/24/solid/ChevronUpDownIcon';
import {
	ListboxInput,
	ListboxOption,
	ListboxButton,
	ListboxPopover,
	ListboxList,
} from '@reach/listbox';

interface OptionProps {
	label: string;
	value: string;
}

interface SelectProps {
	options: OptionProps[];
	selected: string;
	onChange: (value: string) => void;
	name: string;
}

const Select = ({ options, selected, onChange, name }: SelectProps) => {
	return (
		<ListboxInput
			defaultValue={selected}
			onChange={onChange}
			aria-labelledby={name}
			name={name}
			id={name}
		>
			<ListboxButton
				className="wcpos-w-full wcpos-rounded wcpos-border wcpos-border-gray-300 wcpos-leading-8 focus:wcpos-border-wp-admin-theme-color"
				arrow={<ChevronUpDownIcon className="wcpos-w-5 wcpos-h-5 wcpos-text-gray-400" />}
			/>
			<ListboxPopover className="wcpos-mt-1 wcpos-overflow-auto wcpos-text-base wcpos-bg-white wcpos-border-0 wcpos-rounded-md wcpos-shadow-lg wcpos-max-h-60 sm:wcpos-text-sm">
				<ListboxList>
					{options.map((option) => (
						<ListboxOption key={option.value} value={option.value}>
							{option.label}
						</ListboxOption>
					))}
				</ListboxList>
			</ListboxPopover>
		</ListboxInput>
	);
};

export default Select;
