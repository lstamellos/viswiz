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

test('serialized Gutenberg VisWiz blocks render independently in a constrained mobile group', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  const viewportWidth = 390;
  const viewportHeight = 844;
  await page.setViewportSize({ width: viewportWidth, height: viewportHeight });
  await page.goto(`/?page_id=${fixture.blockPageId}`);

  const group = page.locator('.wp-block-group').filter({
    has: page.getByRole('heading', { name: 'VisWiz Gutenberg block fixture' }),
  }).first();
  await expect(group).toBeVisible();

  // Simulate a narrow Gutenberg/theme content column while preserving the
  // actual server-rendered core/group and viswiz/visualization block output.
  await group.evaluate((element) => {
    element.style.setProperty('width', '300px', 'important');
    element.style.setProperty('max-width', 'calc(100vw - 32px)', 'important');
    element.style.setProperty('margin-left', 'auto', 'important');
    element.style.setProperty('margin-right', 'auto', 'important');
  });

  const visualizations = group.locator('.viswiz-visualization[data-viswiz-visualization]');
  await expect(visualizations).toHaveCount(2);
  for (const instance of await visualizations.all()) {
    await expect(instance).toHaveAttribute('data-viswiz-visualization', String(fixture.visualizationId));
    await expect(instance).toHaveAttribute(
      'data-viswiz-endpoint',
      new RegExp(`/wp-json/viswiz/v2/visualizations/${fixture.visualizationId}$`)
    );
    await expect(instance.locator('.viswiz-graph-frame')).toBeVisible();
    await expectContained(instance, group);
    await expectContained(instance.locator('.viswiz-graph-svg'), instance);
  }

  const first = visualizations.nth(0);
  const second = visualizations.nth(1);
  const firstToolbar = first.locator('.viswiz-graph-toolbar');
  const secondToolbar = second.locator('.viswiz-graph-toolbar');
  const firstStatus = first.locator('.viswiz-graph-status');
  const secondStatus = second.locator('.viswiz-graph-status');
  const firstSearch = firstToolbar.locator('input[type="search"]');
  const secondSearch = secondToolbar.locator('input[type="search"]');

  await expect(firstStatus).toContainText(`${fixture.counts.nodes}/${fixture.counts.nodes}`);
  await expect(secondStatus).toContainText(`${fixture.counts.nodes}/${fixture.counts.nodes}`);
  await expect(firstSearch).toHaveValue('');
  await expect(secondSearch).toHaveValue('');

  // Two blocks referencing the same saved visualization must keep independent
  // runtime state rather than sharing search/filter state through the endpoint.
  await firstSearch.fill('Alice');
  await expect(firstStatus).toContainText(`1/${fixture.counts.nodes}`);
  await expect(secondStatus).toContainText(`${fixture.counts.nodes}/${fixture.counts.nodes}`);
  await expect(secondSearch).toHaveValue('');

  await firstToolbar.locator('.viswiz-clear-search').click();
  await expect(firstSearch).toHaveValue('');
  await expect(firstStatus).toContainText(`${fixture.counts.nodes}/${fixture.counts.nodes}`);

  for (const toolbar of [firstToolbar, secondToolbar]) {
    for (const control of await toolbar.locator('input, select, button').all()) {
      if (await control.isVisible()) await expectContained(control, group);
    }
  }

  const overflowMetrics = await page.evaluate((mobileWidth) => {
    const root = document.documentElement;
    const visualizations = [...document.querySelectorAll('.wp-block-group .viswiz-visualization')];
    const withVisWiz = root.scrollWidth;
    const previous = visualizations.map((element) => element.style.getPropertyValue('display'));
    visualizations.forEach((element) => element.style.setProperty('display', 'none', 'important'));
    const withoutVisWiz = root.scrollWidth;
    visualizations.forEach((element, index) => {
      if (previous[index]) element.style.setProperty('display', previous[index]);
      else element.style.removeProperty('display');
    });
    return { withVisWiz, withoutVisWiz, mobileWidth };
  }, viewportWidth);
  expect(
    overflowMetrics.withVisWiz,
    `Gutenberg VisWiz overflow diagnostics: ${JSON.stringify(overflowMetrics)}`
  ).toBeLessThanOrEqual(Math.max(viewportWidth, overflowMetrics.withoutVisWiz));

  const fullscreenEnabled = await page.evaluate(() => document.fullscreenEnabled);
  if (fullscreenEnabled) {
    const fullscreen = first.locator('.viswiz-fullscreen');
    await expect(fullscreen).toBeVisible();
    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(true);
    await expect(fullscreen).toHaveText('Exit full screen');

    const fullscreenBox = await first.boundingBox();
    expect(fullscreenBox).not.toBeNull();
    expect(fullscreenBox.x).toBeGreaterThanOrEqual(-1);
    expect(fullscreenBox.y).toBeGreaterThanOrEqual(-1);
    expect(fullscreenBox.x + fullscreenBox.width).toBeLessThanOrEqual(viewportWidth + 1);
    expect(fullscreenBox.y + fullscreenBox.height).toBeLessThanOrEqual(viewportHeight + 1);
    await expect(second).toBeAttached();

    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(false);
    await expect(fullscreen).toHaveText('Full screen');
  }

  expect(clientErrors).toEqual([]);
});
