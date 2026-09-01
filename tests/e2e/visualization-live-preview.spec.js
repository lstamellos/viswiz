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

async function createFromDataset(page, datasetId, renderer) {
  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${datasetId}`);
  const card = page.locator('[data-viswiz-create-visualization]');
  await expect(card).toBeVisible();
  await card.locator('select[name="renderer"]').selectOption(renderer);
  await Promise.all([
    page.waitForURL(/\/wp-admin\/post\.php\?post=\d+&action=edit&viswiz_created_from_dataset=1/),
    card.getByRole('button', { name: 'Create visualization' }).click(),
  ]);
  return new URL(page.url()).searchParams.get('post');
}

async function savedSpec(page, postId) {
  return page.evaluate(async (id) => {
    const response = await fetch(`/wp-json/viswiz/v2/visualizations/${id}`, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': window.VisWizAdminV2?.nonce || '' },
    });
    return response.json();
  }, postId);
}

async function waitForPreview(page) {
  const status = page.locator('[data-viswiz-preview-status]');
  await expect(status).toContainText('Preview updated');
  return page.locator('[data-viswiz-preview-canvas]');
}

test('visualization editor renders unsaved settings with the public runtime and preserves them on save', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);

  const postId = await createFromDataset(page, fixture.rowDatasetId, 'pie');
  const preview = page.locator('[data-viswiz-live-preview]');
  await expect(preview).toBeVisible();
  await expect(preview).toContainText('Unsaved preview');

  const canvas = await waitForPreview(page);
  await expect(canvas.locator('svg path')).toHaveCount(2);
  await expect(canvas.locator('.viswiz-legend')).toBeVisible();

  const original = await savedSpec(page, postId);
  expect(original.renderer).toBe('pie');
  expect(original.settings.primary_color).toBe('#2563eb');
  expect(original.settings.show_legend).toBe(true);

  const config = page.locator('[data-viswiz-visualization-config]');
  await config.locator('[data-viswiz-renderer]').selectOption('bar');
  await config.locator('input[name="viswiz_settings[primary_color]"]').evaluate((input) => {
    input.value = '#ff0000';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await expect(page.locator('[data-viswiz-preview-status]')).toContainText('Preview updated');
  await expect(canvas.locator('svg rect')).toHaveCount(2);
  await expect(canvas).toHaveClass(/is-bar/);
  await expect(canvas).not.toHaveClass(/is-pie/);
  expect(await canvas.evaluate((element) => getComputedStyle(element).getPropertyValue('--viswiz-primary').trim())).toBe('#ff0000');

  const stillSaved = await savedSpec(page, postId);
  expect(stillSaved.renderer).toBe('pie');
  expect(stillSaved.settings.primary_color).toBe('#2563eb');

  await config.locator('[data-viswiz-renderer]').selectOption('pie');
  const legendSetting = config.locator('input[name="viswiz_settings[show_legend]"]');
  await legendSetting.uncheck();
  await expect(page.locator('[data-viswiz-preview-status]')).toContainText('Preview updated');
  await expect(canvas.locator('.viswiz-legend')).toHaveCount(0);

  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#save-post').click(),
  ]);
  await expect(page).toHaveURL(new RegExp(`/wp-admin/post\\.php\\?post=${postId}&action=edit`));
  await expect(page.locator('input[name="viswiz_settings[show_legend]"]')).not.toBeChecked();
  const saved = await savedSpec(page, postId);
  expect(saved.renderer).toBe('pie');
  expect(saved.settings.primary_color).toBe('#ff0000');
  expect(saved.settings.show_legend).toBe(false);
  await waitForPreview(page);
  await expect(page.locator('[data-viswiz-preview-canvas] .viswiz-legend')).toHaveCount(0);

  const graphPostId = await createFromDataset(page, fixture.graphDatasetId, 'graph');
  expect(Number(graphPostId)).toBeGreaterThan(0);
  const graphCanvas = await waitForPreview(page);
  await expect(graphCanvas.locator('.viswiz-graph-frame')).toBeVisible();
  await expect(graphCanvas.locator('.viswiz-graph-node')).toHaveCount(fixture.counts.nodes);
  expect(await page.evaluate(() => Boolean(window.VisWiz?.__graphRuntimeBridged))).toBe(true);

  expect(clientErrors).toEqual([]);
});
