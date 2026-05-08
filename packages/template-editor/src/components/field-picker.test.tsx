import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { FieldPicker } from './field-picker';
import type { FieldSchema } from '../types';

vi.mock('../translations', () => ({
	t: (key: string) => {
		const strings: Record<string, string> = {
			'editor.fields': 'Fields',
			'editor.search_fields': 'Search fields...',
		};
		return strings[key] ?? key;
	},
}));

const mountedRoots: Root[] = [];

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	mountedRoots.length = 0;
	document.body.innerHTML = '';
});

function getButtons(container: HTMLElement): HTMLButtonElement[] {
	return Array.from(container.querySelectorAll('button'));
}

function getButton(container: HTMLElement, text: string): HTMLButtonElement {
	const button = getButtons(container).find((item) => item.textContent?.includes(text));

	if (!button) {
		throw new Error(`Button not found: ${text}`);
	}

	return button;
}

describe('FieldPicker', () => {
	it('nests dotted schema sections under their parent section', async () => {
		const schema: FieldSchema = {
			store: {
				label: 'Store',
				fields: {
					id: { type: 'number', label: 'Store ID' },
					name: { type: 'string', label: 'Store Name' },
				},
			},
			'store.tax_ids': {
				label: 'Store Tax IDs',
				is_array: true,
				fields: {
					type: { type: 'string', label: 'Type' },
					value: { type: 'string', label: 'Value' },
				},
			},
		};

		const onInsertField = vi.fn();
		const container = document.createElement('div');
		const root = createRoot(container);
		mountedRoots.push(root);
		document.body.appendChild(container);

		await act(async () => {
			root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
		});

		expect(container.textContent).toContain('Store');
		expect(container.textContent).not.toContain('Store Tax IDs');

		await act(async () => {
			getButton(container, 'Store').click();
		});

		expect(container.textContent).toContain('Store ID');
		expect(container.textContent).toContain('Store Tax IDs');

		await act(async () => {
			getButton(container, 'Store Tax IDs').click();
		});

		expect(container.textContent).toContain('Type');
		expect(container.textContent).toContain('Value');
	});
});
