const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const fixturePath = process.env.VISWIZ_E2E_FIXTURE || '/tmp/viswiz-e2e-fixture.json';
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

const galleryImages = [
  {
    url: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22640%22 height=%22360%22%3E%3Crect width=%22640%22 height=%22360%22 fill=%22%23dbeafe%22/%3E%3C/svg%3E',
    alt: 'Alice gallery image one',
    caption: 'First mobile gallery image',
    featured: true,
  },
  {
    url: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22640%22 height=%22360%22%3E%3Crect width=%22640%22 height=%22360%22 fill=%22%23ede9fe%22/%3E%3C/svg%3E',
    alt: 'Alice gallery image two',
    caption: 'Second mobile gallery image',
    featured: false,
  },
];

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

async function expectInsideViewport(locator, viewportWidth, tolerance = 1) {
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  expect(box.x).toBeGreaterThanOrEqual(-tolerance);
  expect(box.x + box.width).toBeLessThanOrEqual(viewportWidth + tolerance);
}

test('public graph remains contained and usable at a narrow mobile viewport', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  const viewportWidth = 390;
  const viewportHeight = 844;
  await page.setViewportSize({ width: viewportWidth, height: viewportHeight });

  // Exercise the real public gallery renderer without depending on external
  // files or expanding the shared database fixture with WordPress attachments.
  await page.route('**/wp-json/viswiz/**', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    const nodes = Array.isArray(body?.data?.nodes) ? body.data.nodes : [];
    const alice = nodes.find((node) => String(node.uuid) === String(fixture.nodeUuids.alice));
    if (alice) alice.image_gallery = galleryImages;
    await route.fulfill({ response, json: body });
  });

  await page.goto(`/?page_id=${fixture.pageId}`);

  const graphs = page.locator('.viswiz-visualization');
  await expect(graphs).toHaveCount(2);
  for (const instance of await graphs.all()) {
    await expect(instance.locator('.viswiz-graph-frame')).toBeVisible();
    await expectInsideViewport(instance, viewportWidth);
  }

  const graph = graphs.first();
  const toolbar = graph.locator('.viswiz-graph-toolbar');
  await expect(toolbar).toBeVisible();
  for (const control of await toolbar.locator('input, select, button').all()) {
    if (await control.isVisible()) await expectContained(control, graph);
  }

  const overflowMetrics = await page.evaluate((mobileWidth) => {
    const root = document.documentElement;
    const visualizations = [...document.querySelectorAll('.viswiz-visualization')];
    const box = (element) => {
      const rect = element.getBoundingClientRect();
      return {
        tag: element.tagName,
        className: typeof element.className === 'string' ? element.className : element.className?.baseVal || '',
        left: Math.round(rect.left * 100) / 100,
        right: Math.round(rect.right * 100) / 100,
        width: Math.round(rect.width * 100) / 100,
        clientWidth: element.clientWidth,
        scrollWidth: element.scrollWidth,
        overflowX: getComputedStyle(element).overflowX,
      };
    };

    const withVisWiz = root.scrollWidth;
    const visualizationBoxes = visualizations.map(box);
    const offenders = visualizations
      .flatMap((visualization, visualizationIndex) => [...visualization.querySelectorAll('*')]
        .map((element) => ({ visualizationIndex, ...box(element) })))
      .filter((entry) => entry.left < -1 || entry.right > mobileWidth + 1)
      .sort((a, b) => b.right - a.right)
      .slice(0, 20);

    const hiddenIndividually = visualizations.map((element) => {
      const previous = element.style.getPropertyValue('display');
      element.style.setProperty('display', 'none', 'important');
      const scrollWidth = root.scrollWidth;
      if (previous) element.style.setProperty('display', previous);
      else element.style.removeProperty('display');
      return scrollWidth;
    });

    const previous = visualizations.map((element) => element.style.getPropertyValue('display'));
    visualizations.forEach((element) => element.style.setProperty('display', 'none', 'important'));
    const withoutVisWiz = root.scrollWidth;
    visualizations.forEach((element, index) => {
      if (previous[index]) element.style.setProperty('display', previous[index]);
      else element.style.removeProperty('display');
    });

    return { withVisWiz, withoutVisWiz, visualizationBoxes, hiddenIndividually, offenders };
  }, viewportWidth);
  expect(
    overflowMetrics.withVisWiz,
    `VisWiz mobile overflow diagnostics:\n${JSON.stringify(overflowMetrics, null, 2)}`
  ).toBeLessThanOrEqual(Math.max(viewportWidth, overflowMetrics.withoutVisWiz));

  const fullscreenEnabled = await page.evaluate(() => document.fullscreenEnabled);
  if (fullscreenEnabled) {
    const fullscreen = graph.locator('.viswiz-fullscreen');
    await expect(fullscreen).toBeVisible();
    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(true);
    await expect(fullscreen).toHaveText('Exit full screen');

    const fullscreenBox = await graph.boundingBox();
    expect(fullscreenBox).not.toBeNull();
    expect(fullscreenBox.x).toBeGreaterThanOrEqual(-1);
    expect(fullscreenBox.y).toBeGreaterThanOrEqual(-1);
    expect(fullscreenBox.x + fullscreenBox.width).toBeLessThanOrEqual(viewportWidth + 1);
    expect(fullscreenBox.y + fullscreenBox.height).toBeLessThanOrEqual(viewportHeight + 1);
    for (const control of await toolbar.locator('input, select, button').all()) {
      if (await control.isVisible()) await expectContained(control, graph);
    }

    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(false);
    await expect(fullscreen).toHaveText('Full screen');
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
  expect(modalBox.x + modalBox.width).toBeLessThanOrEqual(viewportWidth - 16);
  expect(modalBox.y).toBeGreaterThanOrEqual(0);
  expect(modalBox.y + modalBox.height).toBeLessThanOrEqual(viewportHeight);

  const gallery = overlay.locator('.viswiz-node-gallery');
  const galleryImage = gallery.locator('.viswiz-node-image');
  const galleryCaption = gallery.locator('.viswiz-node-image-caption');
  const galleryCount = gallery.locator('.viswiz-node-image-count');
  await expect(gallery).toBeVisible();
  await expect(galleryImage).toHaveAttribute('src', galleryImages[0].url);
  await expect(galleryImage).toHaveAttribute('alt', galleryImages[0].alt);
  await expect(galleryCaption).toHaveText(galleryImages[0].caption);
  await expect(galleryCount).toHaveText('1/2');
  await expectContained(gallery, modal);

  await gallery.getByRole('button', { name: 'Next image' }).click();
  await expect(galleryImage).toHaveAttribute('src', galleryImages[1].url);
  await expect(galleryImage).toHaveAttribute('alt', galleryImages[1].alt);
  await expect(galleryCaption).toHaveText(galleryImages[1].caption);
  await expect(galleryCount).toHaveText('2/2');
  await expectContained(gallery, modal);

  await gallery.getByRole('button', { name: 'Previous image' }).click();
  await expect(galleryImage).toHaveAttribute('src', galleryImages[0].url);
  await expect(galleryCount).toHaveText('1/2');

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
