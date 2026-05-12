import { useState } from 'react';
import { Chip } from '@wcpos/ui';
import { t } from '../translations';

interface ThermalElementsProps {
	searchFilter: string;
	onInsertField: (text: string) => void;
}

interface ThermalElement {
	key: string;
	label: string;
	snippet: string;
}

function getElements(): ThermalElement[] {
	return [
		{
			key: 'barcode',
			label: t('element.barcode'),
			snippet: '<barcode type="code128" height="60">{{order.number}}</barcode>',
		},
		{
			key: 'qrcode',
			label: t('element.qr_code'),
			snippet: '<qrcode size="4">{{order.number}}</qrcode>',
		},
		{
			key: 'line-single',
			label: t('element.line_single'),
			snippet: '<line />',
		},
		{
			key: 'line-double',
			label: t('element.line_double'),
			snippet: '<line style="double" />',
		},
		{
			key: 'row',
			label: t('element.row_columns'),
			snippet: '<row>\n  <col width="24"></col>\n  <col width="24" align="right"></col>\n</row>',
		},
		{
			key: 'feed',
			label: t('element.feed'),
			snippet: '<feed lines="1" />',
		},
		{
			key: 'cut',
			label: t('element.cut'),
			snippet: '<cut />',
		},
		{
			key: 'image',
			label: t('element.image'),
			snippet: '<image src="" width="200" />',
		},
	];
}

function filterElements(elements: ThermalElement[], searchFilter: string): ThermalElement[] {
	if (!searchFilter) return elements;
	const lower = searchFilter.toLowerCase();
	return elements.filter(({ key, label }) =>
		key.toLowerCase().includes(lower) || label.toLowerCase().includes(lower)
	);
}

export function thermalMatchesSearch(searchFilter: string): boolean {
	if (!searchFilter) return true;
	return filterElements(getElements(), searchFilter).length > 0;
}

function Chevron({ open }: { open: boolean }) {
	return (
		<svg
			width="10"
			height="10"
			viewBox="0 0 10 10"
			aria-hidden="true"
			className="wcpos:text-gray-400 wcpos:shrink-0"
			style={{ transform: open ? 'rotate(90deg)' : 'none', transition: 'transform 120ms' }}
		>
			<path d="M3 2 L7 5 L3 8 Z" fill="currentColor" />
		</svg>
	);
}

export function ThermalElements({ searchFilter, onInsertField }: ThermalElementsProps) {
	const [expanded, setExpanded] = useState(false);

	const elements = getElements();
	const filtered = filterElements(elements, searchFilter);

	if (searchFilter && filtered.length === 0) return null;

	const isExpanded = searchFilter ? true : expanded;

	return (
		<div className="wcpos:mb-0.5 wcpos:mt-3 wcpos:pt-3 wcpos:border-t wcpos:border-gray-200">
			<button
				type="button"
				onClick={() => setExpanded(!expanded)}
				aria-expanded={isExpanded}
				className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:w-full wcpos:px-1.5 wcpos:py-1 wcpos:text-left wcpos:text-sm wcpos:font-semibold wcpos:text-gray-700 hover:wcpos:bg-gray-100 wcpos:rounded wcpos:border-0 wcpos:bg-transparent wcpos:cursor-pointer"
			>
				<Chevron open={isExpanded} />
				<span className="wcpos:truncate">{t('editor.elements')}</span>
				<span className="wcpos:ml-auto wcpos:text-xs wcpos:font-normal wcpos:text-gray-400 wcpos:tabular-nums">
					{elements.length}
				</span>
			</button>

			{isExpanded && (
				<div className="wcpos:ml-4 wcpos:mt-0.5">
					{filtered.map(({ key, label, snippet }) => (
						<button
							key={key}
							type="button"
							onClick={() => onInsertField(snippet)}
							title={snippet}
							className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:w-full wcpos:min-w-0 wcpos:px-1.5 wcpos:py-1 wcpos:text-left wcpos:text-sm wcpos:text-gray-600 hover:wcpos:bg-blue-50 hover:wcpos:text-blue-700 wcpos:rounded wcpos:border-0 wcpos:bg-transparent wcpos:cursor-pointer"
						>
							<span className="wcpos:truncate wcpos:flex-1">{label}</span>
							<Chip variant="info" size="xs" className="wcpos:shrink-0 wcpos:font-mono">
								&lt;/&gt;
							</Chip>
						</button>
					))}
				</div>
			)}
		</div>
	);
}
