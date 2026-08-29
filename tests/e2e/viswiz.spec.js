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

async function createDataset(page, name, schema = 'categorical') {
  await page.goto('/wp-admin/admin.php?page=viswiz-datasets');
  const card = page.locator('.viswiz-card').filter({ has: page.getByRole('heading', { name: 'Create dataset' }) });
  await card.locator('input[name="name"]').fill(name);
  await card.locator('select[name="schema_type"]').selectOption(schema);
  await Promise.all([
    page.waitForURL(/page=viswiz-datasets&dataset_id=\d+/),
    card.getByRole('button', { name: 'Create dataset' }).click(),
  ]);
  await expect(page.locator('#viswiz-dataset-editor')).toBeVisible();
}

function captureClientErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
  return errors;
}

async function acceptNextDialog(page) {
  return new Promise((resolve) => {
    page.once('dialog', async (dialog) => {
      await dialog.accept();
      resolve();
    });
  });
}

async function chooseLazyNode(dialog, side, searchText) {
  const search = dialog.getByLabel(`${side} node search`, { exact: true });
  const select = dialog.getByLabel(`${side} node`, { exact: true });
  await search.fill(searchText);
  await expect(select.locator('option')).toHaveCount(1);
  await expect(select.locator('option')).toContainText(searchText);
  await select.selectOption({ index: 0 });
}

test('categorical spreadsheet creates, edits and removes an item through the browser', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  await createDataset(page, 'E2E browser categorical');

  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor).toBeVisible();
  await expect(editor).toHaveAttribute('data-viswiz-spreadsheet-editor', '1');
  const table = editor.locator('table').first();
  await expect(table.locator('tbody tr')).toHaveCount(0);
  await expect(table.locator('thead')).toContainText('Label');
  await expect(table.locator('thead')).toContainText('Value');
  await expect(table.locator('thead')).toContainText('Color');
  await expect(table.locator('thead')).not.toContainText('Latitude');

  const addItem = editor.getByRole('button', { name: 'Add item' });
  await addItem.click();
  await expect(table.locator('tbody tr')).toHaveCount(1);
  let browserRow = table.locator('tbody tr').last();
  let browserLabel = browserRow.locator('[data-field-path="label"]');
  const value = browserRow.locator('[data-field-path="value"]');
  await browserLabel.fill('Browser row');
  await browserLabel.press('Tab');
  await expect(value).toBeFocused();
  await value.fill('37.5');
  await expect(editor.locator('[data-viswiz-grid-state]')).toContainText('1 unsaved change');
  await editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(editor.locator('[data-viswiz-grid-state]')).toContainText('All changes saved');
  browserRow = table.locator('tbody tr').last();
  browserLabel = browserRow.locator('[data-field-path="label"]');
  await expect(browserLabel).toHaveValue('Browser row');

  await browserLabel.fill('Browser row updated');
  await editor.getByRole('button', { name: 'Save changes' }).click();
  browserRow = table.locator('tbody tr').last();
  browserLabel = browserRow.locator('[data-field-path="label"]');
  await expect(browserLabel).toHaveValue('Browser row updated');

  await browserRow.getByRole('button', { name: 'Remove' }).click();
  await expect(browserRow).toHaveClass(/is-pending-delete/);
  await expect(browserRow.getByRole('button', { name: 'Undo' })).toBeVisible();
  await browserRow.getByRole('button', { name: 'Undo' }).click();
  browserRow = table.locator('tbody tr').last();
  await expect(browserRow).not.toHaveClass(/is-pending-delete/);
  await browserRow.getByRole('button', { name: 'Remove' }).click();
  await editor.getByRole('button', { name: 'Save changes' }).click();
  await expect(table.locator('tbody tr')).toHaveCount(0);

  await addItem.click();
  await expect(table.locator('tbody tr')).toHaveCount(1);
  await editor.getByRole('button', { name: 'Discard changes' }).click();
  await expect(table.locator('tbody tr')).toHaveCount(0);
  expect(clientErrors).toEqual([]);
});

test('graph editor creates, edits and deletes nodes and relations with lazy endpoints', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await login(page);
  await page.goto(`/wp-admin/admin.php?page=viswiz-datasets&dataset_id=${fixture.graphDatasetId}`);

  const editor = page.locator('#viswiz-dataset-editor');
  await expect(editor).toBeVisible();
  await expect(page.locator('[data-viswiz-inline-spec]')).toHaveCount(0);
  await expect(page.locator('#viswiz-dataset-payload')).toHaveCount(0);

  const nodeTable = editor.locator('table').nth(0);
  const relationTable = editor.locator('table').nth(1);
  await expect(nodeTable.locator('tbody tr')).toHaveCount(fixture.counts.nodes);
  await expect(relationTable.locator('tbody tr')).toHaveCount(fixture.counts.relations);

  const addNode = editor.getByRole('button', { name: 'Add node' });
  await addNode.click();
  let dialog = page.locator('dialog.viswiz-editor-dialog');
  await expect(dialog.getByRole('heading', { name: 'Add node' })).toBeVisible();
  await dialog.locator('[name="title"]').fill('Delta Reporter');
  await dialog.locator('[name="slug"]').fill('delta-reporter');
  await dialog.locator('[name="label"]').fill('Delta');
  await dialog.locator('[name="node_type"]').selectOption('person');
  await dialog.locator('[name="node_subtype"]').selectOption('journalist');
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).toHaveCount(0);
  await expect(nodeTable.locator('tbody tr')).toHaveCount(fixture.counts.nodes + 1);

  let deltaRow = nodeTable.locator('tbody tr').filter({ hasText: 'Delta Reporter' });
  await deltaRow.getByRole('button', { name: 'Edit' }).click();
  dialog = page.locator('dialog.viswiz-editor-dialog');
  await expect(dialog.getByRole('heading', { name: 'Edit node' })).toBeVisible();
  await dialog.locator('[name="title"]').fill('Delta Reporter Updated');
  await dialog.getByRole('button', { name: 'Save node' }).click();
  await expect(dialog).toHaveCount(0);
  await expect(nodeTable.locator('tbody tr').filter({ hasText: 'Delta Reporter Updated' })).toHaveCount(1);

  await editor.getByRole('button', { name: 'Add relation' }).click();
  dialog = page.locator('dialog.viswiz-editor-dialog');
  await expect(dialog.getByRole('heading', { name: 'Add relation' })).toBeVisible();
  await chooseLazyNode(dialog, 'From', 'Delta Reporter Updated');
  await chooseLazyNode(dialog, 'To', 'Organization Alpha');
  await dialog.locator('[name="relation_type"]').selectOption('connected_to');
  await dialog.getByRole('button', { name: 'Save relation' }).focus();
  await page.keyboard.press('Enter');
  await expect(dialog).toHaveCount(0);
  await expect(relationTable.locator('tbody tr')).toHaveCount(fixture.counts.relations + 1);
  let relationRow = relationTable.locator('tbody tr').filter({ hasText: 'Delta Reporter Updated' }).filter({ hasText: 'Organization Alpha' });
  await expect(relationRow).toHaveCount(1);

  let confirm = acceptNextDialog(page);
  await relationRow.getByRole('button', { name: 'Delete' }).click();
  await confirm;
  await expect(relationTable.locator('tbody tr')).toHaveCount(fixture.counts.relations);

  deltaRow = nodeTable.locator('tbody tr').filter({ hasText: 'Delta Reporter Updated' });
  confirm = acceptNextDialog(page);
  await deltaRow.getByRole('button', { name: 'Delete' }).click();
  await confirm;
  await expect(nodeTable.locator('tbody tr')).toHaveCount(fixture.counts.nodes);
  await expect(page.locator('dialog.viswiz-editor-dialog')).toHaveCount(0);

  await addNode.click();
  dialog = page.locator('dialog.viswiz-editor-dialog');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(addNode).toBeFocused();
  expect(clientErrors).toEqual([]);
});

test('public graph supports filtering, modal navigation, focus, zoom and late rendering', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await page.goto(`/?page_id=${fixture.pageId}`);

  const visualizations = page.locator('.viswiz-visualization');
  await expect(visualizations).toHaveCount(2);
  await expect(visualizations.nth(0).locator('.viswiz-graph-frame')).toBeVisible();
  await expect(visualizations.nth(1).locator('.viswiz-graph-frame')).toBeVisible();

  const graph = visualizations.nth(0);
  const toolbar = graph.locator('.viswiz-graph-toolbar');
  const status = graph.locator('.viswiz-graph-status');
  const search = toolbar.locator('input[type="search"]');
  await expect(status).toContainText(`${fixture.counts.nodes}/${fixture.counts.nodes}`);
  await expect(status).toContainText(`${fixture.counts.relations}/${fixture.counts.relations}`);

  await search.fill('Alice');
  await expect(status).toContainText(`1/${fixture.counts.nodes}`);
  await toolbar.locator('.viswiz-clear-search').click();
  await expect(search).toHaveValue('');
  await expect(status).toContainText(`${fixture.counts.nodes}/${fixture.counts.nodes}`);

  const selects = toolbar.locator('select');
  await selects.nth(0).selectOption('organization');
  await expect(status).toContainText(`2/${fixture.counts.nodes}`);
  await toolbar.locator('.viswiz-clear-all-filters').click();
  await expect(selects.nth(0)).toHaveValue('');

  const personFacet = graph.locator('[data-viswiz-property-kind="node_type"][data-viswiz-property-value="person"]').first();
  await personFacet.click();
  await expect(graph.locator('.viswiz-selected-facet')).toContainText('Person');
  await expect(graph.locator(`[data-viswiz-node-uuid="${fixture.nodeUuids.organization}"]`)).toHaveCSS('opacity', '0.18');
  await graph.locator('.viswiz-selected-facet').click();
  await expect(graph.locator('.viswiz-selected-facet')).toHaveCount(0);

  await search.fill('Alice');
  const alice = graph.locator(`[data-viswiz-node-uuid="${fixture.nodeUuids.alice}"]`);
  await alice.click();
  let overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(overlay.locator('.viswiz-node-modal h3')).toHaveText('Alice Reporter');
  await expect(overlay.locator('.viswiz-node-description strong')).toHaveText('investigates');
  await expect(overlay.locator('.viswiz-related-node-link').filter({ hasText: 'Organization Alpha' })).toBeVisible();
  await overlay.locator('.viswiz-related-node-link').filter({ hasText: 'Organization Alpha' }).click();
  overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(overlay.locator('.viswiz-node-modal h3')).toHaveText('Organization Alpha');
  await expect(search).toHaveValue('Alice');
  await overlay.locator('.viswiz-modal-close').click();
  await expect(overlay).toHaveCount(0);
  await toolbar.locator('.viswiz-clear-search').click();

  await alice.click();
  overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(overlay.locator('.viswiz-node-property-link').filter({ hasText: 'Journalist' })).toBeVisible();
  await overlay.locator('.viswiz-node-property-link').filter({ hasText: 'Journalist' }).click();
  const propertyOverlay = page.locator('body > .viswiz-property-overlay');
  await expect(propertyOverlay.locator('h3')).toHaveText('Journalist');
  await expect(propertyOverlay.locator('.viswiz-property-node-list')).toContainText('Alice Reporter');
  await propertyOverlay.locator('.viswiz-modal-close').click();
  await expect(propertyOverlay).toHaveCount(0);
  await overlay.locator('.viswiz-modal-close').click();

  await alice.click();
  overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await overlay.locator('.viswiz-focus-connections').click();
  const focusBar = graph.locator('.viswiz-connection-focus-bar');
  await expect(focusBar).toBeVisible();
  await expect(focusBar.locator('.viswiz-connection-focus-name')).toContainText('Alice Reporter');
  await expect(graph.locator('.is-viswiz-connection-outside')).toHaveCount(2);
  await focusBar.locator('[data-hops="2"]').click();
  await expect(graph.locator('.is-viswiz-connection-outside')).toHaveCount(0);
  await focusBar.locator('.viswiz-connection-focus-clear').click();
  await expect(graph).not.toHaveClass(/has-viswiz-connection-focus/);

  await selects.nth(1).selectOption('member_of');
  await alice.click();
  overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await overlay.locator('.viswiz-focus-connections').click();
  await expect(graph.locator('.is-viswiz-connection-outside')).toHaveCount(3);
  await focusBar.locator('.viswiz-connection-focus-clear').click();
  await toolbar.locator('.viswiz-clear-all-filters').click();

  const svg = graph.locator('.viswiz-graph-svg');
  const initialViewBox = await svg.getAttribute('viewBox');
  await graph.getByRole('button', { name: 'Zoom in' }).click();
  await expect.poll(() => svg.getAttribute('viewBox')).not.toBe(initialViewBox);
  const box = await svg.boundingBox();
  expect(box).not.toBeNull();
  await page.mouse.move(box.x + box.width - 24, box.y + box.height - 24);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width - 74, box.y + box.height - 64, { steps: 4 });
  await page.mouse.up();
  await graph.getByRole('button', { name: 'Reset zoom' }).click();
  await expect.poll(() => svg.getAttribute('viewBox')).toBe(initialViewBox);

  const fullscreenEnabled = await page.evaluate(() => document.fullscreenEnabled);
  if (fullscreenEnabled) {
    const fullscreen = graph.locator('.viswiz-fullscreen');
    await expect(fullscreen).toBeVisible();
    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(true);
    await expect(fullscreen).toHaveText('Exit full screen');
    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(false);
    await expect(fullscreen).toHaveText('Full screen');
  }

  const endpoint = await graph.getAttribute('data-viswiz-endpoint');
  await page.evaluate((url) => {
    const container = document.createElement('div');
    container.className = 'viswiz-visualization';
    container.dataset.viswizVisualization = 'dynamic-e2e';
    container.dataset.viswizEndpoint = url;
    container.dataset.e2eDynamic = '1';
    container.innerHTML = '<div class="viswiz-loading">Loading visualization…</div>';
    document.body.appendChild(container);
  }, endpoint);
  const dynamic = page.locator('[data-e2e-dynamic="1"]');
  await dynamic.scrollIntoViewIfNeeded();
  await expect(dynamic.locator('.viswiz-graph-frame')).toBeVisible();
  await expect(dynamic.locator('.viswiz-graph-node')).toHaveCount(fixture.counts.nodes);

  expect(clientErrors).toEqual([]);
});
