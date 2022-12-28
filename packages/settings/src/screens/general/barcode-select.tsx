import * as React from 'react';

import ChevronUpDownIcon from '@heroicons/react/24/solid/ChevronUpDownIcon';
import {
	Combobox,
	ComboboxInput,
	ComboboxPopover,
	ComboboxList,
	ComboboxOption,
} from '@reach/combobox';
import { useQueryClient, useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { throttle } from 'lodash';

interface BarcodeSelect {
	selected: string;
	onSelect: (value: string) => void;
}

const BarcodeSelect = ({ selected, onSelect }: BarcodeSelect) => {
	const [term, setTerm] = React.useState('');
	const { isLoading, isError, data, error } = useQuery({
		queryKey: ['barcodes'],
		queryFn: async () => {
			const response = await apiFetch({
				path: `wcpos/v1/settings/general/barcodes?wcpos=1`,
				method: 'GET',
			}).catch((err) => {
				throw new Error(err.message);
			});

			// convert to array
			return Object.values(response);
		},
	});

	// const results = isLoading ? [] : data;

	const results = React.useMemo(() => {
		const options = isLoading ? [] : data;
		if (!options.includes(selected)) {
			options.push(selected);
		}
		return options.sort().filter((option) => option.includes(term.trim().toLocaleLowerCase()));
	}, [term, data, selected, isLoading]);

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
				<ChevronUpDownIcon
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
