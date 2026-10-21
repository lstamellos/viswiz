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
  return page.locator('#viswiz-dataset-editor');
}

async function editorId(dialog) {
  await expect(dialog).toHaveAttribute('data-viswiz-rich-editor-state', 'ready');
  const textarea = dialog.locator('textarea[name="description"]');
  const id = await textarea.getAttribute('id');
  expect(id).toBeTruthy();
  return id;
}

async function expectEditorRemoved(page, id) {
  await expect.poll(() => page.evaluate((editorIdValue) => !window.tinymce?.get(editorIdValue), id)).toBe(true);
}

test('node description uses the WordPress visual editor and survives repeated dialog lifecycles', async ({ page }) => {
  await login(page);
  const editor = await createGraphDataset(page, 'E2E node rich editor');
  await expect(editor.getByRole('button', { name: 'Add node' })).toBeVisible();

  await editor.getByRole('button', { name: 'Add node' }).click();
  let dialog = page.locator('dialog.viswiz-editor-dialog').filter({ has: page.getByRole('heading', { name: 'Add node' }) });
  await expect(dialog).toBeVisible();
  await dialog.locator('[name="title"]').fill('Rich Editor Node');
  await dialog.locator('[name="slug"]').fill('rich-editor-node');
  await dialog.locator('[name="label"]').fill('Rich Editor Node');
  await dialog.locator('[name="node_type"]').selectOption('person');

  const firstId = await editorId(dialog);
  await expect(dialog.locator('.wp-editor-wrap')).toBeVisible();
  const textTab = dialog.locator(`#${firstId}-html`);
  const visualTab = dialog.locator(`#${firstId}-tmce`);
  await textTab.focus();
  await textTab.press('Enter');
  await expect(dialog.locator(`#${firstId}`)).toBeVisible();
  await visualTab.focus();
  await visualTab.press('Enter');

  const firstFrame = page.frameLocator(`#${firstId}_ifr`);
  const firstBody = firstFrame.locator('body');
  await expect(firstBody).toBeVisible();
  await firstBody.click();
  await firstBody.press('Control+b');
  await firstBody.type('Rich description');
  await firstBody.press('Control+b');
  await firstBody.type(' plain text');

  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).toHaveCount(0);
  await expectEditorRemoved(page, firstId);

  const nodeTable = editor.locator('table').nth(0);
  let row = nodeTable.locator('tbody tr').filter({ hasText: 'Rich Editor Node' });
  await expect(row).toHaveCount(1);
  await row.getByRole('button', { name: 'Edit' }).click();
  dialog = page.locator('dialog.viswiz-editor-dialog').filter({ has: page.getByRole('heading', { name: 'Edit node' }) });
  await expect(dialog).toBeVisible();
  const secondId = await editorId(dialog);
  expect(secondId).not.toBe(firstId);
  const secondFrame = page.frameLocator(`#${secondId}_ifr`);
  await expect(secondFrame.locator('body')).toContainText('Rich description plain text');
  await expect(secondFrame.locator('body strong')).toContainText('Rich description');

  await dialog.locator('[name="title"]').focus();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expectEditorRemoved(page, secondId);

  row = nodeTable.locator('tbody tr').filter({ hasText: 'Rich Editor Node' });
  await row.getByRole('button', { name: 'Edit' }).click();
  dialog = page.locator('dialog.viswiz-editor-dialog').filter({ has: page.getByRole('heading', { name: 'Edit node' }) });
  const thirdId = await editorId(dialog);
  expect(thirdId).not.toBe(secondId);
  await dialog.getByRole('button', { name: 'Close' }).click();
  await expect(dialog).toHaveCount(0);
  await expectEditorRemoved(page, thirdId);
});
