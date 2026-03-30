import { test as base, expect, Page } from '@playwright/test';

/**
 * WordPress admin credentials for testing
 */
export const ADMIN_USER = {
	username: process.env.WP_ADMIN_USER || 'admin',
	password: process.env.WP_ADMIN_PASSWORD || 'password',
};

/**
 * Extended test fixture with WordPress admin login
 */
export const test = base.extend<{ adminPage: Page }>({
	adminPage: async ({ page }, use) => {
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

		// Capture console errors for debugging
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				console.log(`[BROWSER ERROR] ${msg.text()}`);
			}
		});
		page.on('pageerror', (err) => {
			console.log(`[PAGE ERROR] ${err.message}`);
		});

		// Login to WordPress admin
		await page.goto('/wp-login.php');
		await page.fill('#user_login', ADMIN_USER.username);
		await page.fill('#user_pass', ADMIN_USER.password);
		await page.click('#wp-submit');

		// Wait for dashboard to load
		await page.waitForURL(/\/wp-admin\//);

		await use(page);
	},
});

export { expect };
