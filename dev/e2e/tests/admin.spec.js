// @ts-check
const { test, expect } = require('@playwright/test');

const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'Admin123!';

test.describe('Admin', () => {
  test('admin can log in and work with the Tradeaze configuration', async ({ page }) => {
    await page.goto('/admin/');
    await page.locator('#username').fill(ADMIN_USER);
    await page.locator('#login').fill(ADMIN_PASSWORD);
    await page.locator('.action-login').click();
    await expect(page.locator('.page-title')).toContainText(/dashboard/i, {
      timeout: 90_000,
    });

    // First login can pop the "Allow admin usage data collection" modal;
    // dismiss it if it appears so it cannot block later clicks.
    const usageModal = page.getByRole('button', { name: /don't allow/i });
    try {
      await usageModal.click({ timeout: 10_000 });
    } catch {
      // Modal not shown — nothing to dismiss.
    }

    // Works without a URL secret key because the dev store disables
    // admin/security/use_form_key (see install-magento.sh).
    await page.goto('/admin/admin/system_config/edit/section/tradeaze_api/');
    await expect(page.getByText('API Token').first()).toBeVisible({
      timeout: 90_000,
    });
    await expect(
      page.getByRole('button', { name: /create webhooks/i })
    ).toBeVisible();

    // Saving the section exercises the module's config backend models:
    // the encrypted token field and ValidateGeoNames, which passes
    // because GB GeoNames data was imported during setup.
    await page.getByRole('button', { name: /save config/i }).click();
    await expect(page.locator('.message-success')).toContainText(
      /saved the configuration/i,
      { timeout: 90_000 }
    );
  });
});
