// @ts-check
const { defineConfig } = require('@playwright/test');

// BASE_URL selects the store under test:
//   http://localhost:8080                  (docker compose fallback, CI)
//   https://tradeaze-magento2.ddev.site    (ddev)
module.exports = defineConfig({
  testDir: './tests',
  // Developer mode renders are slow (unminified JS, no caches warm).
  timeout: 180_000,
  expect: { timeout: 30_000 },
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    // ddev uses locally-trusted certs that the CI browser won't know.
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Sandboxes with a pre-provisioned Chromium can point at it directly.
    launchOptions: process.env.PW_CHROMIUM_PATH
      ? { executablePath: process.env.PW_CHROMIUM_PATH }
      : {},
  },
});
