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

function captureClientErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
  return errors;
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
  await expect(editor.getByRole('button', { name: 'Add node', exact: true })).toBeVisible();
  return editor;
}

async function addNode(page, editor, title, slug, type) {
  const add = editor.getByRole('button', { name: 'Add node', exact: true });
  await add.click();
  const dialog = page.locator('dialog[open]').last();
  await expect(dialog.locator('[name="title"]')).toBeFocused();
  await dialog.locator('[name="title"]').fill(title);
  await dialog.locator('[name="slug"]').fill(slug);
  await dialog.locator('[name="label"]').fill(title);
  await dialog.locator('[name="node_type"]').selectOption(type);
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).not.toBeVisible();
  await expect(editor).toContainText(title);
  return editor.locator('tbody tr').filter({ hasText: title }).first();
}

async function expectFocusInside(dialog, page, key) {
  await page.keyboard.press(key);
  await expect.poll(() => dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true);
}

test('native admin dialog keeps tab focus, Escape cancels, and focus returns to its invoker', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  const editor = await createGraphDataset(page, 'E2E dialog keyboard escape');
  const addNode = editor.getByRole('button', { name: 'Add node', exact: true });

  await addNode.click();
  const dialog = page.locator('dialog[open]').last();
  const title = dialog.locator('[name="title"]');
  await expect(title).toBeFocused();
  await title.fill('Unsaved Escape Node');

  for (let index = 0; index < 8; index += 1) {
    await expectFocusInside(dialog, page, 'Tab');
  }
  for (let index = 0; index < 4; index += 1) {
    await expectFocusInside(dialog, page, 'Shift+Tab');
  }

  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await expect(addNode).toBeFocused();
  await expect(editor).not.toContainText('Unsaved Escape Node');
  expect(clientErrors).toEqual([]);
});

test('Enter submits an unambiguous node form and focus survives the editor rerender', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  const editor = await createGraphDataset(page, 'E2E dialog keyboard submit');
  const addNode = editor.getByRole('button', { name: 'Add node', exact: true });

  await addNode.click();
  let dialog = page.locator('dialog[open]').last();
  await dialog.locator('[name="title"]').fill('Keyboard Saved Node');
  await dialog.locator('[name="slug"]').fill('keyboard-saved-node');
  await dialog.locator('[name="label"]').fill('Keyboard Saved Node');
  await dialog.locator('[name="node_type"]').selectOption('person');
  await dialog.locator('[name="label"]').focus();
  await page.keyboard.press('Enter');

  await expect(dialog).not.toBeVisible();
  await expect(editor).toContainText('Keyboard Saved Node');
  await expect(editor.getByRole('button', { name: 'Add node', exact: true })).toBeFocused();

  const row = editor.locator('tbody tr').filter({ hasText: 'Keyboard Saved Node' }).first();
  const edit = row.getByRole('button', { name: 'Edit', exact: true });
  await edit.click();
  dialog = page.locator('dialog[open]').last();
  await dialog.locator('[name="title"]').fill('Keyboard Saved Node Updated');
  await dialog.locator('[name="label"]').fill('Keyboard Saved Node Updated');
  await dialog.locator('[name="label"]').focus();
  await page.keyboard.press('Enter');

  await expect(dialog).not.toBeVisible();
  const updatedRow = editor.locator('tbody tr').filter({ hasText: 'Keyboard Saved Node Updated' }).first();
  await expect(updatedRow).toBeVisible();
  await expect(updatedRow.getByRole('button', { name: 'Edit', exact: true })).toBeFocused();
  expect(clientErrors).toEqual([]);
});

test('relation listbox Escape closes natively and nested quick-create returns focus to the relation dialog', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  const editor = await createGraphDataset(page, 'E2E relation dialog keyboard');
  await addNode(page, editor, 'Keyboard Source', 'keyboard-source-dialog', 'person');
  await addNode(page, editor, 'Keyboard Target', 'keyboard-target-dialog', 'organization');

  let sourceRow = editor.locator('tbody tr').filter({ hasText: 'Keyboard Source' }).first();
  let addRelation = sourceRow.getByRole('button', { name: 'Add relation', exact: true });
  await addRelation.click();
  let relationDialog = page.locator('dialog[open]').last();
  const toSearch = relationDialog.getByLabel('To node search', { exact: true });
  const toSelect = relationDialog.getByLabel('To node', { exact: true });
  await toSearch.fill('Keyboard Target');
  await expect(toSelect.locator('option')).toHaveCount(1);
  await toSearch.press('ArrowDown');
  await expect(toSelect).toBeFocused();
  await page.keyboard.press('Escape');

  await expect(relationDialog).not.toBeVisible();
  await expect(addRelation).toBeFocused();

  await addRelation.click();
  relationDialog = page.locator('dialog[open]').last();
  const createTarget = relationDialog.getByRole('button', { name: 'Create to node', exact: true });
  await createTarget.click();
  const nestedDialog = page.locator('dialog[open]').last();
  await expect(page.locator('dialog[open]')).toHaveCount(2);
  await expect(nestedDialog.locator('[name="title"]')).toBeFocused();
  await page.keyboard.press('Escape');

  await expect(page.locator('dialog[open]')).toHaveCount(1);
  await expect(relationDialog).toBeVisible();
  await expect(createTarget).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(relationDialog).not.toBeVisible();
  sourceRow = editor.locator('tbody tr').filter({ hasText: 'Keyboard Source' }).first();
  addRelation = sourceRow.getByRole('button', { name: 'Add relation', exact: true });
  await expect(addRelation).toBeFocused();
  expect(clientErrors).toEqual([]);
});
