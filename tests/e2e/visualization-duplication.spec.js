const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const fixturePath = process.env.VISWIZ_E2E_FIXTURE || '/tmp/viswiz-e2e-fixture.json';
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const adminUser = process.env.VISWIZ_E2E_ADMIN_USER || 'admin';
const adminPassword = process.env.VISWIZ_E2E_ADMIN_PASSWORD || 'admin';

async function login(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(adminUser);
  await page.locator('#user_pass').fill(adminPassword);
  await Promise.all([
    page.waitForURL(/\/wp-admin\//),
    page.locator('#wp-submit').click(),
  ]);
}

function captureClientErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
  return errors;
}

async function createVisualization(page) {
  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.rowDatasetId}`);
  const card = page.locator('[data-viswiz-create-visualization]');
  await expect(card).toBeVisible();
  await card.locator('select[name="renderer"]').selectOption('bar');
  await Promise.all([
    page.waitForURL(/\/wp-admin\/post\.php\?post=\d+&action=edit&viswiz_created_from_dataset=1/),
    card.getByRole('button', { name: 'Create visualization' }).click(),
  ]);
  return Number(new URL(page.url()).searchParams.get('post'));
}

test('visualization duplication creates a draft that reuses the same dataset and settings', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);

  const sourceId = await createVisualization(page);
  expect(sourceId).toBeGreaterThan(0);

  const sourceTitle = await page.locator('#title').inputValue();
  const config = page.locator('[data-viswiz-visualization-config]');
  await expect(config.locator('[data-viswiz-renderer]')).toHaveValue('bar');
  await expect(config.locator('[data-viswiz-source]')).toHaveValue('dataset');
  await expect(config.locator('[data-viswiz-dataset-select]')).toHaveValue(String(fixture.rowDatasetId));

  const primaryColor = config.locator('input[name="viswiz_settings[primary_color]"]');
  await primaryColor.fill('#123456');

  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#save-post').click(),
  ]);
  await expect(page).toHaveURL(new RegExp(`/wp-admin/post\\.php\\?post=${sourceId}&action=edit`));
  await expect(primaryColor).toHaveValue('#123456');

  const duplicateAction = page.locator('[data-viswiz-duplicate-visualization]');
  await expect(duplicateAction).toBeVisible();
  await Promise.all([
    page.waitForURL(new RegExp(`/wp-admin/post\\.php\\?post=\\d+&action=edit&viswiz_duplicated_from=${sourceId}`)),
    duplicateAction.click(),
  ]);

  const duplicateId = Number(new URL(page.url()).searchParams.get('post'));
  expect(duplicateId).toBeGreaterThan(0);
  expect(duplicateId).not.toBe(sourceId);
  await expect(page.locator('#title')).toHaveValue(`${sourceTitle} — Copy`);
  await expect(page.locator('#post_status')).toHaveValue('draft');

  const duplicateConfig = page.locator('[data-viswiz-visualization-config]');
  await expect(duplicateConfig.locator('[data-viswiz-renderer]')).toHaveValue('bar');
  await expect(duplicateConfig.locator('[data-viswiz-source]')).toHaveValue('dataset');
  await expect(duplicateConfig.locator('[data-viswiz-dataset-select]')).toHaveValue(String(fixture.rowDatasetId));
  await expect(duplicateConfig.locator('input[name="viswiz_settings[primary_color]"]')).toHaveValue('#123456');

  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#save-post').click(),
  ]);
  await expect(page).toHaveURL(new RegExp(`/wp-admin/post\\.php\\?post=${duplicateId}&action=edit`));
  await expect(duplicateConfig.locator('[data-viswiz-dataset-select]')).toHaveValue(String(fixture.rowDatasetId));
  await expect(duplicateConfig.locator('input[name="viswiz_settings[primary_color]"]')).toHaveValue('#123456');

  await page.goto(`/wp-admin/post.php?post=${sourceId}&action=edit`);
  await expect(page.locator('#title')).toHaveValue(sourceTitle);
  await expect(page.locator('[data-viswiz-dataset-select]')).toHaveValue(String(fixture.rowDatasetId));
  await expect(page.locator('input[name="viswiz_settings[primary_color]"]')).toHaveValue('#123456');

  await page.goto('/wp-admin/edit.php?post_type=viswiz_visualization');
  await expect(page.locator(`#post-${sourceId} .row-actions`).getByRole('link', { name: 'Duplicate' })).toHaveCount(1);
  await expect(page.locator(`#post-${duplicateId} .row-actions`).getByRole('link', { name: 'Duplicate' })).toHaveCount(1);

  expect(clientErrors).toEqual([]);
});
