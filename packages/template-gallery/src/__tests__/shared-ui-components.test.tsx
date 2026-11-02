/// <reference types="vite/client" />
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { TemplatesTable } from '../components/active-templates-table';
import css from '../index.css?raw';

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
	language: '',
	tax_display: '',
	is_premade: false,
	is_active: true,
	offline_capable: true,
	gallery_key: null,
	gallery_version: 1,
	source: 'custom',
	menu_order: 0,
	date_created: '',
	date_modified: '',
	status: 'publish',
	is_virtual: false,
};

describe('template gallery shared UI integration', () => {
	it('renders the active state control with shared switch semantics', () => {
		const markup = renderToStaticMarkup(
			<TemplatesTable
				templates={[activeTemplate]}
				onSetActive={() => {}}
				onPreview={() => {}}
				onToggle={() => {}}
				onDelete={() => {}}
				onReorder={() => {}}
				togglingId={null}
				deletingId={null}
			/>
		);

		expect(markup).toContain('role="switch"');
		expect(markup).toContain('aria-checked="true"');
		expect(markup).not.toContain('aria-pressed');
	});

	it('renders the toggle track with classes that survive missing host resets', () => {
		// Tailwind preflight is not always loaded in consumers (e.g. Template
		// Gallery imports utilities only). Without these classes the user-agent
		// button padding/line-height misaligns the knob inside the track.
		const markup = renderToStaticMarkup(
			<TemplatesTable
				templates={[activeTemplate]}
				onSetActive={() => {}}
				onPreview={() => {}}
				onToggle={() => {}}
				onDelete={() => {}}
				onReorder={() => {}}
				togglingId={null}
				deletingId={null}
			/>
		);

		const switchMatch = markup.match(/<button[^>]*role="switch"[^>]*>[\s\S]*?<\/button>/);
		expect(switchMatch).not.toBeNull();
		const switchTag = switchMatch![0];

		// Vertical centering of the knob inside the track.
		expect(switchTag).toContain('wcpos:items-center');
		// Neutralize browser/WP-admin button padding + margin.
		expect(switchTag).toContain('wcpos:p-0');
		expect(switchTag).toContain('wcpos:m-0');
		// Defensive against inline baseline shifts.
		expect(switchTag).toContain('wcpos:align-middle');
		// Prevent line-height from affecting knob height/alignment.
		expect(switchTag).toContain('wcpos:block');
	});

	it('includes shared UI source files in Tailwind generation', () => {
		expect(css).toContain('@source "../../ui/src"');
	});
});
