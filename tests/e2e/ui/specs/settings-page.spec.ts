import { test, expect } from '../fixtures/admin';

test.describe('Settings Page', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		await adminPage.waitForLoadState('networkidle');
	});

	test('Settings page loads without PHP errors', async ({ adminPage }) => {
		await expect(adminPage.locator('body')).not.toContainText('Fatal error');
		await expect(adminPage.locator('body')).not.toContainText('Warning:');
		await expect(adminPage.locator('#woocommerce-pos-settings')).toBeVisible();
	});

	test('Settings React app loads', async ({ adminPage }) => {
		// JS error fallback should not be visible
		await expect(adminPage.locator('#woocommerce-pos-js-error')).not.toBeVisible({ timeout: 15000 });
	});

	test('Navigation sidebar is visible', async ({ adminPage }) => {
		// Capture console errors for debugging CI failures
		const errors: string[] = [];
		adminPage.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text());
		});
		adminPage.on('pageerror', (err) => errors.push(err.message));

		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		await adminPage.waitForLoadState('networkidle');
		await adminPage.waitForTimeout(5000);

		const nav = adminPage.locator('aside');
		const isVisible = await nav.isVisible().catch(() => false);
		if (!isVisible) {
			const html = await adminPage.locator('#woocommerce-pos-settings').innerHTML();
			console.log('DEBUG mount-div innerHTML:', html.substring(0, 3000));
			console.log('DEBUG console errors:', JSON.stringify(errors));
		}

		await expect(nav).toBeVisible({ timeout: 15000 });

		// All five nav links should be present
		await expect(nav.getByText('General')).toBeVisible();
		await expect(nav.getByText('Checkout')).toBeVisible();
		await expect(nav.getByText('Access')).toBeVisible();
		await expect(nav.getByText('Sessions')).toBeVisible();
		await expect(nav.getByText('License')).toBeVisible();
	});

	test('Defaults to General page', async ({ adminPage }) => {
		// The index route redirects to #/general
		await adminPage.waitForTimeout(2000);
		expect(adminPage.url()).toContain('#/general');
	});
});
