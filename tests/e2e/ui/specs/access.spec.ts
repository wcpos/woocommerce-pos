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
		// At least the administrator and shop_manager rows must appear in the
		// role list, or the access settings response wasn't rendered into a
		// role list. Match by stable role-id testid rather than the translated
		// display name, which changes per locale.
		await expect(adminPage.getByTestId('access-role-administrator')).toBeVisible({
			timeout: 10000,
		});
		await expect(adminPage.getByTestId('access-role-shop_manager')).toBeVisible();
	});

	test('selecting a role renders its capability groups', async ({ adminPage }) => {
		// Administrator is selected by default. Capabilities are grouped under
		// WCPOS / WooCommerce / WordPress headings — at least the WCPOS and
		// WordPress groups must render or the capability tree is broken. The
		// group testids are keyed off the non-translatable group ids.
		await expect(adminPage.getByTestId('access-capability-group-wcpos')).toBeVisible({
			timeout: 10000,
		});
		await expect(adminPage.getByTestId('access-capability-group-wp')).toBeVisible();
	});
});
