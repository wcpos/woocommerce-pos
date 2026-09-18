import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { TemplatesTable } from '../components/active-templates-table';

import type { Template } from '../types';

vi.mock('@tanstack/react-router', () => ({ useNavigate: () => vi.fn() }));
vi.mock('../translations', () => ({ t: (key: string) => key }));

const base: Template = {
	id: 1,
	title: 'Detailed Thermal',
	description: '',
	type: 'receipt',
	category: 'receipt',
	language: 'xml',
	engine: 'thermal',
	output_type: 'escpos',
	paper_width: '48',
	tax_display: 'default',
	is_virtual: false,
	is_premade: true,
	status: 'publish',
	is_active: true,
	offline_capable: true,
	gallery_key: 'thermal-detailed-80mm',
	gallery_version: 1,
	source: 'custom',
	menu_order: 0,
	date_created: '',
	date_modified: '',
};

const props = {
	type: 'receipt' as const,
	onSetActive: vi.fn(),
	onPreview: vi.fn(),
	onToggle: vi.fn(),
	onDelete: vi.fn(),
	onReorder: vi.fn(),
	togglingId: null,
	deletingId: null,
};

function render(template: Template) {
	return renderToStaticMarkup(
		<TemplatesTable
			{...props}
			templates={[template]}
			onInstallNewVersion={vi.fn()}
			installingNewVersion={false}
		/>
	);
}

describe('gallery update badge', () => {
	it('offers the new version when the merchant has edited their copy', () => {
		const html = render({
			...base,
			gallery_update: { status: 'outdated-edited', installed_version: 1, latest_version: 2 },
		});

		expect(html).toContain('table.update_available');
		expect(html).toContain('table.install_new_version');
	});

	// An unedited copy is replaced in place on upgrade, so the merchant has nothing to decide.
	// Showing a badge here is what would turn this into noise everyone learns to ignore.
	it('stays silent for an unedited copy that will be updated automatically', () => {
		const html = render({
			...base,
			gallery_update: { status: 'outdated-untouched', installed_version: 1, latest_version: 2 },
		});

		expect(html).not.toContain('table.update_available');
		expect(html).not.toContain('table.install_new_version');
	});

	it('stays silent for a template that is up to date', () => {
		const html = render({
			...base,
			gallery_update: { status: 'current', installed_version: 2, latest_version: 2 },
		});

		expect(html).not.toContain('table.update_available');
	});

	// A response from a plugin build older than the comparison omits the field entirely.
	it('stays silent when the field is absent', () => {
		const html = render(base);

		expect(html).not.toContain('table.update_available');
	});
});
