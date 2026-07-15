import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { FieldPicker } from './field-picker';
import type { FieldSchema } from '../types';

vi.mock('../translations', () => ({
	t: (key: string, params?: Record<string, string | number>) => {
		const strings: Record<string, string> = {
			'editor.fields': 'Fields',
			'editor.resize_fields': 'Resize fields panel',
			'editor.search_fields': 'Search fields...',
			'editor.search_fields_placeholder': 'Search fields...',
			'editor.search_fields_label': 'Search fields',
			'editor.clear_search': 'Clear search',
			'editor.no_field_matches': 'No fields match "{query}".',
			'editor.insert': 'Insert',
			'editor.insert_loop_block': 'Insert loop block',
			'editor.loop': 'loop',
			'editor.field_type_list': 'list',
		};
		const template = strings[key] ?? key;
		if (!params) return template;
		return Object.entries(params).reduce(
			(acc, [k, v]) => acc.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v)),
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
	window.localStorage.clear();
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


	it('does not cap its own height so it can match the editor column', async () => {
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

		expect(container.firstElementChild?.getAttribute('style') ?? '').not.toContain('max-height');
	});

	describe('resizable panel', () => {
		const schema: FieldSchema = {
			order: {
				label: 'Order',
				fields: { number: { type: 'number', label: 'Number' } },
			},
		};

		function getPanel(container: HTMLElement): HTMLElement {
			return container.firstElementChild as HTMLElement;
		}

		function getHandle(container: HTMLElement): HTMLElement {
			const handle = container.querySelector('[role=separator]');
			if (!handle) {
				throw new Error('Resize handle not found');
			}
			return handle as HTMLElement;
		}

		it('defaults to a 280px wide panel with an accessible resize handle', async () => {
			const { container, root, onInsertField } = renderPicker(schema);

			await act(async () => {
				root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
			});

			expect(getPanel(container).style.width).toBe('280px');

			const handle = getHandle(container);
			expect(handle.getAttribute('aria-orientation')).toBe('vertical');
			expect(handle.getAttribute('aria-label')).toBe('Resize fields panel');
			expect(handle.getAttribute('aria-valuenow')).toBe('280');
			expect(handle.getAttribute('aria-controls')).toBe('wcpos-template-editor-fields-panel');
			expect(handle.tabIndex).toBe(0);
		});

		it('restores a persisted width, clamped to the allowed range', async () => {
			window.localStorage.setItem('wcpos-template-editor-fields-width', '9999');

			const { container, root, onInsertField } = renderPicker(schema);

			await act(async () => {
				root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
			});

			expect(getPanel(container).style.width).toBe('520px');
		});

		it('resizes with arrow keys and persists the new width', async () => {
			const { container, root, onInsertField } = renderPicker(schema);

			await act(async () => {
				root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
			});

			const handle = getHandle(container);
			await act(async () => {
				handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
			});

			expect(getPanel(container).style.width).toBe('296px');
			expect(window.localStorage.getItem('wcpos-template-editor-fields-width')).toBe('296');

			await act(async () => {
				handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
			});

			expect(getPanel(container).style.width).toBe('200px');
		});

		it('follows pointer drag on the handle', async () => {
			const { container, root, onInsertField } = renderPicker(schema);

			await act(async () => {
				root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
			});

			const panel = getPanel(container);
			vi.spyOn(panel, 'getBoundingClientRect').mockReturnValue({
				left: 0,
				right: 280,
				top: 0,
				bottom: 600,
				width: 280,
				height: 600,
				x: 0,
				y: 0,
				toJSON: () => ({}),
			} as DOMRect);

			const handle = getHandle(container);
			await act(async () => {
				handle.dispatchEvent(
					new MouseEvent('pointerdown', { bubbles: true, clientX: 280, buttons: 1 }),
				);
			});
			await act(async () => {
				handle.dispatchEvent(
					new MouseEvent('pointermove', { bubbles: true, clientX: 340, buttons: 1 }),
				);
			});
			await act(async () => {
				handle.dispatchEvent(new MouseEvent('pointerup', { bubbles: true, clientX: 340 }));
			});

			expect(panel.style.width).toBe('340px');
			// Width persists once the drag completes.
			expect(window.localStorage.getItem('wcpos-template-editor-fields-width')).toBe('340');

			// After the drag ends, further moves must not resize the panel.
			await act(async () => {
				handle.dispatchEvent(
					new MouseEvent('pointermove', { bubbles: true, clientX: 400, buttons: 1 }),
				);
			});
			expect(panel.style.width).toBe('340px');
		});

		it('ignores non-primary-button drags', async () => {
			const { container, root, onInsertField } = renderPicker(schema);

			await act(async () => {
				root.render(<FieldPicker schema={schema} engine="logicless" onInsertField={onInsertField} />);
			});

			const handle = getHandle(container);
			await act(async () => {
				handle.dispatchEvent(
					new MouseEvent('pointerdown', { bubbles: true, clientX: 280, button: 2, buttons: 2 }),
				);
			});
			await act(async () => {
				handle.dispatchEvent(
					new MouseEvent('pointermove', { bubbles: true, clientX: 400, buttons: 2 }),
				);
			});

			expect(getPanel(container).style.width).toBe('280px');
		});
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
