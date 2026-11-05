const { test, expect } = require('../../tooling/node_modules/@playwright/test');

// These checks never submit data, send recovery email, or authenticate real users.
test.beforeEach(async ({ page }, testInfo) => {
  testInfo.failedStylesheets = [];
  page.on('response', response => {
    if (response.request().resourceType() === 'stylesheet' && response.status() >= 400) {
      testInfo.failedStylesheets.push(response.url());
    }
  });
  await page.route('**/*', route => {
    if (!['GET', 'HEAD', 'OPTIONS'].includes(route.request().method())) return route.abort();
    return route.continue();
  });
});

test.afterEach(async ({}, testInfo) => {
  expect(testInfo.failedStylesheets, 'Stylesheets should load successfully').toEqual([]);
});

for (const [role, path] of [
  ['resident', 'ResidentLogin.php'],
  ['staff', 'RHULogin.php'],
  ['administrator', 'RHUAdminLogin.php'],
]) {
  test(`${role}: login form loads`, async ({ page }) => {
    const response = await page.goto(path);
    expect(response.status()).toBe(200);
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.locator('form button[type="submit"]')).toBeVisible();
  });

  test(`${role}: required fields reject empty and malformed input`, async ({ page }) => {
    await page.goto(path);
    const email = page.locator('input[name="email"]');
    const password = page.locator('input[name="password"]');
    expect(await email.evaluate(el => el.validity.valueMissing)).toBe(true);
    expect(await password.evaluate(el => el.validity.valueMissing)).toBe(true);
    await email.fill('invalid-email');
    expect(await email.evaluate(el => el.validity.typeMismatch)).toBe(true);
    await email.fill('browser-test@example.invalid');
    await password.fill('Sample-only-123');
    expect(await email.evaluate(el => el.checkValidity())).toBe(true);
    expect(await password.evaluate(el => el.checkValidity())).toBe(true);
  });

  test(`${role}: password can be shown and hidden`, async ({ page }) => {
    await page.goto(path);
    const password = page.locator('input[name="password"]');
    await password.fill('Sample-only-123');
    await page.locator('#togglePwd').click();
    await expect(password).toHaveAttribute('type', 'text');
    await expect(password).toHaveValue('Sample-only-123');
    await page.locator('#togglePwd').click();
    await expect(password).toHaveAttribute('type', 'password');
  });
}

test('resident can reach account registration', async ({ page }) => {
  await page.goto('ResidentLogin.php');
  await page.getByRole('link', { name: 'Create account', exact: true }).click();
  await expect(page).toHaveURL(/ResidentRegistration\.php/);
  await expect(page.locator('form').first()).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeVisible();
});

test('resident can reach password recovery without sending email', async ({ page }) => {
  await page.goto('ResidentLogin.php');
  await page.getByRole('link', { name: 'Forgot your password?' }).click();
  await expect(page).toHaveURL(/ForgotPassword\.php\?portal=resident/);
  await expect(page.locator('input[type="email"]')).toBeVisible();
});

test('staff portal links to staff login', async ({ page }) => {
  await page.goto('rhulandingpage.php');
  await page.locator('a[href="RHULogin.php"]').filter({ visible: true }).first().click();
  await expect(page).toHaveURL(/RHULogin\.php/);
  await expect(page.locator('input[name="password"]')).toBeVisible();
});

for (const path of [
  'ResidentDashboard.php', 'RHUAdminDashboard.php', 'NurseDashboard.php',
  'DentalDashboard.php', 'MedTechDashboard.php', 'MidwifeDashboard.php',
  'SanitaryDashboard.php', 'StaffResidentRecords.php', 'StaffResidentAppointments.php',
  'ResidentID.php',
]) {
  test(`signed-out visitors cannot open ${path}`, async ({ page }) => {
    const response = await page.goto(path);
    expect(response.status()).toBeLessThan(400);
    await expect(page).toHaveURL(/(?:ResidentLogin|RHULogin|RHUAdminLogin)\.php/);
    await expect(page.locator('input[name="password"]')).toBeVisible();
  });
}

test('resident login works at mobile width', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('ResidentLogin.php');
  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="password"]')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.getByRole('link', { name: 'Forgot your password?' }).click();
  await expect(page.locator('input[type="email"]')).toBeVisible();
});
