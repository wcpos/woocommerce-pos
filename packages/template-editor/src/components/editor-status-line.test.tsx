import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { EditorStatusLine } from './editor-status-line';

vi.mock('../translations', () => ({
	t: (key: string, params?: Record<string, string | number>) => {
		const strings: Record<string, string> = {
			'editor.status.line_col': 'Ln {line}, Col {col}',
			'editor.status.lines': '{count} lines',
			'editor.status.saved': 'Synced to form',
			'editor.status.unsaved': 'Unsaved changes',
			'editor.status.engine_html': 'HTML & Mustache',
			'editor.status.engine_thermal': 'Thermal',
			'editor.status.engine_php': 'Legacy PHP',
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
});

function renderStatus(saved: boolean) {
	const container = document.createElement('div');
	const root = createRoot(container);
	mountedRoots.push(root);
	document.body.appendChild(container);

	act(() => {
		root.render(
			<EditorStatusLine
				engine="logicless"
				line={12}
				col={8}
				lineCount={34}
				saved={saved}
			/>,
		);
	});

	return container;
}

describe('EditorStatusLine', () => {
	it('interpolates cursor position and line count', () => {
		const container = renderStatus(true);

		expect(container.textContent).toContain('Ln 12, Col 8');
		expect(container.textContent).toContain('34 lines');
	});

	it('describes synced state without claiming autosave', () => {
		const container = renderStatus(true);

		expect(container.textContent).toContain('Synced to form');
		expect(container.textContent).not.toContain('Auto-saved');
	});

	it('shows unsaved changes while content is settling', () => {
		const container = renderStatus(false);

		expect(container.textContent).toContain('Unsaved changes');
	});
});
