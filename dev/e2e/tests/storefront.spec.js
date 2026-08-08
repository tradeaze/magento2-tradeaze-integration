// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Storefront', () => {
  test('homepage renders', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.logo')).toBeVisible();
  });

  test('category page shows a product grid', async ({ page }) => {
    await page.goto('/women.html');
    await expect(
      page.locator('.products-grid .product-item').first()
    ).toBeVisible();
  });

  test('guest reaches checkout shipping methods with a GB address', async ({ page }) => {
    // Fusion Backpack is a simple product in the Luma sample data (no
    // size/colour options), so it can be added to the cart directly.
    await page.goto('/fusion-backpack.html');
    await page.locator('#product-addtocart-button').click();
    await expect(page.locator('div.message-success')).toBeVisible({
      timeout: 60_000,
    });

    await page.goto('/checkout/');
    // The checkout form is a Knockout app that loads asynchronously.
    const email = page.locator('#customer-email-fieldset #customer-email');
    await email.waitFor({ state: 'visible', timeout: 90_000 });
    await email.fill('e2e-shopper@example.com');
    await page.locator('input[name="firstname"]').fill('E2E');
    await page.locator('input[name="lastname"]').fill('Shopper');
    await page.locator('select[name="country_id"]').selectOption('GB');
    await page.locator('input[name="street[0]"]').fill('1 London Wall');
    await page.locator('input[name="city"]').fill('London');
    await page.locator('input[name="postcode"]').fill('EC2M 5QQ');
    await page.locator('input[name="telephone"]').fill('02012345678');

    // Shipping methods refresh from the server once the address is
    // complete. Without a Tradeaze API token the module is inactive, so
    // the baseline expectation is that built-in methods (flat rate)
    // appear and checkout is not broken by the module's observers.
    const methods = page.locator('.table-checkout-shipping-method tbody tr');
    await expect(methods.first()).toBeVisible({ timeout: 90_000 });

    // With a configured staging token (dev/.env) the Tradeaze options
    // should appear too. Opt in via EXPECT_TRADEAZE=1.
    if (process.env.EXPECT_TRADEAZE === '1') {
      await expect(
        page.locator('.table-checkout-shipping-method')
      ).toContainText(/tradeaze/i, { timeout: 90_000 });
    }
  });
});
