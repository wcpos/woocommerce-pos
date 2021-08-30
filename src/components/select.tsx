import * as React from 'react';
import {
	ListboxInput,
	ListboxOption,
	ListboxButton,
	ListboxPopover,
	ListboxList,
} from '@reach/listbox';
import { SelectorIcon } from '@heroicons/react/solid';

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
				className="w-full rounded border border-gray-300 leading-8 focus:border-wp-admin-theme-color"
				arrow={<SelectorIcon className="w-5 h-5 text-gray-400" />}
			/>
			<ListboxPopover className="mt-1 overflow-auto text-base bg-white border-0 rounded-md shadow-lg max-h-60 sm:text-sm">
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
