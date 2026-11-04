// Page globals the analytics entry reads. WooCommerce Admin provides `wp.hooks`
// (pinned by the `@wordpress/hooks` webpack external); free's PostHog bootstrap
// puts a capture stub on `window.wcpos.posthog`. `WcposGlobal` merges with the
// declaration in `@wcpos/i18n`.
declare global {
	interface WcposGlobal {
		posthog?: {
			capture?: (event: string, properties?: Record<string, unknown>) => void;
		};
	}

	interface Window {
		wp?: {
			hooks?: {
				addFilter: (
					hookName: string,
					namespace: string,
					callback: (...args: any[]) => unknown
				) => void;
			};
		};
	}
}

export {};
