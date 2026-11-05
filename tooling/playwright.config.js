import { defineConfig, devices } from '@playwright/test';
import { fileURLToPath } from 'node:url';

process.env.PLAYWRIGHT_BROWSERS_PATH ||= fileURLToPath(new URL('../scratch/playwright-browsers', import.meta.url));
// Workers inherit the runner's ID; separate runners must not clean each other's traces.
process.env.RHU_PLAYWRIGHT_RUN_ID ||= `${Date.now()}-${process.pid}`;

export default defineConfig({
  testDir: '../tests/e2e',
  outputDir: `test-results/run-${process.env.RHU_PLAYWRIGHT_RUN_ID}`,
  workers: 2,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost/htdocs/',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
