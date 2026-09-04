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

async function createBarVisualization(page) {
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

async function setColor(locator, value) {
  await locator.evaluate((field, next) => {
    field.value = next;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }, value);
}

async function savedSpec(page, postId) {
  return page.evaluate(async (id) => {
    const cfg = window.VisWizAdminV2;
    const response = await fetch(`${cfg.restUrl}/visualizations/${id}`, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce || '' },
    });
    return { status: response.status, body: await response.json() };
  }, postId);
}

test('personal display presets apply as unsaved renderer-compatible form changes', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);

  const postId = await createBarVisualization(page);
  expect(postId).toBeGreaterThan(0);

  const config = page.locator('[data-viswiz-visualization-config]');
  const renderer = config.locator('[data-viswiz-renderer]');
  const source = config.locator('[data-viswiz-source]');
  const dataset = config.locator('[data-viswiz-dataset-select]');
  const primary = config.locator('input[name="viswiz_settings[primary_color]"]');
  const fullScreen = config.locator('input[name="viswiz_settings[full_screen]"]');
  const legend = config.locator('input[name="viswiz_settings[show_legend]"]');

  await expect(renderer).toHaveValue('bar');
  await expect(source).toHaveValue('dataset');
  await expect(dataset).toHaveValue(String(fixture.rowDatasetId));

  const initialSaved = await savedSpec(page, postId);
  expect(initialSaved.status).toBe(200);
  expect(initialSaved.body.renderer).toBe('bar');
  expect(initialSaved.body.settings.primary_color).toBe('#2563eb');
  expect(initialSaved.body.settings.full_screen).toBe(true);

  await setColor(primary, '#123456');
  await fullScreen.uncheck();

  const presets = page.locator('[data-viswiz-display-presets]');
  await expect(presets).toBeVisible();
  await presets.locator('[data-viswiz-preset-name]').fill('E2E display preset');
  await presets.getByRole('button', { name: 'Save current display as preset' }).click();
  await expect(presets.locator('[data-viswiz-preset-status]')).toContainText('Display preset saved.');
  await expect(presets.locator('[data-viswiz-preset-select] option:checked')).toContainText('E2E display preset');

  const afterPresetSave = await savedSpec(page, postId);
  expect(afterPresetSave.status).toBe(200);
  expect(afterPresetSave.body.renderer).toBe('bar');
  expect(afterPresetSave.body.settings.primary_color).toBe('#2563eb');
  expect(afterPresetSave.body.settings.full_screen).toBe(true);

  await renderer.selectOption('pie');
  await expect(renderer).toHaveValue('pie');
  await expect(source).toHaveValue('dataset');
  await expect(dataset).toHaveValue(String(fixture.rowDatasetId));
  await expect(legend.locator('xpath=ancestor::label[1]')).toBeVisible();

  await setColor(primary, '#abcdef');
  await fullScreen.check();
  await legend.uncheck();

  const previewResponse = page.waitForResponse((response) => {
    if (!response.url().includes('/wp-json/viswiz/v2/visualizations/preview') || response.request().method() !== 'POST' || !response.ok()) return false;
    const body = response.request().postData() || '';
    return body.includes('"renderer":"pie"') && body.includes('"primary_color":"#123456"');
  });
  await presets.getByRole('button', { name: 'Apply' }).click();
  await previewResponse;

  await expect(primary).toHaveValue('#123456');
  await expect(fullScreen).not.toBeChecked();
  await expect(legend).not.toBeChecked();
  await expect(renderer).toHaveValue('pie');
  await expect(source).toHaveValue('dataset');
  await expect(dataset).toHaveValue(String(fixture.rowDatasetId));
  await expect(presets.locator('[data-viswiz-preset-status]')).toContainText('Preset applied to unsaved display settings.');

  const beforeVisualizationSave = await savedSpec(page, postId);
  expect(beforeVisualizationSave.status).toBe(200);
  expect(beforeVisualizationSave.body.renderer).toBe('bar');
  expect(beforeVisualizationSave.body.settings.primary_color).toBe('#2563eb');
  expect(beforeVisualizationSave.body.settings.full_screen).toBe(true);

  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#save-post').click(),
  ]);
  await expect(page).toHaveURL(new RegExp(`/wp-admin/post\\.php\\?post=${postId}&action=edit`));
  await expect(page.locator('[data-viswiz-renderer]')).toHaveValue('pie');
  await expect(page.locator('[data-viswiz-source]')).toHaveValue('dataset');
  await expect(page.locator('[data-viswiz-dataset-select]')).toHaveValue(String(fixture.rowDatasetId));
  await expect(page.locator('input[name="viswiz_settings[primary_color]"]')).toHaveValue('#123456');
  await expect(page.locator('input[name="viswiz_settings[full_screen]"]')).not.toBeChecked();
  await expect(page.locator('input[name="viswiz_settings[show_legend]"]')).not.toBeChecked();

  const afterVisualizationSave = await savedSpec(page, postId);
  expect(afterVisualizationSave.status).toBe(200);
  expect(afterVisualizationSave.body.renderer).toBe('pie');
  expect(afterVisualizationSave.body.source_type).toBe('dataset');
  expect(afterVisualizationSave.body.meta.dataset_id).toBe(fixture.rowDatasetId);
  expect(afterVisualizationSave.body.settings.primary_color).toBe('#123456');
  expect(afterVisualizationSave.body.settings.full_screen).toBe(false);
  expect(afterVisualizationSave.body.settings.show_legend).toBe(false);

  const reloadedPresets = page.locator('[data-viswiz-display-presets]');
  const presetSelect = reloadedPresets.locator('[data-viswiz-preset-select]');
  const presetValue = await presetSelect.locator('option').filter({ hasText: 'E2E display preset' }).getAttribute('value');
  expect(presetValue).toBeTruthy();
  await presetSelect.selectOption(presetValue);
  page.once('dialog', (dialog) => dialog.accept());
  await reloadedPresets.getByRole('button', { name: 'Delete' }).click();
  await expect(reloadedPresets.locator('[data-viswiz-preset-status]')).toContainText('Display preset deleted.');
  await expect(presetSelect.locator('option')).toHaveCount(1);

  expect(clientErrors).toEqual([]);
});
