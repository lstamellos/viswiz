# VisWiz 2.0.41 stabilization acceptance

This release closes the original P1 stabilization backlog without starting P2 development.

## Validated scope

- #22: public visualization REST payloads are loaded only by the base frontend loader; the graph runtime consumes the already-rendered spec, including late runtime attachment.
- #23: administrator-only diagnostics show bounded failure categories for published visualization loads, import preview/commit and incomplete legacy migration. Raw queries, import rows, user identifiers and dataset payloads are never stored.
- #21: representative *frontend* Chromium benchmarks were added in `tests/e2e/performance-budgets.spec.js`. They ran on CI and all five required jobs passed on PR #135.

## Reference measurements

Measurements from Chromium CI run for PR #135 on 2026-10-21 (single run, not percentiles):

| Dataset | Size | Render (ms) | Search interaction (ms) | DOM elements | HTML output bytes |
| --- | ---: | ---: | ---: | ---: | ---: |
| Line rows | 1,000 | 54 | — | 2,002 | 153,285 |
| Line rows | 10,000 | 392 | — | 20,003 | 1,540,544 |
| Line rows | 50,000 | 1,989 | — | 100,003 | 7,745,650 |
| Graph, ~1.5 edges/node | 100 | 54 | 29 | 1,635 | 225,601 |
| Graph, ~1.5 edges/node | 500 | 202 | 57 | 8,035 | 1,120,742 |
| Graph, ~1.5 edges/node | 1,000 | 365 | 61 | 16,035 | 2,238,110 |
| Graph, ~1.5 edges/node | 5,000 | 2,110 | 459 | 80,035 | 11,214,761 |

## Practical performance budgets for this phase

For the reference Chromium CI fixture, the outer-tier **soft budgets** are up to 5,000 ms rendering for 50,000 time-series rows or 5,000 graph nodes, and up to 1,200 ms for graph text-search at 5,000 nodes. Measurements exceeding these soft budgets warrant investigation, not automatic runtime replacement. These thresholds allow for environment variability and are not promises for arbitrary production hardware, dataset complexity or themes.

The JavaScript heap API returned the same apparent 10 MB across all tiers, so **heap usage is not established** from this run. DOM element count and serialized output size are reported instead as explicit resource growth indicators. Server-side/admin payload sizes, memory and paged search at these tiers have not yet been benchmarked; the existing browser tests separately verify server-aware bounded collections and pagination, but do not constitute a high-volume load test.

The release does not include Canvas/WebGL/workers, alternative renderers or new platform limits.

## Release checks

- All five CI jobs required before release (PHP 8.1/8.3 unit/static, minimum WordPress/PHP, WordPress+WooCommerce smoke, Chromium E2E).
- GitHub release package must validate as `viswiz-2.0.41.zip`.
- WordPress production deployment/upgrade and actual site compatibility remain an operational acceptance check, separate from CI; do not claim that has been performed from the repository alone.

## Follow-up scale verification (2026-10-22, VisWiz 2.0.43)

The original 2.0.41 release did not include high-volume administrator measurements. A later benchmark-only change (PR #140) exercised WordPress REST dataset pages, searches and initial admin HTML generation for 1k/10k/50k rows and 100/500/1k/5k graph nodes using temporary CI fixtures. Full values, methodology and limitations are recorded in `docs/ADMIN_SCALE_BENCHMARK_2026-10-22.md`. This follow-up **does not retroactively change** the 2.0.41 release tests or verify production concurrency, accurate per-request heap peaks, or end-to-end client latency at maximum sizes.
