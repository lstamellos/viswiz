const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const fixturePath = process.env.VISWIZ_E2E_FIXTURE || '/tmp/viswiz-e2e-fixture.json';
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

function captureClientErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
  return errors;
}

test('public graph accessibility semantics, focus lifecycle and reduced motion', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await page.goto(`/?page_id=${fixture.pageId}`);

  const graphs = page.locator('.viswiz-visualization');
  await expect(graphs).toHaveCount(2);
  const graph = graphs.nth(0);
  await expect(graph.locator('.viswiz-graph-frame')).toBeVisible();

  const status = graph.locator('.viswiz-graph-status');
  await expect(status).toHaveAttribute('role', 'status');
  await expect(status).toHaveAttribute('aria-live', 'polite');
  await expect(status).toHaveAttribute('aria-atomic', 'true');

  const alice = graph.locator(`[data-viswiz-node-uuid="${fixture.nodeUuids.alice}"]`);
  await expect(alice).toHaveAttribute('role', 'button');
  await expect(alice).toHaveAttribute('tabindex', '0');
  await expect(alice).toHaveAttribute('aria-label', /Alice Reporter/);
  await alice.focus();
  await page.keyboard.press('Space');

  let nodeOverlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(nodeOverlay).toHaveCount(1);
  await expect(nodeOverlay).toHaveAttribute('role', 'dialog');
  await expect(nodeOverlay).toHaveAttribute('aria-modal', 'true');
  const nodeClose = nodeOverlay.locator('.viswiz-modal-close');
  await expect(nodeClose).toBeFocused();

  const journalist = nodeOverlay.locator('.viswiz-node-property-link').filter({ hasText: 'Journalist' });
  await journalist.click();
  const propertyOverlay = page.locator('body > .viswiz-property-overlay');
  await expect(propertyOverlay).toHaveCount(1);
  await expect(propertyOverlay).toHaveAttribute('role', 'dialog');
  await expect(propertyOverlay).toHaveAttribute('aria-modal', 'true');
  const propertyClose = propertyOverlay.locator('.viswiz-modal-close');
  const propertyNodes = propertyOverlay.locator('.viswiz-property-node-link');
  await expect(propertyClose).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(propertyNodes.last()).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(propertyClose).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(propertyOverlay).toHaveCount(0);
  await expect(nodeOverlay).toHaveCount(1);
  await expect(journalist).toBeFocused();

  await journalist.click();
  await expect(propertyOverlay).toHaveCount(1);
  await propertyOverlay.locator('.viswiz-property-node-link').first().click();
  await expect(propertyOverlay).toHaveCount(0);
  nodeOverlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(nodeOverlay).toHaveCount(1);
  await expect(nodeOverlay.locator('.viswiz-modal-close')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(nodeOverlay).toHaveCount(0);

  const personFacet = graph.locator('[data-viswiz-property-kind="node_type"][data-viswiz-property-value="person"]').first();
  await personFacet.focus();
  await page.keyboard.press('Space');
  await expect(personFacet).toHaveAttribute('aria-pressed', 'true');
  const selectedHost = graph.locator('.viswiz-selected-facets');
  await expect(selectedHost).toHaveAttribute('aria-live', 'polite');
  await expect(selectedHost).toHaveAttribute('aria-relevant', 'additions removals text');
  const selectedFacet = graph.locator('.viswiz-selected-facet');
  await expect(selectedFacet).toBeVisible();
  await selectedFacet.focus();
  await page.keyboard.press('Enter');
  await expect(selectedFacet).toHaveCount(0);
  await expect(graph.locator('.viswiz-clear-all-filters')).toBeFocused();

  await alice.click();
  nodeOverlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await nodeOverlay.locator('.viswiz-focus-connections').click();
  const focusBar = graph.locator('.viswiz-connection-focus-bar');
  await expect(focusBar).toBeVisible();
  await expect(focusBar).toHaveAttribute('aria-live', 'polite');
  await expect(focusBar).toHaveAttribute('aria-atomic', 'true');
  const focusClear = focusBar.locator('.viswiz-connection-focus-clear');
  await focusClear.focus();
  await page.keyboard.press('Enter');
  await expect(focusBar).toBeHidden();
  await expect(alice).toBeFocused();

  const fullscreenEnabled = await page.evaluate(() => document.fullscreenEnabled);
  if (fullscreenEnabled) {
    const fullscreen = graph.locator('.viswiz-fullscreen');
    await expect(fullscreen).toHaveAttribute('aria-pressed', 'false');
    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(true);
    await expect(fullscreen).toHaveAttribute('aria-pressed', 'true');
    await expect(graph.locator('.viswiz-fullscreen-status')).toContainText('Exit full screen');
    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(false);
    await expect(fullscreen).toHaveAttribute('aria-pressed', 'false');
    await expect(graph.locator('.viswiz-fullscreen-status')).toContainText('Full screen');
  }

  await page.emulateMedia({ reducedMotion: 'reduce' });
  const transitionDuration = await graph.locator('.viswiz-graph-node').first().evaluate((element) => getComputedStyle(element).transitionDuration);
  expect(transitionDuration).toBe('0s');
  const tagTransition = await graph.locator('.viswiz-node-card-tag-bg').first().evaluate((element) => getComputedStyle(element).transitionDuration);
  expect(tagTransition).toBe('0s');

  const ids = await page.locator('.viswiz-visualization [id]').evaluateAll((elements) => elements.map((element) => element.id).filter(Boolean));
  expect(new Set(ids).size).toBe(ids.length);
  expect(clientErrors).toEqual([]);
});
