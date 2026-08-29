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

  await expect(editor.locator('tbody tr')).toHaveCount(3);
  await expect(editor.getByDisplayValue('Alpha')).toHaveCount(1);
  await expect(editor.getByDisplayValue('Beta')).toHaveCount(1);
  await expect(editor.getByDisplayValue('Gamma')).toHaveCount(1);
  await expect(editor.getByDisplayValue('30')).toHaveCount(1);

  const alphaLabel = editor.getByDisplayValue('Alpha');
  await alphaLabel.focus();
  await alphaLabel.press('Tab');
  await expect(editor.getByDisplayValue('10')).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(alphaLabel).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(editor.getByDisplayValue('Beta')).toBeFocused();
  await page.keyboard.press('ArrowUp');
  await expect(alphaLabel).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(editor.getByDisplayValue('Beta')).toBeFocused();

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

  let betaRow = editor.getByDisplayValue('Beta').locator('xpath=ancestor::tr');
  await betaRow.getByRole('button', { name: 'Remove' }).click();
  betaRow = editor.getByDisplayValue('Beta').locator('xpath=ancestor::tr');
  await expect(betaRow).toHaveClass(/is-pending-delete/);
  await betaRow.getByRole('button', { name: 'Undo' }).click();
  betaRow = editor.getByDisplayValue('Beta').locator('xpath=ancestor::tr');
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
  await expect(editor.getByDisplayValue('Incomplete')).toHaveCount(0);
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

  const local = editor.getByDisplayValue('Original');
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
  await expect(editor.getByDisplayValue('Local draft')).toHaveCount(1);
  await expect(page.locator('[data-viswiz-dataset-search]')).toBeDisabled();
});
