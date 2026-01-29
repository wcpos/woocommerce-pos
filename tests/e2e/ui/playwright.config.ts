import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for WCPOS E2E UI tests.
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
	testDir: './specs',
	
	/* Run tests in files in parallel */
	fullyParallel: true,
	
	/* Fail the build on CI if you accidentally left test.only in the source code */
	forbidOnly: !!process.env.CI,
	
	/* Retry on CI only */
	retries: process.env.CI ? 2 : 0,
	
	/* Opt out of parallel tests on CI */
	workers: process.env.CI ? 1 : undefined,
	
	/* Reporter to use */
	reporter: [
		['html', { open: 'never' }],
		['junit', { outputFile: 'playwright-results.xml' }],
	],
	
	/* Shared settings for all the projects below */
	use: {
		/* Base URL to use in actions like `await page.goto('/')` */
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		
		/* Collect trace when retrying the failed test */
		trace: 'on-first-retry',
		
		/* Screenshot on failure */
		screenshot: 'only-on-failure',
	},

	/* Configure projects for major browsers */
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],

	/* Run local dev server before starting the tests */
	// webServer: {
	//   command: 'pnpm run wp-env start',
	//   url: 'http://localhost:8888',
	//   reuseExistingServer: !process.env.CI,
	// },
});
