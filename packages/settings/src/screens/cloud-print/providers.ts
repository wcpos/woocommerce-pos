import type { CloudProvider } from '../../hooks/use-cloud-print-settings';
import type { TemplateEngine } from '../../hooks/use-receipt-templates';

/**
 * A small square badge rendered next to a provider name.
 *
 * `mark` is the short glyph/initials shown inside the badge; `className` holds
 * the `wcpos:`-prefixed Tailwind utilities for its background/text colour.
 */
export interface ProviderBadge {
	mark: string;
	className: string;
}

/**
 * Static, per-provider metadata. This module is the single source of truth for
 * provider-specific UI/behaviour so components never branch on the id directly.
 */
export interface ProviderMeta {
	/** Brand/product name (a proper noun, intentionally not translated). */
	label: string;
	badge: ProviderBadge;
	/** Polling printers fetch jobs from this site; push providers are submitted to. */
	isPolling: boolean;
	/** REST poll endpoint slug, or `null` for push providers. */
	pollEndpoint: 'cloudprnt' | 'epson-sdp' | null;
	/** Receipt-template engines this provider can render for automatic jobs. */
	templateEngines: 'all' | 'thermal';
}

export const PROVIDERS: Record<CloudProvider, ProviderMeta> = {
	printnode: {
		label: 'PrintNode',
		badge: { mark: 'PN', className: 'wcpos:bg-teal-600 wcpos:text-white' },
		isPolling: false,
		pollEndpoint: null,
		templateEngines: 'all',
	},
	'star-online': {
		label: 'Star Online',
		badge: { mark: '☆', className: 'wcpos:bg-indigo-600 wcpos:text-white' },
		isPolling: false,
		pollEndpoint: null,
		templateEngines: 'thermal',
	},
	'star-cloudprnt': {
		label: 'Star CloudPRNT',
		badge: { mark: '★', className: 'wcpos:bg-blue-500 wcpos:text-white' },
		isPolling: true,
		pollEndpoint: 'cloudprnt',
		templateEngines: 'thermal',
	},
	'epson-sdp': {
		label: 'Epson Server Direct Print',
		badge: { mark: 'E', className: 'wcpos:bg-blue-900 wcpos:text-white' },
		isPolling: true,
		pollEndpoint: 'epson-sdp',
		templateEngines: 'thermal',
	},
};

/**
 * Typed accessor for a provider's metadata.
 */
export function getProvider(id: CloudProvider): ProviderMeta {
	return PROVIDERS[id];
}

/**
 * Filter receipt-template options to those a given provider can render.
 *
 * Providers declare their renderable template engines independently from
 * polling/push delivery. PrintNode accepts every active template; Star Online,
 * Star CloudPRNT, and Epson SDP accept only thermal templates. The input objects
 * are returned untouched, so any extra fields are preserved.
 */
export function templateOptionsForProvider<T extends { engine: TemplateEngine }>(
	options: T[],
	provider: CloudProvider
): T[] {
	if (PROVIDERS[provider].templateEngines === 'all') {
		return options;
	}
	return options.filter((option) => option.engine === PROVIDERS[provider].templateEngines);
}
