const { test, expect } = require('@playwright/test');

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

async function createDataset(page, name, schema) {
  await page.goto('/wp-admin/admin.php?page=viswiz-datasets');
  const card = page.locator('.viswiz-card').filter({ has: page.getByRole('heading', { name: 'Create dataset' }) });
  await card.locator('input[name="name"]').fill(name);
  await card.locator('select[name="schema_type"]').selectOption(schema);
  await Promise.all([
    page.waitForURL(/page=viswiz-datasets&dataset_id=\d+/),
    card.getByRole('button', { name: 'Create dataset' }).click(),
  ]);
  await expect(page.locator('[data-viswiz-guided-import]')).toBeVisible();
}

async function preparePaste(page, text) {
  const importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-source]').fill(text);
  await importer.locator('[data-viswiz-import-prepare]').click();
  await expect(importer.locator('[data-viswiz-import-mapping]')).toBeVisible();
  return importer;
}

async function commitAndWaitForReload(page, commit) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    commit.click(),
  ]);
  await expect(page.locator('[data-viswiz-guided-import]')).toBeVisible();
}

async function validateAndCommit(page, importer, expectedAction) {
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('[data-viswiz-import-preview]')).toBeVisible();
  await expect(importer.locator('.viswiz-import-issues.notice-error')).toHaveCount(0);
  await expect(importer.locator('.viswiz-import-action').filter({ hasText: expectedAction })).toBeVisible();
  const commit = importer.locator('[data-viswiz-import-commit]');
  await expect(commit).toBeVisible();
  await commitAndWaitForReload(page, commit);
}

test('guided row import supports spreadsheet paste, preview, commit and keyed upsert', async ({ page }) => {
  await login(page);
  await createDataset(page, 'E2E import rows', 'categorical');

  let importer = await preparePaste(page, 'row_key\tlabel\tvalue\nimport-alpha\tImported Alpha\t42,5');
  await expect(importer.locator('[data-viswiz-import-map="row_key"]')).toHaveValue('row_key');
  await expect(importer.locator('[data-viswiz-import-map="label"]')).toHaveValue('label');
  await expect(importer.locator('[data-viswiz-import-map="value"]')).toHaveValue('value');
  await validateAndCommit(page, importer, 'create');

  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor.locator('tbody tr')).toHaveCount(1);
  await expect(editor.locator('tbody tr')).toContainText('Imported Alpha');
  await expect(editor.locator('tbody tr')).toContainText('42.5');

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-mode]').selectOption('upsert');
  await importer.locator('[data-viswiz-import-source]').fill('row_key\tlabel\tvalue\nimport-alpha\tImported Alpha Updated\t99');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-summary')).toContainText('1 Update');
  await expect(importer.locator('.viswiz-import-action').filter({ hasText: 'update' })).toBeVisible();
  await commitAndWaitForReload(page, importer.locator('[data-viswiz-import-commit]'));

  await expect(page.locator('#viswiz-dataset-editor tbody tr')).toHaveCount(1);
  await expect(page.locator('#viswiz-dataset-editor tbody tr')).toContainText('Imported Alpha Updated');
  await expect(page.locator('#viswiz-dataset-editor tbody tr')).toContainText('99');

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-source]').fill('row_key\tlabel\tvalue\nbad\tBad numeric\tnot-a-number');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-issues.notice-error')).toContainText('Expected a number');
  await expect(importer.locator('[data-viswiz-import-commit]')).not.toBeVisible();
});

test('guided graph import maps external keys to stable UUIDs across node upsert and relations', async ({ page }) => {
  await login(page);
  await createDataset(page, 'E2E import graph', 'graph');

  let importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-file]').setInputFiles({
    name: 'nodes.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('external_key,title,node_type\nreporter,Imported Reporter,person\nnewsroom,Imported Newsroom,organization', 'utf8'),
  });
  await expect(importer.locator('[data-viswiz-import-source]')).toHaveValue(/Imported Reporter/);
  await importer.locator('[data-viswiz-import-prepare]').click();
  await expect(importer.locator('[data-viswiz-import-map="external_key"]')).toHaveValue('external_key');
  await expect(importer.locator('[data-viswiz-import-map="title"]')).toHaveValue('title');
  await expect(importer.locator('[data-viswiz-import-map="node_type"]')).toHaveValue('node_type');
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-summary')).toContainText('2 Create');
  await commitAndWaitForReload(page, importer.locator('[data-viswiz-import-commit]'));

  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor.locator('table').nth(0).locator('tbody tr')).toHaveCount(2);
  await expect(editor.locator('table').nth(1).locator('tbody tr')).toHaveCount(0);

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-kind]').selectOption('relations');
  await importer.locator('[data-viswiz-import-source]').fill('external_key\tfrom_key\tto_key\trelation_type\nreporter-newsroom\treporter\tnewsroom\tmember_of');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await expect(importer.locator('[data-viswiz-import-map="from_key"]')).toHaveValue('from_key');
  await expect(importer.locator('[data-viswiz-import-map="to_key"]')).toHaveValue('to_key');
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-issues.notice-error')).toHaveCount(0);
  await expect(importer.locator('.viswiz-import-summary')).toContainText('1 Create');
  await commitAndWaitForReload(page, importer.locator('[data-viswiz-import-commit]'));
  await expect(page.locator('#viswiz-dataset-editor table').nth(1).locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('#viswiz-dataset-editor table').nth(1).locator('tbody tr')).toContainText('Imported Reporter');
  await expect(page.locator('#viswiz-dataset-editor table').nth(1).locator('tbody tr')).toContainText('Imported Newsroom');

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-kind]').selectOption('nodes');
  await importer.locator('[data-viswiz-import-mode]').selectOption('upsert');
  await importer.locator('[data-viswiz-import-source]').fill('external_key\ttitle\tnode_type\nreporter\tImported Reporter Updated\tperson');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-summary')).toContainText('1 Update');
  await commitAndWaitForReload(page, importer.locator('[data-viswiz-import-commit]'));

  await expect(page.locator('#viswiz-dataset-editor table').nth(0).locator('tbody tr')).toHaveCount(2);
  await expect(page.locator('#viswiz-dataset-editor table').nth(0).locator('tbody tr')).toContainText('Imported Reporter Updated');
  await expect(page.locator('#viswiz-dataset-editor table').nth(1).locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('#viswiz-dataset-editor table').nth(1).locator('tbody tr')).toContainText('Imported Reporter Updated');
});
