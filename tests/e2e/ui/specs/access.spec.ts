import { test, expect } from '../fixtures/admin';

test.describe('Access Settings', () => {
	test.beforeEach(async ({ adminPage }) => {
		const settingsLoaded = adminPage.waitForResponse(
			(resp) => resp.url().includes('wcpos/v1/settings/access') && resp.status() === 200,
			{ timeout: 30000 }
		);
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/access');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
		await settingsLoaded;
	});

	test('renders the role list', async ({ adminPage }) => {
		// At least Administrator and Shop Manager must appear in the role list,
		// or the access settings response wasn't translated into a role list.
		await expect(adminPage.getByText('Administrator')).toBeVisible({ timeout: 10000 });
		await expect(adminPage.getByText('Shop Manager')).toBeVisible();
	});

	test('selecting a role renders its capability groups', async ({ adminPage }) => {
		// Administrator is selected by default. Capabilities are grouped under
		// WCPOS / WooCommerce / WordPress headings — at least the WCPOS heading
		// must render or the capability tree is broken.
		await expect(adminPage.getByRole('heading', { name: 'WCPOS' })).toBeVisible({
			timeout: 10000,
		});
		await expect(adminPage.getByRole('heading', { name: 'WordPress' })).toBeVisible();
	});
});
