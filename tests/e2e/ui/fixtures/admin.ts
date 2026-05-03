import { test as base, expect, Page } from '@playwright/test';

/**
 * WordPress admin credentials for testing
 */
export const ADMIN_USER = {
	username: process.env.WP_ADMIN_USER || 'admin',
	password: process.env.WP_ADMIN_PASSWORD || 'password',
};

interface FailedResponse {
	url: string;
	status: number;
	method: string;
}

/**
 * Returns true if a same-origin response/request failure should be ignored by
 * the assertion in afterEach. Off-origin failures (analytics, external CDN
 * images, etc.) are always ignored — they aren't the plugin's responsibility.
 */
function isIgnoredFailure(url: string, status: number): boolean {
	let parsed: URL;
	try {
		parsed = new URL(url);
	} catch {
		return true;
	}

	const baseUrl = new URL(process.env.WP_BASE_URL || 'http://localhost:8888');
	if (parsed.host !== baseUrl.host) {
		return true;
	}

	// WP doesn't ship a favicon by default; ignore.
	if (parsed.pathname === '/favicon.ico') {
		return true;
	}

	// Source map requests in dev/SCRIPT_DEBUG mode can 404 if maps weren't built.
	if (parsed.pathname.endsWith('.map') && status === 404) {
		return true;
	}

	return false;
}

/**
 * Extended test fixture with WordPress admin login.
 *
 * Captures same-origin network failures and uncaught page errors during the
 * test, then asserts the buffers are empty after the spec body runs. This is
 * what makes a "page renders" assertion meaningful — without it, broken REST
 * endpoints and missing assets pass silently because the DOM still loads.
 */
export const test = base.extend<{ adminPage: Page }>({
	adminPage: async ({ page }, use) => {
		const failedResponses: FailedResponse[] = [];
		const pageErrors: string[] = [];

		// Populate browser-side error buffer so specs can read window.__console_errors
		await page.addInitScript(() => {
			(window as any).__console_errors = [];
			const push = (msg: unknown) => {
				(window as any).__console_errors.push(String(msg));
			};
			window.addEventListener('error', (event) => {
				push(event.error?.message ?? event.message);
			});
			const originalConsoleError = console.error;
			console.error = (...args: unknown[]) => {
				push(args.map(String).join(' '));
				originalConsoleError(...args);
			};
		});

		page.on('response', (resp) => {
			const status = resp.status();
			if (status < 400) return;
			if (isIgnoredFailure(resp.url(), status)) return;
			failedResponses.push({ url: resp.url(), status, method: resp.request().method() });
		});

		page.on('requestfailed', (req) => {
			if (isIgnoredFailure(req.url(), 0)) return;
			failedResponses.push({
				url: req.url(),
				status: 0,
				method: req.method(),
			});
		});

		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				console.log(`[BROWSER ERROR] ${msg.text()}`);
			}
		});

		page.on('pageerror', (err) => {
			console.log(`[PAGE ERROR] ${err.message}`);
			pageErrors.push(err.message);
		});

		// Login to WordPress admin
		await page.goto('/wp-login.php');
		await page.fill('#user_login', ADMIN_USER.username);
		await page.fill('#user_pass', ADMIN_USER.password);
		await page.click('#wp-submit');

		// Wait for dashboard to load
		await page.waitForURL(/\/wp-admin\//);

		await use(page);

		// Assert the test did not trigger same-origin network failures or
		// uncaught JS errors. Allowlist same-origin URLs that are expected to
		// fail (e.g. dev-only sourcemaps) inside isIgnoredFailure() above.
		const failureLines = failedResponses.map(
			(r) => `  ${r.method} ${r.status} ${r.url}`
		);
		expect(
			failedResponses,
			`Test triggered same-origin network failures:\n${failureLines.join('\n')}`
		).toEqual([]);
		expect(
			pageErrors,
			`Test triggered uncaught page errors:\n${pageErrors.join('\n')}`
		).toEqual([]);
	},
});

export { expect };
