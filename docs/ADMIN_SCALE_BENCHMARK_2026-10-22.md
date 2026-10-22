# Admin performance baseline — VisWiz P1 #21 (2026-10-22)

## Scope and methodology

Benchmark script: `tests/wp-admin-performance.php`, run by WP-CLI inside disposable WordPress + MySQL 8.0 CI after the WordPress smoke tests (VisWiz 2.0.43 + PR #140). Sizes: 1k/10k/50k categorical rows; 100/500/1k/5k graph nodes with 1.5 relations per node. Fixtures are inserted into temporary CI tables in 250-record SQL batches; this **does not** benchmark user-facing import/commit/revision processing.

All REST times below are **medians of three in-process WordPress REST calls** (PHP hrtime, milliseconds). They exclude network latency, browser execution, concurrent traffic and caches. Not production percentiles. Initial admin HTML times measure server output only. Existing browser tests verify editor paging/search at smaller sizes but are not full-browser benchmarks at 50k rows/5k nodes.

## Row dataset results

| Rows | First page | Last page | Search | First page JSON | Admin HTML |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 2.10 ms | 2.65 ms | 3.83 ms | 20.4 KB | 1.00 ms |
| 10,000 | 2.70 ms | 11.09 ms | 30.50 ms | 20.4 KB | 1.11 ms |
| 50,000 | 8.18 ms | 51.11 ms | 152.14 ms | 20.4 KB | 1.12 ms |

Each search targets the final row, beyond the first page, and returns exactly one match. All pages are bounded to 100 records with correct total headers. Last-page cost increases with the SQL OFFSET, not constant-time keyset pagination.

## Graph results

| Nodes / relations | Node page | Node search | Relation page | Relation search | Node options | Admin HTML |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 100 / 150 | 21.81 ms | 1.65 ms | 2.39 ms | 3.17 ms | 0.82 ms | 1.80 ms |
| 500 / 750 | 99.99 ms | 3.95 ms | 4.71 ms | 10.92 ms | 1.55 ms | 4.05 ms |
| 1,000 / 1,500 | 198.25 ms | 6.46 ms | 7.22 ms | 20.50 ms | 2.20 ms | 6.65 ms |
| 5,000 / 7,500 | 1,006.08 ms | 27.82 ms | 28.38 ms | 97.29 ms | 8.18 ms | 28.69 ms |

A node page remains around 26 KB JSON and a relation page around 46 KB JSON (100 records/page), despite increasing dataset size.

**Recorded limitation:** With 5k nodes the *unfiltered first node page* takes about 1 second in-process to return 100 records. The current SQL includes degree aggregation and is not constant-time in the measured cases. This remains a known scale limitation; no query rewrite or new features are in scope.

## Memory and reliability

The observed PHP process usage was roughly 112 MiB with WordPress + WooCommerce already loaded. This absolute figure is **not per-request peak memory** and does not prove request allocations are independent of dataset size. Frontend `performance.memory` was also unreliable in the earlier benchmark. The benchmark validates request pagination limits and search correctness; it does not establish concurrent load, production resource ceilings or performance of full import/write operations.

## Provisional soft budgets (investigation triggers only)

For the CI fixture, not universal promises: 50k-row first/last REST pages under 250 ms and off-page search under 500 ms; 5k-node first page under 2,000 ms, node or relation search under 500 ms and node-option lookup under 100 ms; server-side initial admin HTML under 200 ms. The public renderer budgets remain documented in `docs/STABILIZATION_ACCEPTANCE_2.0.41.md`.

These benchmarks **close the verification portion** of P1 #21 at the named tiers. P2, new renderers, and optimization work remain frozen.
