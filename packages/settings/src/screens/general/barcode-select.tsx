import * as React from 'react';

import { Combobox } from '../../components/ui';
import { t } from '../../translations';

interface BarcodeSelectProps {
	selected: string;
	onSelect: (value: string | null) => void;
}

function BarcodeSelect({ selected, onSelect }: BarcodeSelectProps) {
	const options = React.useMemo(() => {
		const barcodes = window?.wcpos?.settings?.barcodes ?? [];
		return barcodes.map((barcode) => ({ value: barcode, label: barcode }));
	}, []);

	return (
		<Combobox
			value={selected}
			options={options}
			onChange={(value) => onSelect(value || null)}
			allowCustomValue
			createLabel={(query) => t('settings.barcode_field_create', { value: query })}
			noResultsLabel={t('settings.barcode_field_no_results')}
			aria-label={t('settings.barcode_field')}
		/>
	);
}

export default BarcodeSelect;
