/// <reference path="./svg.d.ts" />
/// <reference types="vite/client" />
/// <reference types="vite-plugin-svgr/client" />

declare global {
	interface WcposSettings {
		currencySymbol?: string;
		currencyDecimals?: number;
		getComponent?: (key: string) => import('react').ComponentType | undefined;
		environment?: {
			cloudflare?: { proxied: boolean; plugin_active: boolean };
			order_storage?: { hpos: boolean; settings_url: string };
		};
		barcodes?: string[];
		order_statuses?: Record<string, string>;
		countries?: Record<string, string>;
		storeCountry?: string;
		updateExtensionsCount?: number | null;
		unreadLogCounts?: Record<string, number>;
		currentUserId?: number;
		cloudPrintStoreOptions?: { id: number; name: string }[];
		anon_id?: string;
		site_uuid?: string;
	}

	/** Merges with the `WcposGlobal` declared by `@wcpos/i18n`. */
	interface WcposGlobal {
		settings?: WcposSettings;
		[key: string]: unknown;
	}

	interface Window {
		wcpos?: WcposGlobal;
		wpApiSettings?: { root?: string; nonce?: string };
	}
}

export {};
