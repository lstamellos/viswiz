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

async function datasetPayload(page) {
  return page.evaluate(async () => {
    const editor = document.querySelector('#viswiz-dataset-editor');
    const id = Number(editor?.dataset.datasetId || 0);
    const response = await fetch(`${window.VisWizAdminV2.restUrl}/datasets/${id}`, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': window.VisWizAdminV2.nonce || '' },
    });
    if (!response.ok) throw new Error(`Dataset REST request failed: ${response.status}`);
    return response.json();
  });
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
  const row = editor.locator('tbody tr').first();
  await expect(editor.locator('tbody tr')).toHaveCount(1);
  await expect(row.locator('[data-field-path="label"]')).toHaveValue('Imported Alpha');
  await expect(row.locator('[data-field-path="value"]')).toHaveValue('42.5');

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-mode]').selectOption('upsert');
  await importer.locator('[data-viswiz-import-source]').fill('row_key\tlabel\tvalue\nimport-alpha\tImported Alpha Updated\t99');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-summary')).toContainText('1 Update');
  await expect(importer.locator('.viswiz-import-action').filter({ hasText: 'update' })).toBeVisible();
  await commitAndWaitForReload(page, importer.locator('[data-viswiz-import-commit]'));

  const updatedRow = page.locator('#viswiz-dataset-editor tbody tr').first();
  await expect(page.locator('#viswiz-dataset-editor tbody tr')).toHaveCount(1);
  await expect(updatedRow.locator('[data-field-path="label"]')).toHaveValue('Imported Alpha Updated');
  await expect(updatedRow.locator('[data-field-path="value"]')).toHaveValue('99');

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-mode]').selectOption('append');
  await importer.locator('[data-viswiz-import-source]').fill('row_key\tlabel\tvalue\nimport-alpha\tDuplicate Alpha\t100');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('[data-viswiz-import-message]')).toContainText('import key mapping contains conflicts');
  await expect(importer.locator('[data-viswiz-import-commit]')).not.toBeVisible();

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
  await expect(editor.locator('table').nth(0).locator('tbody tr[data-viswiz-item-uuid]')).toHaveCount(2);
  await expect(editor.locator('table').nth(1).locator('tbody tr[data-viswiz-item-uuid]')).toHaveCount(0);

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-kind]').selectOption('relations');
  await importer.locator('[data-viswiz-import-source]').fill('external_key\tfrom_key\tto_key\trelation_type\nreporter-newsroom\treporter\tnewsroom\tmember_of\nreporter-newsroom-linked\treporter\tnewsroom\tconnected_to');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await expect(importer.locator('[data-viswiz-import-map="from_key"]')).toHaveValue('from_key');
  await expect(importer.locator('[data-viswiz-import-map="to_key"]')).toHaveValue('to_key');
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-issues.notice-error')).toHaveCount(0);
  await expect(importer.locator('.viswiz-import-summary')).toContainText('2 Create');
  await commitAndWaitForReload(page, importer.locator('[data-viswiz-import-commit]'));
  await expect(page.locator('#viswiz-dataset-editor table').nth(1).locator('tbody tr[data-viswiz-item-uuid]')).toHaveCount(2);

  let payload = await datasetPayload(page);
  const member = payload.payload.relations.find((relation) => relation.meta?._viswiz_import_key === 'reporter-newsroom');
  const connected = payload.payload.relations.find((relation) => relation.meta?._viswiz_import_key === 'reporter-newsroom-linked');
  expect(member).toMatchObject({ relation_type: 'member_of', label: 'Member of', inverse_label: 'Has member', direction: 'directed', intensity: 1 });
  expect(connected).toMatchObject({ relation_type: 'connected_to', label: 'Connected to', inverse_label: 'Connected to', direction: 'undirected', intensity: 1 });

  importer = page.locator('[data-viswiz-guided-import]');
  await importer.locator('[data-viswiz-import-kind]').selectOption('nodes');
  await importer.locator('[data-viswiz-import-mode]').selectOption('upsert');
  await importer.locator('[data-viswiz-import-source]').fill('external_key\ttitle\tnode_type\nreporter\tImported Reporter Updated\tperson');
  await importer.locator('[data-viswiz-import-prepare]').click();
  await importer.locator('[data-viswiz-import-preview-button]').click();
  await expect(importer.locator('.viswiz-import-summary')).toContainText('1 Update');
  await commitAndWaitForReload(page, importer.locator('[data-viswiz-import-commit]'));

  const nodeRows = page.locator('#viswiz-dataset-editor table').nth(0).locator('tbody tr[data-viswiz-item-uuid]');
  await expect(nodeRows).toHaveCount(2);
  await expect(nodeRows.filter({ hasText: 'Imported Reporter Updated' })).toHaveCount(1);
  const relationRows = page.locator('#viswiz-dataset-editor table').nth(1).locator('tbody tr[data-viswiz-item-uuid]');
  await expect(relationRows).toHaveCount(2);
  await expect(relationRows.filter({ hasText: 'Imported Reporter Updated' })).toHaveCount(2);

  payload = await datasetPayload(page);
  expect(payload.payload.relations).toHaveLength(2);
});
