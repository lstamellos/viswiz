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
  await expect(page.locator('#viswiz-dataset-editor[data-viswiz-server-editor]')).toBeVisible();
  return Number(new URL(page.url()).searchParams.get('dataset_id'));
}

async function commitImport(page, datasetId, request) {
  const result = await page.evaluate(async ({ datasetId: id, request: body }) => {
    const cfg = window.VisWizAdminV2;
    const editor = document.querySelector('#viswiz-dataset-editor');
    body.expected_revision = Number(editor?.dataset.revision || 0);
    const response = await fetch(`${cfg.restUrl}/datasets/${id}/import`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: JSON.stringify(body),
    });
    return { ok: response.ok, status: response.status, data: await response.json() };
  }, { datasetId, request });
  expect(result.ok, JSON.stringify(result.data)).toBeTruthy();
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('#viswiz-dataset-editor[data-viswiz-server-editor]')).toBeVisible();
}

test('row editor pages and searches on the server without embedding the full payload', async ({ page }) => {
  await login(page);
  const datasetId = await createDataset(page, 'E2E server rows', 'categorical');
  const records = Array.from({ length: 230 }, (_, index) => ({
    key: `large-row-${String(index + 1).padStart(3, '0')}`,
    label: `Large Row ${String(index + 1).padStart(3, '0')}`,
    value: String(index + 1),
  }));
  await commitImport(page, datasetId, {
    kind: 'rows',
    mode: 'append',
    mapping: { row_key: 'key', label: 'label', value: 'value' },
    records,
  });

  await expect(page.locator('#viswiz-dataset-payload')).toHaveCount(0);
  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor.locator('tbody tr')).toHaveCount(100);
  await expect(editor).toContainText('230 rows');
  await expect(editor.locator('.viswiz-editor-pager')).toContainText('Page 1 / 3');
  await expect(editor).not.toContainText('Large Row 230');

  await editor.getByRole('button', { name: 'Next' }).click();
  await expect(editor.locator('.viswiz-editor-pager')).toContainText('Page 2 / 3');
  await expect(editor).toContainText('Large Row 101');

  const search = page.locator('[data-viswiz-dataset-search]');
  await search.fill('Large Row 230');
  await expect(editor.locator('tbody tr')).toHaveCount(1);
  await expect(editor).toContainText('Large Row 230');
  await expect(editor).toContainText('1 rows');

  await editor.getByRole('button', { name: 'Edit' }).click();
  const dialog = page.locator('dialog[open]');
  await dialog.locator('input[name="label"]').fill('Large Row 230 Updated');
  await dialog.getByRole('button', { name: 'Save row' }).click();
  await expect(dialog).not.toBeVisible();
  await expect(editor).toContainText('Large Row 230 Updated');
  await expect(page.locator('.viswiz-admin-wrap h1 small')).toContainText(/^r\d+$/);
});

test('graph editor uses server pages and lazy node lookup for relation endpoints', async ({ page }) => {
  await login(page);
  const datasetId = await createDataset(page, 'E2E server graph', 'graph');
  const records = Array.from({ length: 130 }, (_, index) => ({
    external_key: `graph-node-${String(index + 1).padStart(3, '0')}`,
    title: `Graph Node ${String(index + 1).padStart(3, '0')}`,
    node_type: index % 2 ? 'person' : 'organization',
  }));
  await commitImport(page, datasetId, {
    kind: 'nodes',
    mode: 'append',
    mapping: { external_key: 'external_key', title: 'title', node_type: 'node_type' },
    records,
  });

  const editor = page.locator('#viswiz-dataset-editor');
  const tables = editor.locator('table');
  await expect(tables.nth(0).locator('tbody tr')).toHaveCount(100);
  await expect(editor).toContainText('130 nodes');
  await expect(editor.locator('.viswiz-server-editor-section').nth(0).locator('.viswiz-editor-pager')).toContainText('Page 1 / 2');
  await expect(tables.nth(0)).not.toContainText('Graph Node 130');

  const search = page.locator('[data-viswiz-dataset-search]');
  await search.fill('Graph Node 130');
  await expect(tables.nth(0).locator('tbody tr')).toHaveCount(1);
  await expect(tables.nth(0)).toContainText('Graph Node 130');
  await search.fill('');
  await expect(tables.nth(0).locator('tbody tr')).toHaveCount(100);

  await editor.getByRole('button', { name: 'Add relation' }).click();
  const dialog = page.locator('dialog[open]');
  const fromSearch = dialog.getByLabel('From node search');
  const toSearch = dialog.getByLabel('To node search');
  await fromSearch.fill('Graph Node 130');
  const fromSelect = dialog.getByLabel('From node');
  await expect(fromSelect.locator('option')).toHaveCount(1);
  await expect(fromSelect.locator('option')).toContainText('Graph Node 130');
  await fromSelect.selectOption({ index: 0 });

  await toSearch.fill('Graph Node 001');
  const toSelect = dialog.getByLabel('To node');
  await expect(toSelect.locator('option')).toHaveCount(1);
  await expect(toSelect.locator('option')).toContainText('Graph Node 001');
  await toSelect.selectOption({ index: 0 });
  await dialog.locator('input[name="label"]').fill('Large graph link');
  await dialog.getByRole('button', { name: 'Save relation' }).click();
  await expect(dialog).not.toBeVisible();
  await expect(editor.locator('table').nth(1)).toContainText('Large graph link');
  await expect(editor.locator('table').nth(1)).toContainText('Graph Node 130');
  await expect(editor.locator('table').nth(1)).toContainText('Graph Node 001');
});
