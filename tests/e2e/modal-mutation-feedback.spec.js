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

async function addNode(page, editor, title, slug) {
  await editor.getByRole('button', { name: 'Add node' }).click();
  const dialog = page.locator('dialog[open]').last();
  await dialog.locator('[name="title"]').fill(title);
  await dialog.locator('[name="slug"]').fill(slug);
  await dialog.locator('[name="label"]').fill(title);
  await dialog.locator('[name="node_type"]').selectOption('person');
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).not.toBeVisible();
  return editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: title });
}

test('duplicate node slug error stays inside the node dialog and marks the slug field invalid', async ({ page }) => {
  await login(page);
  const editor = await createGraphDataset(page, 'E2E modal mutation feedback');
  await addNode(page, editor, 'Existing Node', 'existing-node');
  const editableRow = await addNode(page, editor, 'Editable Node', 'editable-node');

  await editableRow.getByRole('button', { name: 'Edit' }).click();
  const dialog = page.locator('dialog[open]').last();
  const slug = dialog.locator('[name="slug"]');
  await slug.fill('existing-node');
  await dialog.getByRole('button', { name: 'Save node' }).click();

  await expect(dialog).toBeVisible();
  const modalNotice = dialog.locator('[data-viswiz-editor-notice]');
  await expect(modalNotice).toBeVisible();
  await expect(modalNotice).toContainText('Another node already uses this slug.');
  await expect(editor.locator(':scope > [data-viswiz-editor-notice]')).toHaveCount(0);

  await expect(slug).toHaveAttribute('aria-invalid', 'true');
  await expect(slug.locator('xpath=ancestor::label[contains(@class,"viswiz-field")]')).toHaveClass(/form-invalid/);
  await expect(dialog.locator('[data-viswiz-field-error="slug"]')).toContainText('Another node already uses this slug.');

  await slug.fill('editable-node-unique');
  await expect(slug).not.toHaveAttribute('aria-invalid', 'true');
  await expect(dialog.locator('[data-viswiz-field-error="slug"]')).toHaveCount(0);
});
