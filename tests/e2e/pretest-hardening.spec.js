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

async function createDataset(page, name, schema) {
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

async function restPost(page, path, body) {
  return page.evaluate(async ({ path, body }) => {
    const cfg = window.VisWizAdminV2;
    const response = await fetch(`${cfg.restUrl}${path}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: JSON.stringify(body),
    });
    return { status: response.status, body: await response.json() };
  }, { path, body });
}

test('legacy row and raw replacement writes enforce the dataset schema', async ({ page }) => {
  await login(page);
  const { id } = await createDataset(page, 'E2E canonical row guard', 'geo');
  const revision = await page.evaluate(() => Number(document.querySelector('#viswiz-dataset-editor').dataset.revision));

  const legacy = await restPost(page, `/datasets/${id}/rows`, {
    expected_revision: revision,
    row: { label: 'Invalid legacy row', value: 10 },
  });
  expect(legacy.status).toBe(422);
  expect(legacy.body.code).toBe('viswiz_invalid_row_schema');
  expect(legacy.body.data.field).toBe('latitude');

  const replacement = await restPost(page, `/datasets/${id}`, {
    expected_revision: revision,
    payload: { rows: [{ label: 'Invalid replacement row', value: 10 }] },
  });
  expect(replacement.status).toBe(422);
  expect(replacement.body.code).toBe('viswiz_row_payload_validation');
  expect(replacement.body.data.issues[0].field).toBe('latitude');
});

test('legacy and replacement writes preserve supported row aliases', async ({ page }) => {
  await login(page);

  const geo = await createDataset(page, 'E2E geo aliases', 'geo');
  let revision = await page.evaluate(() => Number(document.querySelector('#viswiz-dataset-editor').dataset.revision));
  const legacyGeo = await restPost(page, `/datasets/${geo.id}/rows`, {
    expected_revision: revision,
    row: { label: 'Athens', lat: 37.9838, lng: 23.7275 },
  });
  expect(legacyGeo.status).toBe(200);

  const xy = await createDataset(page, 'E2E xy aliases', 'xy');
  revision = await page.evaluate(() => Number(document.querySelector('#viswiz-dataset-editor').dataset.revision));
  const replacementXy = await restPost(page, `/datasets/${xy.id}`, {
    expected_revision: revision,
    payload: { rows: [{ label: 'Point A', x: 12.5, y: 42.75 }] },
  });
  expect(replacementXy.status).toBe(200);
});

test('dirty spreadsheet drafts block side mutations until save or discard', async ({ page }) => {
  await login(page);
  const { editor } = await createDataset(page, 'E2E dirty mutation guard', 'categorical');

  await editor.getByRole('button', { name: 'Add item' }).click();
  const row = editor.locator('tbody tr').last();
  await row.locator('[data-field-path="label"]').fill('Draft');
  await row.locator('[data-field-path="value"]').fill('5');

  await expect(page.locator('[data-viswiz-import-button]')).toBeDisabled();
  await expect(page.locator('[data-viswiz-commerce-snapshot]')).toBeDisabled();
  const metadataSubmit = page.locator('form').filter({ has: page.locator('input[name="action"][value="viswiz_dataset_update"]') }).getByRole('button', { name: 'Save metadata' });
  await expect(metadataSubmit).toBeDisabled();

  await editor.getByRole('button', { name: 'Discard changes' }).click();
  await expect(page.locator('[data-viswiz-import-button]')).toBeEnabled();
  await expect(page.locator('[data-viswiz-commerce-snapshot]')).toBeEnabled();
  await expect(metadataSubmit).toBeEnabled();
});

test('generic batch save failures are visible in the spreadsheet editor', async ({ page }) => {
  await login(page);
  const { editor, id } = await createDataset(page, 'E2E visible server error', 'categorical');

  await editor.getByRole('button', { name: 'Add item' }).click();
  const row = editor.locator('tbody tr').last();
  await row.locator('[data-field-path="label"]').fill('Will fail');
  await row.locator('[data-field-path="value"]').fill('9');

  await page.route(`**/wp-json/viswiz/v2/datasets/${id}/editor/rows/batch`, async (route) => {
    await route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ code: 'viswiz_test_failure', message: 'Simulated server failure', data: { status: 500 } }),
    });
  });

  await editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(editor.locator('[data-viswiz-spreadsheet-server-error]')).toContainText('Simulated server failure');
  await expect(row.locator('.viswiz-grid-input[data-field-path="label"]')).toHaveValue('Will fail');
});
