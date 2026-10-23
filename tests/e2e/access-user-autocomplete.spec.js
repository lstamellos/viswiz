const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const fixturePath = process.env.VISWIZ_E2E_FIXTURE || '/tmp/viswiz-e2e-fixture.json';
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
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

test('dataset grants: search by Greek display name and username; save, reload, remove', async ({ page }) => {
  await login(page);
  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.rowDatasetId}`);

  const picker = page.locator('[data-viswiz-user-picker][data-kind="dataset"][data-mode="editors"]');
  const input = picker.locator('[data-viswiz-user-search]');
  await expect(picker).toBeVisible();
  await expect(picker.locator('input[type="text"][name="viswiz_access[editors]"]')).toHaveCount(0);

  await input.fill('Αλεξ');
  const alex = picker.getByRole('option', { name: /Αλεξάνδρα Δοκιμή.*viswiz-e2e-alexandra/ });
  await expect(alex).toBeVisible();
  await alex.click();
  await expect(picker.locator('input[name="viswiz_access[editors][]"]')).toHaveValue(String(fixture.aclEditorId));

  await input.fill('viswiz-e2e-nikos');
  await expect(picker.getByRole('option', { name: /Nikos Contributor.*viswiz-e2e-nikos/ })).toBeVisible();
  await input.press('ArrowDown');
  await input.press('Enter');
  await expect(picker.locator('input[name="viswiz_access[editors][]"]')).toHaveCount(2);

  await input.fill('viswiz-e2e-viewer');
  await expect(picker.locator('[data-viswiz-user-status]')).toContainText('No matching users');
  await expect(picker.getByRole('option')).toHaveCount(0);
  await input.fill('');

  await Promise.all([
    page.waitForURL(/access_saved=1/),
    picker.locator('xpath=ancestor::form').getByRole('button', { name: 'Save access' }).click(),
  ]);
  const persisted = page.locator('[data-viswiz-user-picker][data-kind="dataset"][data-mode="editors"]');
  await expect(persisted.locator('input[name="viswiz_access[editors][]"]')).toHaveCount(2);
  await persisted.getByRole('button', { name: 'Remove Αλεξάνδρα Δοκιμή' }).click();
  await expect(persisted.locator('input[name="viswiz_access[editors][]"]')).toHaveCount(1);
  await Promise.all([
    page.waitForURL(/access_saved=1/),
    persisted.locator('xpath=ancestor::form').getByRole('button', { name: 'Save access' }).click(),
  ]);
  await expect(page.locator('[data-viswiz-user-picker][data-kind="dataset"][data-mode="editors"] input[name="viswiz_access[editors][]"]')).toHaveCount(1);
});

test('visualization: search for editor and choose new owner by username', async ({ page }) => {
  await login(page);
  await page.goto(`/wp-admin/post.php?post=${fixture.visualizationId}&action=edit`);

  const editorPicker = page.locator('[data-viswiz-user-picker][data-kind="visualization"][data-mode="editors"]');
  await expect(editorPicker).toBeVisible();
  await editorPicker.locator('[data-viswiz-user-search]').fill('Αλεξ');
  await editorPicker.getByRole('option', { name: /Αλεξάνδρα Δοκιμή/ }).click();
  await expect(editorPicker.locator('input[name="viswiz_access[editors][]"]')).toHaveValue(String(fixture.aclEditorId));

  const ownerPicker = page.locator('[data-viswiz-user-picker][data-kind="visualization"][data-mode="owner"]');
  await expect(ownerPicker).toBeVisible();
  await ownerPicker.locator('[data-viswiz-user-search]').fill('viswiz-e2e-nikos');
  await ownerPicker.getByRole('option', { name: /Nikos Contributor/ }).click();
  await expect(ownerPicker.locator('input[name="viswiz_access[owner]"]')).toHaveValue(String(fixture.aclSecondEditorId));

  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#publish').click(),
  ]);
  await page.goto(`/wp-admin/post.php?post=${fixture.visualizationId}&action=edit`);
  await expect(page.locator('[data-viswiz-user-picker][data-mode="owner"] input[name="viswiz_access[owner]"]')).toHaveValue(String(fixture.aclSecondEditorId));
  await expect(page.locator('[data-viswiz-user-picker][data-mode="editors"] input[name="viswiz_access[editors][]"]')).toHaveValue(String(fixture.aclEditorId));
});
