import { useState } from 'react';
import type { FieldSchema } from '../types';
import { t } from '../translations';
import { useResizableWidth } from '../hooks/use-resizable-width';
import { FieldTreeNode } from './field-tree-node';
import { SearchField } from './search-field';
import { ThermalElements, thermalMatchesSearch } from './thermal-elements';

interface FieldPickerProps {
	schema: FieldSchema;
	engine: string;
	onInsertField: (text: string) => void;
}

const PANEL_ID = 'wcpos-template-editor-fields-panel';

export interface FieldTreeEntry {
	sectionKey: string;
	section: FieldSchema[string];
	children: FieldTreeEntry[];
}

export function getFieldTreeEntries(schema: FieldSchema): FieldTreeEntry[] {
	const entries = new Map<string, FieldTreeEntry>();
	const childEntries: FieldTreeEntry[] = [];

	for (const [key, section] of Object.entries(schema)) {
		entries.set(key, {
			sectionKey: key,
			section,
			children: [],
		});
	}

	for (const entry of entries.values()) {
		if (!entry.sectionKey.includes('.')) continue;

		const parentKey = entry.sectionKey.split('.')[0];
		const parent = entries.get(parentKey);

		if (parent) {
			parent.children.push(entry);
			childEntries.push(entry);
		}
	}

	const childKeys = new Set(childEntries.map((entry) => entry.sectionKey));

	return Array.from(entries.values()).filter((entry) => !childKeys.has(entry.sectionKey));
}

function entryMatchesSearch(entry: FieldTreeEntry, search: string): boolean {
	if (!search) return true;
	const lower = search.toLowerCase();

	if (entry.sectionKey.toLowerCase().includes(lower)) return true;
	if (entry.section.label.toLowerCase().includes(lower)) return true;

	const fieldMatches = Object.entries(entry.section.fields).some(([key, field]) => (
		key.toLowerCase().includes(lower) || field.label.toLowerCase().includes(lower)
	));
	if (fieldMatches) return true;

	return entry.children.some((child) => entryMatchesSearch(child, search));
}

export function FieldPicker({ schema, engine, onInsertField }: FieldPickerProps) {
	const [search, setSearch] = useState('');
	const { width, isResizing, panelRef, separatorProps } = useResizableWidth({
		storageKey: 'wcpos-template-editor-fields-width',
		defaultWidth: 280,
		minWidth: 200,
		maxWidth: 520,
	});
	const entries = getFieldTreeEntries(schema);

	const hasFieldMatches = entries.some((entry) => entryMatchesSearch(entry, search));
	const hasThermalMatches = engine === 'thermal' && thermalMatchesSearch(search);
	const showEmptyState = Boolean(search) && !hasFieldMatches && !hasThermalMatches;

	return (
		<div
			ref={panelRef}
			style={{ width: `${width}px` }}
			className="wcpos:relative wcpos:shrink-0 wcpos:self-stretch"
		>
			<div
				id={PANEL_ID}
				className="wcpos:h-full wcpos:flex wcpos:flex-col wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:rounded-lg wcpos:overflow-hidden"
			>
				<div className="wcpos:p-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:shrink-0">
					<div className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider wcpos:mb-2 wcpos:px-1">
						{t('editor.fields')}
					</div>
					<SearchField value={search} onChange={setSearch} />
				</div>
				<div className="wcpos:flex-1 wcpos:overflow-y-auto wcpos:p-2">
					{showEmptyState ? (
						<div className="wcpos:flex wcpos:flex-col wcpos:items-start wcpos:gap-2 wcpos:px-2 wcpos:py-6 wcpos:text-sm wcpos:text-gray-500">
							<span>{t('editor.no_field_matches', { query: search })}</span>
							<button
								type="button"
								onClick={() => setSearch('')}
								className="wcpos:text-wp-admin-theme-color wcpos:hover:underline wcpos:text-sm wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
							>
								{t('editor.clear_search')}
							</button>
						</div>
					) : (
						<>
							{entries.map(({ sectionKey, section, children }) => (
								<FieldTreeNode
									key={sectionKey}
									sectionKey={sectionKey}
									section={section}
									children={children}
									searchFilter={search}
									onInsertField={onInsertField}
								/>
							))}
							{engine === 'thermal' && (
								<ThermalElements searchFilter={search} onInsertField={onInsertField} />
							)}
						</>
					)}
				</div>
			</div>
			{/*
			 * The hit zone straddles the panel border into the column gap
			 * (4px inside, 8px outside) so it stays clear of the field
			 * list's scrollbar while offering a 12px-wide drag target.
			 */}
			<div
				{...separatorProps}
				aria-label={t('editor.resize_fields')}
				aria-controls={PANEL_ID}
				className="wcpos:group wcpos:absolute wcpos:inset-y-0 wcpos:-end-2 wcpos:w-3 wcpos:cursor-col-resize wcpos:touch-none wcpos:focus-visible:outline-none"
			>
				<div
					className={`wcpos:absolute wcpos:inset-y-0 wcpos:start-1 wcpos:w-[3px] wcpos:rounded-full ${
						isResizing
							? 'wcpos:bg-wp-admin-theme-color'
							: 'wcpos:bg-transparent wcpos:group-hover:bg-gray-300 wcpos:group-focus-visible:bg-wp-admin-theme-color'
					}`}
				/>
			</div>
		</div>
	);
}
