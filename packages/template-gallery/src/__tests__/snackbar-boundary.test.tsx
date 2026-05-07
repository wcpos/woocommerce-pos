import * as React from 'react';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { SnackbarProvider, useSnackbar } from '@wcpos/ui';

function TriggerSnackbar() {
	const { addSnackbar } = useSnackbar();

	React.useEffect(() => {
		addSnackbar({
			id: 'template-saved',
			message: 'Order saved',
			status: 'success',
			timeout: false,
		});
	}, [addSnackbar]);

	return <div>Templates</div>;
}

describe('template gallery snackbar boundary', () => {
	let root: Root | null = null;
	let host: HTMLDivElement | null = null;

	afterEach(() => {
		if (root) {
			act(() => root?.unmount());
		}
		host?.remove();
		root = null;
		host = null;
	});

	it('can align the fixed snackbar slot to the WP admin content frame instead of the inset mount wrapper', () => {
		const wpContent = document.createElement('div');
		vi.spyOn(wpContent, 'getBoundingClientRect').mockReturnValue({
			left: 160,
			width: 1024,
			top: 0,
			right: 1184,
			bottom: 0,
			height: 0,
			x: 160,
			y: 0,
			toJSON: () => ({}),
		});

		host = document.createElement('div');
		document.body.append(host);

		act(() => {
			root = createRoot(host!);
			root.render(
				<SnackbarProvider boundsElement={wpContent}>
					<TriggerSnackbar />
				</SnackbarProvider>
			);
		});

		const slot = host.querySelector('[role="status"]') as HTMLElement;
		expect(slot.style.left).toBe('160px');
		expect(slot.style.width).toBe('1024px');
	});
});
