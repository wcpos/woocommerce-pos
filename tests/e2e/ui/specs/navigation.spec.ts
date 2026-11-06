import { test, expect } from '../fixtures/admin';

test.describe('Settings Navigation', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		await adminPage.waitForLoadState('domcontentloaded');
		// Wait for the React app sidebar to render
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
	});

	test('clicking nav items changes the route', async ({ adminPage }) => {
		// Click Checkout
		await adminPage.getByTestId('settings-nav-checkout').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/checkout');
		await expect(adminPage.getByTestId('settings-nav-checkout')).toHaveAttribute(
			'aria-current',
			'page'
		);

		// Click Access
		await adminPage.getByTestId('settings-nav-access').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/access');
		await expect(adminPage.getByTestId('settings-nav-access')).toHaveAttribute(
			'aria-current',
			'page'
		);

		// Click Sessions
		await adminPage.getByTestId('settings-nav-sessions').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/sessions');
		await expect(adminPage.getByTestId('settings-nav-sessions')).toHaveAttribute(
			'aria-current',
			'page'
		);

		// Click License
		await adminPage.getByTestId('settings-nav-license').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/license');
		await expect(adminPage.getByTestId('settings-nav-license')).toHaveAttribute(
			'aria-current',
			'page'
		);

		// Click General to go back
		await adminPage.getByTestId('settings-nav-general').click();
		await adminPage.waitForTimeout(500);
		expect(adminPage.url()).toContain('#/general');
		await expect(adminPage.getByTestId('settings-nav-general')).toHaveAttribute(
			'aria-current',
			'page'
		);
	});

	test('active nav item is highlighted', async ({ adminPage }) => {
		// General should be active by default after redirect
		await adminPage.waitForTimeout(2000);
		// Nav hrefs are hash routes (`#/<page>`); match the end of the href
		const generalLink = adminPage.locator('aside a[href$="#/general"]');
		await expect(generalLink).toBeVisible({ timeout: 15000 });
		// Active items get the wcpos:font-semibold class
		await expect(generalLink).toHaveClass(/font-semibold/);
	});

	test('deep linking via hash URL works', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/checkout');
		await adminPage.waitForLoadState('domcontentloaded');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });

		const checkoutLink = adminPage.locator('aside a[href$="#/checkout"]');
		await expect(checkoutLink).toHaveClass(/font-semibold/);
	});

	test('deep linking to access page works', async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/access');
		await adminPage.waitForLoadState('domcontentloaded');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });

		const accessLink = adminPage.locator('aside a[href$="#/access"]');
		await expect(accessLink).toHaveClass(/font-semibold/);
	});
});
