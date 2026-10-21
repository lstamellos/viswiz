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

async function createGraphDataset(page, name) {
  await page.goto('/wp-admin/admin.php?page=viswiz-datasets');
  const card = page.locator('.viswiz-card').filter({
    has: page.getByRole('heading', { name: 'Create dataset' }),
  });
  await card.locator('input[name="name"]').fill(name);
  await card.locator('select[name="schema_type"]').selectOption('graph');
  await Promise.all([
    page.waitForURL(/page=viswiz-datasets&dataset_id=\d+/),
    card.getByRole('button', { name: 'Create dataset' }).click(),
  ]);
  return page.locator('#viswiz-dataset-editor');
}

async function mediaOpened(page, dialogs) {
  const modal = page.locator('.media-modal:visible').last();
  await expect(modal).toBeVisible();
  for (const dialog of dialogs) {
    await expect(dialog).toHaveAttribute('data-viswiz-media-handoff', '1');
    await expect(dialog).toHaveCSS('visibility', 'hidden');
    await expect.poll(() => dialog.evaluate((element) => element.matches(':modal'))).toBe(false);
  }
  // Assert the actual WordPress media frame receives pointer events.
  expect(await modal.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const above = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    return element.contains(above);
  })).toBe(true);
  return modal;
}

async function mediaClosed(page, dialogs) {
  await expect(page.locator('.media-modal:visible')).toHaveCount(0);
  for (const dialog of dialogs) {
    await expect(dialog).toBeVisible();
    await expect.poll(() => dialog.evaluate((element) => element.matches(':modal'))).toBe(true);
  }
}

test('node featured and gallery images use clickable WordPress media library and retain the draft editor', async ({ page }) => {
  const clientErrors = [];
  page.on('pageerror', (error) => clientErrors.push(error.message));
  await login(page);
  // Own dataset: saving a node must not change the shared public graph fixture
  // used by the rest of the browser regression suite.
  const editor = await createGraphDataset(page, 'E2E media picker isolated graph');
  await editor.getByRole('button', { name: 'Add node' }).click();

  const dialog = page.locator('dialog.viswiz-editor-dialog').last();
  await expect(dialog).toBeVisible();
  await dialog.locator('[name="title"]').fill('E2E image selection node');
  await dialog.locator('[name="slug"]').fill('e2e-image-selection-node');
  await dialog.locator('[name="node_type"]').selectOption('person');
  await expect(dialog).toHaveAttribute('data-viswiz-rich-editor-state', 'ready');
  const richEditorId = await dialog.locator('textarea[name="description"]').getAttribute('id');

  await dialog.getByRole('button', { name: 'Choose featured image' }).click();
  let modal = await mediaOpened(page, [dialog]);
  await modal.getByRole('tab', { name: 'Media Library' }).click();
  const image = modal.locator(`.attachment[data-id="${fixture.mediaAttachmentId}"]`);
  await expect(image).toBeVisible();
  await image.click();
  await modal.locator('.media-button-select').click();
  await mediaClosed(page, [dialog]);
  await expect(dialog.locator('[name="main_image_id"]')).toHaveValue(String(fixture.mediaAttachmentId));

  await dialog.getByRole('button', { name: 'Choose other images' }).click();
  modal = await mediaOpened(page, [dialog]);
  await modal.getByRole('tab', { name: 'Media Library' }).click();
  await modal.locator(`.attachment[data-id="${fixture.mediaAttachmentId}"]`).click();
  await modal.locator('.media-button-select').click();
  await mediaClosed(page, [dialog]);
  await expect(dialog.locator('[name="other_image_ids"]')).toHaveValue(String(fixture.mediaAttachmentId));

  // Canceling the picker must also restore the native dialog without cleaning TinyMCE.
  await dialog.getByRole('button', { name: 'Choose featured image' }).click();
  modal = await mediaOpened(page, [dialog]);
  await modal.locator('.media-modal-close').click();
  await mediaClosed(page, [dialog]);
  expect(await page.evaluate((id) => Boolean(window.tinymce?.get(id)), richEditorId)).toBe(true);

  await expect(dialog.locator('[name="title"]')).toHaveValue('E2E image selection node');
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).toHaveCount(0);
  await expect(editor.locator('table').first().locator('tbody tr').filter({ hasText: 'E2E image selection node' })).toHaveCount(1);
  expect(clientErrors).toEqual([]);
});

test('quick-create node image picker restores both nested editor dialogs', async ({ page }) => {
  await login(page);
  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.graphDatasetId}`);
  const editor = page.locator('#viswiz-dataset-editor');
  await editor.locator('.viswiz-editor-toolbar').getByRole('button', { name: 'Add relation', exact: true }).click();
  const relation = page.locator('dialog.viswiz-editor-dialog').filter({ has: page.locator('.viswiz-dialog-heading h2', { hasText: 'Add relation' }) });
  await expect(relation).toBeVisible();
  await relation.getByRole('button', { name: 'Create to node' }).click();
  const node = page.locator('dialog.viswiz-editor-dialog').filter({ has: page.locator('.viswiz-dialog-heading h2', { hasText: 'Create to node' }) });
  await expect(node).toBeVisible();
  await expect(relation).toBeVisible();

  await node.getByRole('button', { name: 'Choose featured image' }).click();
  const modal = await mediaOpened(page, [relation, node]);
  await modal.locator('.media-modal-close').click();
  await mediaClosed(page, [relation, node]);

  await node.getByRole('button', { name: 'Close' }).click();
  await expect(node).toHaveCount(0);
  await expect(relation).toBeVisible();
  await relation.getByRole('button', { name: 'Close' }).click();
  await expect(relation).toHaveCount(0);
});
