import * as React from 'react';

import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AutoPrintRules } from './auto-print-rules';

import type { CloudAssignment, CloudPrinter } from '../../hooks/use-cloud-print-settings';

type AutoPrintRulesTestProps = React.ComponentProps<typeof AutoPrintRules> & {
	storeOptions?: { id: number; name: string }[];
};

function makePrinters(): CloudPrinter[] {
	return [
		{ id: 'kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 },
		{ id: 'front', name: 'Front counter', provider: 'epson-sdp', store_id: 0 },
	];
}

const templateOptions = [
	{ value: '11', label: 'Standard receipt', engine: 'thermal' as const },
	{ value: '22', label: 'Kitchen ticket', engine: 'thermal' as const },
];

function makeAssignments(): CloudAssignment[] {
	return [
		{ printer_id: 'kitchen', store_id: 0, scope: 'pos', template_id: '11' },
		{ printer_id: 'front', store_id: 0, scope: 'online', template_id: '22' },
	];
}

function renderRules(overrides: Partial<AutoPrintRulesTestProps> = {}) {
	const onChange = vi.fn(overrides.onChange);
	const printers = overrides.printers ?? makePrinters();
	const assignments = overrides.assignments ?? makeAssignments();
	const utils = render(
		<AutoPrintRules
			printers={printers}
			assignments={assignments}
			templateOptions={overrides.templateOptions ?? templateOptions}
			storeOptions={overrides.storeOptions ?? []}
			onChange={onChange}
		/>
	);
	return { ...utils, onChange, printers, assignments };
}

describe('AutoPrintRules', () => {
	it('renders each assignment as a sentence with three selects', () => {
		renderRules();

		expect(screen.getByTestId('rule-0')).toBeInTheDocument();
		expect(screen.getByTestId('rule-1')).toBeInTheDocument();

		// Sentence fragments are present.
		expect(screen.getAllByText(/Automatically print/i).length).toBeGreaterThan(0);
		expect(screen.getAllByText(/^to$/i).length).toBeGreaterThan(0);
		expect(screen.getAllByText(/using the/i).length).toBeGreaterThan(0);
		expect(screen.getAllByText(/template\./i).length).toBeGreaterThan(0);

		// Three selects per row.
		const row0 = screen.getByTestId('rule-0');
		expect(within(row0).getByTestId('rule-scope-0')).toBeInTheDocument();
		expect(within(row0).getByTestId('rule-printer-0')).toBeInTheDocument();
		expect(within(row0).getByTestId('rule-template-0')).toBeInTheDocument();
	});

	it('sizes each rule select from its currently selected label', () => {
		renderRules({
			assignments: [{ printer_id: 'kitchen', store_id: 0, scope: 'every', template_id: '11' }],
		});

		expect(screen.getByTestId('rule-scope-0')).toHaveStyle({
			width: 'calc(11ch + 3.5rem)',
		});
		expect(screen.getByTestId('rule-printer-0')).toHaveStyle({
			width: 'calc(7ch + 3.5rem)',
		});
		expect(screen.getByTestId('rule-template-0')).toHaveStyle({
			width: 'calc(16ch + 3.5rem)',
		});
	});

	it('changing scope select calls onChange with scope updated and other fields preserved', () => {
		const { onChange } = renderRules();
		const scope = screen.getByTestId('rule-scope-0');
		fireEvent.change(scope, { target: { value: 'online' } });
		expect(onChange).toHaveBeenCalledTimes(1);
		const next = onChange.mock.calls[0][0] as CloudAssignment[];
		expect(next[0]).toEqual({ printer_id: 'kitchen', store_id: 0, scope: 'online', template_id: '11' });
		// Other rows untouched.
		expect(next[1]).toEqual({ printer_id: 'front', store_id: 0, scope: 'online', template_id: '22' });
	});

	it('changing template select calls onChange with template_id set to String(value)', () => {
		const { onChange } = renderRules();
		const template = screen.getByTestId('rule-template-0');
		fireEvent.change(template, { target: { value: '22' } });
		expect(onChange).toHaveBeenCalledTimes(1);
		const next = onChange.mock.calls[0][0] as CloudAssignment[];
		expect(next[0].template_id).toBe('22');
		expect(next[0].scope).toBe('pos');
		expect(next[0].printer_id).toBe('kitchen');
		expect(next[0].store_id).toBe(0);
	});

	it('changing printer select calls onChange with printer_id updated', () => {
		const { onChange } = renderRules();
		const printer = screen.getByTestId('rule-printer-0');
		fireEvent.change(printer, { target: { value: 'front' } });
		expect(onChange).toHaveBeenCalledTimes(1);
		const next = onChange.mock.calls[0][0] as CloudAssignment[];
		expect(next[0].printer_id).toBe('front');
		expect(next[0].scope).toBe('pos');
		expect(next[0].template_id).toBe('11');
		expect(next[0].store_id).toBe(0);
	});

	it('+ Add rule appends a default rule', () => {
		const { onChange, printers } = renderRules();
		fireEvent.click(screen.getByTestId('rules-add'));
		expect(onChange).toHaveBeenCalledTimes(1);
		const next = onChange.mock.calls[0][0] as CloudAssignment[];
		expect(next).toHaveLength(3);
		expect(next[2]).toEqual({
			printer_id: printers[0].id,
			store_id: 0,
			scope: 'every',
			template_id: templateOptions[0].value,
		});
	});

	it('Remove calls onChange with the row removed', () => {
		const { onChange } = renderRules();
		fireEvent.click(screen.getByTestId('rule-remove-0'));
		expect(onChange).toHaveBeenCalledTimes(1);
		const next = onChange.mock.calls[0][0] as CloudAssignment[];
		expect(next).toHaveLength(1);
		expect(next[0]).toEqual({ printer_id: 'front', store_id: 0, scope: 'online', template_id: '22' });
	});

	it('renders the empty state and a working add button when there are no rules', () => {
		const { onChange } = renderRules({ assignments: [] });
		expect(screen.getByTestId('rules-empty')).toHaveTextContent('No rules yet.');
		const add = screen.getByTestId('rules-add');
		expect(add).toBeInTheDocument();
		fireEvent.click(add);
		expect(onChange).toHaveBeenCalledTimes(1);
		expect((onChange.mock.calls[0][0] as CloudAssignment[])).toHaveLength(1);
	});

	it('disables the add button when there are no printers', () => {
		renderRules({ printers: [], assignments: [] });
		expect(screen.getByTestId('rules-add')).toBeDisabled();
	});

	it('renders the tip note and the POS › Templates link', () => {
		renderRules();
		expect(
			screen.getByText(/leave it empty to print receipts only manually from the POS/i)
		).toBeInTheDocument();
		const link = screen.getByRole('link', { name: /POS › Templates/i });
		expect(link).toBeInTheDocument();
		expect(link).toHaveAttribute('href');
	});

	it('does not crash when an assignment template_id is not in templateOptions', () => {
		const { onChange } = renderRules({
			assignments: [{ printer_id: 'kitchen', store_id: 0, scope: 'pos', template_id: '999' }],
		});
		expect(screen.getByTestId('rule-template-0')).toBeInTheDocument();
		expect(onChange).not.toHaveBeenCalled();
	});

	it('filters template options per row by the row printer provider', () => {
		const mixedOptions = [
			{ value: '11', label: 'Thermal receipt', engine: 'thermal' as const },
			{ value: '22', label: 'PDF receipt', engine: 'logicless' as const },
			{ value: '33', label: 'Legacy receipt', engine: 'legacy-php' as const },
		];
		const printers: CloudPrinter[] = [
			{ id: 'kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 },
			{ id: 'cloud', name: 'Cloud', provider: 'printnode', store_id: 0 },
		];
		renderRules({
			printers,
			assignments: [
				{ printer_id: 'kitchen', store_id: 0, scope: 'pos', template_id: '11' },
				{ printer_id: 'cloud', store_id: 0, scope: 'online', template_id: '22' },
			],
			templateOptions: mixedOptions,
		});

		// Star (direct polling) row → only the thermal template.
		const starSelect = screen.getByTestId('rule-template-0');
		const starOptions = within(starSelect)
			.getAllByRole('option')
			.map((o) => o.textContent);
		expect(starOptions).toEqual(['Thermal receipt']);

		// PrintNode (push) row → all templates.
		const pnSelect = screen.getByTestId('rule-template-1');
		const pnOptions = within(pnSelect)
			.getAllByRole('option')
			.map((o) => o.textContent);
		expect(pnOptions).toEqual(['Thermal receipt', 'PDF receipt', 'Legacy receipt']);
	});

	it('defaults a new rule template_id to the first option valid for the first printer', () => {
		const mixedOptions = [
			{ value: '22', label: 'PDF receipt', engine: 'logicless' as const },
			{ value: '11', label: 'Thermal receipt', engine: 'thermal' as const },
		];
		// First printer is a star (direct) printer, so the only valid option is the thermal one.
		const { onChange } = renderRules({
			assignments: [],
			templateOptions: mixedOptions,
		});
		fireEvent.click(screen.getByTestId('rules-add'));
		const next = onChange.mock.calls[0][0] as CloudAssignment[];
		expect(next[0].template_id).toBe('11');
		expect(next[0].store_id).toBe(0);
	});

	it('lists store rows after channel rows when store options are available', () => {
		renderRules({
			assignments: [{ printer_id: 'kitchen', store_id: 0, scope: 'every', template_id: '11' }],
			storeOptions: [
				{ id: 7, name: 'Store A' },
				{ id: 8, name: 'Store B' },
			],
		});

		const options = within(screen.getByTestId('rule-scope-0'))
			.getAllByRole('option')
			.map((option) => option.textContent);
		expect(options).toEqual([
			'every order',
			'every in-store (POS) order',
			'every online order',
			'orders for Store A',
			'orders for Store B',
		]);
	});

	it('selecting a store row writes store_id with the every scope', () => {
		const { onChange } = renderRules({
			assignments: [{ printer_id: 'kitchen', store_id: 0, scope: 'pos', template_id: '11' }],
			storeOptions: [{ id: 7, name: 'Store A' }],
		});

		fireEvent.change(screen.getByTestId('rule-scope-0'), {
			target: { value: 'store:7' },
		});

		expect(onChange).toHaveBeenCalledTimes(1);
		expect((onChange.mock.calls[0][0] as CloudAssignment[])[0]).toEqual({
			printer_id: 'kitchen',
			store_id: 7,
			scope: 'every',
			template_id: '11',
		});
	});

	it('selecting a channel row resets store_id to 0 and writes the selected scope', () => {
		const { onChange } = renderRules({
			assignments: [{ printer_id: 'kitchen', store_id: 7, scope: 'every', template_id: '11' }],
			storeOptions: [{ id: 7, name: 'Store A' }],
		});

		fireEvent.change(screen.getByTestId('rule-scope-0'), {
			target: { value: 'online' },
		});

		expect(onChange).toHaveBeenCalledTimes(1);
		expect((onChange.mock.calls[0][0] as CloudAssignment[])[0]).toEqual({
			printer_id: 'kitchen',
			store_id: 0,
			scope: 'online',
			template_id: '11',
		});
	});

	it('shows the store row selected by store_id', () => {
		renderRules({
			assignments: [{ printer_id: 'kitchen', store_id: 7, scope: 'every', template_id: '11' }],
			storeOptions: [{ id: 7, name: 'Store A' }],
		});

		expect(screen.getByTestId('rule-scope-0')).toHaveValue('store:7');
	});

	it('renders a disabled unknown store option for absent store_id and keeps it selected', () => {
		renderRules({
			assignments: [{ printer_id: 'kitchen', store_id: 42, scope: 'every', template_id: '11' }],
			storeOptions: [{ id: 7, name: 'Store A' }],
		});

		expect(screen.getByTestId('rule-scope-0')).toHaveValue('store:42');
		expect(screen.getByRole('option', { name: 'Unknown store (#42)' })).toBeDisabled();
	});
});
