import * as React from 'react';
import {
	Combobox,
	ComboboxInput,
	ComboboxPopover,
	ComboboxList,
	ComboboxOption,
} from '@reach/combobox';
import SelectorIcon from '@heroicons/react/solid/SelectorIcon';
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
			<div className="wcpos-relative">
				<ComboboxInput
					id="barcode-field"
					name="barcode-field"
					placeholder={selected}
					onChange={throttle(handleChange, 100)}
					onKeyDown={handleKeyDown}
					className="wcpos-w-full wcpos-px-2 wcpos-pr-10 wcpos-rounded wcpos-border wcpos-border-gray-300 wcpos-leading-8 focus:wcpos-border-wp-admin-theme-color"
				/>
				<SelectorIcon
					className="wcpos-absolute wcpos-p-1.5 wcpos-m-px wcpos-top-0 wcpos-right-0 wcpos-w-8 wcpos-h-8 wcpos-text-gray-400 wcpos-pointer-events-none"
					aria-hidden="true"
				/>
			</div>
			<ComboboxPopover className="wcpos-mt-1 wcpos-overflow-auto wcpos-text-base wcpos-bg-white wcpos-border-0 wcpos-rounded-md wcpos-shadow-lg wcpos-max-h-60 wcpos-ring-1 wcpos-ring-black wcpos-ring-opacity-5 focus:wcpos-outline-none sm:wcpos-text-sm">
				<ComboboxList>
					{results.length > 0 ? (
						results.map((option) => <ComboboxOption key={option} value={option} />)
					) : (
						<div className="wcpos-p-2">Press &lsquo;enter&rsquo; to add new field</div>
					)}
				</ComboboxList>
			</ComboboxPopover>
		</Combobox>
	);
};

export default BarcodeSelect;
