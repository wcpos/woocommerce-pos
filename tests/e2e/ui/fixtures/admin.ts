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
