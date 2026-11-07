/**
 * wp-admin renders skip links (`Skip to main content`, `Skip to toolbar`) as
 * `<a class="screen-reader-shortcut" href="#id">`. Our admin apps use a hash
 * router, so letting the browser follow one rewrites `location.hash` and the
 * router treats the id as an unknown route. This moves focus to the target
 * instead and leaves the hash alone.
 *
 * Returns a function that removes the listener.
 */
export function installAdminSkipLinks(doc: Document = document): () => void {
	const onClick = (event: MouseEvent) => {
		const target = event.target as Element | null;
		const link = target?.closest?.('a.screen-reader-shortcut[href^="#"]');
		if (!link) {
			return;
		}

		const id = (link.getAttribute('href') ?? '').slice(1);
		const destination = id ? doc.getElementById(id) : null;
		if (!destination) {
			return;
		}

		event.preventDefault();
		if (!destination.hasAttribute('tabindex')) {
			destination.setAttribute('tabindex', '-1');
		}
		destination.focus();
		destination.scrollIntoView();
	};

	doc.addEventListener('click', onClick);
	return () => doc.removeEventListener('click', onClick);
}
