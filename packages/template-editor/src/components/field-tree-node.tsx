import { useState } from 'react';
import type { SectionInfo } from '../types';

interface FieldTreeNodeProps {
	sectionKey: string;
	section: SectionInfo;
	searchFilter: string;
	onInsertField: (text: string) => void;
}

export function FieldTreeNode({ sectionKey, section, searchFilter, onInsertField }: FieldTreeNodeProps) {
	const [expanded, setExpanded] = useState(false);

	const filteredFields = Object.entries(section.fields).filter(([key, field]) => {
		if (!searchFilter) return true;
		const lower = searchFilter.toLowerCase();
		return key.toLowerCase().includes(lower) || field.label.toLowerCase().includes(lower);
	});

	if (searchFilter && filteredFields.length === 0) return null;

	const isExpanded = searchFilter ? true : expanded;

	const handleSectionClick = () => {
		if (section.is_array) {
			onInsertField(`{{#${sectionKey}}}\n\n{{/${sectionKey}}}`);
		}
	};

	const handleFieldClick = (fieldKey: string) => {
		if (section.is_array) {
			onInsertField(`{{${fieldKey}}}`);
		} else {
			onInsertField(`{{${sectionKey}.${fieldKey}}}`);
		}
	};

	return (
		<div className="wcpos:mb-1">
			<button
				type="button"
				onClick={() => setExpanded(!expanded)}
				className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:w-full wcpos:px-2 wcpos:py-1 wcpos:text-left wcpos:text-sm wcpos:font-semibold wcpos:text-gray-700 hover:wcpos:bg-gray-100 wcpos:rounded"
			>
				<span className="wcpos:text-xs wcpos:text-gray-400 wcpos:w-4">
					{isExpanded ? '\u25BC' : '\u25B6'}
				</span>
				{section.label}
				{section.is_array && (
					<button
						type="button"
						className="wcpos:ml-auto wcpos:text-xs wcpos:bg-blue-100 wcpos:text-blue-700 wcpos:px-1.5 wcpos:rounded wcpos:cursor-pointer"
						onClick={(e) => { e.stopPropagation(); handleSectionClick(); }}
						title={`Insert {{#${sectionKey}}}...{{/${sectionKey}}} block`}
						aria-label={`Insert {{#${sectionKey}}}...{{/${sectionKey}}} block`}
					>
						[]
					</button>
				)}
			</button>

			{isExpanded && (
				<div className="wcpos:ml-5">
					{filteredFields.map(([fieldKey, field]) => (
						<button
							key={fieldKey}
							type="button"
							onClick={() => handleFieldClick(fieldKey)}
							className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:w-full wcpos:px-2 wcpos:py-0.5 wcpos:text-left wcpos:text-sm wcpos:text-gray-600 hover:wcpos:bg-blue-50 hover:wcpos:text-blue-700 wcpos:rounded"
							title={`Insert {{${section.is_array ? fieldKey : sectionKey + '.' + fieldKey}}}`}
						>
							<span className="wcpos:truncate">{field.label}</span>
							{field.type === 'money' && (
								<span className="wcpos:ml-auto wcpos:text-xs wcpos:text-green-600">$</span>
							)}
						</button>
					))}
				</div>
			)}
		</div>
	);
}
