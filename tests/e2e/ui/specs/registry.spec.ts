import { test, expect } from '../fixtures/admin';

test.describe('Extension Registry', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		await adminPage.waitForLoadState('networkidle');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
	});

	test('registry API is exposed on window.wcpos.settings', async ({ adminPage }) => {
		const hasAPI = await adminPage.evaluate(() => {
			return (
				typeof (window as any).wcpos?.settings?.registerPage === 'function' &&
				typeof (window as any).wcpos?.settings?.registerField === 'function' &&
				typeof (window as any).wcpos?.settings?.modifyField === 'function'
			);
		});
		expect(hasAPI).toBe(true);
	});

	test('registering a page adds it to the nav', async ({ adminPage }) => {
		// Register a test page via the global registry API
		await adminPage.evaluate(() => {
			(window as any).wcpos.settings.registerPage({
				id: 'test-page',
				label: 'Test Page',
				group: 'tools',
				component: () => null,
				priority: 100,
			});
		});

		// The sidebar should now contain the "Test Page" link
		await expect(adminPage.locator('aside').getByText('Test Page')).toBeVisible({ timeout: 5000 });
	});
});
