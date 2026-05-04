/// <reference types="vite/client" />
/// <reference types="vite-plugin-svgr/client" />

declare global {
	interface WcposSettings {
		barcodes?: string[];
		order_statuses?: Record<string, string>;
		countries?: Record<string, string>;
		updateExtensionsCount?: number | null;
		unreadLogCounts?: Record<string, number>;
		currentUserId?: number;
	}

	interface Window {
		wcpos?: {
			settings?: WcposSettings;
			[key: string]: unknown;
		};
	}
}

export {};
