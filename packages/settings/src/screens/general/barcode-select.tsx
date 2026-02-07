import * as React from 'react';

import Combobox from '../../components/combobox';

interface BarcodeSelectProps {
	selected: string;
	onSelect: (value: string | null) => void;
}

const BarcodeSelect = ({ selected, onSelect }: BarcodeSelectProps) => {
	const [query, setQuery] = React.useState('');
	const barcodes = window?.wcpos?.settings?.barcodes;

	const options = React.useMemo(() => {
		const filtered = (barcodes || [])
			.filter((barcode) => barcode.toLowerCase().includes(query.toLowerCase()))
			.map((option) => ({
				value: option,
				label: option,
			}));

		const exactMatch = (barcodes || []).some(
			(barcode) => barcode.toLowerCase() === query.toLowerCase()
		);

		if (query && !exactMatch) {
			filtered.push({
				value: query,
				label: `Create "${query}"`,
			});
		}

		return filtered;
	}, [barcodes, query]);

	return (
		<Combobox
			value={selected}
			options={options}
			onChange={({ value }) => {
				onSelect(value);
			}}
			onSearch={(value) => setQuery(value)}
		/>
	);
};

export default BarcodeSelect;
