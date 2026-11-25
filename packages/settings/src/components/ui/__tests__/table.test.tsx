import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { Table, TableBody, TableCell, TableRow } from '@wcpos/ui';

import tableCss from '../../../../../ui/src/table.css?raw';
import settingsCss from '../../../index.css?raw';

// The scroll shadows are CSS only and jsdom cannot paint pseudo-elements or run scroll
// timelines, so pin the pairing they depend on: the hook class on the scroller, the rules
// keyed on it, and the import that puts them in the settings stylesheet.
describe('Table scroll shadows', () => {
	it('puts the shadow hook on the element that scrolls', () => {
		render(
			<Table data-testid="table">
				<TableBody>
					<TableRow>
						<TableCell>Cash</TableCell>
					</TableRow>
				</TableBody>
			</Table>
		);
		const scroller = screen.getByTestId('table');
		expect(scroller).toHaveClass('wcpos-table', 'wcpos:overflow-x-auto');
		expect(scroller.firstElementChild?.tagName).toBe('TABLE');
	});

	it('drives both edge shadows from the scroller they sit in', () => {
		// Only where scroll timelines exist: elsewhere a zero-length animation with
		// fill-mode both would pin the shadows on.
		const [base, supported = ''] = tableCss.split(
			'@supports (animation-timeline: scroll(nearest inline)) {'
		);
		expect(base.replace(/\/\*[\s\S]*?\*\//g, '')).not.toMatch(/animation-/);
		expect(supported).toMatch(
			/\.wcpos-table::after\s*\{[^}]*animation-timeline:\s*scroll\(nearest inline\)/
		);
		expect(supported).toMatch(
			/\.wcpos-table::before\s*\{[^}]*animation-name:\s*wcpos-table-shadow-in/
		);
		expect(supported).toMatch(
			/\.wcpos-table::after\s*\{[^}]*animation-name:\s*wcpos-table-shadow-out/
		);
		expect(settingsCss).toContain('@import "../../ui/src/table.css";');
	});
});
