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

async function createGraphDataset(page) {
  await page.goto('/wp-admin/admin.php?page=viswiz-datasets');
  const card = page.locator('.viswiz-card').filter({ has: page.getByRole('heading', { name: 'Create dataset' }) });
  await card.locator('input[name="name"]').fill('E2E filtered dialog focus');
  await card.locator('select[name="schema_type"]').selectOption('graph');
  await Promise.all([
    page.waitForURL(/page=viswiz-datasets&dataset_id=\d+/),
    card.getByRole('button', { name: 'Create dataset' }).click(),
  ]);
  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor.getByRole('button', { name: 'Add node', exact: true })).toBeVisible();
  return editor;
}

test('saving an edit that leaves the active search returns focus to the dataset search', async ({ page }) => {
  await login(page);
  const editor = await createGraphDataset(page);

  await editor.getByRole('button', { name: 'Add node', exact: true }).click();
  let dialog = page.locator('dialog[open]').last();
  await dialog.locator('[name="title"]').fill('FocusNeedle Node');
  await dialog.locator('[name="slug"]').fill('stable-focus-node');
  await dialog.locator('[name="label"]').fill('FocusNeedle Node');
  await dialog.locator('[name="node_type"]').selectOption('person');
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).not.toBeVisible();

  const search = page.locator('[data-viswiz-dataset-search]');
  await search.fill('FocusNeedle');
  const row = editor.locator('tbody tr').filter({ hasText: 'FocusNeedle Node' }).first();
  await expect(row).toBeVisible();

  await row.getByRole('button', { name: 'Edit', exact: true }).click();
  dialog = page.locator('dialog[open]').last();
  await dialog.locator('[name="title"]').fill('Renamed Outside Filter');
  await dialog.locator('[name="label"]').fill('Renamed Outside Filter');
  await dialog.getByRole('button', { name: 'Save node' }).click();

  await expect(dialog).not.toBeVisible();
  await expect(editor).not.toContainText('Renamed Outside Filter');
  await expect(search).toBeFocused();
});
