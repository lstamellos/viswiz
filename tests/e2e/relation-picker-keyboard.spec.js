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
  await card.locator('input[name="name"]').fill('E2E relation picker keyboard');
  await card.locator('select[name="schema_type"]').selectOption('graph');
  await Promise.all([
    page.waitForURL(/page=viswiz-datasets&dataset_id=\d+/),
    card.getByRole('button', { name: 'Create dataset' }).click(),
  ]);
  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor.getByRole('button', { name: 'Add node' })).toBeVisible();
  return editor;
}

async function addNode(page, editor, title, slug, type) {
  await editor.getByRole('button', { name: 'Add node' }).click();
  const dialog = page.locator('dialog[open]').last();
  await dialog.locator('[name="title"]').fill(title);
  await dialog.locator('[name="slug"]').fill(slug);
  await dialog.locator('[name="label"]').fill(title);
  await dialog.locator('[name="node_type"]').selectOption(type);
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).not.toBeVisible();
}

test('Enter in node search selects an available endpoint instead of submitting the relation form', async ({ page }) => {
  await login(page);
  const editor = await createGraphDataset(page);

  await addNode(page, editor, 'Keyboard Source', 'keyboard-source', 'person');
  await addNode(page, editor, 'Keyboard Target', 'keyboard-target', 'organization');

  const sourceRow = editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: 'Keyboard Source' });
  await sourceRow.getByRole('button', { name: 'Add relation' }).click();

  const dialog = page.locator('dialog[open]').last();
  const search = dialog.getByLabel('To node search', { exact: true });
  const select = dialog.getByLabel('To node', { exact: true });

  await search.fill('Keyboard Target');
  await expect(select.locator('option')).toHaveCount(1);
  await expect(select.locator('option')).toContainText('Keyboard Target');
  await expect(select).toHaveValue('');

  await search.press('Enter');

  await expect(dialog).toBeVisible();
  await expect(select).not.toHaveValue('');
  await expect(select.locator('option:checked')).toContainText('Keyboard Target');
  await expect(select).toBeFocused();
  await expect(dialog).not.toContainText('Choose both relation endpoints.');

  await dialog.locator('[name="relation_type"]').selectOption('member_of');
  await dialog.getByRole('button', { name: 'Save relation' }).click();
  await expect(dialog).not.toBeVisible();

  const relationTable = editor.locator('table').nth(1);
  await expect(relationTable).toContainText('Keyboard Source');
  await expect(relationTable).toContainText('Keyboard Target');
});
