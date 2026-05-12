import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { FieldPicker } from './field-picker';
import type { FieldSchema } from '../types';

vi.mock('../translations', () => ({
	t: (key: string, params?: Record<string, string | number>) => {
		const strings: Record<string, string> = {
			'editor.fields': 'Fields',
			'editor.search_fields': 'Search fields...',
			'editor.search_fields_placeholder': 'Search fields...',
			'editor.search_fields_label': 'Search fields',
			'editor.clear_search': 'Clear search',
			'editor.no_field_matches': 'No fields match "{{query}}".',
			'editor.insert': 'Insert',
			'editor.insert_loop_block': 'Insert loop block',
			'editor.loop': 'loop',
			'editor.field_type_list': 'list',
		};
		const template = strings[key] ?? key;
		if (!params) return template;
		return Object.entries(params).reduce(
			(acc, [k, v]) => acc.replace(new RegExp(`{{${k}}}`, 'g'), String(v)),
			template,
		);
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

function renderPicker(schema: FieldSchema, engine = 'logicless', onInsertField = vi.fn()) {
	const container = document.createElement('div');
	const root = createRoot(container);
	mountedRoots.push(root);
	document.body.appendChild(container);
	return { container, root, onInsertField };
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

	it('renders type badges for money, number, boolean and string[] fields', async () => {
		const schema: FieldSchema = {
			order: {
				label: 'Order',
				fields: {
					number: { type: 'number', label: 'Number' },
					total: { type: 'money', label: 'Total' },
					completed: { type: 'boolean', label: 'Completed' },
					tags: { type: 'string[]', label: 'Tags' },
					note: { type: 'string', label: 'Note' },
				},
			},
		};

		const { container, root, onInsertField } = renderPicker(schema);

		await act(async () => {
			root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
		});
		await act(async () => {
			getButton(container, 'Order').click();
		});

		const numberButton = getButton(container, 'Number');
		const totalButton = getButton(container, 'Total');
		const completedButton = getButton(container, 'Completed');
		const tagsButton = getButton(container, 'Tags');
		const noteButton = getButton(container, 'Note');

		expect(numberButton.textContent).toContain('#');
		expect(totalButton.textContent).toContain('$');
		expect(completedButton.textContent).toContain('T/F');
		expect(tagsButton.textContent).toContain('list');
		// Plain strings get no badge.
		expect(noteButton.textContent).toMatch(/^\s*Note\s*$/);
	});

	it('inserts a loop block when the array section loop chip is clicked', async () => {
		const schema: FieldSchema = {
			lines: {
				label: 'Lines',
				is_array: true,
				fields: {
					name: { type: 'string', label: 'Name' },
					qty: { type: 'number', label: 'Qty' },
				},
			},
		};

		const onInsertField = vi.fn();
		const { container, root } = renderPicker(schema, 'logicless', onInsertField);

		await act(async () => {
			root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
		});

		const loopButton = getButtons(container).find((b) =>
			b.getAttribute('aria-label') === 'Insert loop block'
		);

		expect(loopButton).toBeDefined();
		await act(async () => {
			loopButton!.click();
		});

		expect(onInsertField).toHaveBeenCalledWith('{{#lines}}\n\n{{/lines}}');
	});

	it('shows an empty state when search has no matches', async () => {
		const schema: FieldSchema = {
			order: {
				label: 'Order',
				fields: { number: { type: 'number', label: 'Number' } },
			},
		};

		const { container, root, onInsertField } = renderPicker(schema);

		await act(async () => {
			root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
		});

		const search = container.querySelector('input[type=text]') as HTMLInputElement;
		const nativeSetter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value',
		)!.set!;
		await act(async () => {
			nativeSetter.call(search, 'zzzznomatch');
			search.dispatchEvent(new Event('input', { bubbles: true }));
		});

		expect(container.textContent).toContain('No fields match "zzzznomatch"');
		expect(container.textContent).not.toContain('Order');

		const clearButton = getButton(container, 'Clear search');
		await act(async () => {
			clearButton.click();
		});

		expect(container.textContent).toContain('Order');
		expect(container.textContent).not.toContain('No fields match');
	});

	it('shows a field count next to non-array sections', async () => {
		const schema: FieldSchema = {
			order: {
				label: 'Order',
				fields: {
					number: { type: 'number', label: 'Number' },
					total: { type: 'money', label: 'Total' },
					status: { type: 'string', label: 'Status' },
				},
			},
		};

		const { container, root, onInsertField } = renderPicker(schema);

		await act(async () => {
			root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
		});

		const orderButton = getButton(container, 'Order');
		expect(orderButton.textContent).toContain('3');
	});
});
