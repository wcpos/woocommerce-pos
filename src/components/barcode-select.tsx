import * as React from 'react';
import {
	Combobox,
	ComboboxInput,
	ComboboxPopover,
	ComboboxList,
	ComboboxOption,
	ComboboxOptionText,
} from '@reach/combobox';
import { SelectorIcon } from '@heroicons/react/solid';
import { throttle } from 'lodash';

interface BarcodeSelect {
	options: string[];
	selected: string;
	onSelect: (value: string) => void;
}

const BarcodeSelect = ({ options, selected, onSelect }: BarcodeSelect) => {
	const [term, setTerm] = React.useState('');

	const results = React.useMemo(() => {
		if (!options.includes(selected)) {
			options.push(selected);
		}
		return options.sort().filter((option) => option.includes(term.trim().toLocaleLowerCase()));
	}, [term, options, selected]);

	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => setTerm(event.target.value);

	const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
		if (event.key === 'Enter') {
			onSelect(term);
		}
	};

	return (
		<Combobox aria-labelledby="barcode-field" onSelect={onSelect} openOnFocus={true}>
			<div className="relative">
				<ComboboxInput
					id="barcode-field"
					name="barcode-field"
					placeholder={selected}
					onChange={throttle(handleChange, 100)}
					onKeyDown={handleKeyDown}
					className="w-full px-2 pr-10 rounded border border-gray-300 leading-8 focus:border-wp-admin-theme-color"
				/>
				<SelectorIcon
					className="absolute p-1.5 m-px top-0 right-0 w-8 h-8 text-gray-400 pointer-events-none"
					aria-hidden="true"
				/>
			</div>
			<ComboboxPopover className="mt-1 overflow-auto text-base bg-white border-0 rounded-md shadow-lg max-h-60 ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
				<ComboboxList>
					{results.length > 0 ? (
						results.map((option) => <ComboboxOption key={option} value={option} />)
					) : (
						<div className="p-2">Press &lsquo;enter&rsquo; to add new field</div>
					)}
				</ComboboxList>
			</ComboboxPopover>
		</Combobox>
	);
};

export default BarcodeSelect;
