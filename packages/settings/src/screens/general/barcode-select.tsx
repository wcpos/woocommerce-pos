import * as React from 'react';

import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import Combobox from '../../components/combobox';
import useNotices from '../../hooks/use-notices';

interface BarcodeSelectProps {
	selected: string;
	onSelect: (value: string | null) => void;
}

const BarcodeSelect = ({ selected, onSelect }: BarcodeSelectProps) => {
	const [query, setQuery] = React.useState('');
	const { setNotice } = useNotices();

	const { data, isFetching } = useQuery<string[]>({
		queryKey: ['barcodes', query],
		queryFn: async () => {
			const response = await apiFetch<string[]>({
				path: `wcpos/v1/settings/general/barcodes?wcpos=1&search=${encodeURIComponent(query)}`,
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

	return (
		<Combobox
			value={selected}
			options={options}
			onChange={({ value }) => {
				onSelect(value);
			}}
			onSearch={(value) => setQuery(value)}
			loading={isFetching}
		/>
	);
};

export default BarcodeSelect;
