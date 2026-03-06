import { useState } from 'react';
import type { FieldSchema } from '../types';
import { FieldTreeNode } from './field-tree-node';
import { SearchField } from './search-field';

interface FieldPickerProps {
	schema: FieldSchema;
	onInsertField: (text: string) => void;
}

export function FieldPicker({ schema, onInsertField }: FieldPickerProps) {
	const [search, setSearch] = useState('');

	return (
		<div
			className="wcpos:w-60 wcpos:shrink-0 wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:overflow-y-auto"
			style={{ maxHeight: 600 }}
		>
			<div className="wcpos:p-2 wcpos:border-b wcpos:border-gray-200">
				<div className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase wcpos:mb-2">
					Fields
				</div>
				<SearchField value={search} onChange={setSearch} />
			</div>
			<div className="wcpos:p-2">
				{Object.entries(schema).map(([key, section]) => (
					<FieldTreeNode
						key={key}
						sectionKey={key}
						section={section}
						searchFilter={search}
						onInsertField={onInsertField}
					/>
				))}
			</div>
		</div>
	);
}
