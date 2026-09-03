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

async function createPieFromDataset(page) {
  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.rowDatasetId}`);
  const card = page.locator('[data-viswiz-create-visualization]');
  await expect(card).toBeVisible();
  await card.locator('select[name="renderer"]').selectOption('pie');
  await Promise.all([
    page.waitForURL(/\/wp-admin\/post\.php\?post=\d+&action=edit&viswiz_created_from_dataset=1/),
    card.getByRole('button', { name: 'Create visualization' }).click(),
  ]);
}

test('Woo source filters use picker UI and preserve graceful inactive-Woo behavior', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  await createPieFromDataset(page);

  expect(await page.evaluate(() => window.VisWizWooSourceSelection?.available === true)).toBe(false);

  const source = page.locator('[data-viswiz-source]');
  await expect(source.locator('option[value="woo_live"]')).toHaveText('WooCommerce live query');
  expect(await source.locator('option[value="woo_live"]').evaluate((option) => option.disabled)).toBe(true);

  const productCanonical = page.locator('[data-viswiz-woo="product_ids"]');
  const categoryCanonical = page.locator('[data-viswiz-woo="category_ids"]');
  await expect(productCanonical).toHaveAttribute('type', 'hidden');
  await expect(categoryCanonical).toHaveAttribute('type', 'hidden');

  const productPicker = page.locator('[data-viswiz-woo-picker="product_ids"]');
  const categoryPicker = page.locator('[data-viswiz-woo-picker="category_ids"]');
  await expect(productPicker).toHaveCount(1);
  await expect(categoryPicker).toHaveCount(1);
  await expect(productPicker).toHaveClass(/wc-product-search/);
  await expect(categoryPicker).toHaveClass(/wc-category-search/);
  await expect(productPicker).toBeDisabled();
  await expect(categoryPicker).toBeDisabled();
  await expect(productPicker.locator('xpath=ancestor::label[1]')).toContainText('Products');
  await expect(categoryPicker.locator('xpath=ancestor::label[1]')).toContainText('Categories');

  const livePanel = page.locator('[data-viswiz-source-panel="woo_live"]');
  await expect(livePanel.locator('[data-viswiz-woo-note="live"]')).toContainText('WooCommerce is not active');

  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.rowDatasetId}`);
  const snapshotButton = page.locator('[data-viswiz-commerce-snapshot]');
  await expect(snapshotButton).toBeDisabled();
  await expect(snapshotButton).toHaveText('Replace dataset with current snapshot');
  const snapshot = snapshotButton.locator('xpath=ancestor::section[1]');
  await expect(snapshot.locator('[data-viswiz-woo-note="snapshot"]')).toContainText('do not stay synchronized with WooCommerce');
  await expect(snapshot.locator('[data-viswiz-woo-note="unavailable"]')).toContainText('WooCommerce is not active');

  expect(clientErrors).toEqual([]);
});
