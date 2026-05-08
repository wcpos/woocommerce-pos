import { useState } from 'react';
import type { SectionInfo } from '../types';
import type { FieldTreeEntry } from './field-picker';

interface FieldTreeNodeProps {
	sectionKey: string;
	section: SectionInfo;
	children?: FieldTreeEntry[];
	searchFilter: string;
	onInsertField: (text: string) => void;
	depth?: number;
}

export function FieldTreeNode({
	sectionKey,
	section,
	children = [],
	searchFilter,
	onInsertField,
	depth = 0,
}: FieldTreeNodeProps) {
	const [expanded, setExpanded] = useState(false);

	const lowerSearch = searchFilter.toLowerCase();
	const sectionMatches = Boolean(searchFilter) && (
		sectionKey.toLowerCase().includes(lowerSearch) || section.label.toLowerCase().includes(lowerSearch)
	);
	const filteredFields = Object.entries(section.fields).filter(([key, field]) => {
		if (!searchFilter) return true;
		return sectionMatches || key.toLowerCase().includes(lowerSearch) || field.label.toLowerCase().includes(lowerSearch);
	});
	const visibleChildren = children.filter((child) => {
		if (!searchFilter) return true;

		const childLower = searchFilter.toLowerCase();
		const childSectionMatches = child.sectionKey.toLowerCase().includes(childLower)
			|| child.section.label.toLowerCase().includes(childLower);
		const childFieldMatches = Object.entries(child.section.fields).some(([key, field]) => (
			key.toLowerCase().includes(childLower) || field.label.toLowerCase().includes(childLower)
		));

		return childSectionMatches || childFieldMatches;
	});

	if (searchFilter && filteredFields.length === 0 && visibleChildren.length === 0 && !sectionMatches) return null;

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
		<div className="wcpos:mb-1" style={{ marginLeft: depth > 0 ? 12 : 0 }}>
			<div className="wcpos:flex wcpos:items-center wcpos:gap-1">
				<button
					type="button"
					onClick={() => setExpanded(!expanded)}
					className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:flex-1 wcpos:px-2 wcpos:py-1 wcpos:text-left wcpos:text-sm wcpos:font-semibold wcpos:text-gray-700 hover:wcpos:bg-gray-100 wcpos:rounded"
				>
					<span className="wcpos:text-xs wcpos:text-gray-400 wcpos:w-4">
						{isExpanded ? '\u25BC' : '\u25B6'}
					</span>
					{section.label}
				</button>
				{section.is_array && (
					<button
						type="button"
						className="wcpos:text-xs wcpos:bg-blue-100 wcpos:text-blue-700 wcpos:px-1.5 wcpos:rounded wcpos:cursor-pointer"
						onClick={handleSectionClick}
						title={`Insert {{#${sectionKey}}}...{{/${sectionKey}}} block`}
						aria-label={`Insert {{#${sectionKey}}}...{{/${sectionKey}}} block`}
					>
						[]
					</button>
				)}
			</div>

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
					{visibleChildren.map((child) => (
						<FieldTreeNode
							key={child.sectionKey}
							sectionKey={child.sectionKey}
							section={child.section}
							children={child.children}
							searchFilter={searchFilter}
							onInsertField={onInsertField}
							depth={depth + 1}
						/>
					))}
				</div>
			)}
		</div>
	);
}
