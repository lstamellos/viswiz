const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const fixturePath = process.env.VISWIZ_E2E_FIXTURE || '/tmp/viswiz-e2e-fixture.json';
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

test('public graph runtime reuses the rendered spec without a second REST fetch', async ({ page }) => {
  const endpointPath = `/wp-json/viswiz/v2/visualizations/${fixture.visualizationId}`;
  const requests = [];
  const errors = [];
  page.on('request', (request) => {
    if (request.method() === 'GET' && new URL(request.url()).pathname === endpointPath) {
      requests.push(request.url());
    }
  });
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto(`/?page_id=${fixture.pageId}`);
  const graphs = page.locator('.viswiz-visualization[data-viswiz-visualization]');
  await expect(graphs).toHaveCount(2);
  for (const graph of await graphs.all()) {
    await expect(graph.locator('.viswiz-graph-node')).toHaveCount(fixture.counts.nodes);
  }

  const requestsAfterInitialRender = requests.length;
  expect(requestsAfterInitialRender).toBeGreaterThanOrEqual(1);

  // Simulate a runtime that did not observe the original render bridge event.
  // The public renderer must hand off the existing spec, without requesting it again.
  const recovered = await graphs.first().evaluate(async (container) => {
    const runtime = window.VisWizGraphRuntime;
    const original = window.VisWiz.getRenderedSpec(container);
    const state = runtime.stateFor(container);
    state.spec = null;
    const adopted = await runtime.getSpec(container);
    return {
      sameReference: adopted === original,
      correctSchema: adopted?.schema === 'graph',
      nodeCount: adopted?.data?.nodes?.length,
    };
  });
  expect(recovered).toEqual({
    sameReference: true,
    correctSchema: true,
    nodeCount: fixture.counts.nodes,
  });
  expect(requests).toHaveLength(requestsAfterInitialRender);

  const first = graphs.first();
  await first.locator('.viswiz-graph-toolbar input[type="search"]').fill('Alice');
  await expect(first.locator('.viswiz-graph-status')).toContainText(`1/${fixture.counts.nodes}`);
  await expect(graphs.nth(1).locator('.viswiz-graph-status'))
    .toContainText(`${fixture.counts.nodes}/${fixture.counts.nodes}`);

  // Dynamic public instances still load through the canonical frontend loader.
  const endpoint = await first.getAttribute('data-viswiz-endpoint');
  await page.evaluate((url) => {
    const container = document.createElement('div');
    container.className = 'viswiz-visualization';
    container.dataset.viswizVisualization = 'late-spec-ownership-test';
    container.dataset.viswizEndpoint = url;
    container.dataset.e2ePayloadHandoff = '1';
    container.innerHTML = '<div class="viswiz-loading">Loading visualization…</div>';
    document.body.appendChild(container);
  }, endpoint);
  const dynamic = page.locator('[data-e2e-payload-handoff="1"]');
  await dynamic.scrollIntoViewIfNeeded();
  await expect(dynamic.locator('.viswiz-graph-node')).toHaveCount(fixture.counts.nodes);
  expect(requests).toHaveLength(requestsAfterInitialRender + 1);
  expect(errors).toEqual([]);
});
