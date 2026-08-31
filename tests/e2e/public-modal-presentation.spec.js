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

test('public node modal preserves rich-text blocks and groups related nodes by relation label', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await page.goto(`/?page_id=${fixture.pageId}`);

  const graph = page.locator('.viswiz-visualization').first();
  await expect(graph.locator('.viswiz-graph-frame')).toBeVisible();

  const alice = graph.locator(`[data-viswiz-node-uuid="${fixture.nodeUuids.alice}"]`);
  await alice.click();

  const overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(overlay.locator('.viswiz-node-modal h3')).toHaveText('Alice Reporter');

  await page.addStyleTag({
    content: '.viswiz-node-description>p{display:inline!important}',
  });
  await expect(overlay.locator('.viswiz-node-description > p')).toHaveCSS('display', 'block');

  const groups = overlay.locator('.viswiz-related-list > .viswiz-related-group');
  await expect(groups).toHaveCount(2);
  await expect(groups.locator('.viswiz-related-group-label')).toHaveText([
    'Member of',
    'Participated in',
  ]);
  await expect(groups.nth(0).locator('.viswiz-related-node-link')).toHaveText(['Organization Alpha']);
  await expect(groups.nth(1).locator('.viswiz-related-node-link')).toHaveText(['Event Gamma']);

  await groups.nth(0).locator('.viswiz-related-node-link').click();
  const nextOverlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(nextOverlay.locator('.viswiz-node-modal h3')).toHaveText('Organization Alpha');

  expect(clientErrors).toEqual([]);
});
