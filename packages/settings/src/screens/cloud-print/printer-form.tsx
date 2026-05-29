import * as React from 'react';

import { t } from '../../translations';

import type { CloudPrinter } from '../../hooks/use-cloud-print-settings';

export function PrinterForm({ onAdd }: { onAdd: (printer: CloudPrinter) => void }) {
	const [id, setId] = React.useState('');
	const [name, setName] = React.useState('');
	const [provider, setProvider] = React.useState<CloudPrinter['provider']>('star-cloudprnt');
	const trimmedId = id.trim();

	return (
		<div className="wcpos:flex wcpos:flex-col wcpos:gap-2 wcpos:mt-3">
			<input
				data-testid="cloud-printer-id-input"
				placeholder={t('cloud_print.printer_id', 'Printer ID')}
				value={id}
				onChange={(e) => setId(e.target.value)}
			/>
			<input
				data-testid="cloud-printer-name-input"
				placeholder={t('cloud_print.printer_name', 'Printer name')}
				value={name}
				onChange={(e) => setName(e.target.value)}
			/>
			<select
				data-testid="cloud-printer-protocol-select"
				value={provider}
				onChange={(e) => setProvider(e.target.value as CloudPrinter['provider'])}
			>
				<option value="star-cloudprnt">Star CloudPRNT</option>
				<option value="epson-sdp">Epson Server Direct Print</option>
			</select>
			<button
				type="button"
				data-testid="cloud-printer-add"
				disabled={'' === trimmedId}
				onClick={() => {
					if ('' === trimmedId) {
						return;
					}
					onAdd({
						id: trimmedId,
						name: '' === name ? trimmedId : name,
						provider,
						store_id: 0,
					});
					setId('');
					setName('');
				}}
			>
				{t('cloud_print.add_printer', 'Add printer')}
			</button>
		</div>
	);
}
