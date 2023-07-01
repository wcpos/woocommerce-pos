import * as React from 'react';

import Combobox from '../../components/combobox';
import useNotices from '../../hooks/use-notices';

interface BarcodeSelectProps {
	selected: string;
	onSelect: (value: string | null) => void;
}

const BarcodeSelect = ({ selected, onSelect }: BarcodeSelectProps) => {
	const [query, setQuery] = React.useState('');
	const barcodes = window?.wcpos?.settings?.barcodes;

	const options = React.useMemo(() => {
		return (barcodes || []).map((option) => {
			return {
				value: option,
				label: option,
			};
		});
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
