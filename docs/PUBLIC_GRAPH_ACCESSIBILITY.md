# Public graph accessibility audit

Baseline: VisWiz 2.0.38, 2026-09-06.

This milestone closes usability backlog P1 #18 for the public graph runtime. The target is WordPress accessibility practice and WCAG 2.2 AA for plugin-owned interaction surfaces, while retaining VisWiz's existing renderer/data/state architecture.

## Existing accessible behavior retained

Before this audit, the public graph already provided several important accessibility contracts:

- graph nodes are focusable SVG button targets with accessible names;
- graph nodes activate with Enter or Space;
- node type/subtype tags are focusable SVG buttons with `aria-pressed` state and Enter/Space activation;
- search, native filters and zoom controls have accessible names;
- graph result counts use a polite live region;
- connection-focus controls expose pressed state and a polite live region;
- node dialogs expose dialog/modal semantics, move initial focus to Close, contain keyboard focus, close with Escape and restore focus to the invoking node;
- gallery controls have accessible names;
- multiple graph instances already retain independent runtime state.

## Findings fixed

### 1. Property dialog focus containment

The property view had dialog semantics, initial focus and Escape handling but did not explicitly contain Tab/Shift+Tab. Public modal keyboard handling now treats the topmost VisWiz modal as the active dialog and wraps focus inside it.

### 2. Topmost Escape and nested modal lifecycle

Property-node navigation could leave the source node dialog underneath a newly opened node dialog. Multiple document-level Escape handlers could then act on more than the topmost dialog, and focus could return behind the still-open modal.

Property-node navigation now replaces the underlying node dialog rather than stacking node dialogs. Capture-phase Escape handling closes only the topmost VisWiz modal.

### 3. Focus after controls disappear

Two dynamic controls could remove/hide themselves while focused:

- removing a selected facet pill;
- clearing an active connection-focus bar.

Facet removal now moves focus to a stable graph filter/search control. Clearing connection focus returns focus to the former connection root node, with a stable toolbar fallback if that node is unavailable.

### 4. Screen-reader status semantics

The existing graph result status is explicitly exposed as `role="status"`, polite and atomic. Selected facet changes are exposed through a polite live region without changing the semantics of the interactive pill buttons. Connection-focus state is atomic.

### 5. Fullscreen state

Fullscreen controls now expose `aria-pressed`. Each visualization owns a visually hidden polite status region that announces the translated current fullscreen control state when fullscreen enters or exits. No document-global IDs are introduced, so multiple visualization instances remain independent.

### 6. Focus visibility and theme resilience

Plugin-owned public graph controls receive a high-visibility dual focus ring that remains discernible against light and dark host-theme backgrounds. SVG node and facet targets receive explicit high-contrast focus strokes.

Interactive graph controls use neutral high-contrast foreground/background/border colors instead of inheriting arbitrary visualization text/background colors. Modal action links are also hardened against host-theme color overrides.

Author-configurable data colors remain part of visualization content and are not silently rewritten by the runtime. The graph card title layer and type/subtype tags already render text over controlled dark surfaces.

### 7. Reduced motion

When `prefers-reduced-motion: reduce` is active, VisWiz disables its progress-fill, graph-node and graph-tag transitions and avoids smooth scrolling behavior on the visualization container.

## Regression coverage

Source-contract coverage verifies:

- existing SVG button and pressed-state semantics;
- topmost modal Escape/Tab containment;
- property-navigation modal replacement;
- stable focus fallback after disappearing controls;
- live status and fullscreen pressed-state semantics;
- focus-visible and reduced-motion CSS;
- absence of a new fetch path or competing graph state owner in the accessibility hardening.

Chromium coverage verifies:

- SVG node keyboard activation;
- node and property dialog semantics and initial focus;
- Tab/Shift+Tab containment in the property dialog;
- Escape closes only the active property dialog and returns focus to its opener;
- property-node navigation leaves exactly one node dialog open;
- facet keyboard activation and focus recovery after pill removal;
- connection-focus status and focus recovery after clearing;
- fullscreen `aria-pressed` plus live state announcement when supported;
- reduced-motion transition suppression;
- no duplicate IDs across multiple VisWiz visualization instances.

## Architecture

The accessibility hardening stays inside the existing graph runtime presentation/lifecycle layer. It performs no REST request, does not fetch visualization payloads, does not own graph filters/data, and does not introduce a second renderer or persistent graph state model.

## Reference standards

- WCAG 2.2: https://www.w3.org/TR/WCAG22/
- WAI-ARIA Authoring Practices — Dialog (Modal) Pattern: https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/
- WAI-ARIA Authoring Practices — Button Pattern: https://www.w3.org/WAI/ARIA/apg/patterns/button/
- WordPress Accessibility Coding Standards: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/accessibility/
