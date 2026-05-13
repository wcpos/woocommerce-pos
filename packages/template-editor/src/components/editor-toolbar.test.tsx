import { act } from 'react';
import { EditorState } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { codeFolding, foldedRanges } from '@codemirror/language';
import { html } from '@codemirror/lang-html';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { EditorToolbar, runFoldAllRecursively } from './editor-toolbar';

vi.mock('../translations', () => ({
	t: (key: string) => {
		const strings: Record<string, string> = {
			'editor.toolbar.undo': 'Undo',
			'editor.toolbar.redo': 'Redo',
			'editor.toolbar.find': 'Find',
			'editor.toolbar.wrap': 'Word wrap',
			'editor.toolbar.fold_all': 'Fold all',
			'editor.toolbar.unfold_all': 'Unfold all',
		};
		return strings[key] ?? key;
	},
}));

const mountedRoots: Root[] = [];
const editorViews: EditorView[] = [];

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	for (const view of editorViews) {
		view.destroy();
	}
	mountedRoots.length = 0;
	editorViews.length = 0;
	document.body.innerHTML = '';
});

function renderToolbar(wrap: boolean, onToggleWrap = vi.fn()) {
	const container = document.createElement('div');
	const root = createRoot(container);
	mountedRoots.push(root);
	document.body.appendChild(container);

	act(() => {
		root.render(
			<EditorToolbar
				viewRef={{ current: null }}
				wrap={wrap}
				onToggleWrap={onToggleWrap}
			/>,
		);
	});

	return { container, onToggleWrap };
}

function getFoldedRangeCount(view: EditorView): number {
	let count = 0;
	foldedRanges(view.state).between(0, view.state.doc.length, () => {
		count += 1;
	});
	return count;
}

describe('EditorToolbar', () => {
	it('shows word wrap as a pressed toggle and calls the toggle handler', () => {
		const onToggleWrap = vi.fn();
		const { container } = renderToolbar(true, onToggleWrap);

		const wrapButton = container.querySelector('button[aria-label="Word wrap"]') as HTMLButtonElement;

		expect(wrapButton).toBeTruthy();
		expect(wrapButton.getAttribute('aria-pressed')).toBe('true');

		act(() => {
			wrapButton.click();
		});

		expect(onToggleWrap).toHaveBeenCalledTimes(1);
	});

	it('folds nested children so unfold all can reveal each layer', () => {
		const view = new EditorView({
			state: EditorState.create({
				doc: '<section>\n  <div>\n    <p>Nested</p>\n  </div>\n</section>\n',
				extensions: [html(), codeFolding()],
			}),
			parent: document.body,
		});
		editorViews.push(view);

		expect(runFoldAllRecursively(view)).toBe(true);

		expect(getFoldedRangeCount(view)).toBeGreaterThan(1);
	});
});
