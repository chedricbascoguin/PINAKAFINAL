const { test, expect } = require('../../tooling/node_modules/@playwright/test');

test('homepage shows the health services', async ({ page }) => {
  const failedStylesheets = [];
  page.on('response', response => {
    if (response.request().resourceType() === 'stylesheet' && response.status() >= 400) {
      failedStylesheets.push(response.url());
    }
  });
  const response = await page.goto('./');
  expect(response.status()).toBe(200);
  expect(failedStylesheets, 'Homepage stylesheets should load successfully').toEqual([]);
  await expect(page).toHaveTitle(/ResiHUnity RHU/);
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Your Health,');
  await expect(page.getByRole('heading', { name: 'RHU Healthcare Services', exact: true })).toBeVisible();
});

test('resident sign-in link opens the login form', async ({ page }) => {
  await page.goto('./');
  const announcement = page.locator('#awareness-landing-overlay');
  if (await announcement.isVisible()) {
    await announcement.getByRole('button', { name: 'Proceed to Site' }).click();
    await expect(announcement).toBeHidden();
  }
  await page.getByRole('link', { name: 'Resident Sign In', exact: true }).first().click();
  await expect(page).toHaveURL(/ResidentLogin\.php/);
  await expect(page.locator('input[type="password"]')).toBeVisible();
});
