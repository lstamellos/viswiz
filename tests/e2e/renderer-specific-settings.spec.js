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
}

function setting(page, key) {
  return page.locator(`[name="viswiz_settings[${key}]"]`);
}

async function expectSettingVisible(page, key, visible = true) {
  const locator = setting(page, key);
  await expect(locator).toHaveCount(1);
  if (visible) await expect(locator).toBeVisible();
  else await expect(locator).toBeHidden();
}

async function expectSectionVisible(page, key) {
  await expect(page.locator(`[data-viswiz-settings-section="${key}"]`)).toBeVisible();
}

test('visualization editor exposes only settings relevant to the selected renderer', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  await createFromDataset(page, fixture.rowDatasetId, 'pie');

  const config = page.locator('[data-viswiz-visualization-config]');
  await expect(config).toHaveAttribute('data-viswiz-renderer-settings-grouped', '1');
  for (const group of ['data', 'appearance', 'labels', 'interaction', 'advanced']) {
    await expectSectionVisible(page, group);
  }

  await expectSettingVisible(page, 'show_legend');
  await expectSettingVisible(page, 'target', false);
  await expectSettingVisible(page, 'show_graph_toolbar', false);
  await expectSettingVisible(page, 'node_modal_related_heading', false);
  await expectSettingVisible(page, 'full_screen');
  await expectSettingVisible(page, 'primary_color');
  await expectSettingVisible(page, 'refresh_ms', false);

  const source = config.locator('[data-viswiz-source]');
  const wooOption = source.locator('option[value="woo_live"]');
  const wooState = await page.evaluate(() => ({
    available: window.VisWizRendererSettings?.wooAvailable === true,
    pieSupportsWoo: window.VisWizAdminV2?.renderers?.pie?.woo_live === true,
  }));
  expect(wooState.pieSupportsWoo).toBe(true);
  expect(await wooOption.evaluate((option) => option.disabled)).toBe(!wooState.available);
  if (wooState.available) {
    await source.selectOption('woo_live');
    await expectSettingVisible(page, 'refresh_ms');
    await source.selectOption('dataset');
    await expectSettingVisible(page, 'refresh_ms', false);
  }

  const renderer = config.locator('[data-viswiz-renderer]');
  await renderer.selectOption('bar');
  await expectSettingVisible(page, 'show_legend', false);
  await expectSettingVisible(page, 'target', false);
  await expectSettingVisible(page, 'show_graph_toolbar', false);
  await expectSettingVisible(page, 'full_screen');

  await renderer.selectOption('progress');
  await expectSettingVisible(page, 'target');
  await expectSettingVisible(page, 'show_legend', false);
  await expectSettingVisible(page, 'show_graph_toolbar', false);

  expect(clientErrors).toEqual([]);
});

test('graph settings stay graph-specific and continue driving the real live preview', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  await createFromDataset(page, fixture.graphDatasetId, 'graph');

  const config = page.locator('[data-viswiz-visualization-config]');
  const canvas = page.locator('[data-viswiz-preview-canvas]');
  await expect(config).toHaveAttribute('data-viswiz-renderer-settings-grouped', '1');
  await expect(page.locator('[data-viswiz-preview-status]')).toContainText('Preview updated');
  await expect(canvas.locator('.viswiz-graph-frame')).toBeVisible();

  await expectSettingVisible(page, 'show_graph_toolbar');
  await expectSettingVisible(page, 'show_graph_search');
  await expectSettingVisible(page, 'show_graph_filters');
  await expectSettingVisible(page, 'show_graph_zoom');
  await expectSettingVisible(page, 'show_node_images');
  await expectSettingVisible(page, 'show_type_badges');
  await expectSettingVisible(page, 'show_relation_labels');
  await expectSettingVisible(page, 'node_modal_related_heading');
  await expectSettingVisible(page, 'show_legend', false);
  await expectSettingVisible(page, 'target', false);
  await expectSettingVisible(page, 'refresh_ms', false);

  const wooOption = config.locator('[data-viswiz-source] option[value="woo_live"]');
  expect(await wooOption.evaluate((option) => option.disabled)).toBe(true);

  const searchSetting = setting(page, 'show_graph_search');
  await expect(searchSetting).toBeChecked();
  await expect(canvas.locator('.viswiz-graph-toolbar input[type="search"]')).toBeVisible();
  await searchSetting.uncheck();
  await expect(page.locator('[data-viswiz-preview-status]')).toContainText('Preview updated');
  await expect(canvas.locator('.viswiz-graph-toolbar input[type="search"]')).toHaveCount(0);
  expect(await page.evaluate(() => Boolean(window.VisWiz?.__graphRuntimeBridged))).toBe(true);

  expect(clientErrors).toEqual([]);
});
