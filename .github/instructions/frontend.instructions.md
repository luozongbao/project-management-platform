---
description: "Use when editing CSS or JavaScript in assets/css/, assets/js/, or client/assets/. Covers the v2 token and component layers, the load-order contract, the ES5 constraint on client JS, dark mode, and the class names that are pinned by regression tests."
applyTo: ["assets/**", "client/assets/**"]
---

# Front-end conventions

Vanilla JS and plain CSS. **No framework, no bundler, no npm, no build step.**

## CSS layers

Three layers, loaded in this exact order — legacy first so v2 wins at equal specificity:

````
assets/css/v2-tokens.css       → 80 custom properties, :root only, no selectors
assets/css/style.css            → legacy v1 base (428 lines after the dead-selector sweep)
assets/css/v2-components.css    → the component vocabulary
assets/css/pages/<page>.css     → per-page stylesheets (13 files)
````

- `v2-components.css` is linked by `includes/header.php` **only when `$use_v2_shell` is
  truthy**. Any page that renders its own `<head>` — the client portal
  (`client/dashboard.php`, three separate head blocks) and `includes/landing.php` — **must
  link the component layer explicitly**.
- **Diagnostic:** a v2 class computing to `0px none`, `transparent`, or `padding 0px` almost
  always means the component layer is missing from the cascade. Check the `<link>` before
  suspecting the CSS itself.

## Tokens

- Use the token, not a literal colour or size: `--brand-*`, `--gray-*`,
  `--success/-warning/-danger/-info-{100,500,700}`, `--status-*`, `--font-*`, `--fs-*`,
  `--lh-*`, `--fw-*`, `--sp-1..20` (4px base), `--r-*`, shadows, durations.
- Semantic tints use the `-100` / `-500` / `-700` triple.
- New tokens go in `v2-tokens.css` as `:root` custom properties — that file holds **no
  selectors**.

## Dark mode is a designed feature

`v2-components.css` §10 redefines the whole `--gray-*` scale under
`@media (prefers-color-scheme: dark)` (`--gray-0` becomes `#0c1018`). Dark colours are
**expected**, not a defect. To compare against the light mockup, emulate
`colorScheme: 'light'` first.

## Naming traps

- v2 modifiers use a **double** hyphen: `.status--on_hold`, `.btn--primary`, `.alert--danger`,
  `.reason-chip--delay`, `.reason-chip--on-hold`. Single-hyphen forms (`.status-on_hold`)
  match nothing and render unstyled — easy to miss because the status *value* uses an
  underscore.
- `.timeline` is an `li` with `timeline__body` / `timeline__meta` — **not**
  `div.timeline__item` / `__content` / `__date`.
- A later duplicate declaration of the same property in a block **silently wins** — grep for a
  second occurrence before assuming the earlier rule applies.

## Test-pinned class names

These exist **only** as regression-test guards. Renaming them retires a guard silently, even
though the feature still works. Keep them as an additional class alongside the v2 styling,
with a comment saying why.

| Class | Pinned by |
|---|---|
| `.task-reason-card` | `database/task_status_reasons_test.php` |
| `.derived-pill` | `database/task_parent_status_derived_test.php` |
| `.inherit-indicator`, `.inherit-warning` | issue-011 effort-inherit UI |
| `.reason-chip--delay`, `.reason-chip--on-hold` | issue-035 §4.3 colour contract |
| `.status-reason-required`, `.not-estimated` | missing-reason / not-estimated markers |

## JavaScript

- Shared behaviour lives in `assets/js/script.js` — `init*()` functions registered from a
  single `DOMContentLoaded` listener. **No inline `<script>` blocks**; move behaviour there.
- The client portal's `client/assets/js/subtask-toggle.js` is hand-written **ES5**: `var` only,
  no arrow functions, no template literals, no block-scoped declarations. Match that style.
- `pmpCopyText()` has a real fallback chain — the Async Clipboard API does not exist outside a
  secure context. Never assume `navigator.clipboard` is present.
- `initStatusReason()` must **read** the server's `data-reason-show="0|1"` flag, not re-derive
  the delayed predicate from the status field. The server is the single source of truth, and
  weakening the server-side validation is forbidden.
- Every `localStorage` access must be wrapped in try/catch (sandboxed iframe, private mode).
- Pair every JS tree-selector with its CSS-side `:not()` scope — e.g. JS
  `ul.task-tree:not(.root)` must match CSS `#tasks-progress[data-subtasks="hidden"]
  ul.task-tree:not(.root)`.

## Assets

- Asset paths are **root-absolute** (`/assets/css/…`) and go through `asset_url()` for
  cache-busting.
- One exception: the landing page's
  `<script src="/assets/js/share-code-format.js" defer></script>` must stay the **literal
  unversioned** path — `database/probe_diag_test.php` pins it.
- The mockup in `docs/design/2.0/` is **generated**. Edit `docs/design/2.0/_build/*.py` and
  re-run `python3 docs/design/2.0/_build/main.py`; never hand-edit the generated HTML.
