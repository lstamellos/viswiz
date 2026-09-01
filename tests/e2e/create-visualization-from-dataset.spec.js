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

async function rendererValues(page) {
  const card = page.locator('[data-viswiz-create-visualization]');
  await expect(card).toBeVisible();
  return card.locator('select[name="renderer"] option').evaluateAll((options) => options.map((option) => option.value));
}

test('dataset detail creates a preconnected visualization using only compatible renderers', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);

  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.rowDatasetId}`);
  expect(await rendererValues(page)).toEqual([
    'pie',
    'bar',
    'column',
    'line',
    'area',
    'progress',
    'counter',
  ]);

  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.graphDatasetId}`);
  expect(await rendererValues(page)).toEqual([
    'graph',
    'flow_diagram',
    'org_chart',
  ]);

  const createCard = page.locator('[data-viswiz-create-visualization]');
  const renderer = createCard.locator('select[name="renderer"]');
  await renderer.selectOption('flow_diagram');

  await Promise.all([
    page.waitForURL(/\/wp-admin\/post\.php\?post=\d+&action=edit&viswiz_created_from_dataset=1/),
    createCard.getByRole('button', { name: 'Create visualization' }).click(),
  ]);

  await expect(page.locator('#title')).toHaveValue('E2E graph dataset — Flow diagram');
  const config = page.locator('[data-viswiz-visualization-config]');
  await expect(config).toBeVisible();
  await expect(config.locator('[data-viswiz-renderer]')).toHaveValue('flow_diagram');
  await expect(config.locator('[data-viswiz-source]')).toHaveValue('dataset');
  await expect(config.locator('[data-viswiz-dataset-select]')).toHaveValue(String(fixture.graphDatasetId));
  await expect(config.locator('[data-viswiz-dataset-select] option:checked')).toContainText('E2E graph dataset');
  await expect(config.getByRole('link', { name: 'Open dataset editor' })).toHaveAttribute(
    'href',
    new RegExp(`admin\\.php\\?page=viswiz-datasets&dataset_id=${fixture.graphDatasetId}$`)
  );

  const createdPostId = new URL(page.url()).searchParams.get('post');
  expect(Number(createdPostId)).toBeGreaterThan(0);

  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#save-post').click(),
  ]);
  await expect(page).toHaveURL(new RegExp(`/wp-admin/post\\.php\\?post=${createdPostId}&action=edit`));
  await expect(page.locator('#title')).toHaveValue('E2E graph dataset — Flow diagram');
  await expect(page.locator('[data-viswiz-renderer]')).toHaveValue('flow_diagram');
  await expect(page.locator('[data-viswiz-source]')).toHaveValue('dataset');
  await expect(page.locator('[data-viswiz-dataset-select]')).toHaveValue(String(fixture.graphDatasetId));

  expect(clientErrors).toEqual([]);
});
