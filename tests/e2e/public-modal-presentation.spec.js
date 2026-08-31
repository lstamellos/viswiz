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

test('public node modal restores legacy rich-text paragraphs and groups related nodes by relation label', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  await page.goto(`/?page_id=${fixture.pageId}`);

  const graph = page.locator('.viswiz-visualization').first();
  await expect(graph.locator('.viswiz-graph-frame')).toBeVisible();

  const alice = graph.locator(`[data-viswiz-node-uuid="${fixture.nodeUuids.alice}"]`);
  await alice.click();

  const overlay = page.locator('body > .viswiz-modal-overlay:not(.viswiz-property-overlay)');
  await expect(overlay.locator('.viswiz-node-modal h3')).toHaveText('Alice Reporter');

  const description = overlay.locator('.viswiz-node-description');
  await description.evaluate((element) => {
    element.innerHTML = 'A <strong>rich-text</strong> description for testing the WordPress editor.\n\nSecond paragraph with <em>emphasis</em> and <a href="https://example.com/alice">a test link</a>.\n<ul>\n\t<li>First list item</li>\n\t<li>Second list item</li>\n</ul>\n<strong>Phase C rich editor save test.</strong>';
  });
  await page.addStyleTag({
    content: 'html body .viswiz-modal-overlay .viswiz-node-modal .viswiz-node-description > p{display:inline!important}',
  });
  await page.evaluate(() => document.dispatchEvent(new MouseEvent('click', { bubbles: true })));

  await expect(description.locator(':scope > p')).toHaveCount(3);
  await expect(description.locator(':scope > ul')).toHaveCount(1);
  expect(await description.locator(':scope > *').evaluateAll((elements) => elements.map((element) => element.tagName))).toEqual([
    'P',
    'P',
    'UL',
    'P',
  ]);

  const paragraphs = description.locator(':scope > p');
  await expect(paragraphs.nth(0)).toContainText('A rich-text description for testing the WordPress editor.');
  await expect(paragraphs.nth(1)).toContainText('Second paragraph with emphasis and a test link.');
  await expect(paragraphs.nth(2)).toContainText('Phase C rich editor save test.');
  await expect(paragraphs.nth(0)).toHaveCSS('display', 'block');
  await expect(paragraphs.nth(1)).toHaveCSS('display', 'block');
  await expect(paragraphs.nth(2)).toHaveCSS('display', 'block');
  const paragraphTops = await paragraphs.evaluateAll((elements) => elements.map((element) => element.getBoundingClientRect().top));
  expect(paragraphTops[1]).toBeGreaterThan(paragraphTops[0]);
  expect(paragraphTops[2]).toBeGreaterThan(paragraphTops[1]);

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
