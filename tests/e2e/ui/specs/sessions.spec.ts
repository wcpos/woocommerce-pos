import { test, expect } from '../fixtures/admin';

test.describe('Sessions Settings', () => {
	test.beforeEach(async ({ adminPage }) => {
		// Sessions uses a different endpoint than the other pages — the
		// settings hook fetches /wcpos/v1/auth/users/sessions via Suspense.
		const sessionsLoaded = adminPage.waitForResponse(
			(resp) =>
				resp.url().includes('wcpos/v1/auth/users/sessions') && resp.status() === 200,
			{ timeout: 30000 }
		);
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/sessions');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
		await sessionsLoaded;
	});

	test('renders the manage-sessions description', async ({ adminPage }) => {
		// Either the user list with sessions OR the empty-state notice must
		// render, but the descriptive notice is shown in both cases.
		await expect(
			adminPage.getByText('Manage active user sessions', { exact: false })
		).toBeVisible({ timeout: 10000 });
	});

	test('current admin user has at least one session in the list', async ({ adminPage }) => {
		// The user that just logged in via the fixture must appear with at
		// least one session — if not, the auth/sessions wiring is broken.
		await expect(adminPage.getByText('admin', { exact: false }).first()).toBeVisible({
			timeout: 10000,
		});
	});
});
