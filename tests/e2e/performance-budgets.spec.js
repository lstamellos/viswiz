const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const fixture = JSON.parse(fs.readFileSync(process.env.VISWIZ_E2E_FIXTURE || '/tmp/viswiz-e2e-fixture.json', 'utf8'));

test('record representative VisWiz rendering and interaction scale', async ({ page }) => {
  test.setTimeout(240_000);
  await page.goto(`/?page_id=${fixture.pageId}`);
  await expect.poll(() => page.evaluate(() => Boolean(window.VisWiz?.render))).toBe(true);

  const measurements = await page.evaluate(async () => {
    const results = [];
    const container = document.createElement('div');
    container.className = 'viswiz-visualization';
    container.style.width = '700px';
    document.body.appendChild(container);
    const tick = () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const measure = async (label, n, spec, interaction) => {
      const before = performance.now();
      window.VisWiz.render(container, spec);
      await tick();
      const renderMs = Math.round(performance.now() - before);
      const count = container.querySelectorAll('*').length;
      const domSize = container.outerHTML.length;
      let interactionMs = null;
      if (interaction) {
        const start = performance.now();
        interaction();
        await tick();
        interactionMs = Math.round(performance.now() - start);
      }
      const heapBytes = performance.memory?.usedJSHeapSize ?? null;
      results.push({ label, n, renderMs, interactionMs, elements: count, domBytes: domSize, heapBytes });
      container.replaceChildren();
      await tick();
    };
    for (const n of [1000, 10000, 50000]) {
      const rows = Array.from({ length: n }, (_, i) => ({
        uuid: String(i), label: `Series ${i}`, x_numeric: i,
        x_value: String(i), value: i % 500, y_value: i % 500,
      }));
      await measure('rows/line', n, { id: 'perf-row', renderer: 'line', schema: 'time_series', settings: { full_screen: false }, data: { rows } });
    }
    for (const n of [100, 500, 1000, 5000]) {
      const nodes = Array.from({ length: n }, (_, i) => ({
        uuid: `node-${i}`, slug: `node-${i}`, title: `Graph node ${i}`,
        node_type: i % 2 ? 'person' : 'organization', node_subtype: '',
      }));
      const relations = Array.from({ length: Math.floor(n * 1.5) }, (_, i) => ({
        uuid: `edge-${i}`, from_node_uuid: nodes[i % n].uuid,
        to_node_uuid: nodes[(i + 1) % n].uuid, relation_type: 'connected_to', direction: 'directed',
      }));
      await measure('graph', n, {
        id: 'perf-graph', renderer: 'graph', schema: 'graph',
        settings: { show_graph_toolbar: true, show_graph_filters: true, show_graph_search: true, full_screen: false },
        data: { nodes, relations },
      }, () => {
        const search = container.querySelector('input[type="search"]');
        if (search) {
          search.value = 'Graph node 1';
          search.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    }
    container.remove();
    return results;
  });

  console.log('VISWIZ_PERFORMANCE_RESULTS=' + JSON.stringify(measurements));
  expect(measurements).toHaveLength(7);
  for (const result of measurements) {
    expect(Number.isFinite(result.renderMs)).toBe(true);
    expect(result.renderMs).toBeGreaterThanOrEqual(0);
    expect(result.elements).toBeGreaterThan(0);
  }
});
