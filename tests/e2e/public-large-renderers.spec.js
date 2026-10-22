const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const fixture = JSON.parse(fs.readFileSync(process.env.VISWIZ_E2E_FIXTURE || '/tmp/viswiz-e2e-fixture.json', 'utf8'));

test('initial graph view contains every node and adapts to graph size', async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto('/?page_id=' + fixture.pageId);
  await expect.poll(() => page.evaluate(() => Boolean(window.VisWiz?.render))).toBe(true);
  const results = await page.evaluate(async () => {
    const host = document.createElement('div');
    host.className = 'viswiz-visualization';
    host.style.width = '700px';
    document.body.appendChild(host);
    const snapshots = [];
    for (const n of [1, 10, 100, 241, 1000, 5000]) {
      const nodes = Array.from({ length: n }, (_, i) => ({
        uuid: 'node-' + i, title: 'Graph node ' + i,
        node_type: i % 2 ? 'person' : 'organization',
      }));
      const relations = Array.from({ length: Math.floor(n * 1.5) }, (_, i) => ({
        uuid: 'edge-' + i, from_node_uuid: nodes[i % n].uuid,
        to_node_uuid: nodes[(i + 1) % n].uuid, relation_type: 'linked', direction: 'directed',
      }));
      window.VisWiz.render(host, {
        id: 'graph-scale', renderer: 'graph', schema: 'graph',
        settings: { show_graph_toolbar: true }, data: { nodes, relations },
      });
      await new Promise((resolve) => requestAnimationFrame(resolve));
      const svg = host.querySelector('.viswiz-graph-svg');
      const box = svg.viewBox.baseVal;
      const compact = svg.classList.contains('is-viswiz-compact');
      const nodesEls = [...host.querySelectorAll('.viswiz-graph-node')];
      const positions = nodesEls.map((element) => {
        if (compact) return { x: Number(element.getAttribute('cx')), y: Number(element.getAttribute('cy')) };
        const match = /translate\\(([-\\d.]+),([-\\d.]+)\\)/.exec(element.getAttribute('transform') || '');
        return { x: Number(match?.[1]), y: Number(match?.[2]) };
      });
      const margin = compact ? 10 : 105;
      const contained = positions.every(({ x, y }) => (
        x - margin >= box.x - 0.01 && y - margin >= box.y - 0.01
        && x + margin <= box.x + box.width + 0.01
        && y + margin <= box.y + box.height + 0.01
      ));
      const domCount = host.querySelectorAll('*').length;
      snapshots.push({ n, contained, compact, domCount, viewWidth: box.width, count: nodesEls.length,
        enhanced: host.querySelectorAll('.viswiz-node-card-styled').length });
    }
    // Search must still reveal editable-size node cards in a dense graph.
    const search = host.querySelector('input[type="search"]');
    search.value = 'Graph node 4999';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((resolve) => requestAnimationFrame(resolve));
    const filtered = {
      count: host.querySelectorAll('.viswiz-graph-node').length,
      compact: host.querySelector('.viswiz-graph-svg').classList.contains('is-viswiz-compact'),
    };
    host.remove();
    return { snapshots, filtered };
  });
  expect(results.snapshots).toHaveLength(6);
  for (const result of results.snapshots) {
    expect(result.count).toBe(result.n);
    expect(result.contained).toBe(true);
    expect(result.compact).toBe(result.n > 240);
    if (result.compact) {
      expect(result.enhanced).toBe(0);
      expect(result.domCount).toBeLessThan(4 * result.n + 1000);
    }
  }
  expect(results.snapshots[5].viewWidth).toBeGreaterThan(results.snapshots[1].viewWidth);
  expect(results.filtered).toEqual({ count: 1, compact: false });
});

test('large row renderers avoid mounting every item at once', async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto('/?page_id=' + fixture.pageId);
  await expect.poll(() => page.evaluate(() => Boolean(window.VisWiz?.render))).toBe(true);
  const result = await page.evaluate(() => {
    const host = document.createElement('div');
    host.className = 'viswiz-visualization';
    document.body.appendChild(host);
    const rows = Array.from({ length: 10000 }, (_, i) => ({
      label: 'Entry ' + i, value: i % 50 + 1, x_numeric: i,
      y_value: i === 5000 ? 10000 : i % 30,
      latitude: 37 + i / 100000, longitude: 23 + i / 100000,
    }));
    const measure = (renderer) => {
      window.VisWiz.render(host, {
        id: 'row-scale', renderer, settings: {}, data: { rows },
      });
      return {
        elements: host.querySelectorAll('*').length,
        canvas: host.querySelectorAll('canvas').length,
        circles: host.querySelectorAll('circle').length,
        items: host.querySelectorAll('.viswiz-counter-card,.viswiz-timeline li,.viswiz-diagram-card').length,
        paging: Boolean(host.querySelector('.viswiz-rows-pagination')),
      };
    };
    const all = Object.fromEntries(['line', 'area', 'scatter', 'map', 'bar', 'pie', 'counter', 'timeline', 'progress', 'diagram']
      .map((type) => [type, measure(type)]));
    window.VisWiz.render(host, { renderer: 'counter', settings: {}, data: { rows } });
    const controls = host.querySelector('.viswiz-rows-pagination');
    const first = controls.querySelector('span').textContent;
    controls.querySelectorAll('button')[1].click();
    const second = controls.querySelector('span').textContent;
    const cards = host.querySelectorAll('.viswiz-counter-card').length;
    host.remove();
    return { all, first, second, cards };
  });
  for (const type of ['line', 'area', 'scatter', 'map', 'bar', 'pie', 'counter', 'timeline', 'progress', 'diagram']) {
    expect(result.all[type].elements).toBeLessThan(1200);
  }
  expect(result.all.scatter.canvas).toBe(1);
  expect(result.all.map.canvas).toBe(1);
  expect(result.all.line.circles).toBe(0);
  expect(result.all.bar.paging).toBe(true);
  expect(result.all.counter.paging).toBe(true);
  expect(result.first).toBe('1–150 / 10000');
  expect(result.second).toBe('151–300 / 10000');
  expect(result.cards).toBe(150);
});
