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

async function expectInsideViewport(locator, viewportWidth, tolerance = 1) {
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  expect(box.x).toBeGreaterThanOrEqual(-tolerance);
  expect(box.x + box.width).toBeLessThanOrEqual(viewportWidth + tolerance);
}

const categoricalRows = [
  { label: 'Alpha', value: 12, color: '#2563eb' },
  { label: 'Beta', value: 28, color: '#7c3aed' },
  { label: 'Phase C deliberately long category label for constrained-container verification', value: 19, color: '#059669' },
];

const timeRows = [
  { x_value: '2026-01-01', x_numeric: 1767225600, label: 'January', value: 14 },
  { x_value: '2026-02-01', x_numeric: 1769904000, label: 'February', value: 31 },
  { x_value: '2026-03-01', x_numeric: 1772323200, label: 'March with a deliberately long timeline label', value: 22 },
];

const xyRows = [
  { x_numeric: 1, y_value: 4, label: 'Point one' },
  { x_numeric: 2, y_value: 9, label: 'Point two' },
  { x_numeric: 3, y_value: 6, label: 'Point three' },
];

const geoRows = [
  { latitude: 37.9838, longitude: 23.7275, label: 'Athens', value: 20 },
  { latitude: 40.6401, longitude: 22.9444, label: 'Thessaloniki', value: 12 },
  { latitude: 38.2466, longitude: 21.7346, label: 'Patras', value: 8 },
];

const graphData = {
  nodes: [
    { uuid: '81111111-1111-4111-8111-111111111111', title: 'Director', label: 'Director', node_type: 'person', node_subtype: 'editor' },
    { uuid: '82222222-2222-4222-8222-222222222222', title: 'Investigations desk with a deliberately long complete node title', label: 'Investigations desk', node_type: 'organization', node_subtype: 'newsroom' },
    { uuid: '83333333-3333-4333-8333-333333333333', title: 'Reporter', label: 'Reporter', node_type: 'person', node_subtype: 'journalist' },
    { uuid: '84444444-4444-4444-8444-444444444444', title: 'Public event', label: 'Public event', node_type: 'event', node_subtype: '' },
  ],
  relations: [
    { uuid: '91111111-1111-4111-8111-111111111111', from_node_uuid: '81111111-1111-4111-8111-111111111111', to_node_uuid: '82222222-2222-4222-8222-222222222222', relation_type: 'leader_of', label: 'Leads', direction: 'directed' },
    { uuid: '92222222-2222-4222-8222-222222222222', from_node_uuid: '82222222-2222-4222-8222-222222222222', to_node_uuid: '83333333-3333-4333-8333-333333333333', relation_type: 'connected_to', label: 'Includes', direction: 'directed' },
    { uuid: '93333333-3333-4333-8333-333333333333', from_node_uuid: '83333333-3333-4333-8333-333333333333', to_node_uuid: '84444444-4444-4444-8444-444444444444', relation_type: 'participated_in', label: 'Participates', direction: 'directed' },
  ],
};

const specs = [
  { renderer: 'pie', settings: { title: 'Pie renderer', full_screen: true, show_legend: true }, data: { rows: categoricalRows } },
  { renderer: 'bar', settings: { title: 'Bar renderer', full_screen: true }, data: { rows: categoricalRows } },
  { renderer: 'column', settings: { title: 'Column renderer', full_screen: true }, data: { rows: categoricalRows } },
  { renderer: 'line', settings: { title: 'Line renderer', full_screen: true }, data: { rows: timeRows } },
  { renderer: 'area', settings: { title: 'Area renderer', full_screen: true }, data: { rows: timeRows } },
  { renderer: 'scatter', settings: { title: 'Scatter renderer', full_screen: true }, data: { rows: xyRows } },
  { renderer: 'counter', settings: { title: 'Counter renderer with a deliberately long responsive title', full_screen: true }, data: { rows: categoricalRows } },
  { renderer: 'timeline', settings: { title: 'Timeline renderer', full_screen: true }, data: { rows: timeRows } },
  {
    renderer: 'progress',
    settings: { title: 'Progress renderer', full_screen: true },
    data: { rows: [
      { label: 'Primary target with a deliberately long mobile label', value: 62, meta: { target: 100 } },
      { label: 'Secondary target', value: 18, meta: { target: 40 } },
    ] },
  },
  { renderer: 'map', settings: { title: 'Map renderer', full_screen: true }, data: { rows: geoRows } },
  {
    renderer: 'diagram',
    settings: { title: 'Diagram renderer', full_screen: true },
    data: { rows: [
      { label: 'First section with a deliberately long heading', meta: { text: 'A compact explanatory section used to exercise constrained responsive cards.' } },
      { label: 'Second section', meta: { text: 'Another section with enough text to wrap naturally on a narrow screen.' } },
    ] },
  },
  { renderer: 'flow_diagram', settings: { title: 'Flow diagram renderer', full_screen: true, show_graph_toolbar: false }, data: graphData },
  { renderer: 'org_chart', settings: { title: 'Org chart renderer', full_screen: true, show_graph_toolbar: false }, data: graphData },
];

const rendererAssertions = {
  pie: ['.viswiz-svg path', 3],
  bar: ['.viswiz-svg rect', 3],
  column: ['.viswiz-svg rect', 3],
  line: ['.viswiz-svg circle', 3],
  area: ['.viswiz-svg circle', 3],
  scatter: ['.viswiz-svg circle', 3],
  counter: ['.viswiz-counter-card', 3],
  timeline: ['.viswiz-timeline li', 3],
  progress: ['.viswiz-progress-track', 2],
  map: ['.viswiz-map-marker', 3],
  diagram: ['.viswiz-diagram-card', 2],
  flow_diagram: ['[data-viswiz-node-uuid]', 4],
  org_chart: ['[data-viswiz-node-uuid]', 4],
};

test('all public renderer modes remain contained and usable in a narrow constrained host', async ({ page }) => {
  const clientErrors = captureClientErrors(page);
  const viewportWidth = 390;
  const viewportHeight = 844;
  await page.setViewportSize({ width: viewportWidth, height: viewportHeight });
  await page.goto(`/?page_id=${fixture.pageId}`);
  await expect.poll(() => page.evaluate(() => typeof window.VisWiz?.render)).toBe('function');

  await page.evaluate((rendererSpecs) => {
    document.querySelectorAll('[data-viswiz-visualization]').forEach((element) => element.remove());
    const host = document.createElement('section');
    host.id = 'viswiz-renderer-matrix';
    host.style.width = '300px';
    host.style.maxWidth = 'calc(100vw - 32px)';
    host.style.margin = '0 auto';
    host.style.display = 'grid';
    host.style.gap = '20px';
    document.body.appendChild(host);

    rendererSpecs.forEach((spec, index) => {
      const container = document.createElement('div');
      container.className = 'viswiz-visualization';
      container.dataset.auditRenderer = spec.renderer;
      container.dataset.auditIndex = String(index);
      host.appendChild(container);
      window.VisWiz.render(container, spec);
    });
  }, specs);

  const host = page.locator('#viswiz-renderer-matrix');
  await expect(host).toBeVisible();
  await expectInsideViewport(host, viewportWidth);

  const fullscreenEnabled = await page.evaluate(() => document.fullscreenEnabled);
  for (const spec of specs) {
    const container = host.locator(`[data-audit-renderer="${spec.renderer}"]`);
    await container.scrollIntoViewIfNeeded();
    await expect(container).toHaveClass(new RegExp(`is-${spec.renderer}`));
    await expectInsideViewport(container, viewportWidth);

    const containerBox = await container.boundingBox();
    expect(containerBox).not.toBeNull();
    expect(containerBox.width).toBeLessThanOrEqual(300.5);

    const [selector, expectedCount] = rendererAssertions[spec.renderer];
    await expect(container.locator(selector)).toHaveCount(expectedCount);

    const title = container.locator(':scope > .viswiz-title');
    await expect(title).toBeVisible();
    await expectContained(title, container);

    const svg = container.locator(':scope > .viswiz-svg, .viswiz-graph-svg').first();
    if (await svg.count()) await expectContained(svg, container);

    if (fullscreenEnabled) {
      const fullscreen = container.locator(':scope > .viswiz-fullscreen');
      await expect(fullscreen).toBeVisible();
      await expectContained(fullscreen, container);
      const buttonBox = await fullscreen.boundingBox();
      const titleBox = await title.boundingBox();
      expect(buttonBox).not.toBeNull();
      expect(titleBox).not.toBeNull();
      expect(buttonBox.y).toBeLessThanOrEqual(titleBox.y);
    }
  }

  await expect(host.locator('[data-audit-renderer="pie"] .viswiz-legend li')).toHaveCount(3);
  await expect(host.locator('[data-audit-renderer="progress"] .viswiz-progress-track').first()).toHaveAttribute('aria-valuenow', '62');
  await expect(host.locator('[data-audit-renderer="progress"] .viswiz-progress-track').first()).toHaveAttribute('aria-valuemax', '100');

  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(overflow.scrollWidth).toBeLessThanOrEqual(Math.max(viewportWidth, overflow.clientWidth) + 1);

  if (fullscreenEnabled) {
    const pie = host.locator('[data-audit-renderer="pie"]');
    const fullscreen = pie.locator(':scope > .viswiz-fullscreen');
    await pie.scrollIntoViewIfNeeded();
    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(true);
    await expect(fullscreen).toHaveText('Exit full screen');

    const fullscreenBox = await pie.boundingBox();
    expect(fullscreenBox).not.toBeNull();
    expect(fullscreenBox.x).toBeGreaterThanOrEqual(-1);
    expect(fullscreenBox.y).toBeGreaterThanOrEqual(-1);
    expect(fullscreenBox.x + fullscreenBox.width).toBeLessThanOrEqual(viewportWidth + 1);
    expect(fullscreenBox.y + fullscreenBox.height).toBeLessThanOrEqual(viewportHeight + 1);
    await expectContained(pie.locator('.viswiz-svg'), pie);
    await expectContained(pie.locator('.viswiz-legend'), pie);

    await fullscreen.click();
    await expect.poll(() => page.evaluate(() => Boolean(document.fullscreenElement))).toBe(false);
    await expect(fullscreen).toHaveText('Full screen');
  }

  expect(clientErrors).toEqual([]);
});
