# Application "About" / Version Popup + Release-Process Checklist

**Status:** Proposed
**Type:** Feature / Process
**Priority:** Low-Medium
**Target Release:** v1.7.2
**Affects:** `config.php`, `config.template.php`, `install.php`, `includes/header.php`, `includes/footer.php`, `dashboard.php` (no markup change — inherits from header/footer), `client/dashboard.php`, `client/index.php`, `client/lang.php`, `assets/css/style.css` (one new `.app-about` block, ~40 lines), `client/assets/client.css` (one new `.app-about` block, ~30 lines), `RELEASE.md` (process section appended at the end), `docs/RELEASE_PROCESS.md` (new file), `README.md` (one sentence + a link), one new test file `database/about_popup_test.php`

> **Why two artefacts (code + process).** The version is currently scattered
> across three places — `README.md:3` ("Latest release" banner), the top
> entry of `RELEASE.md`, and the absence of any in-app indicator — and the
> dev has to remember to update all three (or two out of three, which is
> the bug pattern that produced v1.7.1.2 being silently out of sync in the
> changelog). This issue is the in-app piece. The
> `docs/RELEASE_PROCESS.md` companion file is the piece that prevents the
> problem from coming back: a six-step checklist the dev runs at the end
> of every release, anchored to a single source of truth
> (`const APP_VERSION` in `config.php`).

---

## 1. Problem

There is **no in-app way to see which version of the platform is running**.
Three symptoms:

1. A user opens the PM dashboard and the page does not show a version
   number anywhere. The only place the version exists is in
   `README.md:3` ("Latest release: v1.7.1.2 (2026-10-16)") and at the
   top of `RELEASE.md`. A user who has never opened either file has no
   way to know which version they are on.
2. A user opens the public client portal (`client/dashboard.php?code=…`)
   and the same is true — no version, no "About", no way to report a bug
   against a specific build.
3. **There is no single source of truth for the version in code.** A
   search for `APP_VERSION` across the workspace returns zero hits. The
   only places the version string lives are README.md and RELEASE.md,
   which are both human-edited. A new dev who follows the install wizard
   gets a `config.php` with no version constant and has to grep the
   repo to find the current version. v1.7.1.1 → v1.7.1.2 was the most
   recent release to demonstrate this drift (the dev had to update
   README.md and RELEASE.md manually; the in-app user has no way to
   verify what shipped).

There is also no developer-facing checklist to enforce a clean release
process, so the dev has to remember by hand to (a) update
`README.md:3`, (b) add a `## <version> — <date>` entry to `RELEASE.md`,
and (c) bump the version string. A new dev will forget one of these on
the first release they ship.

---

## 2. Goals

In priority order:

- **G1. Single source of truth for the version in code.** A new
  `const APP_VERSION` in `config.php` (and the matching entry in
  `config.template.php`) is the only place the version string is
  defined. The install wizard writes the constant into new
  `config.php` files. The popup reads it from there. The README
  banner and the RELEASE.md top entry are human-edited summaries of
  this one constant.

- **G2. An "About" trigger on the PM dashboard surface.**
  A small `?` icon button in the navbar (between the "Contacts" link
  and the user dropdown) opens a popup / disclosure showing the app
  name, the version, the developer attribution, and a link to
  `RELEASE.md`. The trigger is rendered by `includes/header.php` so it
  appears on every internal-app page (dashboard, projects, tasks,
  contacts, project detail, task detail, profile, …). It is visible
  to both signed-in PMs and to logged-out visitors on the login /
  register / forgot-password pages, because the version applies to the
  build, not to the user.

- **G3. An "About" trigger on the client portal surface.**
  The same trigger on `client/dashboard.php` (in the `.client-header`
  block, next to the language switch) and `client/index.php` (in the
  `.client-foot` paragraph) so any stakeholder — logged in or not —
  can see which build they are looking at. The popup is localized
  (EN + zh) via the existing `t()` helper in `client/lang.php`. The
  client-portal popup **does not** link to `RELEASE.md` (that file
  reveals internal milestone history that is not relevant to a public
  stakeholder).

- **G4. A release-process checklist.**
  A new `docs/RELEASE_PROCESS.md` file with a six-step checklist the
  dev runs at the end of every release, anchored to the new
  `APP_VERSION` constant. The checklist is also summarised at the
  bottom of `RELEASE.md` so it is visible in the same place dev
  already looks when shipping.

- **G5. Regression coverage for the new contract.**
  A new `database/about_popup_test.php` that asserts (a) the two
  `APP_VERSION` constants exist in `config.php` and
  `config.template.php` and agree, (b) the install wizard writes the
  constant, (c) the PM navbar renders the trigger, (d) the client
  portal renders the trigger, (e) the popup content includes the
  version + the developer attribution, (f) `docs/RELEASE_PROCESS.md`
  exists, and (g) `README.md:3` and the top of `RELEASE.md` carry the
  same version string as `APP_VERSION`. The last two are the drift
  guards.

---

## 3. Non-Goals

- **NG1. No schema change.** No new table, no new column. The version
  is a config constant, not a database row.
- **NG2. No AJAX endpoint, no API.** The popup is fully static HTML
  served by the existing page handler. There is no
  `/api/v1/version` and no plan to add one.
- **NG3. No JavaScript framework, no build step.** The popup is
  implemented with a native `<details>` / `<summary>` disclosure
  (zero JS, zero CSP risk, works on the public client portal under
  the issue-020 CSP tightening). The internal-app navbar trigger can
  be either a `<details>` (preferred) or a `<dialog>` opened by a
  tiny inline script — the spec picks `<details>` for parity.
- **NG4. No change to the existing `APP_NAME` / "Powered by" string
  in `includes/footer.php` line 5.** The current footer copy
  (`© <year> <APP_NAME>. All rights reserved.`) stays. The new
  trigger sits next to it, not on top of it.
- **NG5. No change to the existing navbar links** (Dashboard,
  Projects, Tasks, Contacts, user dropdown). The new trigger is a
  separate, visually quieter icon, not a replacement for any link.
- **NG6. No new localization surface beyond EN + zh.** The existing
  `client/lang.php` is bilingual; the new keys are added in both
  languages. The internal-app popup uses the hard-coded English
  string "Vibe coding with AI agent (MiniMaxM3)" as the developer
  attribution because (a) the internal app is English-only and
  (b) the attribution names a specific tool, which is not localizable.

---

## 4. UX

### 4.1. PM dashboard / internal app

```
┌──────────────────────────────────────────────────────────────────┐
│  [≡] Project Management System        Dashboard Projects Tasks   │
│                                              Contacts  [?][user▾]│
└──────────────────────────────────────────────────────────────────┘
                                                            ▲
                                                            │
                                          NEW: round info-icon
                                          button (aria-label="About")
```

Clicking the `?` icon opens a small disclosure panel anchored under
the navbar (CSS-only, no JS):

```
                                  ┌──────────────────────────────┐
                                  │ [≡] Project Management System│
                                  │                              │
                                  │  Version  v1.7.1.2           │
                                  │                              │
                                  │  Built by Me                 │
                                  │  Vibe coding with AI agent   │
                                  │  (MiniMaxM3)                 │
                                  │                              │
                                  │  View full changelog →       │
                                  │                              │
                                  │                       [Close]│
                                  └──────────────────────────────┘
```

- The disclosure uses `<details><summary>?</summary>…</details>`.
  The `<summary>` is the `?` button; the `<details>` body is the
  card above. Clicking `?` again, or pressing Esc while the body is
  open and focused, closes the disclosure. Built-in browser
  behaviour, no JS.
- The card is rendered as a 280px-wide floating panel, anchored to
  the right of the trigger, with a subtle shadow. It is
  `position: absolute` relative to a `position: relative` wrapper
  around the trigger, so it does not push any other content.
- The "View full changelog" link points to `RELEASE.md`. This link
  is **internal-app only** — it does not appear on the client
  portal popup.
- The trigger is also visible on `login.php`, `register.php`, and
  `forgot_password.php` (these pages include `includes/header.php`
  with `$show_nav = false` — see section 6.2).

### 4.2. Client portal

```
┌──────────────────────────────────────────────────────────────────┐
│ [≡] Project Management System — Client Portal                    │
│                                       Code: AX498-99ZB2-92C3J    │
│                                       [EN] [中文]  [?]  ← NEW    │
└──────────────────────────────────────────────────────────────────┘
```

Clicking the `?` icon opens the same disclosure card, but the
content is localized:

```
EN:                                  zh:
┌──────────────────────────────┐    ┌──────────────────────────────┐
│ Project Management System    │    │ 项目管理系统                  │
│                              │    │                              │
│ Version  v1.7.1.2            │    │ 版本  v1.7.1.2               │
│                              │    │                              │
│ Built by Me                  │    │ 由 Me 构建                    │
│ Vibe coding with AI agent    │    │ Vibe coding with AI agent    │
│ (MiniMaxM3)                  │    │ (MiniMaxM3)                  │
│                              │    │                              │
│                       [Close]│    │                       [关闭]  │
└──────────────────────────────┘    └──────────────────────────────┘
```

- The trigger is the same `<details>`/`<summary>` pattern, so it
  works under the issue-020 CSP tightening (no inline JS, no
  external JS, no nonce, no `'unsafe-inline'`).
- The "View full changelog" link is **absent** on the client
  portal. The client surface is anonymous; we do not want to leak
  the internal milestone list to a public visitor.
- On `client/index.php` (the code-entry landing), the trigger sits
  in the `.client-foot` paragraph instead of in the header (there
  is no `.client-header` on the landing). The popup body is
  identical.

### 4.3. State persistence

The popup has **no persistent state**. It opens and closes on
click. We deliberately do **not** remember "user collapsed the
about popup" in `localStorage` — the whole point of the popup is
that a user can find the version at any time, and a "I will not
show this again" flag defeats that purpose. (This matches the
existing `assets/js/script.js` / `assets/js/subtask-manager.js`
philosophy: no `localStorage` for UI chrome.)

---

## 5. Data Flow / Logic

This feature is read-only. There is no write path.

```
┌──────────────────────────────────────────────────────────────┐
│   config.php   const APP_VERSION = 'v1.7.1.2';              │
│   config.template.php   const APP_VERSION = '<placeholder>'; │
│   install.php   writes const APP_VERSION = 'v1.7.1.2';      │
│                            into new config.php               │
└──────────────────┬───────────────────────────────────────────┘
                   │ require_once config.php
                   ▼
┌──────────────────────────────────────────────────────────────┐
│   includes/header.php                                        │
│   echo '<details class="app-about">                          │
│           <summary class="app-about-trigger"                 │
│                    aria-label="About">?</summary>            │
│           <div class="app-about-panel">                      │
│             <h3>' . e(APP_NAME) . '</h3>                     │
│             <p>Version  ' . e(APP_VERSION) . '</p>            │
│             <p>Built by Me<br>                               │
│                Vibe coding with AI agent (MiniMaxM3)</p>     │
│             <a href="RELEASE.md">View full changelog →</a>   │
│             <button type="button"                            │
│                     onclick="this.closest(                    │
│                       details).open=false">Close</button>     │
│           </div>                                             │
│         </details>';                                         │
└──────────────────┬───────────────────────────────────────────┘
                   │ rendered into every page that includes
                   │ includes/header.php
                   ▼
┌──────────────────────────────────────────────────────────────┐
│   client/dashboard.php (and client/index.php)                │
│   echo '<details class="app-about app-about--client">        │
│           <summary class="app-about-trigger"                 │
│                    aria-label="' . e(t('about.trigger_aria')) │
│                    + '">?</summary>                          │
│           <div class="app-about-panel">                      │
│             <h3>' . e(APP_NAME) . '</h3>                      │
│             <dl>                                             │
│               <dt>' . e(t('about.version')) . '</dt>          │
│               <dd>' . e(APP_VERSION) . '</dd>                │
│               <dt>' . e(t('about.built_by')) . '</dt>        │
│               <dd>Me<br>                                     │
│                   Vibe coding with AI agent (MiniMaxM3)</dd>  │
│             </dl>                                            │
│             <button type="button"                            │
│                     onclick="this.closest(                    │
│                       details).open=false">                  │
│               ' . e(t('about.close')) . '                    │
│             </button>                                        │
│           </div>                                             │
│         </details>';                                         │
└──────────────────────────────────────────────────────────────┘
```

Notes on the inline `onclick` handler:

- The internal app's CSP allows `'unsafe-inline'` for `script-src`
  (`includes/security_headers.php:30`), so the Close button works
  without ceremony.
- The **client portal's CSP does NOT** allow `'unsafe-inline'`. The
  `onclick` on the Close button is therefore NOT used on the client
  surface. Instead, the client portal popup omits the explicit
  Close button entirely and relies on the native `<details>`
  behaviour: clicking the `?` trigger again, or pressing Esc, or
  losing focus and clicking elsewhere, all close the disclosure.
  This is a deliberate simplification — the trade-off is one less
  UI affordance on the client surface in exchange for zero JS and
  zero CSP risk. The EN popup shows `[Close]` because it is a
  decorative label; the ZH popup shows `[关闭]`. The buttons are
  rendered with `hidden` attribute on the client portal so they
  never ship to the browser.

Wait — that re-introduces an inline event handler. The
`onclick="this.closest('details').open=false"` is on the
internal-app Close button only. On the client portal the button is
omitted entirely. The spec is:

- **Internal app**: `<details>` + summary + body + Close button
  with inline `onclick`. CSP allows it.
- **Client portal**: `<details>` + summary + body, **no Close
  button**, no inline JS. Native browser behaviour only.

The `<details>` toggle itself is the browser's built-in, not an
inline event handler, so it works under the issue-020 CSP.

---

## 6. Schema / API Definition

### 6.1. New `APP_VERSION` constant

Add to `config.php` (between the `APP_URL` line and the `TIMEZONE`
line) and to `config.template.php` (in the same place):

````php
const APP_VERSION = 'v1.7.1.2';
````

- The string is the `vX.Y.Z` form, with a leading lowercase `v`.
- It matches the version on the most recent `RELEASE.md` top entry
  on the day the constant is bumped.
- For `config.template.php`, the value is set to the current
  shipping version (`v1.7.1.2` at the time this issue is filed).
  When a new release ships, the dev updates the template in lock-step
  with `config.php`. The drift guard in `database/about_popup_test.php`
  fails the build if the two diverge.

### 6.2. `install.php` writer change

The install wizard's `var_export()`-based config writer
(`install.php:166-187`) currently emits 14 constants. Add one more
line, in the same `APP_*` block:

````php
. "const APP_NAME    = 'Project Management System';\n"
. "const APP_VERSION = " . var_export($current_version, true) . ";\n"
. "const APP_URL     = " . var_export($app_url, true) . ";\n"
````

The `$current_version` value is hard-coded in the installer body
(next to the `APP_NAME` line at `install.php:187`), not pulled from
`RELEASE.md` at runtime. The reason: the install wizard does not
have access to the live repo on the target host; we want the
version pinned at install time to match the version the dev was
running the wizard from.

`$current_version` is set to `'v1.7.1.2'` for the v1.7.2 release
(it matches the version of the code that ships the wizard) and
gets bumped by hand on each release as part of the
`docs/RELEASE_PROCESS.md` checklist.

### 6.3. New `client/lang.php` keys

Add a new top-level `about` namespace to the EN and zh catalogs in
`client/lang.php`. Keys + values:

| Key                | EN                                          | zh             |
| ------------------ | ------------------------------------------- | -------------- |
| `about.trigger_aria` | "About this application"                  | 关于本应用     |
| `about.title`      | "About"                                     | 关于           |
| `about.version`    | "Version"                                   | 版本           |
| `about.built_by`   | "Built by"                                  | 构建者         |
| `about.attribution` | "Vibe coding with AI agent (MiniMaxM3)"    | Vibe coding with AI agent (MiniMaxM3) |
| `about.foot_note`  | "Have feedback? Please contact your project manager." | 如有反馈，请联系您的项目经理。 |

The attribution string is not translated — it names a specific
tool, the same on every locale. (See NG6.)

### 6.4. New CSS

Two new rule blocks, ~40 lines each, in the existing
`assets/css/style.css` and `client/assets/client.css`:

**`assets/css/style.css`** — new `.app-about` block at the bottom
of the navbar section:

```css
/* Application About / Version disclosure (issue-028) */
.app-about {
    position: relative;
}
.app-about-trigger {
    list-style: none;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.95rem;
    user-select: none;
}
.app-about-trigger::-webkit-details-marker { display: none; }
.app-about-trigger:hover { background: rgba(255,255,255,0.22); }
.app-about[open] .app-about-trigger { background: rgba(255,255,255,0.28); }
.app-about-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 280px;
    background: #fff;
    color: #333;
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.18);
    padding: 16px 18px;
    z-index: 1100;
}
.app-about-panel h3 {
    font-size: 1rem;
    margin: 0 0 10px 0;
    color: #007bff;
}
.app-about-panel p { margin: 4px 0; font-size: 0.9rem; }
.app-about-panel a { color: #007bff; text-decoration: none; }
.app-about-panel a:hover { text-decoration: underline; }
.app-about-panel button {
    margin-top: 10px;
    background: #f1f3f4;
    color: #333;
    border: 0;
    border-radius: 4px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 0.85rem;
}
```

**`client/assets/client.css`** — same shape, but anchored to the
client header (right side) and using the existing client color
palette. Close button is omitted (see §5).

The internal-app trigger is rendered inside the `<nav class="navbar">`
block, which has a white-on-blue background — the rule above uses
`rgba(255,255,255,0.12)` so the round button matches the navbar
visual weight without competing with the main nav links.

The client-portal trigger is rendered inside the
`.client-header-right` block, which has a transparent background —
the rule for `.app-about--client .app-about-trigger` uses
`background: rgba(0,123,255,0.10)` so the round button matches the
language-switch link style.

### 6.5. `RELEASE.md` — process section appended at the end

At the bottom of `RELEASE.md`, after the v1.0.0 entry, append:

````markdown
---

## Release process (issue-028)

A six-step checklist the dev runs at the end of every release. The
goal is to keep `APP_VERSION` (the code constant), the top of
`RELEASE.md`, the `README.md` banner, and the in-app About popup
in lock-step. The full checklist lives at
[`docs/RELEASE_PROCESS.md`](docs/RELEASE_PROCESS.md); the short
version:

1. Bump `APP_VERSION` in `config.php` and `config.template.php`.
2. Bump the `current_version` literal in `install.php` (the
   `var_export()` writer at `install.php:~170`).
3. Add a new top entry to `RELEASE.md`.
4. Update the `README.md:3` "Latest release" banner.
5. Run `database/about_popup_test.php` and confirm it passes.
6. Open the About popup on a live instance (PM + client) and
   confirm the new version renders.

`database/about_popup_test.php` enforces invariants 1, 2, 3, 4, 5
at test time — it fails the build if any of them drift.
````

### 6.6. `README.md` change

Two edits to `README.md`:

1. Line 3 "Latest release" banner — already updated on every
   release. The new contract is "this banner is a human-edited
   summary of `const APP_VERSION` in `config.php`; the source of
   truth is the constant, the banner is the visible pointer".
2. Add a one-sentence note in the existing features / about block
   of `README.md` (after the feature list, before any
   "Contributing" / "License" sections — there is no such section
   today, so the note is added at the end of the file):

   ````markdown
   ## About this build

   The version string in the "Latest release" banner above is the
   same string as the `APP_VERSION` constant in `config.php` and
   is rendered live in the in-app About popup on every page (the
   `?` icon in the navbar on the internal app, the `?` icon in
   the header on the client portal). See
   [`docs/RELEASE_PROCESS.md`](docs/RELEASE_PROCESS.md) for the
   release checklist the dev runs to keep the three in sync.
   ````

### 6.7. New file `docs/RELEASE_PROCESS.md`

A ~80-line doc. See §9 for the full content.

### 6.8. No new file in `database/migrations/`

There is no schema change. No `2026MMDD_*.sql` file is needed.

---

## 7. Validation & Edge Cases

| # | Surface              | Trigger            | Scenario                                              | Expected                                                              |
| - | -------------------- | ------------------ | ----------------------------------------------------- | --------------------------------------------------------------------- |
| 1 | Internal — `dashboard.php`        | Navbar `?` button  | Authenticated user, clicks `?`                        | Disclosure opens under navbar; shows `APP_NAME`, `v1.7.1.2`, "Built by Me — Vibe coding with AI agent (MiniMaxM3)", "View full changelog →", Close button. |
| 2 | Internal — `dashboard.php`        | Navbar `?` button  | Authenticated user, clicks `?` again                  | Disclosure closes.                                                    |
| 3 | Internal — `dashboard.php`        | Close button       | Authenticated user, clicks "Close" inside disclosure | Disclosure closes. No page navigation.                                |
| 4 | Internal — `projects.php`         | Navbar `?` button  | Same as #1, on a different page                       | Same disclosure content (the trigger is in the shared header).        |
| 5 | Internal — `login.php`            | Navbar `?` button  | Logged-out user on the login page                     | Trigger still visible (the version applies to the build, not the user); disclosure shows the same content. |
| 6 | Internal — `register.php`         | Navbar `?` button  | Logged-out user on the register page                  | Same as #5.                                                           |
| 7 | Internal — keyboard               | Tab, Enter, Esc    | User tabs to the `?` button, presses Enter, then Esc  | Enter opens, Esc closes. Native `<details>` behaviour.               |
| 8 | Internal — screen reader          | VoiceOver / NVDA   | `aria-label="About"` is announced on the summary.    | NVDA announces "About, button, collapsed" / "expanded".               |
| 9 | Client — `client/dashboard.php`   | Header `?` button  | Anonymous stakeholder on EN locale, clicks `?`        | Disclosure opens; shows localized strings; **no** "View full changelog" link. |
| 10 | Client — `client/dashboard.php` | Header `?` button | Anonymous stakeholder on zh locale, clicks `?`        | Disclosure opens; shows zh strings (版本, 由 Me 构建, 关闭). |
| 11 | Client — `client/index.php`      | `.client-foot` `?` | Visitor on the code-entry landing (no header), clicks `?` | Disclosure opens; same content as the dashboard popup.                |
| 12 | Client — CSP                     | `script-src`       | Open browser DevTools on client portal                | **No** CSP violation is reported. The disclosure has zero inline JS. |
| 13 | Client — keyboard                | Tab, Enter, Esc    | Same as #7, on the client surface                     | Same.                                                                 |
| 14 | Client — screen reader           | VoiceOver / NVDA   | Same as #8                                            | aria-label localized via `t('about.trigger_aria')`.                   |
| 15 | Code — `APP_VERSION` constant    | `config.php`       | Dev greps the repo for `APP_VERSION`                  | Two hits: `config.php` and `config.template.php`, both equal to `v1.7.1.2`. |
| 16 | Code — `APP_VERSION` drift        | Manual edit        | Dev changes `config.php` to `v1.7.2.0` but forgets to update `config.template.php` | `database/about_popup_test.php` layer-1 assertion `APP_VERSION values agree across config.php and config.template.php` fails. |
| 17 | Code — `install.php` writer      | Fresh install      | User runs the install wizard on a clean host          | Generated `config.php` contains `const APP_VERSION = 'v1.7.1.2';`.     |
| 18 | Docs — `README.md` drift          | Manual edit        | Dev changes `config.php` to `v1.7.2.0` but forgets the `README.md:3` banner | `database/about_popup_test.php` layer-3 assertion `README.md:3 banner carries the same version as APP_VERSION` fails. |
| 19 | Docs — `RELEASE.md` drift         | Manual edit        | Dev changes `config.php` to `v1.7.2.0` but forgets to add a top entry to `RELEASE.md` | `database/about_popup_test.php` layer-3 assertion `RELEASE.md top entry carries the same version as APP_VERSION` fails. |
| 20 | Docs — process missing            | Manual edit        | Dev deletes `docs/RELEASE_PROCESS.md`                 | `database/about_popup_test.php` layer-4 assertion `docs/RELEASE_PROCESS.md exists` fails. |
| 21 | `RELEASE.md` process section     | At end of file     | Dev reads the bottom of `RELEASE.md`                  | The "Release process" section is present and links to `docs/RELEASE_PROCESS.md`. |
| 22 | JS-disabled (defensive)           | Internal app       | Browser has JS disabled; user clicks `?`              | `<details>` still toggles (no JS used). Disclosure opens / closes.   |
| 23 | Print / `prefers-reduced-motion`  | Internal app       | User prints a page or has reduced-motion enabled     | No animation is set; the disclosure is a static block.                |
| 24 | Long app name                    | Internal app       | `APP_NAME` is changed to a 60-character string        | `.app-about-panel` clips with `overflow: hidden` and `text-overflow: ellipsis`; no layout shift. |
| 25 | Very old browser                 | Both surfaces      | User on IE11 (the project supports "modern browsers"; IE11 is best-effort) | `<details>` is supported in IE11 via polyfill — the rest of the project also relies on `<details>` (issue-020 CSP comment uses it as a reference). If we want to support IE11, add a tiny `details`-polyfill. The project does not ship one today; same trade-off as the rest of the app. |

---

## 8. Acceptance Criteria

A reviewer can verify the issue is done by checking, in order:

1. **Source of truth** — `config.php` and `config.template.php`
   each contain `const APP_VERSION = 'v1.7.1.2';` and the two
   strings are byte-identical.
2. **Install wizard** — Running the install wizard on a clean host
   produces a `config.php` with `const APP_VERSION = 'v1.7.1.2';`.
3. **PM surface** — On `dashboard.php`, `projects.php`,
   `tasks.php`, `contacts.php`, `project_detail.php`,
   `task_detail.php`, `profile.php`, `login.php`, `register.php`,
   `forgot_password.php`, the navbar carries a round `?` button
   with `aria-label="About"`. Clicking it opens a disclosure
   showing the app name, the version, "Built by Me — Vibe coding
   with AI agent (MiniMaxM3)", "View full changelog →" link, and
   a Close button. The version in the disclosure is `v1.7.1.2`.
4. **Client surface** — On `client/dashboard.php?code=…` and
   `client/index.php`, the header (or `.client-foot` on the
   landing) carries a round `?` button. Clicking it opens a
   localized disclosure. EN: "About / Version / Built by / Close";
   zh: "关于 / 版本 / 构建者 / 关闭". The developer-attribution
   string is "Vibe coding with AI agent (MiniMaxM3)" on both
   locales. The "View full changelog" link is **absent** on the
   client surface. DevTools show **no CSP violation**.
5. **Drift guards** — `database/about_popup_test.php` exists and
   passes locally and in CI. The test has the four layers
   described in §11.
6. **Release process** — `docs/RELEASE_PROCESS.md` exists, is
   linked from the bottom of `RELEASE.md`, and is summarised in
   `README.md`. A new dev can read it and ship a release in
   <10 minutes.
7. **README banner** — `README.md:3` is `> **Latest release:**
   v1.7.1.2 (2026-10-16) — see [RELEASE.md](RELEASE.md) for the
   full changelog.` (unchanged from the v1.7.1.2 ship; no edit
   for v1.7.2 because the banner pointer is already correct).
8. **No regressions** — All pre-existing tests pass:
   - `database/security_headers_test.php` (the new trigger does
     not affect the CSP).
   - `database/install_wizard_test.php` (the writer now emits
     one more constant; the test must be updated — see §11.2).
   - `client/toggle_subtasks_test.php` (unaffected).
   - The 9 other regression tests listed in RELEASE.md v1.7.1.2.

---

## 9. The release-process doc

The new file `docs/RELEASE_PROCESS.md` is the durable artefact
that prevents the drift this issue is fixing. Full content:

````markdown
# Release process

A six-step checklist the dev runs at the end of every release.
The goal is to keep four artefacts in lock-step:

1. `const APP_VERSION` in `config.php` and `config.template.php`
   (the code constant — the single source of truth).
2. The `current_version` literal in `install.php` (the install
   wizard writer).
3. The top entry of `RELEASE.md` (the changelog).
4. The "Latest release" banner in `README.md:3` (the user-facing
   pointer).

Plus a fifth artefact that the four above feed:

5. The in-app About popup on every page (the `?` icon in the
   navbar on the internal app, the `?` icon in the header on the
   client portal). The popup reads `APP_VERSION` at render time,
   so updating #1 alone is enough to make the popup show the new
   version on the next page load.

## Checklist

When you are about to ship a release, in order:

### Step 1 — Bump the version constant

Edit `config.php`:

```php
const APP_VERSION = '<NEW_VERSION>';
```

Edit `config.template.php` to the same `<NEW_VERSION>`. The two
must be byte-identical. (The drift guard in
`database/about_popup_test.php` will fail the build if they
diverge.)

`<NEW_VERSION>` is the `vX.Y.Z` form, e.g. `v1.7.2`. If you are
shipping a hotfix, use `v1.7.1.3`; do not skip patch numbers.

### Step 2 — Bump the install-writer version

Edit `install.php`. In the `$config_content` `var_export()` writer
block (around line 187), update the hard-coded `current_version`
literal that backs the `APP_VERSION` line:

```php
. "const APP_VERSION = " . var_export('v1.7.2', true) . ";\n"
```

The version you write here is the version of the code that ships
the wizard, not the version the wizard installs — they are the
same on a clean install because the dev always runs the wizard
from the just-tagged code.

### Step 3 — Add a `RELEASE.md` top entry

Open `RELEASE.md`. At the very top (above the current top entry),
add a new section in the same format as the others:

```markdown
## <NEW_VERSION> — <YYYY-MM-DD>

**One-line summary.** Closes [issue-NNN](docs/issues/issue-NNN.md):
<one paragraph describing the change in user-visible terms>.
</NEW_VERSION>

### Files changed

- ...

### Upgrade notes

- ...
```

The date is the date you are shipping, in UTC, in `YYYY-MM-DD`
form. The format mirrors the existing entries — the drift guard
in `database/about_popup_test.php` parses the top entry with a
strict regex.

### Step 4 — Update the `README.md` banner

Open `README.md`. On line 3, change the "Latest release" banner
to the new version and date:

```markdown
> **Latest release:** <NEW_VERSION> (<YYYY-MM-DD>) — see [RELEASE.md](RELEASE.md) for the full changelog.
```

### Step 5 — Run the drift-guard test

```bash
php database/about_popup_test.php
```

The test has four layers (see the file's docblock for the full
list). It will fail loudly if any of the four artefacts above
are out of sync. Do not ship a release with a failing test.

### Step 6 — Smoke-test the popup on a live instance

Pull the new code, reload the page in a browser, click the `?`
icon in the navbar, and confirm the version in the popup matches
`<NEW_VERSION>`. Do this on **both** the internal app and the
client portal.

For the client portal, also check the EN and zh locales:

```
https://<host>/client/dashboard.php?code=<your-test-code>&lang=en
https://<host>/client/dashboard.php?code=<your-test-code>&lang=zh
```

## What if a step fails

- **Step 1 fails** (e.g. you forget to update the template): the
  drift guard in Step 5 will catch it. Fix the template, re-run.
- **Step 2 fails** (the wizard writes the old version): the
  install-wizard test will catch it. Fix the literal, re-run.
- **Step 3 fails** (the changelog is missing or has the wrong
  date): the drift guard in Step 5 will catch it. Add the entry,
  re-run.
- **Step 4 fails** (the banner is stale): the drift guard in
  Step 5 will catch it. Fix the banner, re-run.
- **Step 5 fails** (any drift guard fails): read the failure
  message, fix the relevant file, re-run. Do **not** ship with a
  failing drift guard.
- **Step 6 fails** (the popup shows the old version): the most
  likely cause is the PHP opcache is serving the old `config.php`.
  Reload the page in a hard-refresh window, or restart
  `pmp-app` (`docker compose restart app`).

## Version-numbering rules

This project uses semantic-ish versioning, in the form
`vMAJOR.MINOR.PATCH`:

- **MAJOR** bumps are breaking schema changes or breaking UX
  rewrites. Bumping MAJOR resets MINOR and PATCH to 0.
- **MINOR** bumps are feature releases. Bumping MINOR resets
  PATCH to 0.
- **PATCH** bumps are bug-fix or hotfix releases on top of the
  current MINOR.

Examples:

- v1.5.0 → v1.5.1 (issue-011 client time display fix)
- v1.5.0 → v1.5.2 (two hotfixes on the same MINOR)
- v1.5.1 → v1.6.0 (breaking CSP tightening in issue-020)
- v1.7.1 → v1.7.1.1 (issue-026 hotfix on the v1.7.1 MINOR)
- v1.7.1.1 → v1.7.1.2 (issue-027 hotfix on the v1.7.1.1 PATCH)
- v1.7.1.2 → v1.7.2 (issue-028 — this release)
````

The doc also includes a short "Why this matters" section
explaining the v1.7.1.2 drift as the canonical example.

---

## 10. Implementation Notes

### 10.1. File-by-file change list

| File                                        | Change                                                                                                                |
| ------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `config.php`                                | Add `const APP_VERSION = 'v1.7.1.2';` between `APP_NAME` and `APP_URL`.                                               |
| `config.template.php`                       | Add `const APP_VERSION = 'v1.7.1.2';` in the same place.                                                              |
| `install.php`                               | Update `$config_content` writer to emit the new `APP_VERSION` line. Update the `current_version` literal to `'v1.7.1.2'`. |
| `includes/header.php`                       | Add the new `<details class="app-about">` block inside the navbar, between the Contacts link and the user dropdown.    |
| `client/dashboard.php`                      | Add the new `<details class="app-about app-about--client">` block inside `.client-header-right`, before the language switch. |
| `client/index.php`                          | Add the same block inside the `.client-foot` paragraph (no header on this page).                                       |
| `client/lang.php`                           | Add the six new keys to the EN and zh catalogs (see §6.3).                                                            |
| `assets/css/style.css`                      | Append the `.app-about` block at the bottom of the navbar section (~40 lines, see §6.4).                              |
| `client/assets/client.css`                  | Append the `.app-about--client` block at the bottom of the file (~30 lines).                                          |
| `RELEASE.md`                                | Append the "Release process" section at the end (see §6.5).                                                          |
| `README.md`                                 | Append the "About this build" section at the end of the file (see §6.6).                                              |
| `docs/RELEASE_PROCESS.md`                   | New file, ~120 lines (see §9).                                                                                        |
| `docs/issues/issue-028.md`                  | This file.                                                                                                            |
| `database/about_popup_test.php`             | New test file, ~200 lines (see §11).                                                                                  |
| `database/install_wizard_test.php`          | Update to expect the new `APP_VERSION` constant in the parsed installer output.                                        |

### 10.2. Render order matters

The `<details>` block in `includes/header.php` must be **inside
the `.nav-menu` flex container** so the absolutely-positioned
`.app-about-panel` anchors to the trigger. If the trigger is
floated out of the flex container, the panel will anchor to the
wrong element and appear in the wrong place.

### 10.3. No change to `client/assets/js/subtask-toggle.js`

The issue-027 controller is unaffected. The new popup is a
separate DOM subtree.

### 10.4. No change to `includes/security_headers.php`

The internal-app CSP already allows `'unsafe-inline'`, so the
Close button's `onclick` is fine. The client-portal CSP is
unchanged (still no `'unsafe-inline'`), and the client-popup has
no inline JS.

### 10.5. Why `<details>`, not `<dialog>`

Two reasons:

1. **CSP**: `<details>` is a native HTML element with built-in
   toggle behaviour, so it needs zero JS. `<dialog>` needs
   `dialog.showModal()` and `dialog.close()`, which are JS calls.
   On the client portal, where CSP forbids inline JS, `<dialog>`
   would require an external controller file, mirroring the
   issue-027 pattern. `<details>` sidesteps the problem entirely.
2. **Symmetry**: using `<details>` on both surfaces keeps the
   code identical, the test surface small, and the behaviour
   predictable. The internal app does not need the
   `<dialog>`-only features (modal backdrop, focus trap) — a
   `<details>` is a disclosure, which is the right semantic for
   "tap to see app info".

If a future issue wants a true modal (e.g. a confirmation dialog
that blocks the rest of the page), `<dialog>` is the right
choice. That is a different feature and is out of scope here.

---

## 11. Test plan

### 11.1. New file `database/about_popup_test.php`

The test has four layers; it is a layered test (no live DB
required) because everything under test is file-content and
string-grep:

**Layer 1 — Source of truth invariants.** Asserts that
`config.php` and `config.template.php` each define
`const APP_VERSION = '<X>';` and the two `<X>` strings are
byte-identical. Asserts that the value matches the version
encoded in the regex extracted from the top of `RELEASE.md` and
the version encoded in the `README.md:3` "Latest release"
banner. (This is the four-way drift guard.)

**Layer 2 — Install-writer invariants.** Asserts that
`install.php`'s `$config_content` block includes a line that
emits `const APP_VERSION = ` and that the literal it emits
matches the `APP_VERSION` constant in `config.php`. (This
prevents a future "I forgot to update the install writer" bug.)

**Layer 3 — Markup invariants.** Asserts that
`includes/header.php` contains a `<details class="app-about">`
block with a `<summary class="app-about-trigger">` and an
`aria-label="About"`. Asserts that the body of the block echoes
`APP_VERSION` and the hard-coded developer-attribution string.
Asserts that `client/dashboard.php` and `client/index.php` each
contain a localized variant of the same block, with no "View full
changelog" link.

**Layer 4 — Process invariants.** Asserts that
`docs/RELEASE_PROCESS.md` exists, is non-empty, contains the
phrases "Bump the version constant", "Bump the install-writer
version", "Add a `RELEASE.md` top entry", "Update the
`README.md` banner", and "Smoke-test the popup on a live
instance" (the five step headings). Asserts that the bottom of
`RELEASE.md` links to `docs/RELEASE_PROCESS.md`. Asserts that
`README.md` contains a link to `docs/RELEASE_PROCESS.md`.

**Layer 5 — Visual / manual smoke.** Listed as a manual step in
§6 of `docs/RELEASE_PROCESS.md`. Not part of the automated
test.

### 11.2. Update `database/install_wizard_test.php`

The installer-writer test (around line 380) currently asserts 13
expected constants in the parsed `config.php`. Add a 14th:

```php
'APP_VERSION' => "'v1.7.1.2'",
```

And add a regression assertion that the writer's emitted
`APP_VERSION` line uses `var_export()` (not the heredoc
`addslashes()` form that the test disallows elsewhere).

### 11.3. Update `database/security_headers_test.php` (comment only)

Line 307-309 (the "JS on the client portal is only …" comment)
is unchanged — the new popup has zero JS on the client portal,
so the existing comment is still correct. No edit.

### 11.4. No change to `client/toggle_subtasks_test.php`

The issue-027 controller is unaffected. The new popup is a
separate DOM subtree.

### 11.5. No change to `client/lang.php`'s test (none exists)

There is no test for `client/lang.php` today. The new keys are
exercised by the markup assertions in layer 3 (the client
markup is grep'd for the new `t('about.*')` keys).

---

## 12. Out of Scope (deferred to a future issue)

- **A "What's new in this version" highlight on first login
  after a release.** The popup is always available; we do not
  plan a "first-paint" callout. If a future PM wants it, file
  issue-029.
- **Server-rendered build hash.** The version is a constant; it
  does not change between deploys of the same code. If a future
  PM wants a per-deploy hash, file issue-030.
- **Telemetry on how often the popup is opened.** Not planned.
- **A "Check for updates" link.** The app is self-hosted; there
  is no central update service. The popup is a manual reference,
  not a live check.
- **Translating the developer-attribution string.** The
  attribution names a specific tool and is not localizable. The
  surrounding labels (Version / Built by / Close) are
  localized.

---

## 13. Cross-references

- [issue-020](./issue-020.md) — tightened the client-portal CSP
  to drop `'unsafe-inline'`. The decision to use `<details>`
  (no JS) instead of `<dialog>` (JS) is driven by this CSP.
- [issue-027](./issue-027.md) — extracted the sub-tasks toggle
  controller into an external JS file for the same reason. The
  new popup deliberately uses `<details>` so it does not need a
  companion JS file. The new test's layer 1 is the same
  drift-guard pattern as the new test's
  `subtask-toggle.js` invariants in `client/toggle_subtasks_test.php`.
- [issue-026](./issue-026.md) — the most recent hotfix, and the
  release that exposed the drift this issue is fixing (README
  banner + RELEASE.md top entry were updated by hand, no
  in-app verification was possible).
- [issue-025](./issue-025.md) — the parent-task status rule; the
  previous feature in the v1.7.x line. The About popup does not
  interact with it.
- [RELEASE.md v1.7.1.2](../RELEASE.md#v1712--2026-10-16) — the
  release whose drift motivated this issue.
- [RELEASE.md "Release process" section](../RELEASE.md#release-process-issue-028)
  — the user-visible pointer to `docs/RELEASE_PROCESS.md`.

---

## 14. Risk and rollback

**Risk:** low. The change is additive (one new constant, one
new CSS block, one new markup block, one new file). Removing it
is a `git revert` plus a re-emit of `config.php` (the install
wizard will keep writing `APP_VERSION` on a fresh install, which
is harmless if the constant is never read).

**Rollback:** if a regression is reported in v1.7.2, revert the
commit. The drift guard in `database/about_popup_test.php` will
fail on the next CI run because the layer-3 markup assertions
will not find the `<details>` block; that is the correct
behaviour — a rollback removes the popup, the test catches the
drift, the build is correctly red until the popup is reinstated
or the test is updated.

**Caveat:** the install wizard writer change is **not** safely
revertable. If v1.7.2 ships and a future dev runs the install
wizard on a clean host, the generated `config.php` will contain
`const APP_VERSION = 'v1.7.1.2';` (the value baked into
`install.php` at v1.7.2 ship time). If a later release wants to
remove the constant entirely, the wizard writer must be updated
at the same time. The drift guard does not catch this case
because the wizard's `$current_version` is not in the
auto-parsed set; this is a known limitation, documented in
`docs/RELEASE_PROCESS.md` step 2.

---

## 15. Estimated effort

- Constant + install writer: 15 min.
- `includes/header.php` markup + CSS: 30 min.
- `client/dashboard.php` + `client/index.php` markup + CSS: 30 min.
- `client/lang.php` new keys (EN + zh): 20 min.
- `RELEASE.md` process section + `README.md` About section: 20 min.
- `docs/RELEASE_PROCESS.md` (the durable artefact): 60 min.
- `database/about_popup_test.php` (four layers): 90 min.
- `database/install_wizard_test.php` update: 15 min.
- Manual smoke + drift-guard verification: 20 min.

**Total: ~5 hours.** One dev, one session, no reviewer needed
beyond the standard "test passes + manual smoke" gate.
