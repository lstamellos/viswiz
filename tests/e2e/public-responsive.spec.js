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

async function expectContained(inner, outer, tolerance = 1) {
  const innerBox = await inner.boundingBox();
  const outerBox = await outer.boundingBox();
  expect(innerBox).not.toBeNull();
  expect(outerBox).not.toBeNull();
  expect(innerBox.x).toBeGreaterThanOrEqual(outerBox.x - tolerance);
  expect(innerBox.x + innerBox.width).toBeLessThanOrEqual(outerBox.x + outerBox.width + tolerance);
}

test('public graph remains contained and usable at a narrow mobile viewport', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/?page_id=${fixture.pageId}`);

  const graph = page.locator('.viswiz-visualization').first();
  const toolbar = graph.locator('.viswiz-graph-toolbar');
  await expect(graph.locator('.viswiz-graph-frame')).toBeVisible();
  await expect(toolbar).toBeVisible();

  const pageMetrics = await page.evaluate(() => ({
    viewportWidth: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(pageMetrics.scrollWidth).toBeLessThanOrEqual(pageMetrics.viewportWidth);

  for (const control of await toolbar.locator('input, select, button').all()) {
    if (await control.isVisible()) await expectContained(control, graph);
  }

  const alice = graph.locator(`[data-viswiz-node-uuid="${fixture.nodeUuids.alice}"]`);
  await alice.click();
  const overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  const modal = overlay.locator('.viswiz-node-modal');
  await expect(modal).toBeVisible();

  // A host theme may reset portaled dialog descendants to content-box. VisWiz
  // must retain its own modal sizing because the overlay intentionally provides
  // a mobile gutter.
  await page.addStyleTag({
    content: 'body > .viswiz-modal-overlay .viswiz-node-modal{box-sizing:content-box}',
  });
  await page.evaluate(() => document.dispatchEvent(new MouseEvent('click', { bubbles: true })));

  await expect(modal).toHaveCSS('box-sizing', 'border-box');
  const modalBox = await modal.boundingBox();
  expect(modalBox).not.toBeNull();
  expect(modalBox.x).toBeGreaterThanOrEqual(16);
  expect(modalBox.x + modalBox.width).toBeLessThanOrEqual(390 - 16);
  expect(modalBox.y).toBeGreaterThanOrEqual(0);
  expect(modalBox.y + modalBox.height).toBeLessThanOrEqual(844);

  const wrappedRelated = overlay.locator('.viswiz-related-node-link').first();
  await wrappedRelated.evaluate((element) => {
    element.textContent = 'Phase C Organization With An Intentionally Very Long Complete Node Title For Mobile Wrapping Verification';
  });
  await expect(wrappedRelated).toHaveCSS('text-align', 'left');
  const lineHeight = Number.parseFloat(await wrappedRelated.evaluate((element) => getComputedStyle(element).lineHeight));
  const wrappedBox = await wrappedRelated.boundingBox();
  expect(wrappedBox).not.toBeNull();
  expect(wrappedBox.height).toBeGreaterThan(lineHeight * 1.5);
  await expectContained(wrappedRelated, modal);

  await overlay.locator('.viswiz-modal-close').click();
  await expect(overlay).toHaveCount(0);
  expect(clientErrors).toEqual([]);
});
