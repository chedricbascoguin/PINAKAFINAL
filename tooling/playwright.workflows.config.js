import { defineConfig, devices } from '@playwright/test';
import { fileURLToPath } from 'node:url';
if (!process.env.RHU_TEST_URL?.startsWith('http://127.0.0.1:') || !/^rhu_pw_\d{13}$/.test(process.env.RHU_TEST_DB || '')) {
  throw new Error('Use npm.cmd run test:workflows to create an isolated database first.');
}
process.env.PLAYWRIGHT_BROWSERS_PATH ||= fileURLToPath(new URL('../scratch/playwright-browsers', import.meta.url));
export default defineConfig({
  testDir: '../tests/workflows',
  workers: 1,
  timeout: 45000,
  outputDir: `test-results/${process.env.RHU_TEST_DB}`,
  reporter: [['list'], ['html', { outputFolder: `playwright-workflows-report/${process.env.RHU_TEST_DB}`, open: 'never' }]],
  use: { baseURL: process.env.RHU_TEST_URL, trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
