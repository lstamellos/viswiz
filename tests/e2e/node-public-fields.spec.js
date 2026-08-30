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

async function createGraphDataset(page, name) {
  await page.goto('/wp-admin/admin.php?page=viswiz-datasets');
  const card = page.locator('.viswiz-card').filter({ has: page.getByRole('heading', { name: 'Create dataset' }) });
  await card.locator('input[name="name"]').fill(name);
  await card.locator('select[name="schema_type"]').selectOption('graph');
  await Promise.all([
    page.waitForURL(/page=viswiz-datasets&dataset_id=\d+/),
    card.getByRole('button', { name: 'Create dataset' }).click(),
  ]);
  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor.getByRole('button', { name: 'Add node' })).toBeVisible();
  return editor;
}

function publicRows(dialog) {
  return dialog.locator('[data-viswiz-public-field-row]');
}

async function addPublicField(dialog, { label, type, value }) {
  await dialog.getByRole('button', { name: 'Add public field' }).click();
  const row = publicRows(dialog).last();
  await row.getByLabel('Label').fill(label);
  await row.getByLabel('Type').selectOption(type);
  await row.getByLabel('Value').fill(value);
  return row;
}

test('node public fields are structured, ordered and kept separate from advanced metadata', async ({ page }) => {
  await login(page);
  const editor = await createGraphDataset(page, 'E2E structured node public fields');

  await editor.getByRole('button', { name: 'Add node' }).click();
  let dialog = page.locator('dialog[open]').last();
  await expect(dialog.getByRole('heading', { name: 'Public fields' })).toBeVisible();
  await expect(dialog.getByText('No public fields yet.')).toBeVisible();
  const advanced = dialog.locator('[data-viswiz-node-meta-advanced]');
  await expect(advanced).not.toHaveAttribute('open', '');

  await dialog.locator('[name="title"]').fill('Structured Metadata Node');
  await dialog.locator('[name="slug"]').fill('structured-metadata-node');
  await dialog.locator('[name="label"]').fill('Structured Metadata Node');
  await dialog.locator('[name="node_type"]').selectOption('person');

  await addPublicField(dialog, {
    label: 'Website',
    type: 'url',
    value: 'https://example.com/profile',
  });
  const summaryRow = await addPublicField(dialog, {
    label: 'Summary',
    type: 'formatted',
    value: '<strong>Public summary</strong>',
  });
  await summaryRow.getByRole('button', { name: 'Move Summary up' }).click();

  await advanced.locator('summary').click();
  await advanced.locator('textarea[name="meta"]').fill(JSON.stringify({ internal_note: 'keep-me', color: '#112233' }, null, 2));
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).toHaveCount(0);

  let row = editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: 'Structured Metadata Node' });
  await row.getByRole('button', { name: 'Edit' }).click();
  dialog = page.locator('dialog[open]').last();
  await expect(publicRows(dialog)).toHaveCount(2);
  await expect(publicRows(dialog).nth(0).getByLabel('Label')).toHaveValue('Summary');
  await expect(publicRows(dialog).nth(0).getByLabel('Type')).toHaveValue('formatted');
  await expect(publicRows(dialog).nth(0).getByLabel('Value')).toHaveValue('<strong>Public summary</strong>');
  await expect(publicRows(dialog).nth(1).getByLabel('Label')).toHaveValue('Website');
  await expect(publicRows(dialog).nth(1).getByLabel('Type')).toHaveValue('url');
  await expect(publicRows(dialog).nth(1).getByLabel('Value')).toHaveValue('https://example.com/profile');

  const reopenedAdvanced = dialog.locator('[data-viswiz-node-meta-advanced]');
  await reopenedAdvanced.locator('summary').click();
  const advancedValue = await reopenedAdvanced.locator('textarea[name="meta"]').inputValue();
  expect(advancedValue).toContain('"internal_note": "keep-me"');
  expect(advancedValue).toContain('"color": "#112233"');
  expect(advancedValue).not.toContain('public_fields');

  const websiteRow = publicRows(dialog).nth(1);
  await websiteRow.getByRole('button', { name: 'Remove Website' }).click();
  await addPublicField(dialog, { label: 'Status', type: 'short', value: 'Active' });
  await expect(publicRows(dialog)).toHaveCount(2);
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).toHaveCount(0);

  row = editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: 'Structured Metadata Node' });
  await row.getByRole('button', { name: 'Edit' }).click();
  dialog = page.locator('dialog[open]').last();
  await expect(publicRows(dialog)).toHaveCount(2);
  await expect(publicRows(dialog).nth(0).getByLabel('Label')).toHaveValue('Summary');
  await expect(publicRows(dialog).nth(1).getByLabel('Label')).toHaveValue('Status');
  await expect(publicRows(dialog).nth(1).getByLabel('Value')).toHaveValue('Active');

  const failedAdvanced = dialog.locator('[data-viswiz-node-meta-advanced]');
  await failedAdvanced.locator('summary').click();
  await dialog.locator('[name="node_type"]').selectOption('');
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(editor.locator('.notice-error')).toContainText('Node title and type are required.');
  await expect(dialog).toBeVisible();
  const failedAdvancedValue = await failedAdvanced.locator('textarea[name="meta"]').inputValue();
  expect(failedAdvancedValue).toContain('"internal_note": "keep-me"');
  expect(failedAdvancedValue).not.toContain('public_fields');
});
