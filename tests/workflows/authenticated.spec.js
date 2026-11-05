const { test, expect } = require('../../tooling/node_modules/@playwright/test');
const password = 'Workflow-Test-123!';
const headers = { 'X-Test-Token': process.env.RHU_TEST_TOKEN };
async function visit(page, url) { return page.goto(url, { waitUntil: 'domcontentloaded' }); }

async function login(page, role) {
  await visit(page, role === 'resident' ? 'ResidentLogin.php' : role === 'admin' ? 'RHUAdminLogin.php' : 'RHULogin.php');
  await page.locator('input[name="email"]').fill(`${role}@example.invalid`);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('form button[type="submit"]').click();
  if (role === 'admin') {
    await expect(page).toHaveURL(/step=mfa/);
    const mail = await (await page.request.get('__test/mail', { headers })).json();
    const code = mail.at(-1).html.match(/class='code-box'>(\d{6})/)[1];
    await page.locator('input[name="mfaCode"]').fill(code);
    await page.locator('form button[type="submit"]').click();
  }
  await expect(page).toHaveURL(/(?:Dashboard|StaffLock)\.php/);
  if (page.url().includes('StaffLock.php')) {
    await page.locator('[name="new_pin"]').fill('123456');
    await page.locator('[name="confirm_pin"]').fill('123456');
    await page.getByRole('button', { name: 'Save unlock PIN' }).click();
    await expect(page).toHaveURL(/Dashboard\.php/);
  }
}

async function healthyPage(page, path) {
  const response = await visit(page, path);
  expect(response.status(), path).toBe(200);
  expect(await page.locator('body').innerText()).not.toMatch(/Fatal error:|Uncaught (?:PDOException|Error)|SQLSTATE\[/);
  await expect(page).toHaveURL(new RegExp(path.split('?')[0].replace('.', '\\.')));
}

for (const [role, dashboard] of [
  ['resident', 'ResidentDashboard.php'], ['nurse', 'NurseDashboard.php'],
  ['dental', 'DentalDashboard.php'], ['midwife', 'MidwifeDashboard.php'],
  ['medtech', 'MedTechDashboard.php'], ['sanitary', 'SanitaryDashboard.php'],
  ['secretary', 'RHUAdminDashboard.php'], ['admin', 'RHUAdminDashboard.php'],
]) {
  test(`${role}: real login and dashboard navigation`, async ({ page }) => {
    test.setTimeout(120000);
    await login(page, role);
    await healthyPage(page, dashboard);
    if (role === 'resident') {
      const tabs = await page.locator('[data-tab-button]').evaluateAll(items => [...new Set(items.map(item => item.dataset.tabButton))]);
      expect(tabs.length).toBeGreaterThan(0);
      for (const tab of tabs) {
        await page.locator(`[data-tab-button="${tab}"]`).first().click();
        await expect(page.locator(`[data-tab-panel="${tab}"]`)).toBeVisible();
      }
      return;
    }
    const links = await page.locator('a[href*="?tab="]').evaluateAll((items, dashboard) => [...new Set(items.map(item => item.getAttribute('href')).filter(href => href.startsWith(dashboard + '?') || href.startsWith('?tab=')).map(href => href.startsWith('?') ? dashboard + href : href))], dashboard);
    expect(links.length, 'Dashboard should expose navigation').toBeGreaterThan(0);
    for (const link of links) await healthyPage(page, link);
  });
}

test('appointment: resident books, nurse completes, resident and admin see result', async ({ page }) => {
  test.setTimeout(120000);
  await login(page, 'resident');
  await visit(page, 'ResidentDashboard.php?tab=records');
  await page.locator('[data-appointment-open]').first().click();
  const modal = page.locator('#appointment-modal');
  await expect(modal).toBeVisible();
  await modal.locator('[name="appointment_type"]').selectOption('General Health Checkup');
  const date = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
  await modal.locator('[name="preferred_date"]').fill(date);
  await modal.locator('[name="preferred_time"]').fill('09:00');
  await modal.locator('[name="chief_complaint"]').fill('Synthetic workflow appointment');
  await modal.locator('[name="health_worker_id"][value="2"]').check();
  await modal.getByRole('button', { name: 'Confirm & Book Appointment' }).click();
  await expect(modal).toBeHidden();
  const state = await (await page.request.get('__test/state', { headers })).json();
  const appointment = state.consultations.find(row => row.chief_complaint.includes('Synthetic workflow appointment'));
  expect(appointment).toBeTruthy();
  expect(Number(appointment.resident_id)).toBe(1);
  expect(appointment.consultation_status).toBe('Scheduled');
  await visit(page, 'ResidentDashboard.php?logout=1');
  await expect(page).toHaveURL(/ResidentLogin\.php/);
  await visit(page, 'ResidentDashboard.php');
  await expect(page).toHaveURL(/ResidentLogin\.php/);
  await login(page, 'nurse');
  await visit(page, 'NurseDashboard.php?tab=opd');
  const record = page.locator(`#consult-record-${appointment.id}`);
  await expect(record).toBeVisible();
  await record.locator('[name="diagnosis"]').fill('Synthetic assessment for browser test');
  await record.locator('[name="consultation_status"]').selectOption('Completed');
  await record.locator('[name="consultation_notes"]').fill('Synthetic consultation completed');
  await record.locator('[name="medications_prescribed"]').fill('Test notes only');
  await record.locator('button[type="submit"]').click();
  await expect.poll(async () => {
    const saved = await (await page.request.get('__test/state', { headers })).json();
    return saved.consultations.find(row => row.id === appointment.id)?.consultation_status;
  }).toBe('Completed');
  await visit(page, 'StaffLogout.php');
  await login(page, 'resident');
  await visit(page, 'ResidentDashboard.php?tab=records');
  await expect(page.locator('body')).toContainText('Synthetic assessment for browser test');
  await visit(page, 'ResidentDashboard.php?logout=1');
  await login(page, 'admin');
  await visit(page, 'RHUAdminDashboard.php?tab=consultations');
  await expect(page.locator('body')).toContainText('Synthetic assessment for browser test');
  await expect(page.locator('body')).toContainText('Completed');
});

test('admin: publish an announcement and verify it on the public landing page', async ({ page }) => {
  await login(page, 'admin');
  await visit(page, 'RHUAdminDashboard.php?tab=announcements');
  const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="create_announcement"]') });
  await form.locator('[name="title"]').fill('Synthetic browser test announcement');
  await form.locator('[name="content"]').fill('Only test data in an isolated database.');
  await form.getByRole('button', { name: 'Publish Announcement' }).click();
  await visit(page, 'LandingPage.php');
  await expect(page.locator('#awareness-landing-overlay')).toContainText('Synthetic browser test announcement');
});

test('staff: logout revokes dashboard access', async ({ page }) => {
  await login(page, 'nurse');
  await visit(page, 'StaffLogout.php');
  await visit(page, 'NurseDashboard.php');
  await expect(page).toHaveURL(/RHULogin\.php/);
});

test('resident: register a new account and sign in with it', async ({ page }) => {
  await visit(page, 'ResidentRegistration.php');
  for (const [name, value] of Object.entries({ first_name: 'Synthetic', last_name: 'Newaccount', email: 'newaccount@example.invalid', dob: '1998-02-03', address: 'Synthetic address', password, confirm_password: password })) {
    await page.locator(`[name="${name}"]`).fill(value);
  }
  await page.locator('[name="barangay"]').selectOption({ label: 'Test Barangay' });
  await page.locator('[name="sex"]').selectOption('Female');
  await page.locator('form button[type="submit"]').click();
  await expect(page).toHaveURL(/ResidentLogin\.php/);
  await page.locator('[name="email"]').fill('newaccount@example.invalid');
  await page.locator('[name="password"]').fill(password);
  await page.locator('form button[type="submit"]').click();
  await expect(page).toHaveURL(/ResidentDashboard\.php/);
  await expect(page.locator('body')).toContainText('Synthetic');
});

test('inventory: admin adds stock, nurse records usage, admin exports history', async ({ page }) => {
  test.setTimeout(120000);
  await login(page, 'admin');
  await visit(page, 'InventoryManagement.php');
  await page.locator('#inventory-tab-add').click();
  const add = page.locator('#inventory-panel-add form');
  for (const [name, value] of Object.entries({ item_name: 'Synthetic test supply', quantity: '10', location: 'Test storage', reason: 'Synthetic receipt' })) {
    await add.locator(`[name="${name}"]`).fill(value);
  }
  await add.getByRole('button', { name: 'Add item and stock' }).click();
  let state = await (await page.request.get('__test/state', { headers })).json();
  const item = state.inventory.find(row => row.item_name === 'Synthetic test supply');
  expect(Number(item.quantity)).toBe(10);
  await visit(page, 'StaffLogout.php');
  await login(page, 'nurse');
  await visit(page, 'InventoryManagement.php');
  await page.locator('#inventory-tab-movement').click();
  const movement = page.locator('#inventory-panel-movement form');
  await movement.locator('[name="item_id"]').selectOption(String(item.id));
  await movement.locator('[name="quantity"]').fill('2');
  await movement.locator('[name="location"]').fill('Synthetic clinic');
  await movement.locator('[name="reason"]').fill('Synthetic usage');
  await movement.getByRole('button', { name: 'Save movement' }).click();
  state = await (await page.request.get('__test/state', { headers })).json();
  expect(Number(state.inventory.find(row => row.id === item.id).quantity)).toBe(8);
  await visit(page, 'StaffLogout.php');
  await login(page, 'admin');
  await visit(page, 'InventoryManagement.php');
  await page.locator('#inventory-tab-history').click();
  const downloadEvent = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download history CSV' }).click();
  const download = await downloadEvent;
  expect(await download.failure()).toBeNull();
  expect(download.suggestedFilename()).toMatch(/\.csv$/);
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  const csv = Buffer.concat(chunks).toString('utf8');
  expect(csv).toContain('Synthetic test supply');
  expect(csv).toContain('Synthetic usage');
});

test('account linking: resident requests access, admin verifies, resident signs in', async ({ page }) => {
  test.setTimeout(120000);
  await visit(page, 'ResidentRegistration.php');
  for (const [name, value] of Object.entries({ first_name: 'Existing', last_name: 'Testpatient', email: 'linked@example.invalid', dob: '1990-03-04', address: 'Synthetic existing patient', password, confirm_password: password })) {
    await page.locator(`[name="${name}"]`).fill(value);
  }
  await page.locator('[name="barangay"]').selectOption({ label: 'Test Barangay' });
  await page.locator('form button[type="submit"]').click();
  await expect(page).toHaveURL(/ResidentLogin\.php/);
  await expect(page.locator('body')).toContainText('account-link request');
  await login(page, 'admin');
  await visit(page, 'ResidentAccountRequests.php');
  const request = page.locator('article').filter({ hasText: 'linked@example.invalid' });
  await request.locator('[name="resident_id"]').selectOption('2');
  await request.locator('[name="verification_note"]').fill('Synthetic identity verification performed by automated test');
  await request.getByRole('button', { name: 'Approve and link existing record' }).click();
  await expect(page.locator('body')).toContainText('Account linked to the existing patient ID');
  await visit(page, 'StaffLogout.php');
  await visit(page, 'ResidentLogin.php');
  await page.locator('[name="email"]').fill('linked@example.invalid');
  await page.locator('[name="password"]').fill(password);
  await page.locator('form button[type="submit"]').click();
  await expect(page).toHaveURL(/ResidentDashboard\.php/);
  await expect(page.locator('body')).toContainText('Existing');
});
