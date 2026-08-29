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

async function createDataset(page, name, schema = 'categorical') {
  await page.goto('/wp-admin/admin.php?page=viswiz-datasets');
  const card = page.locator('.viswiz-card').filter({ has: page.getByRole('heading', { name: 'Create dataset' }) });
  await card.locator('input[name="name"]').fill(name);
  await card.locator('select[name="schema_type"]').selectOption(schema);
  await Promise.all([
    page.waitForURL(/page=viswiz-datasets&dataset_id=\d+/),
    card.getByRole('button', { name: 'Create dataset' }).click(),
  ]);
  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor).toHaveAttribute('data-viswiz-spreadsheet-editor', '1');
  return { editor, id: Number(new URL(page.url()).searchParams.get('dataset_id')) };
}

async function datasetPayload(page, datasetId) {
  return page.evaluate(async (id) => {
    const cfg = window.VisWizAdminV2;
    const response = await fetch(`${cfg.restUrl}/datasets/${id}`, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce || '' },
    });
    return response.json();
  }, datasetId);
}

async function dispatchPaste(locator, text) {
  await locator.evaluate((element, value) => {
    const transfer = new DataTransfer();
    transfer.setData('text/plain', value);
    element.dispatchEvent(new ClipboardEvent('paste', {
      bubbles: true,
      cancelable: true,
      clipboardData: transfer,
    }));
  }, text);
}

test('spreadsheet grid supports batch paste, keyboard movement, one revision bump and pending delete undo', async ({ page }) => {
  await login(page);
  const { editor, id } = await createDataset(page, 'E2E spreadsheet paste');
  const startRevision = await page.evaluate(() => Number(document.querySelector('#viswiz-dataset-editor').dataset.revision));

  await editor.getByRole('button', { name: 'Add item' }).click();
  const firstLabel = editor.locator('[data-grid-index="0"][data-grid-col="0"]');
  await dispatchPaste(firstLabel, 'Alpha\t10\t#112233\nBeta\t20\t#445566\nGamma\t30\t#778899');

  const rows = editor.locator('tbody tr');
  await expect(rows).toHaveCount(3);
  const alphaLabel = rows.nth(0).locator('[data-field-path="label"]');
  const alphaValue = rows.nth(0).locator('[data-field-path="value"]');
  const betaLabel = rows.nth(1).locator('[data-field-path="label"]');
  const gammaLabel = rows.nth(2).locator('[data-field-path="label"]');
  const gammaValue = rows.nth(2).locator('[data-field-path="value"]');
  await expect(alphaLabel).toHaveValue('Alpha');
  await expect(betaLabel).toHaveValue('Beta');
  await expect(gammaLabel).toHaveValue('Gamma');
  await expect(gammaValue).toHaveValue('30');

  await alphaLabel.focus();
  await alphaLabel.press('Tab');
  await expect(alphaValue).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(alphaLabel).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(betaLabel).toBeFocused();
  await page.keyboard.press('ArrowUp');
  await expect(alphaLabel).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(betaLabel).toBeFocused();

  await expect(editor.getByRole('button', { name: 'Save changes' })).toBeEnabled();
  await editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(editor.locator('[data-viswiz-grid-state]')).toContainText('All changes saved');
  const savedRevision = await page.evaluate(() => Number(document.querySelector('#viswiz-dataset-editor').dataset.revision));
  expect(savedRevision).toBe(startRevision + 1);

  let data = await datasetPayload(page, id);
  expect(data.payload.rows).toHaveLength(3);
  expect(data.payload.rows.map((row) => [row.label, row.value, row.color])).toEqual([
    ['Alpha', 10, '#112233'],
    ['Beta', 20, '#445566'],
    ['Gamma', 30, '#778899'],
  ]);

  let betaRow = rows.nth(1);
  await betaRow.getByRole('button', { name: 'Remove' }).click();
  await expect(betaRow).toHaveClass(/is-pending-delete/);
  await betaRow.getByRole('button', { name: 'Undo' }).click();
  betaRow = rows.nth(1);
  await expect(betaRow).not.toHaveClass(/is-pending-delete/);
  await betaRow.getByRole('button', { name: 'Remove' }).click();
  await editor.getByRole('button', { name: 'Save changes' }).click();
  data = await datasetPayload(page, id);
  expect(data.payload.rows.map((row) => row.label)).toEqual(['Alpha', 'Gamma']);

  await editor.getByRole('button', { name: 'Add item' }).click();
  const newRow = editor.locator('tbody tr').last();
  await expect(newRow.locator('.viswiz-grid-cell-error')).toHaveCount(2);
  await expect(editor.getByRole('button', { name: 'Save changes' })).toBeDisabled();
  await newRow.locator('[data-field-path="label"]').fill('Incomplete');
  await expect(newRow.locator('.viswiz-grid-cell-error')).toHaveCount(1);
  await editor.getByRole('button', { name: 'Discard changes' }).click();
  await expect(editor.locator('tbody tr')).toHaveCount(2);
  await expect(editor.locator('tbody tr').nth(0).locator('[data-field-path="label"]')).toHaveValue('Alpha');
  await expect(editor.locator('tbody tr').nth(1).locator('[data-field-path="label"]')).toHaveValue('Gamma');
});

test('spreadsheet keeps local drafts visible when a server revision conflict occurs', async ({ page }) => {
  await login(page);
  const { editor, id } = await createDataset(page, 'E2E spreadsheet conflict');

  await editor.getByRole('button', { name: 'Add item' }).click();
  let row = editor.locator('tbody tr').last();
  await row.locator('[data-field-path="label"]').fill('Original');
  await row.locator('[data-field-path="value"]').fill('5');
  await editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(editor.locator('[data-viswiz-grid-state]')).toContainText('All changes saved');

  row = editor.locator('tbody tr').first();
  const local = row.locator('[data-field-path="label"]');
  await expect(local).toHaveValue('Original');
  await local.fill('Local draft');
  const external = await page.evaluate(async (datasetId) => {
    const cfg = window.VisWizAdminV2;
    const datasetResponse = await fetch(`${cfg.restUrl}/datasets/${datasetId}`, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce || '' },
    });
    const dataset = await datasetResponse.json();
    const row = dataset.payload.rows[0];
    const revision = Number(document.querySelector('#viswiz-dataset-editor').dataset.revision);
    const response = await fetch(`${cfg.restUrl}/datasets/${datasetId}/editor/rows`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: JSON.stringify({ expected_revision: revision, row: { ...row, label: 'Server update' } }),
    });
    return { status: response.status, body: await response.json() };
  }, id);
  expect(external.status).toBe(200);

  await editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(editor.locator('[data-viswiz-grid-state]')).toContainText('Conflict');
  await expect(editor.getByRole('button', { name: 'Reload server version' })).toBeVisible();
  await expect(local).toHaveValue('Local draft');
  await expect(page.locator('[data-viswiz-dataset-search]')).toBeDisabled();
});
