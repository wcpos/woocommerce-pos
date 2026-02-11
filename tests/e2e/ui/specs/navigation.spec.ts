import { test, expect } from '../fixtures/admin';

test.describe('Settings Navigation', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		await adminPage.waitForLoadState('networkidle');
		// Wait for the React app sidebar to render
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
	});

	test('clicking nav items changes the route', async ({ adminPage }) => {
		// Click Checkout
		await adminPage.locator('aside').getByText('Checkout').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/checkout');

		// Click Access
		await adminPage.locator('aside').getByText('Access').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/access');

		// Click Sessions
		await adminPage.locator('aside').getByText('Sessions').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/sessions');

		// Click License
		await adminPage.locator('aside').getByText('License').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/license');

		// Click General to go back
		await adminPage.locator('aside').getByText('General').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/general');
	});

	test('active nav item is highlighted', async ({ adminPage }) => {
		// General should be active by default after redirect
		await adminPage.waitForTimeout(2000);
		// TanStack Router hash history generates full-path hrefs, use ends-with selector
		const generalLink = adminPage.locator('aside a[href$="#/general"]');
		await expect(generalLink).toBeVisible({ timeout: 15000 });
		// Active items get the wcpos:font-semibold class
		await expect(generalLink).toHaveClass(/font-semibold/);
	});

	test('deep linking via hash URL works', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/checkout');
		await adminPage.waitForLoadState('networkidle');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });

		const checkoutLink = adminPage.locator('aside a[href$="#/checkout"]');
		await expect(checkoutLink).toHaveClass(/font-semibold/);
	});

	test('deep linking to access page works', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/access');
		await adminPage.waitForLoadState('networkidle');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });

		const accessLink = adminPage.locator('aside a[href$="#/access"]');
		await expect(accessLink).toHaveClass(/font-semibold/);
	});
});
