import fs from 'fs';
import path from 'path';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { TemplatesTable } from '../components/active-templates-table';
import type { Template } from '../types';

vi.mock('../translations', () => ({
	t: (key: string) => key,
}));

const activeTemplate: Template = {
	id: 123,
	title: 'Receipt Template',
	description: 'Default receipt',
	content: '',
	type: 'receipt',
	category: 'receipt',
	engine: 'thermal',
	output_type: 'escpos',
	paper_width: '80mm',
	version: 1,
	status: 'publish',
	is_virtual: false,
};

describe('template gallery shared UI integration', () => {
	it('renders the active state control with shared switch semantics', () => {
		const markup = renderToStaticMarkup(
			<TemplatesTable
				templates={[activeTemplate]}
				onPreview={() => {}}
				onToggle={() => {}}
				onDelete={() => {}}
				onReorder={() => {}}
				togglingId={null}
				deletingId={null}
			/>,
		);

		expect(markup).toContain('role="switch"');
		expect(markup).toContain('aria-checked="true"');
		expect(markup).not.toContain('aria-pressed');
	});

	it('includes shared UI source files in Tailwind generation', () => {
		const cssPath = path.resolve(__dirname, '../index.css');
		const css = fs.readFileSync(cssPath, 'utf8');

		expect(css).toContain('@source "../../ui/src"');
	});
});
