import * as React from 'react';

import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl } from '@wordpress/components';
import { throttle } from 'lodash';

import useNotices from '../../hooks/use-notices';

interface BarcodeSelectProps {
	selected: string;
	onSelect: (value: string | null) => void;
}

const BarcodeSelect = ({ selected, onSelect }: BarcodeSelectProps) => {
	const [term, setTerm] = React.useState('');
	const { setNotice } = useNotices();

	const { data } = useQuery<string[]>({
		queryKey: ['barcodes'],
		queryFn: async () => {
			const response = await apiFetch<string[]>({
				path: `wcpos/v1/settings/general/barcodes?wcpos=1`,
				method: 'GET',
			}).catch((err) => {
				console.error(err);
				return err;
			});

			// if we have an error response, set the notice
			if (response?.code && response?.message) {
				setNotice({ type: 'error', message: response?.message });
			}

			// convert to array
			return Object.values(response);
		},
		placeholderData: [],
	});

	const options = React.useMemo(() => {
		return (data || []).map((option) => {
			return {
				value: option,
				label: option,
			};
		});
	}, [data]);

	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => setTerm(event.target.value);

	const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
		if (event.key === 'Enter') {
			onSelect(term);
		}
	};

	return (
		<ComboboxControl value={selected} options={options} onChange={onSelect} allowReset={false} />
	);

	// return (
	// 	<Combobox aria-labelledby="barcode-field" onSelect={onSelect} openOnFocus={true}>
	// 		<div className="wcpos-relative">
	// 			<ComboboxInput
	// 				id="barcode-field"
	// 				name="barcode-field"
	// 				placeholder={selected}
	// 				onChange={throttle(handleChange, 100)}
	// 				onKeyDown={handleKeyDown}
	// 				className="wcpos-w-full wcpos-px-2 wcpos-pr-10 wcpos-rounded wcpos-border wcpos-border-gray-300 wcpos-leading-8 focus:wcpos-border-wp-admin-theme-color"
	// 			/>
	// 			<ChevronUpDownIcon
	// 				className="wcpos-absolute wcpos-p-1.5 wcpos-m-px wcpos-top-0 wcpos-right-0 wcpos-w-8 wcpos-h-8 wcpos-text-gray-400 wcpos-pointer-events-none"
	// 				aria-hidden="true"
	// 			/>
	// 		</div>
	// 		<ComboboxPopover className="wcpos-mt-1 wcpos-overflow-auto wcpos-text-base wcpos-bg-white wcpos-border-0 wcpos-rounded-md wcpos-shadow-lg wcpos-max-h-60 wcpos-ring-1 wcpos-ring-black wcpos-ring-opacity-5 focus:wcpos-outline-none sm:wcpos-text-sm">
	// 			<ComboboxList>
	// 				{results.length > 0 ? (
	// 					results.map((option) => <ComboboxOption key={option} value={option} />)
	// 				) : (
	// 					<div className="wcpos-p-2">Press &lsquo;enter&rsquo; to add new field</div>
	// 				)}
	// 			</ComboboxList>
	// 		</ComboboxPopover>
	// 	</Combobox>
	// );
};

export default BarcodeSelect;
