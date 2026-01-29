import { test, expect } from '../fixtures/admin';

test.describe('Plugin Activation', () => {
	test('WCPOS plugin is listed in plugins page', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/plugins.php');
		
		// Check that WCPOS plugin is listed
		const wcposPlugin = adminPage.locator('tr[data-slug="woocommerce-pos"]');
		await expect(wcposPlugin).toBeVisible();
	});

	test('WCPOS plugin is active', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/plugins.php');
		
		// Check that the plugin row has the active class
		const wcposPlugin = adminPage.locator('tr[data-slug="woocommerce-pos"]');
		await expect(wcposPlugin).toHaveClass(/active/);
	});

	test('WCPOS admin menu is visible', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/');
		
		// Check that POS top-level menu item exists in admin menu
		// Use the specific top-level menu item class to avoid matching submenu items
		const posMenu = adminPage.locator('#toplevel_page_woocommerce-pos > a');
		await expect(posMenu).toBeVisible();
	});
});
