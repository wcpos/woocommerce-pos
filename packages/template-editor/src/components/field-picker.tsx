import { useState } from 'react';
import type { FieldSchema } from '../types';
import { t } from '../translations';
import { FieldTreeNode } from './field-tree-node';
import { SearchField } from './search-field';
import { ThermalElements } from './thermal-elements';

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

export function FieldPicker({ schema, engine, onInsertField }: FieldPickerProps) {
	const [search, setSearch] = useState('');
	const entries = getFieldTreeEntries(schema);

	return (
		<div
			className="wcpos:w-60 wcpos:shrink-0 wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:overflow-y-auto"
			style={{ maxHeight: 600 }}
		>
			<div className="wcpos:p-2 wcpos:border-b wcpos:border-gray-200">
				<div className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase wcpos:mb-2">
					{t('editor.fields')}
				</div>
				<SearchField value={search} onChange={setSearch} />
			</div>
			<div className="wcpos:p-2">
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
			</div>
		</div>
	);
}
