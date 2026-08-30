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
  await expect(editor).toBeVisible();
  await expect(editor.getByRole('button', { name: 'Add node' })).toBeVisible();
  return editor;
}

async function addNode(page, editor, { title, slug, type }) {
  await editor.getByRole('button', { name: 'Add node' }).click();
  const dialog = page.locator('dialog[open]').last();
  await expect(dialog.getByRole('heading', { name: 'Add node' })).toBeVisible();
  await dialog.locator('[name="title"]').fill(title);
  await dialog.locator('[name="slug"]').fill(slug);
  await dialog.locator('[name="label"]').fill(title);
  await dialog.locator('[name="node_type"]').selectOption(type);
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).not.toBeVisible();
  const row = editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: title });
  await expect(row).toHaveCount(1);
  return row;
}

async function chooseNode(dialog, side, text) {
  const search = dialog.getByLabel(`${side} node search`, { exact: true });
  const select = dialog.getByLabel(`${side} node`, { exact: true });
  await search.fill(text);
  await expect(select.locator('option')).toHaveCount(1);
  await expect(select.locator('option')).toContainText(text);
  await select.selectOption({ index: 0 });
}

test('node context creates relations, quick-creates endpoints and exposes incoming/outgoing relations', async ({ page }) => {
  await login(page);
  const editor = await createGraphDataset(page, 'E2E graph workflow context');
  const personRow = await addNode(page, editor, { title: 'Workflow Person', slug: 'workflow-person', type: 'person' });
  await addNode(page, editor, { title: 'Workflow Organization', slug: 'workflow-organization', type: 'organization' });

  await personRow.getByRole('button', { name: 'Add relation' }).click();
  const relationDialog = page.locator('dialog[open]').last();
  await expect(relationDialog.getByRole('heading', { name: 'Add relation' })).toBeVisible();
  const fromSelect = relationDialog.getByLabel('From node', { exact: true });
  await expect(fromSelect.locator('option')).toContainText('Workflow Person');
  await expect(fromSelect).not.toHaveValue('');

  await relationDialog.locator('[name="relation_type"]').selectOption('connected_to');
  await relationDialog.locator('[name="label"]').fill('Draft survives quick create');
  await relationDialog.getByRole('button', { name: 'Create to node' }).click();

  const nodeDialog = page.locator('dialog[open]').last();
  await expect(nodeDialog.getByRole('heading', { name: 'Create to node' })).toBeVisible();
  await nodeDialog.locator('[name="title"]').fill('Quick Target');
  await nodeDialog.locator('[name="slug"]').fill('quick-target');
  await nodeDialog.locator('[name="label"]').fill('Quick Target');
  await nodeDialog.locator('[name="node_type"]').selectOption('organization');
  await nodeDialog.getByRole('button', { name: 'Save node' }).click();
  await expect(nodeDialog).not.toBeVisible();

  await expect(relationDialog.locator('[name="label"]')).toHaveValue('Draft survives quick create');
  const toSelect = relationDialog.getByLabel('To node', { exact: true });
  await expect(toSelect.locator('option:checked')).toContainText('Quick Target');
  await relationDialog.getByRole('button', { name: 'Save relation' }).click();
  await expect(relationDialog).not.toBeVisible();

  const relationTable = editor.locator('table').nth(1);
  await expect(relationTable).toContainText('Draft survives quick create');
  await expect(relationTable).toContainText('Workflow Person');
  await expect(relationTable).toContainText('Quick Target');

  const updatedPersonRow = editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: 'Workflow Person' });
  await updatedPersonRow.getByRole('button', { name: 'Edit' }).click();
  let editDialog = page.locator('dialog[open]').last();
  const outgoingPanel = editDialog.locator('[data-viswiz-node-relations]');
  await expect(outgoingPanel).toContainText('Connected relations');
  await expect(outgoingPanel).toContainText('Outgoing');
  await expect(outgoingPanel).toContainText('Quick Target');
  await expect(outgoingPanel).toContainText('Draft survives quick create');
  await editDialog.getByRole('button', { name: 'Close' }).click();

  const targetRow = editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: 'Quick Target' });
  await targetRow.getByRole('button', { name: 'Edit' }).click();
  editDialog = page.locator('dialog[open]').last();
  const incomingPanel = editDialog.locator('[data-viswiz-node-relations]');
  await expect(incomingPanel).toContainText('Incoming');
  await expect(incomingPanel).toContainText('Workflow Person');
  await editDialog.getByRole('button', { name: 'Close' }).click();
});

test('relation constraints warn without blocking and node/relation duplication stays canonical', async ({ page }) => {
  await login(page);
  const editor = await createGraphDataset(page, 'E2E graph workflow duplicate');
  await addNode(page, editor, { title: 'Constraint Person', slug: 'constraint-person', type: 'person' });
  const organizationRow = await addNode(page, editor, { title: 'Constraint Organization', slug: 'constraint-organization', type: 'organization' });

  await organizationRow.getByRole('button', { name: 'Add relation' }).click();
  let dialog = page.locator('dialog[open]').last();
  await chooseNode(dialog, 'To', 'Constraint Organization');
  await dialog.locator('[name="relation_type"]').selectOption('member_of');
  const warning = dialog.locator('[data-viswiz-relation-constraint]');
  await expect(warning).toBeVisible();
  await expect(warning).toContainText('Source should be Person');
  await dialog.locator('[name="label"]').fill('Nonfatal mismatch');
  await dialog.getByRole('button', { name: 'Save relation' }).click();
  await expect(dialog).not.toBeVisible();

  const relationTable = editor.locator('table').nth(1);
  const mismatchRow = relationTable.locator('tbody tr').filter({ hasText: 'Nonfatal mismatch' });
  await expect(mismatchRow).toHaveCount(1);

  const personRow = editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: 'Constraint Person' });
  await personRow.getByRole('button', { name: 'Duplicate' }).click();
  dialog = page.locator('dialog[open]').last();
  await expect(dialog.getByRole('heading', { name: 'Duplicate node' })).toBeVisible();
  await expect(dialog.locator('[name="title"]')).toHaveValue('Constraint Person copy');
  await expect(dialog.locator('[name="slug"]')).toHaveValue(/constraint-person-copy-[a-f0-9]{8}/);
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).not.toBeVisible();
  await expect(editor.locator('table').nth(0).locator('tbody tr').filter({ hasText: 'Constraint Person copy' })).toHaveCount(1);

  await mismatchRow.getByRole('button', { name: 'Duplicate' }).click();
  dialog = page.locator('dialog[open]').last();
  await expect(dialog.getByRole('heading', { name: 'Duplicate relation' })).toBeVisible();
  await expect(dialog.locator('[name="label"]')).toHaveValue('Nonfatal mismatch');
  await dialog.getByRole('button', { name: 'Save relation' }).click();
  await expect(dialog).not.toBeVisible();
  await expect(relationTable.locator('tbody tr').filter({ hasText: 'Nonfatal mismatch' })).toHaveCount(2);
});
