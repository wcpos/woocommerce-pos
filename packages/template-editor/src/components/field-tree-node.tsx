import { useState } from 'react';
import { Chip, Tooltip } from '@wcpos/ui';
import type { FieldInfo, SectionInfo } from '../types';
import { t } from '../translations';
import type { FieldTreeEntry } from './field-picker';

interface FieldTreeNodeProps {
	sectionKey: string;
	section: SectionInfo;
	children?: FieldTreeEntry[];
	searchFilter: string;
	onInsertField: (text: string) => void;
	depth?: number;
}

interface TypeChip {
	variant: 'success' | 'info' | 'warning' | 'debug';
	label: string;
}

function getTypeChip(type: FieldInfo['type']): TypeChip | null {
	if (type === 'money') return { variant: 'success', label: '$' };
	if (type === 'number') return { variant: 'info', label: '#' };
	if (type === 'boolean') return { variant: 'debug', label: 'T/F' };
	if (type === 'string[]') return { variant: 'warning', label: t('editor.field_type_list') };
	return null;
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
	const fieldCount = Object.keys(section.fields).length;

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
		<div className="wcpos:mb-0.5" style={{ marginLeft: depth > 0 ? 10 : 0 }}>
			<div className="wcpos:flex wcpos:items-center wcpos:gap-1">
				<button
					type="button"
					onClick={() => setExpanded(!expanded)}
					aria-expanded={isExpanded}
					className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:flex-1 wcpos:min-w-0 wcpos:px-1.5 wcpos:py-1 wcpos:text-left wcpos:text-sm wcpos:font-semibold wcpos:text-gray-700 hover:wcpos:bg-gray-100 wcpos:rounded wcpos:border-0 wcpos:bg-transparent wcpos:cursor-pointer"
				>
					<Chevron open={isExpanded} />
					<span className="wcpos:truncate">{section.label}</span>
					{!section.is_array && fieldCount > 0 && (
						<span className="wcpos:ml-auto wcpos:text-xs wcpos:font-normal wcpos:text-gray-400 wcpos:tabular-nums">
							{fieldCount}
						</span>
					)}
				</button>
				{section.is_array && (
					<Tooltip text={`${t('editor.insert_loop_block')} {{#${sectionKey}}}…{{/${sectionKey}}}`}>
						<button
							type="button"
							onClick={handleSectionClick}
							aria-label={t('editor.insert_loop_block')}
							className="wcpos:flex wcpos:items-center wcpos:cursor-pointer wcpos:border-0 wcpos:bg-transparent wcpos:p-0 wcpos:rounded-full hover:wcpos:opacity-80"
						>
							<Chip variant="warning" size="xs">{t('editor.loop')}</Chip>
						</button>
					</Tooltip>
				)}
			</div>

			{isExpanded && (
				<div className="wcpos:ml-4 wcpos:mt-0.5">
					{filteredFields.map(([fieldKey, field]) => {
						const chip = getTypeChip(field.type);
						const insertedTag = section.is_array
							? `{{${fieldKey}}}`
							: `{{${sectionKey}.${fieldKey}}}`;
						return (
							<button
								key={fieldKey}
								type="button"
								onClick={() => handleFieldClick(fieldKey)}
								title={`${t('editor.insert')} ${insertedTag}`}
								className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:w-full wcpos:min-w-0 wcpos:px-1.5 wcpos:py-1 wcpos:text-left wcpos:text-sm wcpos:text-gray-600 hover:wcpos:bg-blue-50 hover:wcpos:text-blue-700 wcpos:rounded wcpos:border-0 wcpos:bg-transparent wcpos:cursor-pointer"
							>
								<span className="wcpos:truncate wcpos:flex-1">{field.label}</span>
								{chip && (
									<Chip variant={chip.variant} size="xs" className="wcpos:shrink-0">
										{chip.label}
									</Chip>
								)}
							</button>
						);
					})}
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
