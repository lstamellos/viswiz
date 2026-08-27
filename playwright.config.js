const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: process.env.CI ? 1 : undefined,
  retries: process.env.CI ? 1 : 0,
  timeout: 30000,
  expect: {
    timeout: 7000,
  },
  reporter: process.env.CI
    ? [['line'], ['html', { open: 'never' }]]
    : [['list']],
  use: {
    baseURL: process.env.VISWIZ_E2E_BASE_URL || 'http://127.0.0.1:8889',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
});
