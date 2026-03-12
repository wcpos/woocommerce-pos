import { useState } from 'react';

interface ThermalElementsProps {
	searchFilter: string;
	onInsertField: (text: string) => void;
}

const ELEMENTS = [
	{
		key: 'barcode',
		label: 'Barcode',
		snippet: '<barcode type="code128" height="60">{{meta.order_number}}</barcode>',
	},
	{
		key: 'qrcode',
		label: 'QR Code',
		snippet: '<qrcode size="4">{{meta.order_number}}</qrcode>',
	},
	{
		key: 'line-single',
		label: 'Line (single)',
		snippet: '<line />',
	},
	{
		key: 'line-double',
		label: 'Line (double)',
		snippet: '<line style="double" />',
	},
	{
		key: 'row',
		label: 'Row / Columns',
		snippet: '<row>\n  <col width="24"></col>\n  <col width="24" align="right"></col>\n</row>',
	},
	{
		key: 'feed',
		label: 'Feed (blank lines)',
		snippet: '<feed lines="1" />',
	},
	{
		key: 'cut',
		label: 'Cut',
		snippet: '<cut />',
	},
	{
		key: 'image',
		label: 'Image',
		snippet: '<image src="" width="200" />',
	},
];

export function ThermalElements({ searchFilter, onInsertField }: ThermalElementsProps) {
	const [expanded, setExpanded] = useState(false);

	const filtered = ELEMENTS.filter(({ key, label }) => {
		if (!searchFilter) return true;
		const lower = searchFilter.toLowerCase();
		return key.toLowerCase().includes(lower) || label.toLowerCase().includes(lower);
	});

	if (searchFilter && filtered.length === 0) return null;

	const isExpanded = searchFilter ? true : expanded;

	return (
		<div className="wcpos:mb-1 wcpos:mt-2 wcpos:pt-2 wcpos:border-t wcpos:border-gray-200">
			<button
				type="button"
				onClick={() => setExpanded(!expanded)}
				className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:w-full wcpos:px-2 wcpos:py-1 wcpos:text-left wcpos:text-sm wcpos:font-semibold wcpos:text-gray-700 hover:wcpos:bg-gray-100 wcpos:rounded"
			>
				<span className="wcpos:text-xs wcpos:text-gray-400 wcpos:w-4">
					{isExpanded ? '\u25BC' : '\u25B6'}
				</span>
				Elements
			</button>

			{isExpanded && (
				<div className="wcpos:ml-5">
					{filtered.map(({ key, label, snippet }) => (
						<button
							key={key}
							type="button"
							onClick={() => onInsertField(snippet)}
							className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:w-full wcpos:px-2 wcpos:py-0.5 wcpos:text-left wcpos:text-sm wcpos:text-gray-600 hover:wcpos:bg-blue-50 hover:wcpos:text-blue-700 wcpos:rounded"
							title={snippet}
						>
							<span className="wcpos:truncate">{label}</span>
							<span className="wcpos:ml-auto wcpos:text-xs wcpos:text-purple-600">&lt;/&gt;</span>
						</button>
					))}
				</div>
			)}
		</div>
	);
}
