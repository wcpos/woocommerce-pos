import { useState } from 'react';
import type { FieldSchema } from '../types';
import { t } from '../translations';
import { FieldTreeNode } from './field-tree-node';
import { SearchField } from './search-field';
import { ThermalElements, thermalMatchesSearch } from './thermal-elements';

interface FieldPickerProps {
	schema: FieldSchema;
	engine: string;
	onInsertField: (text: string) => void;
}

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
	const entries = getFieldTreeEntries(schema);

	const hasFieldMatches = entries.some((entry) => entryMatchesSearch(entry, search));
	const hasThermalMatches = engine === 'thermal' && thermalMatchesSearch(search);
	const showEmptyState = Boolean(search) && !hasFieldMatches && !hasThermalMatches;

	return (
		<div
			className="wcpos:w-60 wcpos:shrink-0 wcpos:flex wcpos:flex-col wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:rounded-lg wcpos:overflow-hidden"
			style={{ maxHeight: 600 }}
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
							className="wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:text-sm wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
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
	);
}
