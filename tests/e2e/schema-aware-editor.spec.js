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
  const editor = page.locator('#viswiz-dataset-editor[data-viswiz-server-editor]');
  await expect(editor).toBeVisible();
  if (schema !== 'graph') await expect(editor).toHaveAttribute('data-viswiz-spreadsheet-editor', '1');
  return { editor, id: Number(new URL(page.url()).searchParams.get('dataset_id')) };
}

async function payload(page, datasetId) {
  return page.evaluate(async (id) => {
    const cfg = window.VisWizAdminV2;
    const response = await fetch(`${cfg.restUrl}/datasets/${id}`, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce || '' },
    });
    if (!response.ok) throw new Error(`Dataset payload failed: ${response.status}`);
    return response.json();
  }, datasetId);
}

async function addSchemaRow(editor, noun, values) {
  await editor.getByRole('button', { name: `Add ${noun}` }).click();
  const row = editor.locator('tbody tr').last();
  for (const [path, value] of Object.entries(values)) {
    const control = row.locator(`[data-field-path="${path}"]`);
    if (value !== null) await control.fill(String(value));
  }
  await expect(editor.getByRole('button', { name: 'Save changes' })).toBeEnabled();
  await editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(editor.locator('[data-viswiz-grid-state]')).toContainText('All changes saved');
}

test('row datasets expose schema-specific spreadsheet fields and persist canonical values', async ({ page }) => {
  await login(page);

  let created = await createDataset(page, 'E2E categorical schema editor', 'categorical');
  await expect(created.editor.locator('thead')).toContainText('Label');
  await expect(created.editor.locator('thead')).toContainText('Value');
  await expect(created.editor.locator('thead')).toContainText('Color');
  await expect(created.editor.locator('thead')).not.toContainText('Latitude');
  await addSchemaRow(created.editor, 'item', { label: 'Category A', value: 12.5 });
  let data = await payload(page, created.id);
  expect(data.payload.rows[0]).toMatchObject({ label: 'Category A', value: 12.5 });

  created = await createDataset(page, 'E2E time schema editor', 'time_series');
  await created.editor.getByRole('button', { name: 'Add point' }).click();
  let row = created.editor.locator('tbody tr').last();
  await expect(row.locator('[data-field-path="x_value"]')).toHaveAttribute('type', 'datetime-local');
  await expect(row.locator('[data-field-path="latitude"]')).toHaveCount(0);
  await row.locator('[data-field-path="x_value"]').fill('2026-08-28T10:30');
  await row.locator('[data-field-path="value"]').fill('7.25');
  await row.locator('[data-field-path="label"]').fill('Morning');
  await created.editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(created.editor.locator('[data-viswiz-grid-state]')).toContainText('All changes saved');
  data = await payload(page, created.id);
  expect(data.payload.rows[0].x_value).toBe('2026-08-28T10:30');
  expect(data.payload.rows[0].x_numeric).toBeGreaterThan(0);
  expect(data.payload.rows[0].value).toBe(7.25);

  created = await createDataset(page, 'E2E XY schema editor', 'xy');
  await addSchemaRow(created.editor, 'point', { x_numeric: 3.5, y_value: 9.75, label: 'Point A' });
  data = await payload(page, created.id);
  expect(data.payload.rows[0]).toMatchObject({ x_numeric: 3.5, y_value: 9.75, label: 'Point A' });

  created = await createDataset(page, 'E2E geo schema editor', 'geo');
  await addSchemaRow(created.editor, 'point', { latitude: 37.9838, longitude: 23.7275, label: 'Athens', value: 4 });
  data = await payload(page, created.id);
  expect(data.payload.rows[0]).toMatchObject({ latitude: 37.9838, longitude: 23.7275, label: 'Athens', value: 4 });

  created = await createDataset(page, 'E2E progress schema editor', 'progress');
  await addSchemaRow(created.editor, 'progress item', { label: 'Funding', value: 42, 'meta.target': 100, 'meta.text': 'Goal progress' });
  data = await payload(page, created.id);
  expect(data.payload.rows[0].label).toBe('Funding');
  expect(data.payload.rows[0].value).toBe(42);
  expect(data.payload.rows[0].meta).toMatchObject({ target: 100, text: 'Goal progress' });

  created = await createDataset(page, 'E2E diagram schema editor', 'diagram');
  await addSchemaRow(created.editor, 'section', { label: 'Stage one', 'meta.text': 'Structured section body' });
  data = await payload(page, created.id);
  expect(data.payload.rows[0].label).toBe('Stage one');
  expect(data.payload.rows[0].meta.text).toBe('Structured section body');

  await expect(created.editor.getByRole('button', { name: 'Advanced' })).toHaveCount(1);
  await expect(created.editor.locator('textarea[name="meta"]')).toHaveCount(0);
});

test('targeted row endpoint rejects a schema-invalid write even when browser validation is bypassed', async ({ page }) => {
  await login(page);
  const created = await createDataset(page, 'E2E invalid categorical write', 'categorical');
  const result = await page.evaluate(async (datasetId) => {
    const cfg = window.VisWizAdminV2;
    const editor = document.querySelector('#viswiz-dataset-editor');
    const response = await fetch(`${cfg.restUrl}/datasets/${datasetId}/editor/rows`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: JSON.stringify({
        expected_revision: Number(editor?.dataset.revision || 0),
        row: { value: 5 },
      }),
    });
    return { status: response.status, body: await response.json() };
  }, created.id);
  expect(result.status).toBe(422);
  expect(result.body.code).toBe('viswiz_invalid_row_schema');
  expect(result.body.data.field).toBe('label');
});
