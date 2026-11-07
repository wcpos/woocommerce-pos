import { installAdminSkipLinks } from '@wcpos/ui';

describe('installAdminSkipLinks', () => {
	let uninstall: (() => void) | undefined;

	beforeEach(() => {
		document.body.innerHTML = `
			<a href="#wpbody-content" class="screen-reader-shortcut">Skip to main content</a>
			<div id="wpbody-content">Content</div>
		`;
		window.location.hash = '#/general';
		// jsdom does not implement scrollIntoView.
		Element.prototype.scrollIntoView = vi.fn();
	});

	afterEach(() => {
		uninstall?.();
		uninstall = undefined;
		document.body.innerHTML = '';
	});

	function clickSkipLink() {
		const anchor = document.querySelector('a.screen-reader-shortcut') as HTMLAnchorElement;
		const event = new MouseEvent('click', { bubbles: true, cancelable: true });
		anchor.dispatchEvent(event);
		return event;
	}

	it('moves focus to the target without touching the router hash', () => {
		uninstall = installAdminSkipLinks();

		const event = clickSkipLink();
		const target = document.getElementById('wpbody-content') as HTMLElement;

		expect(event.defaultPrevented).toBe(true);
		expect(window.location.hash).toBe('#/general');
		expect(document.activeElement).toBe(target);
		expect(target.getAttribute('tabindex')).toBe('-1');
		expect(target.scrollIntoView).toHaveBeenCalled();
	});

	it('keeps an existing tabindex on the target', () => {
		document.getElementById('wpbody-content')?.setAttribute('tabindex', '0');
		uninstall = installAdminSkipLinks();

		clickSkipLink();

		expect(document.getElementById('wpbody-content')?.getAttribute('tabindex')).toBe('0');
	});

	it('leaves the click alone when the target id does not exist', () => {
		document.getElementById('wpbody-content')?.remove();
		uninstall = installAdminSkipLinks();

		const event = clickSkipLink();

		expect(event.defaultPrevented).toBe(false);
	});

	it('restores default behaviour after uninstall', () => {
		installAdminSkipLinks()();

		const event = clickSkipLink();

		expect(event.defaultPrevented).toBe(false);
	});
});
