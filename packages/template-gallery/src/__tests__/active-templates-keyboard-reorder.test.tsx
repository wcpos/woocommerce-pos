import * as React from 'react';
import { act } from 'react';

import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { TemplatesTable } from '../components/active-templates-table';

import type { AnyTemplate, Template } from '../types';

vi.mock('@tanstack/react-router', () => ({ useNavigate: () => vi.fn() }));
vi.mock('../translations', () => ({
	t: (key: string, vars?: { title?: string }) => (vars?.title ? `${key}:${vars.title}` : key),
}));

const [A, B, C] = [1, 2, 3];
const make = (id: number, title: string) =>
	({ id, title, status: 'publish', is_virtual: false, engine: 'logicless' }) as Template;
const abc: AnyTemplate[] = [make(A, 'A'), make(B, 'B'), make(C, 'C')];
const onReorder = vi.fn();
let setTemplatesFromTest: (templates: AnyTemplate[]) => void;

/**
 * Holds the templates in state so a reorder rerenders the table in the new order. With
 * `deferred`, a reorder is only recorded and the test decides when `templates` changes.
 */
function Harness({ initial, deferred = false }: { initial: AnyTemplate[]; deferred?: boolean }) {
	const [templates, setTemplates] = React.useState(initial);
	React.useEffect(() => {
		setTemplatesFromTest = setTemplates;
	}, []);
	return (
		<TemplatesTable
			templates={templates}
			onPreview={vi.fn()}
			onToggle={vi.fn()}
			onDelete={vi.fn()}
			onSetActive={vi.fn()}
			togglingId={null}
			deletingId={null}
			onReorder={(ids) => {
				onReorder(ids);
				if (!deferred) setTemplates(ids.map((id) => templates.find((t) => t.id === id)!));
			}}
		/>
	);
}

let root: Root | undefined;
let container: HTMLDivElement;
afterEach(() => {
	act(() => root?.unmount());
	root = undefined;
	document.body.innerHTML = '';
	vi.unstubAllGlobals();
	vi.clearAllMocks();
});

function mount(initial: AnyTemplate[], deferred = false) {
	vi.stubGlobal(
		'ResizeObserver',
		class {
			observe() {}
			disconnect() {}
		}
	);
	container = document.createElement('div');
	document.body.append(container);
	root = createRoot(container);
	act(() => root!.render(<Harness initial={initial} deferred={deferred} />));
}

function moveButton(id: number, direction: 'up' | 'down') {
	const selector = `button[data-move="${direction}"][data-template-id="${id}"]`;
	const button = container.querySelector<HTMLButtonElement>(selector);
	expect(button).not.toBeNull();
	return button!;
}

function previewButton(id: number) {
	const row = moveButton(id, 'up').closest('tr')!;
	return Array.from(row.querySelectorAll('button')).find(
		(b) => b.textContent === 'common.preview'
	)!;
}

const press = (button: HTMLButtonElement) =>
	act(() => {
		button.focus();
		button.click();
	});
const setOrder = (...ids: number[]) =>
	act(() => setTemplatesFromTest(ids.map((id) => abc.find((t) => t.id === id)!)));

describe('keyboard reorder of active templates', () => {
	it('disables Move up on the first row and Move down on the last', () => {
		mount(abc);
		const disabled = [A, B, C].map((id) => [moveButton(id, 'up'), moveButton(id, 'down')]);
		expect(disabled.map((pair) => pair.map((button) => button.disabled))).toEqual([
			[true, false],
			[false, false],
			[false, true],
		]);
	});

	it('disables both buttons when there is one template', () => {
		mount([abc[0]]);
		expect(moveButton(A, 'up').disabled).toBe(true);
		expect(moveButton(A, 'down').disabled).toBe(true);
	});

	it.each([
		[A, 'down', [B, A, C]],
		[C, 'up', [A, C, B]],
	] as const)('moving %s %s reorders to %j', (id, direction, expected) => {
		mount(abc);
		act(() => moveButton(id, direction).click());
		expect(onReorder).toHaveBeenCalledTimes(1);
		expect(onReorder).toHaveBeenCalledWith(expected);
	});

	it('renders both as focusable native buttons with an accessible name', () => {
		mount(abc);
		for (const direction of ['up', 'down'] as const) {
			const button = moveButton(B, direction);
			expect(button.type).toBe('button');
			expect(button.getAttribute('aria-label')).toBe(`table.move_${direction}:B`);
			expect(button.tabIndex).toBeGreaterThanOrEqual(0);
		}
	});

	it('moves focus to Move up when Move down takes the row to the end', () => {
		mount(abc);
		act(() => moveButton(B, 'down').click());
		const order = Array.from(container.querySelectorAll('button[data-move="up"]')).map((button) =>
			Number(button.getAttribute('data-template-id'))
		);
		expect(order).toEqual([A, C, B]);
		expect(moveButton(B, 'down').disabled).toBe(true);
		expect(document.activeElement).toBe(moveButton(B, 'up'));
	});

	it('keeps focus on Move down when the row can still move down', () => {
		mount(abc);
		act(() => moveButton(A, 'down').click());
		expect(document.activeElement).toBe(moveButton(A, 'down'));
	});

	it('waits for the saved order before moving focus', () => {
		mount(abc, true);
		press(moveButton(B, 'down'));
		setOrder(A, B, C);
		setOrder(A, C, B);
		expect(document.activeElement).toBe(moveButton(B, 'up'));
	});

	it('a failed save never takes focus later', () => {
		mount(abc, true);
		press(moveButton(B, 'down'));
		act(() => previewButton(A).focus());
		setOrder(A, B, C);
		expect(document.activeElement).toBe(previewButton(A));
	});

	it('leaves focus alone when the user has moved on', () => {
		mount(abc, true);
		press(moveButton(B, 'down'));
		act(() => previewButton(A).focus());
		setOrder(A, C, B);
		expect(document.activeElement).toBe(previewButton(A));
	});

	it('refocuses when the moved row dropped focus', () => {
		mount(abc, true);
		press(moveButton(B, 'up'));
		act(() => (document.activeElement as HTMLElement).blur());
		expect(document.activeElement).toBe(document.body);
		setOrder(B, A, C);
		expect(document.activeElement).toBe(moveButton(B, 'down'));
	});
});
