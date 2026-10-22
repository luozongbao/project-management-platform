# Issue-035 — v2.0: front-end redesign (complete UX/UI specification + browsable HTML mockup)

> **Status:** spec ready, awaiting UX/UI + dev pickup.
> **Target release:** v2.0 (major — full front-end restyle, **no behaviour change**).
> **Severity:** **n/a** — this is a redesign, not a defect. The primary risk is
> *feature regression during the restyle*, which §9 (Traceability Matrix) exists
> to prevent.
>
> **Author of this spec:** Sa (system-analyst mode). Written from the user's
> request:
> > "I'd like to move on to v.2.0 by redesign our front end for better look and
> > complete. write an issue files for ux/ui desing with complete instruction and
> > complete feature so ux/ui can make a new complete design with out missing any
> > features. I want the complete design writen in html so that I can browse and
> > see it on the browser. the design file should be in docs/design/2.0"
>
> **Deliverables shipped with this issue:**
> | Path | What it is |
> |---|---|
> | `docs/design/2.0/index.html` | **Design index / gallery** — start here |
> | `docs/design/2.0/*.html` | 18 screens + 2 reference sheets (browsable mockup) |
> | `docs/design/2.0/v2-tokens.css` | The design system (tokens + components) |
> | `docs/design/2.0/v2-mockup.js` | Interaction patterns the production JS must reproduce |
> | `docs/design/2.0/_build/` | Generator source (Python) — regenerates the mockup |
> | `docs/issues/issue-036.md` | Ops: serve the mockup on `http://localhost:8080` |
>
> **Upstream dependencies:** none. v2.0 branches from v1.8.0 (the current HEAD).
>
> **Prior context:** v1.8.0 shipped the delayed / on-hold reason feature
> (issue-034). v2.0 **must preserve every v1.8.0 capability**, including the two
> reason fields, the client-portal reason pills, and the delayed / on-hold
> dashboard counters. That is the reason §9 exists.

---

## Table of contents

1. Scope and the red line
2. What "v2.0" means for this codebase (current state audit)
3. Design principles
4. The design system (`v2-tokens.css`)
5. Layout architecture
6. Component library
7. Screen-by-screen specification (all 18 screens)
8. Responsive, accessibility, i18n and theming rules
9. **Traceability Matrix** (v1.8.0 → v2.0, feature by feature)
10. Implementation guidance for the dev
11. Validation table
12. Out of scope
13. Cross-references
14. Risk and rollback
**Appendix C — URL routing and slugs (the v2.0 routing contract)** ← *normative*

---

## 1. Scope and the red line

### 1.1 In scope

- Visual and structural redesign of **every** user-facing screen.
- A shared design system expressed as CSS custom properties.
- A responsive layout (desktop → tablet → mobile) where the current app has none.
- Accessibility remediation (contrast, focus rings, labels, ARIA, keyboard operation).
- Preparing the internal app for bilingual operation without forcing the migration.

### 1.2 The red line — nothing may be dropped

> **v2.0 is a restyle. It may not remove, rename, merge, weaken or defer any
> business capability that exists in v1.8.0.**

Concretely, the following are **out of bounds** for the redesign:

| Forbidden | Why |
|---|---|
| Removing a form field, even if it looks redundant | The field may carry data the owner needs (e.g. `total_time_unit`) |
| Removing or renaming a button, menu entry, filter or stat card | Users navigate by muscle memory |
| Weakening a validation rule (typed confirmation, required reason, rate limit) | Security + data integrity |
| Removing the CSRF token, the share-code flow, or the attempt throttles | Security |
| Removing an AJAX endpoint or changing its contract | The client portal and the task detail page depend on them |
| Dropping the client-portal ENG/中文 switch | The client reads the portal in Chinese |
| Dropping the "About / version" panel | It is the only place the release version is visible |
| Changing any route, query parameter, or form `action` | These are the integration contract with the DB layer and bookmarks |
| Changing the email templates / SMTP behaviour | Out of scope for a UI issue |

> ⚠️ **Exception — the route row above is superseded by Appendix C.** v2.0
> **does** change the route URLs: pages move from `/foo.php` to slug URLs
> (`/foo`), and old `.php` URLs 301-redirect to their slug equivalent. **The
> exception is strictly limited to the URL *shape*.** The following remain
> **absolutely unchanged**, and Appendix C is binding on them:
>
> - **The client share-code URL.** `/?code=XXXXX-XXXXX-XXXXX` keeps its exact
>   shape, its 5-5-5 format, and every code already issued to a client keeps
>   working. See **Appendix C.1**.
> - **Every query-parameter name** (`code`, `token`, `id`, `project_id`,
>   `status`, `parent_id`).
> - **Every AJAX endpoint path** and its request/response contract.
> - **The i18n key set.**
>
> The dev must read Appendix C before writing any route code.

**The traceability matrix in §9 is the enforcement mechanism.** Every row of §9
is a capability that exists in v1.8.0 and must exist in v2.0. If a screen in
the mockup cannot be traced to a §9 row, it is out of scope. If a §9 row has
nowhere to live in the mockup, the mockup is incomplete.

---

## 2. What "v2.0" means for this codebase (current state audit)

The dev must understand the starting point before touching anything.

### 2.1 Current front-end shape

| Aspect | v1.8.0 reality | v2.0 target |
|---|---|---|
| Layout | Single top navbar (`includes/header.php`), full-width content | Fixed left sidebar + topbar + content column |
| Styling | One 984-line `assets/css/style.css`, **no CSS custom properties**, Bootstrap-4-era hardcoded hex colours (`#007bff`, `#6c757d`, `#28a745`, `#dc3545`, `#ffc107`, `#17a2b8`, `#f8f9fa`) | `v2-tokens.css` (`:root` variables) + component layer + per-page layer |
| Page CSS | Duplicated inline `<style>` blocks in nearly every `.php` file | Component classes only; page CSS only where genuinely unique |
| Component vocabulary | `.form-group`, `.btn`, `.card`, `.table`, `.alert`, `.badge`, `.text-*`, `.bg-*`, `.mt-*` | `.field`/`.input`/`.select`/`.textarea`, `.btn` + variants, `.card` + parts, `.table`, `.alert` + variants, `.badge` + variants, `.status--*` |
| Responsive | None | 4 breakpoints (§8.1) |
| Dark mode | None | `prefers-color-scheme` token overrides (opt-in) |
| Accessibility | Labels present but no focus ring management, no ARIA, low-contrast greys | WCAG AA, visible focus, ARIA on all interactive widgets |
| Icons | Font Awesome 6.0.0 CDN | Same CDN (no new dependency) |
| Fonts | System default | `--font-sans` (Inter + system fallbacks), `--font-mono` for codes |
| Internal i18n | English only | Structure prepared for bilingual; client portal keeps ENG/中文 |
| Client portal i18n | ENG/中文 via `client/lang.php` + `t()` | Unchanged mechanism, extended key set |

### 2.2 Current route inventory (the surface v2.0 must cover)

| # | Route | Access | v2.0 mockup file | v2.0 slug URL (Appendix C) |
|---|---|---|---|---|
| 1 | `index.php` | Public | `landing.html` | `/` — **stays at the domain root** |
| 2 | `index.php?code=<code>` / POST code | Public | `landing.html` → `client-dashboard.html` | **`/?code=<code>` — UNCHANGED (C.1)** |
| 3 | `login.php` | Public | `login.html` | `/login` |
| 4 | `register.php` | Public | `register.html` | `/register` |
| 5 | `forgot_password.php` | Public | `forgot-password.html` | `/forgot-password` |
| 6 | `reset_password.php` | Public | `reset-password.html` | `/reset-password?token=…` |
| 7 | `dashboard.php` | Auth | `dashboard.html` | `/dashboard` |
| 8 | `projects.php` | Auth | `projects.html` | `/projects` |
| 9 | `project_detail.php` | Auth | `project-detail.html` | `/projects/<slug>` |
| 10 | `project_edit.php` | Auth | `project-form.html` | `/projects/<slug>/edit` · `/projects/new` |
| 11 | `tasks.php` | Auth | `tasks.html` | `/tasks` |
| 12 | `task_detail.php` | Auth | `task-detail.html` | `/tasks/<slug>` |
| 13 | `task_edit.php` | Auth | `task-form.html` | `/tasks/<slug>/edit` · `/tasks/new` |
| 14 | `contacts.php` | Auth | `contacts.html` | `/contacts` |
| 15 | `contact_detail.php` | Auth | `contact-detail.html` | `/contacts/<slug>` |
| 16 | `contact_edit.php` | Auth | `contact-form.html` | `/contacts/<slug>/edit` · `/contacts/new` |
| 17 | `profile.php` | Auth | `profile.html` | `/profile` |
| 18 | `client/dashboard.php` | Public (code-keyed) | `client-dashboard.html` | **`/client/dashboard.php?code=…` — UNCHANGED (C.1)** |

AJAX endpoints (contract must not change, even if the visual affordance does):

| Endpoint | Purpose | v2.0 UI surface |
|---|---|---|
| `ajax/complete_task.php` | Mark a task complete | `task-detail.html` → *Complete Task* |
| `ajax/update_task_status.php` | Change status (+ `on_hold_reason`) | `task-detail.html` → status badge / *Put on Hold* |
| `ajax/update_task_progress.php` | Update completion % | `task-form.html` → completion slider |
| `ajax/parent_hold.php` | Put a parent task on hold (requires reason) | `task-detail.html` → *Put on Hold* modal |
| `ajax/update_status_reason.php` | Edit a reason in place | `task-detail.html` → *Status Reason* card |
| `ajax/get_project_contacts.php` | Populate contact pickers | `task-form.html` → contact select |

---

## 3. Design principles

1. **One source of truth for colour.** Every colour is a `:root` variable. No
   hex literals in component CSS.
2. **Status is a system, not a decoration.** Five task states
   (`not_started`, `in_progress`, `completed`, `on_hold`, `delayed`) each get a
   fixed background/foreground token pair, and **every** surface that shows a
   status uses the same `.status--<state>` class.
3. **Never encode meaning in colour alone.** Every status badge carries a text
   label; every reason chip carries a prefix ("Delay:" / "On hold:").
4. **Density with air.** The current app crams forms; v2.0 uses a 4px spacing
   scale and a real grid so forms are scannable without becoming airy.
5. **Destructive actions are quarantined.** Delete lives in a *Danger zone* card
   or a modal, never in the primary action row, and always behind a typed
   confirmation.
6. **Same structure everywhere.** Every list screen uses the same
   `page-header → alert (optional) → stat-grid → filter-bar → card(table)`
   rhythm, so the user learns one pattern.
7. **The client portal is a separate product.** It gets its own layout, its own
   responsive rules and its own i18n scope. It must never inherit internal
   chrome (sidebar, user menu, About panel).
8. **Works without JavaScript.** The design degrades to readable HTML; JS only
   adds polish (copy button, toasts, live slider sync).

---

## 4. The design system (`v2-tokens.css`)

### 4.1 Token groups

| Group | Variables | Notes |
|---|---|---|
| Brand | `--brand-50` … `--brand-900` | `--brand-500: #3366ff` is the primary accent |
| Neutrals | `--gray-0` … `--gray-950` | `--gray-0` = page surface, `--gray-50` = app background |
| Semantic | `--success-100/500/700`, `--warning-…`, `--danger-…`, `--info-…` | Always use the **700** variant for text on the **100** variant |
| Status | `--status-{not_started,in_progress,completed,on_hold,delayed}-{bg,fg}` | Derived from the semantic palette; the only status colours allowed |
| Typography | `--font-sans`, `--font-mono`, `--fs-xs..3xl`, `--lh-*`, `--fw-*` | 7 sizes, 4 weights |
| Spacing | `--sp-1` … `--sp-20` | 4px base: `1=4px`, `2=8px`, `3=12px`, `4=16px`, `5=20px`, `6=24px`, `8=32px`, `10=40px`, `12=48px`, `16=64px`, `20=80px` |
| Radii | `--r-sm` 6px, `--r-md` 10px, `--r-lg` 14px, `--r-xl` 20px, `--r-full` | |
| Shadows | `--sh-xs` … `--sh-xl` | 5 levels, all low-alpha so they read on both themes |
| Layout | `--sidebar-w` 260px, `--sidebar-w-collapsed` 76px, `--topbar-h` 64px, `--content-max-w` 1440px | |
| Motion | `--ease`, `--dur-fast` 120ms, `--dur` 200ms, `--dur-slow` 320ms | Honour `prefers-reduced-motion` |

### 4.2 Status colour contract

| State | Background | Foreground | Used by |
|---|---|---|---|
| `not_started` | `--gray-200` | `--gray-700` | neutral, low emphasis |
| `in_progress` | `--info-100` | `--info-700` | active work |
| `completed` | `--success-100` | `--success-700` | done |
| `on_hold` | `--warning-100` | `--warning-700` | orange — **v1.8.0 on-hold reason** |
| `delayed` | `--danger-100` | `--danger-700` | red — **v1.8.0 delay reason** |

> **Do not introduce a 6th status colour.** If a new state is ever needed, it
> needs a token pair here first.

### 4.3 v1.8.0 reason-chip contract (must survive the redesign)

| Chip | Class | Colour | Text |
|---|---|---|---|
| Delay reason | `.reason-chip--delay` | red (`--danger-100` / `--danger-700`) | `Delay: <text>` + `fa-clock-rotate-left` |
| On-hold reason | `.reason-chip--on-hold` | orange (`--warning-100` / `--warning-700`) | `On hold: <text>` + `fa-pause` |

The client portal uses the same two chips (`.reason-pill` in v1.8.0 becomes
`.reason-chip` in v2.0 — same colour semantics, same content).

---

## 5. Layout architecture

### 5.1 Internal (authenticated) shell

```
.app-shell                       grid: [sidebar 260px] [app-main 1fr]
├── .sidebar                    fixed, full height, own scroll
│   ├── .sidebar__brand         icon + APP_NAME + version
│   ├── .sidebar__nav           Dashboard / Projects / Tasks / Contacts
│   │                           + "Account" section: My Profile
│   └── .sidebar__footer        avatar + name + role
└── .app-main
    ├── .topbar                 64px: search · notifications · About · user menu
    ├── .content                max-width 1440px, token padding
    └── .footer
```

### 5.2 Client portal shell (public)

```
.client-shell                   min-height 100vh, gray-50 background
├── .client-header             brand + project name + ENG/中文 switch
├── .client-content            max-width 1080px
│   ├── .client-hero           gradient panel: overall % + bar + 5 counters
│   ├── .client-alert-strip    delayed / on-hold chips
│   ├── .card                  project information grid
│   └── .card                  recursive task tree
└── .footer
```

> The client portal must **never** show the sidebar, the user dropdown, the
> About panel, or any internal navigation.

### 5.3 Public/auth shell

```
.auth-shell                    grid: [aside 1fr] [main 480px]
├── .auth-aside                brand, headline, 3 value bullets  (hidden < 992px)
└── .auth-main
    └── .auth-card             form card, max-width 420px
```

The landing / share-code page uses `.landing` (centred single card) instead —
it is a single-purpose page, not a form.

---

## 6. Component library

Every class below exists in `v2-tokens.css` and is used by at least one screen.

### 6.1 Buttons

| Class | Use |
|---|---|
| `.btn` | Base. Variants: `--primary`, `--secondary`, `--ghost`, `--success`, `--warning`, `--danger` |
| `.btn--sm` / `.btn--lg` / `.btn--block` / `.btn--icon` | Size / layout modifiers |

- Only **one** `.btn--primary` per view region.
- `.btn--danger` only inside a Danger zone or a destructive confirm modal.
- Icon-only buttons require `aria-label` + `title`.

### 6.2 Cards

`.card` → `.card__header` (`.card__title-icon`, `.card__actions`) → `.card__body`
(`--flush` variant removes padding for tables) → `.card__footer`.

### 6.3 Stat tiles

`.stat-grid` → `.stat` (+ `--success`, `--info`, `--on-hold`, `--delayed`) →
`.stat__icon`, `.stat__value`, `.stat__label`, `.stat__meta`.

**The dashboard's delayed and on-hold tiles MUST use `.stat--delayed` and
`.stat--on-hold` respectively** — that is the v1.8.0 requirement (red /
orange), restyled.

### 6.4 Status & badges

- `.status.status--<state>` — the five task states (label always present).
- `.badge` + `--neutral / --info / --success / --warning / --danger / --brand / --outline`.

### 6.5 Forms

- `.field` (label + control + `.hint` / `.error-text`).
- `.input`, `.select`, `.textarea`; `.input[readonly]` and `.input:disabled`
  get a muted surface.
- `.checkbox` / `.radio`; `.checkbox-list--grid` for project pickers.
- `.range-row` — slider + number input + live `%` readout (`.range-value`).
- `.form-grid`, `--2`, `--3`, `--4`; `.form-section` spans the full row.
- Required marker: `<span class="req">*</span>` plus `required` on the control.

### 6.6 Tables & lists

- `.table` (+ `--compact`), `.table-wrap` (horizontal scroll on mobile),
  `.row-actions` (right-aligned action cluster).
- `.empty-state` — icon + title + explanation + primary action.
- `.timeline` — the Recent Activity feed.
- `.detail-list` / `.detail-row` — key/value readouts (`.detail-row__k` / `--v`).

### 6.7 Task tree

`.task-tree` (nested) → `.task-node` (+ `--completed`, `--delayed`, `--on-hold`)
→ `.task-node__head` (name + status badges) → `.task-node__meta` →
`.task-node__progress` (`.progress` + `.task-node__pct`) →
`.task-node__reasons` (the reason chips).

> The nested tree is the **client portal's most important component**. It must
> remain readable at depth 3+ on a phone.

### 6.8 Feedback

- `.alert` + `--info / --success / --warning / --danger / --brand`, each with
  `.alert__title` and `.alert__body`.
- `.toast` + `--success / --danger / --warning`, stacked bottom-right.
- `.modal-backdrop` > `.modal` > `.modal__header` / `.modal__body` / `.modal__footer`.
- `.dropdown` + `.dropdown__menu` + `.dropdown__item` + `.dropdown__divider`.

### 6.9 Layout helpers

`.grid-2`, `.grid-sidebar` (1fr + 340px), `.grid-sidebar--wide` (400px),
`.grid-sidebar--left` (300px + 1fr), `.filter-bar` + `.filter-bar__actions`,
`.page-header` + `.page-header__text` + `.page-actions`, `.breadcrumb`,
`.chip-row`, `.project-grid` + `.project-card`, `.share-box`, `.kanban`.

---

## 7. Screen-by-screen specification

> The authoritative visual reference for every screen below is the HTML mockup
> in `docs/design/2.0/`. This section states the **required content**, so a
> designer can verify the mockup has missed nothing.

### 7.1 `index.php` → `landing.html` — public landing / share-code entry

**Must contain**

1. The APP_NAME / brand mark.
2. A **single** input for the share code, with the code-entry form posting to
   the same route as v1.8.0.
3. A **language switch** (ENG / 中文) — must persist in the 1-year cookie.
4. A link to **owner sign in** (→ `login.php`).
5. Copy explaining what the code is for.
6. The **floating About panel** must remain reachable without JS.

**Must not contain:** the sidebar, the user dropdown, any internal stat.

### 7.2 `login.php` → `login.html`

**Must contain:** username field, password field, submit button, "forgot
password?" link, a link to register, the rate-limit / lockout message area,
and the About panel. **No** register link is shown when registration is
disabled by config — the dev must keep that conditional.

### 7.3 `register.php` → `register.html`

**Must contain:** name, username, email, submit; password-policy hints if
present in v1.8.0; the About panel.

### 7.4 `forgot_password.php` → `forgot-password.html`

**Must contain:** email field, submit, the generic "if the address exists we
will send a link" message, and the **attempt-throttle** messaging. The form
must not reveal whether an address is registered.

### 7.5 `reset_password.php` → `reset-password.html`

**Must contain:** new password, confirm password, token-driven submission, and
the invalid/expired-token error state.

### 7.6 `dashboard.php` → `dashboard.html`

**Must contain — 6 stat cards, in this order:**

1. **Overall Completion %**
2. **Total Projects** (meta: "N completed")
3. **Pending Tasks** (meta: "N total")
4. **Delayed Tasks** → `.stat--delayed` (red) — *v1.8.0*
5. **On Hold Tasks** → `.stat--on-hold` (orange) — *v1.8.0*
6. **Upcoming Deadlines**

**Must contain — 3 widgets:** Recent Projects · Upcoming Deadlines · Recent Tasks.

**Must contain:** the **v1.8.0 backfill banner** above the stat grid, telling
the owner which delayed/on-hold tasks still need a reason. It links to the
tasks list filtered by `missing_reason=1`.

### 7.7 `projects.php` → `projects.html`

**Must contain:** the project **card** grid (status badge, due date, "N tasks",
"N pending"), a **status filter**, and a **delete** action whose confirmation
requires typing the exact **Project Code** (`delete-project-confirmation`).
The empty state ("No Projects Yet" + Add Project) must be designed.

### 7.8 `project_detail.php` → `project-detail.html`

**Must contain:**

1. The **share code** block (`<code id="shareCode">`) with a copy button and
   the client share URL.
2. **4 stats**: average completion %, total tasks, uncompleted tasks, contact count.
3. **Project tasks** list.
4. **Project contacts** list.
5. A **project details** panel (scope, budget + currency, start / expected /
   completion dates, status).
6. **Rotate share code** — destructive-adjacent, must carry its own warning
   (it invalidates the previously shared link).

### 7.9 `project_edit.php` → `project-form.html`

**Must contain — all 9 fields:** name, status (`not_started` / `in_progress` /
`completed` / `on_hold`), description, scope, budget amount (number, step 0.01,
min 0), currency (**THB, USD, CNY, JPY, SGD, EUR** + "— No budget —"), start
date, expected completion date, completion date.

**Plus:** the share-code card with **Rotate share code**, and a **Danger zone**
card with the typed-confirmation delete + confirm modal.

### 7.10 `tasks.php` → `tasks.html`

**Must contain:**

1. **4 stat cards** (total / completed / pending / average completion).
2. The **filter bar**: search, project filter, status filter — the status
   filter list must include **`delayed`** as a selectable value.
3. The **v1.8.0 orange backfill banner** when `?missing_reason=1` is present.
4. **Task rows** with: name, project, status badge, responsible person,
   contact person, due date (red when overdue and not completed), completed
   date, subtask count, actions.
5. **v1.8.0 per-task reason chip** (`fa-exclamation-circle` superscript) and a
   **Delayed** badge on delayed rows.

### 7.11 `task_detail.php` → `task-detail.html`

**Must contain — 4 page actions, in this order:**

1. **Complete Task** — green; hidden when the task is already completed or has subtasks.
2. **Put on Hold / Resume** — only for a parent task with subtasks; opens the
   hold modal, which **requires a reason**.
3. **Edit Task**
4. **Add Subtask**

**Must contain — cards:** Task Overview · Subtasks tree · Task Details
(Created, Expected Completion, Completed) · **Status Reason** (the v1.8.0
editor) · Recent Activity timeline. The parent-task relationship must be
shown when the task is a subtask.

**Status Reason card requirements (v1.8.0):**

- Collapsed editor (`<details>` in v1.8.0; an always-visible card in v2.0 —
  either is acceptable, but the controls must exist).
- Two independent fields: **delay reason** and **on-hold reason**.
- Saving posts to `ajax/update_status_reason.php` with `field` ∈
  `{on_hold_reason, delay_reason}`.
- The **on-hold field is disabled** while the task is not on hold.
- Character cap 2000, shown as a hint.

### 7.12 `task_edit.php` → `task-form.html`

**Must contain — every field in v1.8.0:**

| Field | Control | Notes |
|---|---|---|
| `name` | text | **required** |
| `description` | textarea | |
| `project_id` | select | required |
| status | readonly display + hidden input + select | `not_started` / `in_progress` / `completed` / `on_hold` |
| `completion_percentage` | range 0–100 step 5 **+** number input | live-synced |
| `on_hold_reason` | textarea | **required while `on_hold`** — shown conditionally |
| `delay_reason` | textarea | **required while delayed** — shown conditionally |
| `inherit_subtask_hours` | checkbox | sums subtask estimates |
| `total_time` | number | + `total_time_unit` select (`hours` / `mandays`) |
| `responsible_person_id` | select | |
| `contact_person_id` | select | "No Contact Person" option |
| `expected_completion_date` | date | |
| `completion_date` | datetime-local | |

**Conditional-reason behaviour:** selecting **On Hold** reveals and requires the
on-hold textarea; any non-completed status reveals and requires the delay
textarea; a banner warns when the task is past its expected completion date.

### 7.13 `contacts.php` → `contacts.html`

**Must contain:** **4 stat cards** (total contacts, with email, with phone,
projects covered), a filter bar (search + project filter + reset), and
**contact cards** with avatar, position, company, and action buttons
(email / call / edit). A contact with no email must render a non-link
placeholder, not a broken mailto.

### 7.14 `contact_detail.php` → `contact-detail.html`

**Must contain:** **3 stats** (Projects, Total Tasks, Completed) · Recent Tasks
· **Contact Information** (Email, Phone, Mobile, Address, Added, Updated) ·
**Social Media** (WeChat, LINE, Facebook, LinkedIn) · **Projects (N)**.

### 7.15 `contact_edit.php` → `contact-form.html`

**Must contain — all 12 fields:** name (required), position, company,
description, email, phone, mobile, address, wechat, line_id, facebook (URL),
linkedin (URL) — plus **`projects[]` checkboxes** for project assignment.

### 7.16 `profile.php` → `profile.html`

**Must contain:** **4 stat tiles** (Projects, Tasks, Completed, Contacts) ·
form 1 `update_profile` (name, username, email) · form 2 `change_password`
(current password, new password, confirm password) · the action-buttons row.

### 7.17 `client/dashboard.php` → `client-dashboard.html`

**Must contain:**

1. Header: brand + project name + **ENG / 中文** switch.
2. **Hero:** overall completion % + bar + counters for Tasks, Completed,
   In Progress, **Delayed**, **On Hold**.
3. **v1.8.0 summary chip row** — delayed count and on-hold count with a
   `fa-flag` marker.
4. **Project information** grid: name, scope, budget, start / expected /
   completion dates, status, manager, contact PM, last updated.
5. **Recursive task tree** with status badges + progress bars per node, the
   **"Not estimated"** marker for tasks with no time estimate, and the
   **v1.8.0 reason pills** (delay = red, on-hold = orange) on the relevant nodes.
6. The **subtask show all / show top** toggle for deep trees.
7. The footer.

**Must not contain:** any internal navigation, any edit affordance, any
task-assignment control. The portal is strictly read-only.

### 7.18 Reference sheets (not application screens)

- `components.html` — the design-system reference: colour swatches, type
  scale, buttons, badges, statuses, reason chips, task node, alerts, table,
  form controls, empty state.
- `docs-design-notes.html` — implementation guidance for the dev (layer
  model, shell migration, implementation order, a11y checklist, i18n plan,
  breakpoints, dark mode, the red line).

---

## 8. Responsive, accessibility, i18n and theming rules

### 8.1 Breakpoints

| Breakpoint | Behaviour |
|---|---|
| ≥ 1200px | Full layout; sidebar 260px; detail column 340px |
| 992–1199px | Sidebar collapses to icons (76px); detail column drops below |
| 768–991px | Sidebar becomes an off-canvas drawer; stat grid → 2 cols; filter bar wraps |
| < 768px | Single column; tables scroll horizontally; stat grid → 1 col |
| < 480px | Client hero stats stack; page actions go full width |

### 8.2 Accessibility (WCAG 2.1 AA)

| Rule | Requirement |
|---|---|
| Contrast | Body text ≥ 4.5:1, large text and UI borders ≥ 3:1 |
| Focus | Every interactive element shows a visible focus ring; `outline:none` is only allowed with a replacement |
| Keyboard | All actions keyboard-reachable; modals trap focus and close on `Esc` |
| Labels | Every control has a `<label for>`; required fields marked with `.req` **and** `required` |
| ARIA | `role="dialog" aria-modal="true"` on modals; `aria-label` on breadcrumbs, icon buttons, groups |
| Status colour | Never colour-only — every badge and chip has text |
| Motion | Honour `prefers-reduced-motion`; transitions ≤ 320ms |
| Language | `<html lang>` set per active language on the client portal |

### 8.3 i18n

- The **client portal keeps** ENG / 中文 via `client/lang.php` + `t()`; the
  1-year language cookie is unchanged.
- The mockup demonstrates the mechanism with `data-i18n="English|中文"`; the
  production implementation uses the existing `t('dash.*')` keys.
- New keys required in `client/lang.php` for the v1.8.0 reasons:
  `dash.delay_reason`, `dash.on_hold_reason`, `dash.delayed_count`,
  `dash.on_hold_count`.
- The internal app remains English-only in v2.0, but every visible string
  should sit in a `lang.php`-style map so the internal UI can be localised
  later without a rewrite.

### 8.4 Theming

- Light theme is the default and the only theme shipped in v2.0.
- Dark mode is a `prefers-color-scheme` token override set — **no component
  rule is duplicated**. Status pairs must be re-verified for AA on dark
  surfaces before dark mode is enabled in production.

---

## 9. Traceability Matrix (v1.8.0 → v2.0)

> **This is the enforcement table for the red line in §1.2.** Each row is a
> capability that exists today. Every row must exist in v2.0. The "Mockup
> anchor" column says where to see it.

### 9.1 Public / auth

| # | v1.8.0 capability | Route / source | v2.0 mockup anchor |
|---|---|---|---|
| 1 | Share-code entry form (GET + POST) | `index.php` | `landing.html` — code input |
| 2 | Share-code URL resolves to the client portal | `index.php?code=` | `landing.html` → `client-dashboard.html` |
| 3 | Owner redirected to the dashboard when logged in | `index.php` | `landing.html` (redirect, not rendered) |
| 4 | ENG / 中文 picker on the public landing | `index.php` | `landing.html` — language switch |
| 5 | IP rate limit on code attempts | `index.php` | `landing.html` — error state |
| 6 | Login: username + password | `login.php` | `login.html` |
| 7 | Login rate limit / lockout message | `login.php` | `login.html` — alert |
| 8 | Forgot-password link | `login.php` | `login.html` — footer link |
| 9 | Register: name + username + email | `register.php` | `register.html` |
| 10 | Forgot-password request by email | `forgot_password.php` | `forgot-password.html` |
| 11 | Reset attempt throttling | `forgot_password.php` | `forgot-password.html` — alert |
| 12 | Reset with token: new + confirm password | `reset_password.php` | `reset-password.html` |
| 13 | Invalid / expired token state | `reset_password.php` | `reset-password.html` — alert |
| 14 | Floating About panel on logged-out pages | `includes/header.php` | `landing.html`, `login.html` |

### 9.2 Shell

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 15 | Brand icon + APP_NAME in the header | `includes/header.php` | every screen — `.sidebar__brand` |
| 16 | Nav: Dashboard / Projects / Tasks / Contacts | `includes/header.php` | every screen — `.sidebar__nav` |
| 17 | About panel: version, "Built by", release summary | `includes/header.php` | every screen — topbar `?` dropdown |
| 18 | User dropdown: Profile + Logout | `includes/header.php` | every screen — topbar avatar dropdown |
| 19 | Footer with © year + APP_NAME | `includes/footer.php` | every screen — `.footer` |
| 20 | `displayMessage()` flash area | `includes/functions.php` | `.alert` region at the top of `.content` |

### 9.3 Dashboard

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 21 | Overall Completion % tile | `dashboard.php` | `dashboard.html` — stat 1 |
| 22 | Total Projects tile + "N completed" | `dashboard.php` | `dashboard.html` — stat 2 |
| 23 | Pending Tasks tile + "N total" | `dashboard.php` | `dashboard.html` — stat 3 |
| 24 | **Delayed Tasks tile (red)** | `dashboard.php` (v1.8.0) | `dashboard.html` — stat 4, `.stat--delayed` |
| 25 | **On Hold Tasks tile (orange)** | `dashboard.php` (v1.8.0) | `dashboard.html` — stat 5, `.stat--on-hold` |
| 26 | Upcoming Deadlines tile + widget | `dashboard.php` | `dashboard.html` — stat 6 + sidebar widget |
| 27 | Recent Projects widget | `dashboard.php` | `dashboard.html` — Recent Projects |
| 28 | Recent Tasks widget | `dashboard.php` | `dashboard.html` — Recent Tasks |
| 29 | **v1.8.0 backfill banner** (missing reasons) | `dashboard.php` | `dashboard.html` — banner above the stat grid |

### 9.4 Projects

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 30 | Project card: status badge, due date, N tasks, N pending | `projects.php` | `projects.html` — project card |
| 31 | Project status filter | `projects.php` | `projects.html` — filter bar |
| 32 | Empty state "No Projects Yet" + Add Project | `projects.php` | `projects.html` — `.empty-state` |
| 33 | Delete project with **typed Project Code** confirmation | `projects.php` | `projects.html` — delete modal |
| 34 | Share code + client share URL | `project_detail.php` | `project-detail.html` — `.share-box` |
| 35 | Copy share code | `project_detail.php` | `project-detail.html` — copy button |
| 36 | Rotate share code (+ last-rotated stamp) | `project_edit.php` | `project-form.html` — share card |
| 37 | Stats: avg completion %, total tasks, uncompleted tasks, contacts | `project_detail.php` | `project-detail.html` — 4 stats |
| 38 | Project tasks list | `project_detail.php` | `project-detail.html` — tasks card |
| 39 | Project contacts list | `project_detail.php` | `project-detail.html` — contacts card |
| 40 | Field: name | `project_edit.php` | `project-form.html` |
| 41 | Field: status (4 values) | `project_edit.php` | `project-form.html` |
| 42 | Field: description | `project_edit.php` | `project-form.html` |
| 43 | Field: scope | `project_edit.php` | `project-form.html` |
| 44 | Field: budget amount (number, 0.01, min 0) | `project_edit.php` | `project-form.html` |
| 45 | Field: currency (THB/USD/CNY/JPY/SGD/EUR + none) | `project_edit.php` | `project-form.html` |
| 46 | Field: start date | `project_edit.php` | `project-form.html` |
| 47 | Field: expected completion date | `project_edit.php` | `project-form.html` |
| 48 | Field: completion date | `project_edit.php` | `project-form.html` |
| 49 | Danger-zone delete with typed code + confirm modal | `project_edit.php` | `project-form.html` — danger card + modal |

### 9.5 Tasks

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 50 | Task list with status badge, responsible, contact, due, completed, subtasks | `tasks.php` | `tasks.html` — table |
| 51 | Overdue due date highlighted | `tasks.php` | `tasks.html` — red due date |
| 52 | Filters: search, project, status (incl. **`delayed`**) | `tasks.php` | `tasks.html` — filter bar |
| 53 | `?missing_reason=1` backfill filter | `tasks.php` (v1.8.0) | `tasks.html` — orange banner |
| 54 | **Per-task reason chip** | `tasks.php` (v1.8.0) | `tasks.html` — `.reason-chip` |
| 55 | **Delayed badge on delayed rows** | `tasks.php` (v1.8.0) | `tasks.html` — `.status--delayed` |
| 56 | Page action: Complete Task (hidden if completed / has subtasks) | `task_detail.php` | `task-detail.html` — action 1 |
| 57 | Page action: Put on Hold / Resume (parent w/ subtasks only) | `task_detail.php` | `task-detail.html` — action 2 + modal |
| 58 | Page action: Edit Task | `task_detail.php` | `task-detail.html` — action 3 |
| 59 | Page action: Add Subtask | `task_detail.php` | `task-detail.html` — action 4 |
| 60 | Breadcrumb Dashboard / Tasks / Project / Task | `task_detail.php` | `task-detail.html` |
| 61 | Task Overview card | `task_detail.php` | `task-detail.html` |
| 62 | Parent-task relationship shown | `task_detail.php` | `task-detail.html` — Parent Task card |
| 63 | Subtask list with per-row status + progress | `task_detail.php` | `task-detail.html` — Subtasks card |
| 64 | Task Details: Created / Expected Completion / Completed | `task_detail.php` | `task-detail.html` — Task Details card |
| 65 | **Status Reason card — delay reason** (editable, ≤ 2000 chars) | `task_detail.php` (v1.8.0) | `task-detail.html` — Status Reason card |
| 66 | **Status Reason card — on-hold reason** (editable, ≤ 2000 chars) | `task_detail.php` (v1.8.0) | `task-detail.html` — Status Reason card |
| 67 | Recent Activity timeline | `task_detail.php` | `task-detail.html` — Recent Activity |
| 68 | Field: name (required) | `task_edit.php` | `task-form.html` |
| 69 | Field: description | `task_edit.php` | `task-form.html` |
| 70 | Field: project_id select | `task_edit.php` | `task-form.html` |
| 71 | Field: status display (read-only) + status select | `task_edit.php` | `task-form.html` |
| 72 | Field: completion % (range 0–100 step 5 + number) | `task_edit.php` | `task-form.html` — `.range-row` |
| 73 | **Field: on_hold_reason (required when on hold)** | `task_edit.php` (v1.8.0) | `task-form.html` — conditional panel |
| 74 | **Field: delay_reason (required when delayed)** | `task_edit.php` (v1.8.0) | `task-form.html` — conditional panel |
| 75 | Field: inherit_subtask_hours checkbox | `task_edit.php` | `task-form.html` |
| 76 | Field: total_time + total_time_unit (hours/mandays) | `task_edit.php` | `task-form.html` |
| 77 | Field: responsible_person_id | `task_edit.php` | `task-form.html` |
| 78 | Field: contact_person_id | `task_edit.php` | `task-form.html` |
| 79 | Field: expected_completion_date | `task_edit.php` | `task-form.html` |
| 80 | Field: completion_date (datetime-local) | `task_edit.php` | `task-form.html` |

### 9.6 Contacts

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 81 | 4 stat cards (total, with email, with phone, projects) | `contacts.php` | `contacts.html` |
| 82 | Search + project filter + reset | `contacts.php` | `contacts.html` — filter bar |
| 83 | Contact card: avatar, position, company, email/call/edit | `contacts.php` | `contacts.html` |
| 84 | Contact stats: Projects / Total Tasks / Completed | `contact_detail.php` | `contact-detail.html` |
| 85 | Recent Tasks card | `contact_detail.php` | `contact-detail.html` |
| 86 | Contact Information: Email, Phone, Mobile, Address, Added, Updated | `contact_detail.php` | `contact-detail.html` |
| 87 | Social Media: WeChat, LINE, Facebook, LinkedIn | `contact_detail.php` | `contact-detail.html` |
| 88 | Projects (N) card | `contact_detail.php` | `contact-detail.html` |
| 89 | Field: name (required) | `contact_edit.php` | `contact-form.html` |
| 90 | Field: position | `contact_edit.php` | `contact-form.html` |
| 91 | Field: company | `contact_edit.php` | `contact-form.html` |
| 92 | Field: description | `contact_edit.php` | `contact-form.html` |
| 93 | Field: email | `contact_edit.php` | `contact-form.html` |
| 94 | Field: phone | `contact_edit.php` | `contact-form.html` |
| 95 | Field: mobile | `contact_edit.php` | `contact-form.html` |
| 96 | Field: address | `contact_edit.php` | `contact-form.html` |
| 97 | Field: wechat | `contact_edit.php` | `contact-form.html` |
| 98 | Field: line_id | `contact_edit.php` | `contact-form.html` |
| 99 | Field: facebook (URL) | `contact_edit.php` | `contact-form.html` |
| 100 | Field: linkedin (URL) | `contact_edit.php` | `contact-form.html` |
| 101 | Field: `projects[]` checkboxes | `contact_edit.php` | `contact-form.html` |

### 9.7 Profile

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 102 | 4 stat tiles (Projects, Tasks, Completed, Contacts) | `profile.php` | `profile.html` |
| 103 | Form `update_profile`: name, username, email | `profile.php` | `profile.html` — Profile information |
| 104 | Form `change_password`: current, new, confirm | `profile.php` | `profile.html` — Change Password |
| 105 | Action-buttons row | `profile.php` | `profile.html` — page actions |

### 9.8 Client portal

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 106 | Public, code-keyed, read-only view | `client/dashboard.php` | `client-dashboard.html` |
| 107 | Project info: name, scope, budget, dates, status | `client/dashboard.php` | `client-dashboard.html` — info grid |
| 108 | Overall completion % | `client/dashboard.php` | `client-dashboard.html` — hero |
| 109 | Manager / contact PM / last updated | `client/dashboard.php` | `client-dashboard.html` — info grid |
| 110 | Recursive task tree with status badges + progress | `client/dashboard.php` | `client-dashboard.html` — task tree |
| 111 | "Not estimated" marker | `client/dashboard.php` | `client-dashboard.html` — `.not-estimated` |
| 112 | **Delay reason pill (red)** | `client/dashboard.php` (v1.8.0) | `client-dashboard.html` — `.reason-chip--delay` |
| 113 | **On-hold reason pill (orange)** | `client/dashboard.php` (v1.8.0) | `client-dashboard.html` — `.reason-chip--on-hold` |
| 114 | **Delayed / on-hold summary chip row** | `client/dashboard.php` (v1.8.0) | `client-dashboard.html` — `.client-alert-strip` |
| 115 | ENG / 中文 switch | `client/dashboard.php` | `client-dashboard.html` — header switch |
| 116 | Subtask show-all / show-top toggle | `client/dashboard.php` | `client-dashboard.html` — tree controls |
| 117 | Empty state when the project has no tasks | `client/dashboard.php` | `client-dashboard.html` — `.empty-state` |

### 9.9 Cross-cutting

| # | v1.8.0 capability | Source | v2.0 mockup anchor |
|---|---|---|---|
| 118 | CSRF token in `<meta name="csrf-token">` | `includes/header.php` | — (behaviour, unchanged) |
| 119 | Security headers sent on every page | `includes/security_headers.php` | — (behaviour, unchanged) |
| 120 | `APP_NAME` / `APP_VERSION` / `TIMEZONE` config guard | `includes/header.php` | — (behaviour, unchanged) |
| 121 | Flash messages via `displayMessage()` | `includes/functions.php` | `.alert` region |
| 122 | Flash-message icons / types | `includes/functions.php` | `.alert--success / --danger / --warning / --info` |
| 123 | Client share URL builder | `buildClientShareUrl()` | `project-detail.html` — `.share-box` |
| 124 | Release summary panel content | `getLatestReleaseSummary()` | topbar About dropdown |
| 125 | Project completion recalculation on task change | `syncProjectTaskCompletions()` | — (behaviour, unchanged) |
| 126 | Task delay computation | `isTaskDelayed()` | — (behaviour, unchanged) |
| 127 | Estimated-hours resolution + formatting | `resolveTaskEstimateHours()`, `formatTaskEstimate()` | `task-form.html`, `task-detail.html` |
| 128 | Font Awesome 6.0.0 icon set | CDN | unchanged — same CDN |

### 9.10 URL routing & slugs (v2.0 routing contract — Appendix C)

| # | v1.8.0 capability | Source | v2.0 requirement |
|---|---|---|---|
| 129 | **Client share-code URL shape `/?code=<5-5-5>`** | `buildClientShareUrl()` | **UNCHANGED** — C.1 R3 |
| 130 | **Share-code format 5-5-5 (`CHAR(17)` column)** | `generateShareCode()`, schema | **UNCHANGED** — C.1 R1 |
| 131 | **Codes already issued keep working** | `projects.share_code` | **UNCHANGED** — C.1 R2 |
| 132 | **Share-code format validator regex** | `isValidShareCodeFormat()` | **UNCHANGED** — C.1 R4 |
| 133 | **Share-code input `pattern` + auto-format JS** | `index.php`, `share-code-format.js` | **UNCHANGED** — C.1 R5, R6 |
| 134 | **Share-code rate limiting (total + distinct)** | `includes/functions.php` | **UNCHANGED** — C.1 R8 |
| 135 | **`dash.code_label` / `landing.code_label` i18n** | `client/lang.php` | **UNCHANGED** — C.1 R9 |
| 136 | **Page routes move from `/foo.php` to slug URLs** | all page files | **NEW in v2.0** — C.2 R11 |
| 137 | **Legacy `.php` URLs 301-redirect to slug URLs** | new | **NEW in v2.0** — C.2 R12 |
| 138 | **Record pages addressable by slug (`/projects/<slug>`)** | new | **NEW in v2.0** — C.2 R14, R18 |
| 139 | **New-record routes (`/projects/new`)** | new | **NEW in v2.0** — C.2 target map |
| 140 | **Slug stored in its own immutable column** | new (`slug` VARCHAR(80) UNIQUE) | **NEW in v2.0** — C.2 R15, R17 |
| 141 | **Slug generation + collision suffixing** | new (`slugify()`) | **NEW in v2.0** — C.2 R16, C.5 |
| 142 | **Slug resolution failure → 404** | new | **NEW in v2.0** — C.2 R13 |
| 143 | **All query-parameter names unchanged** | all pages | **UNCHANGED** — C.2 R14, C.8 |
| 144 | **AJAX endpoint paths unchanged** | `ajax/*.php` | **UNCHANGED** — C.2 "AJAX endpoints are NOT slugs" |
| 145 | **Asset paths root-absolute (`/assets/…`)** | `includes/header.php`, `footer.php`, `index.php`, `client/dashboard.php` | **CHANGED** — C.2 "Critical — asset paths" |
| 146 | **Reset-password email link** | `includes/EmailService.php` | **CHANGED to slug** — C.2 internal link migration |
| 147 | **`/client/` nginx block + `.htaccess` deny rules intact** | `docker/nginx.conf`, `.htaccess` | **UNCHANGED** — C.2 R21 |
| 148 | **Share-code rotation action ("Rotate code")** | `project_edit.php` (issue-023) | **Preserve** — C.1 carve-out |
| 149 | **"Last rotated (N days ago)" display + 90-day nudge** | `project_edit.php` | **Preserve** — C.1 carve-out |
| 150 | **Share-code display + copy-URL control** | `project_edit.php`, `buildClientShareUrl()` | `project-detail.html` — `.share-box` |
| 151 | **Share-code input `pattern` = `[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}`** | `index.php` | `landing.html` — code input |
| 152 | **Code input placeholder `AAAAA-AAAAA-AAAAA` + help text** | `client/lang.php` (`landing.code_placeholder`, `landing.code_help`) | `landing.html` — code input |
| 153 | **Delete confirmation requires an exact Project Code match** | `project_delete.php` (`hash_equals`) | `project-form.html` — delete modal |

**Total tracked capabilities: 153.**

---

## 10. Implementation guidance for the dev

### 10.1 File changes

| Path | Change |
|---|---|
| `assets/css/v2-tokens.css` | **New.** `:root` variables only. Copied from `docs/design/2.0/v2-tokens.css`. |
| `assets/css/style.css` | Rewritten: reset + base + components. Legacy v1.8.0 rules deleted **only after** every page is converted. |
| `assets/css/pages/*.css` | **New**, optional. Only genuinely page-specific rules. |
| `includes/header.php` | Sidebar + topbar shell. The config guard, the CSRF meta tag, the security headers and the floating About panel must survive untouched. |
| `includes/footer.php` | Footer + `assets/js/script.js` unchanged in behaviour. |
| `assets/js/script.js` | Port the mockup interactions (§10.2). |
| `assets/js/subtask-manager.js` | Port the subtask manager (retain the v1.8.0 `flex-wrap` fix on `.task-line`). |
| `client/assets/client.css` | Replace with the component layer + client-portal rules. |
| `client/dashboard.php` | Restyle only — the `render_task_tree()` output contract and the `dash.*` i18n keys stay. **Asset paths only** for the routing work — the URL and the share-code logic are unchanged (C.1). |
| All `.php` view files | Markup updated to the component classes. **No PHP logic changes.** |

**Routing workstream files (Appendix C) are listed in C.6.** The two workstreams
share files, so the dev must sequence them per **C.3**: routing first, visual
redesign second.

### 10.2 Interactions to port from `v2-mockup.js`

| Mockup hook | Production behaviour |
|---|---|
| `[data-sidebar-toggle]` | Toggles `.app-shell.is-collapsed`; persisted |
| `[data-dropdown]` / `.dropdown__menu` | Open/close on click; `Esc` closes; outside click closes |
| `[data-range-sync]` / `[data-range-out]` | Slider ⇄ number sync for completion % |
| `[data-status-select]` / `[data-reason-panel]` | Reveals + requires the delay / on-hold reason textareas |
| `[data-copy]` | Copies the share code / URL to the clipboard |
| `[data-toast]` | Non-blocking feedback |
| `[data-modal-open]` / `[data-modal-close]` | Modal open/close, `Esc`, backdrop click, focus trap |
| `[data-lang-set]` / `[data-i18n]` | ENG / 中文 switch (client portal) |
| `[data-filter-form]` / `[data-filter-reset]` | Filter apply/reset |
| `[data-tree-toggle]` | Subtask tree expand/collapse |

### 10.3 Order of work

> **Appendix C adds a workstream that must run first.** The two workstreams
> touch the same files. The order below is the **full** sequence.

**Phase 0 — routing (Appendix C). Nothing visual changes here.**

0.1 Apply the slug migration (`database/migrations/20261022_add_slugs.sql`).
0.2 Implement `slugify()` / `generateUniqueSlug()` in `includes/functions.php`.
0.3 Add the front controller + route table; add the `.htaccess` / nginx rules.
0.4 Add the legacy `.php` → slug 301 redirects.
0.5 Migrate all internal links and make every asset path root-absolute.
0.6 Run the **§11.3** routing validation. **Do not proceed until V21–V38 pass.**

**Phase 1 — visual redesign.**

1. Land `v2-tokens.css` + reset/base. **Nothing else changes visually yet.**
2. Land the component layer.
3. Replace the shell in `includes/header.php` / `includes/footer.php`.
4. Convert pages, risk-ascending:
   `dashboard.php` → `projects.php` → `tasks.php` → `contacts.php` →
   detail pages → forms → auth pages → `client/dashboard.php`.
5. After **each** page, run the existing `database/*_test.php` suite — those
   tests assert on markup structure and will catch regressions early.
6. Delete each page's inline `<style>` block as it is converted.
7. Only then strip the v1.8.0 rules from `style.css`.
8. Bump `APP_VERSION` to `v2.0` in `config.php`, `config.template.php` and
   `install.php`, and update the assertions in
   `database/about_popup_test.php` and `database/install_wizard_test.php`.

> **Do not merge Phase 0 into Phase 1.** Doing the routing and the visual
> redesign in one pass is how the share-code URL quietly changes and a client
> link 404s. The share-code path must be provably untouched (C.1 R1–R10).

### 10.4 Things the dev must not do

- Do not rename or remove a `name=` / `id=` attribute that a PHP file reads.
- Do not change a form `action`, method or the field names it posts.
- Do not change any AJAX endpoint's request or response shape.
- Do not remove a required-attribute, a typed-confirmation check, or a rate limit.
- Do not inline a hex colour in a component rule — use the token.
- Do not introduce a new JS dependency. The mockup is dependency-free.
- **Do not change the client share-code format, length, storage width, URL
  shape, validator regex, `pattern` attribute, auto-format JS, or rate
  limiting.** (C.1 R1–R10)
- **Do not regenerate a record's slug after it has been assigned.** (C.2 R15)
- **Do not derive a slug from the name at render time.** It must come from the
  stored column. (C.2 R17)
- **Do not change any query-parameter name.** Only path segments become
  slugs. (C.2 R14)
- **Do not weaken the `.htaccess` or nginx deny rules** when adding the slug
  rewrite rules. (C.2 R21)

---

## 11. Validation table

### 11.1 Manual (visual)

| # | Check | Expected |
|---|---|---|
| V1 | Open `docs/design/2.0/index.html` | 18 screen cards + the red-line card |
| V2 | Click every card | The corresponding screen renders with the shared shell |
| V3 | Any screen at 375px | Single column; tables scroll; nav reachable |
| V4 | Any screen at 1440px | Sidebar 260px; content ≤ 1440px and centred |
| V5 | Task form → choose "On Hold" | On-hold textarea appears and is required |
| V6 | Task form → choose "In Progress" | Delay textarea appears and is required |
| V7 | Task form → drag the completion slider | Number input + `%` readout update together |
| V8 | Project form → Delete Project | Modal opens; the typed code is required |
| V9 | Task detail → Put on Hold | Modal requires a reason before it can submit |
| V10 | Client portal → 中文 | Labels switch to Chinese; layout unchanged |
| V11 | Client portal | Delayed = red chip, On hold = orange chip |
| V12 | `components.html` | Every token used by the app appears as a swatch |
| V13 | Keyboard-only pass | Every action reachable; focus ring always visible |
| V14 | OS dark mode | Mockup stays readable (tokens adapt) |

### 11.2 Scripted

| # | Check | Command |
|---|---|---|
| V15 | Mockup regenerates cleanly | `python3 docs/design/2.0/_build/main.py` |
| V16 | No un-interpolated placeholders | `grep -R "{stat\|{sub_rows" docs/design/2.0/*.html` → empty |
| V17 | Balanced HTML | Parse each file with `html.parser` → 0 errors |
| V18 | No broken internal links | Resolve every relative `href`/`src` → all exist |
| V19 | Existing test suite still green | `php database/*_test.php` |
| V20 | No hardcoded hex in component CSS | `grep -n "#[0-9a-f]\{3,6\}" assets/css/style.css` → only in `:root` / dark-mode block |

### 11.3 Routing & slug validation (Appendix C)

| # | Check | Command / assertion |
|---|---|---|
| V21 | **Share-code format unchanged** | `generateShareCode()` output matches `/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/` and `strlen() === 17` |
| V22 | **`share_code` column still `CHAR(17)`** | `SHOW COLUMNS FROM projects LIKE 'share_code'` → `CHAR(17)` |
| V23 | **A pre-v2.0 share code still resolves** | Take a code issued before the release; `SELECT id FROM projects WHERE share_code = ?` still returns the same project |
| V24 | **Client URL shape unchanged** | `buildClientShareUrl('AX498-99ZB2-92C3J')` → `<APP_URL>/?code=AX498-99ZB2-92C3J` (byte-identical) |
| V25 | **Client entry point still works** | `curl -sI "http://host/?code=<valid>"` → 302 to the portal with the **same** code value |
| V26 | **Format validator unchanged** | `isValidShareCodeFormat()` source-grep still shows the 5-5-5 regex |
| V27 | **Share-code rate limiting intact** | `php database/client_share_code_protection_test.php` → passes **unchanged** |
| V28 | **Slug migration is idempotent** | Run the migration twice → identical slug values (no renumbering) |
| V29 | **Slug immutability** | Rename a project → its slug is unchanged; `/projects/<old-slug>` still resolves |
| V30 | **Legacy URLs 301** | `curl -sI http://host/dashboard.php` → `301` + `Location: /dashboard`; repeat for every row of the §C.2 legacy map |
| V31 | **Query strings preserved on 301** | `curl -sI "http://host/project_detail.php?id=12&foo=bar"` → `Location: /projects/<slug>?foo=bar` |
| V32 | **Unknown slug → 404** | `curl -sI http://host/projects/does-not-exist` → `404` (not 500, not a 302 to the list) |
| V33 | **Assets resolve on nested routes** | `curl -sI http://host/assets/css/style.css` → `200`; and the page at `/projects/<slug>/edit` loads the stylesheet (browser check) |
| V34 | **No document-relative asset refs remain** | `grep -rn 'href="\(\.\./\)\?assets/\|src="\(\.\./\)\?assets/\|href="\(\.\./\)\?client/assets/' --include=*.php .` → empty |
| V35 | **No hardcoded `.php` page links remain** | `grep -rn 'href="[a-z_]*\.php\|redirect(' --include=*.php .` → only AJAX endpoints, `client/dashboard.php`, and the `.htaccess`/nginx config |
| V36 | **`/client/lang.php` still blocked** | `curl -sI http://host/client/lang.php` → `404` |
| V37 | **Query parameter names unchanged** | `?code=`, `?token=`, `?id=`, `?project_id=`, `?status=`, `?parent_id=` all still honoured |
| V38 | **AJAX endpoint paths unchanged** | All six `ajax/*.php` paths still return their expected responses |
| V39 | **Rotation action still works after the slug migration** | Rotate a code → the new code is 5-5-5, `share_code_rotated_at` is set, the flash message appears, and the redirect lands on `/projects/<slug>/edit` |
| V40 | **Rotation UI preserved** | The `.share-rotation-card`, "Last rotated: …", and the 90-day nudge are all present in the v2.0 project form |
| V41 | **Share code in the UI is 5-5-5** | Every share code shown in the v2.0 UI matches `/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/` — no `HRP-2026-014`-style codes |
| V42 | **Code input contract preserved** | `id="code"`, `name="code"`, `pattern="[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}"`, `maxlength="17"`, `placeholder="AAAAA-AAAAA-AAAAA"` — all present on the landing page |
| V43 | **The auto-format JS still binds** | Type a lowercase code into the landing input → it uppercases and inserts dashes at 5 and 10, capped at 15 alphanumerics |

---

## 12. Out of scope

- Backend / schema changes. **v2.0 adds no columns, no tables, no endpoints.**
  > ⚠️ **Exception — Appendix C.** v2.0 **does** add three `slug` columns
  > (`projects`, `tasks`, `contacts` — `VARCHAR(80) NOT NULL UNIQUE`) and the
  > routing layer that uses them. **That is the only schema change.** No other
  > column, table, or endpoint is added, and **no existing column is altered**
  > — in particular `projects.share_code` stays `CHAR(17)` (C.1 R1).
- New business features (bulk edit, kanban persistence, notifications,
  reporting, attachments, comments, Gantt).
- The kanban board is present in the token file as a layout primitive but is
  **not** an application screen in v2.0.
- Multi-tenant / role-based access.
- Real-time updates (websockets / SSE).
- The internal app's full translation into Chinese — only the *structure* is
  prepared.
- Dark mode as a user-selectable setting — v2.0 only provides the
  `prefers-color-scheme` token override.
- Email template redesign (the v1.8.0 templates stay byte-identical).

---

## 13. Cross-references

| Document | Relevance |
|---|---|
| `docs/issues/issue-034.md` | v1.8.0 — the delayed / on-hold reason feature that v2.0 must preserve |
| `docs/issues/issue-033.md` | v1.7.3.2 — the config guard and all-pages probe in `includes/header.php` |
| `docs/issues/issue-036.md` | Ops — serving this mockup on `http://localhost:8080` |
| `docs/issues/issue-023.md` | v1.10.0 — the client share-code rate limiting that v2.0 must preserve unchanged (C.1 R8) |
| `docs/issues/issue-020.md` | v1.9.0 — the security headers and the client-portal CSP that constrain the routing work (C.2 R21) |
| `docs/issues/issue-021.md` | v1.7.0 — the install wizard; `APP_URL` derivation is unaffected by the slug work |
| `docs/design/2.0/index.html` | The browsable mockup gallery |
| `docs/design/2.0/components.html` | The design-system reference sheet |
| `docs/design/2.0/docs-design-notes.html` | Implementation notes for the dev |
| `docs/design/2.0/v2-tokens.css` | The design tokens (copy into `assets/css/`) |
| `docs/design/2.0/v2-mockup.js` | The interaction reference (port into `assets/js/`) |
| `docs/requirements.md` | Product requirements history |
| `RELEASE.md` | Release process — the v2.0 bump follows it |

---

## 14. Risk and rollback

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A field is silently dropped during the restyle | **high** | high | §9 traceability matrix; the dev walks it row by row and ticks it off |
| A v1.8.0 reason chip loses its red/orange semantics | medium | high | §4.3 contract; `components.html` shows both chips; the client portal is visually verified against `task-detail.html` |
| The typed-confirmation delete is weakened | low | **critical** | Keep the server-side check; the UI change is cosmetic only. Never move the check to JS. |
| A CSS class name collides with a legacy v1.8.0 rule still in `style.css` | medium | medium | Convert in the documented order; delete legacy rules only at the end |
| The `database/*_test.php` suite fails because the markup changed | **high** | medium | The suite asserts on structure — treat failures as real regressions and update the assertions deliberately, never silently |
| The client portal loses a `dash.*` i18n key | medium | high | §8.3 — the key set is unchanged; only additions are allowed |
| The redesign breaks on a 375px viewport | medium | medium | §8.1 breakpoints + V3 |
| The new CSS bloats the payload | low | low | One minified token + component file; no framework, no new JS dependency |
| **A slug workstream changes the client share-code URL and breaks every link already sent out** | medium | **critical** | **C.1 R1–R10. The share-code flow is a separate workstream from the slug workstream, and the share-code code must be moved verbatim — no edits while moving.** Verify with V21–V27. |
| **A record slug is derived from the name at render time, so renaming a record 404s every link already sent out** | **high** | **critical** | **C.2 R15 + R17. The slug MUST be a stored column, never derived.** The migration backfill must be deterministic (T6, V28). |
| **Document-relative asset paths break on nested slug routes** (`/projects/x/edit/assets/css/style.css` → 404) | **high** | high | C.2 "Critical — asset paths". Every asset ref becomes root-absolute. V33 + V34. |
| **The slug rewrite rules shadow the `.htaccess` / nginx deny rules** | medium | **critical** | C.2 R21 + C.2 "Server configuration". Slug rules go **before** the catch-all; deny rules stay. V36. |
| **A 404 slug error surfaces as a 500** (e.g. `fetchOne()` returns null and the page dereferences it) | medium | medium | C.2 R13 — the dev must add an explicit not-found branch in every slug-resolving page. V32. |
| **The slug migration renumbers slugs on a second run** | medium | medium | C.2 "Schema changes" — deterministic backfill, suffix counter computed over `ORDER BY id`. T6, V28. |
| **A legacy `.php` URL renders the page body before redirecting** (flash of the old page) | low | medium | C.2 "Legacy 301 map" — issue the 301 **before** any session/permission logic. V30. |
| **The root `index.php` becomes a circular require** when a front controller is added | medium | high | C.2 "The front controller" — option (a) extract the landing page, or option (b) a separate dispatcher file. Pick one; do not mix. |
| **The "Rotate code" action is lost or broken during the migration** | medium | high | C.1 carve-out — the rotation POST branch, `share_code_rotated_at`, the confirmation dialog and the flash message must all survive. V39, V40. |
| **The share-rotation card has no mockup equivalent, so the dev drops it** | low | medium | C.1 "The share-code UI must also be preserved" — the mockup covers the rotation button, the last-rotated line and the warning. **Only** the 90-day nudge has no mockup equivalent; carry it over from the v1.8.0 markup. |

### Rollback

v2.0 is a self-contained front-end change:

1. `git revert` the v2.0 merge commit on `dev` — the previous `assets/css/style.css`
   and the previous `includes/header.php` come straight back.
2. No schema migration is involved, so no data rollback is required.
3. `APP_VERSION` returns to `v1.8.0` in the same revert.
4. The mockup under `docs/design/2.0/` is documentation — it can stay or be
   removed independently of the application.

---

## Appendix A — How to review this mockup

```bash
# Option 1 — the ops service (see issue-036)
docker compose up -d mockup
# then open http://localhost:8080/

# Option 2 — any static server
cd docs/design/2.0 && python3 -m http.server 8080

# Option 3 — open the file directly
xdg-open docs/design/2.0/index.html     # or just double-click index.html
```

Start at `index.html`. It lists all 18 screens and links to the design-system
sheet and the implementation notes.

## Appendix B — Regenerating the mockup

The mockup is generated, not hand-maintained:

```bash
python3 docs/design/2.0/_build/main.py
```

| File | Responsibility |
|---|---|
| `_build/build.py` | Shared shell (sidebar, topbar, footer), `page()`, and the reusable fragments (`stat`, `status_badge`, `reason_chip`, `task_node`) |
| `_build/screens_part1.py` | Design index, landing, login, register, forgot, reset |
| `_build/screens_part2.py` | Dashboard, projects, project detail |
| `_build/screens_part3.py` | Project form, tasks, task detail |
| `_build/screens_part4.py` | Task form, contacts, contact detail, contact form, profile, client portal |
| `_build/screens_part5.py` | Design-system sheet, design notes |
| `_build/main.py` | Dispatcher |

The generated `.html` files are **committed** to the repo. The generator is a
convenience for future revisions, not a build step the application depends on.

---

## Appendix C — URL routing and slugs (v2.0 routing contract)

> **Status:** normative. This appendix is part of the v2.0 scope and is binding
> on the dev. It resolves two requirements raised by the product owner:
> **(a)** the client share-code URL given out to clients must keep working, and
> **(b)** v2.0 pages are addressed by **slug**, not by `.php` file name.

### C.1 Requirement 1 — the client share-code URL must not change

#### What is actually in production today

| Fact | Source | Value |
|---|---|---|
| Code format | `includes/functions.php` → `generateShareCode()` | 3 groups of 5 chars from `ABCDEFGHJKMNPQRSTUVWXYZ23456789`, joined by `-` → **17 chars total** |
| Storage width | `database/schema.sql` → `projects.share_code` | **`CHAR(17)`** — the column is physically sized to the 5-5-5 format |
| Uniqueness | `database/schema.sql` | `UNIQUE KEY uq_projects_share_code (share_code)` |
| Format validation | `index.php` → `isValidShareCodeFormat()` | `preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', strtoupper($code))` |
| Input hint | `index.php` → `<input id="code">` | `pattern="[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}"` |
| Live auto-format | `assets/js/share-code-format.js` | uppercases, strips non-alphanumerics, inserts `-` after chars 5 and 10, **caps at 15 alphanumeric chars** |
| Lookup | `client/dashboard.php` | `SELECT … FROM projects … WHERE p.share_code = ?` — **exact string match, no normalisation at lookup** |
| Client URL | `includes/functions.php` → `buildClientShareUrl()` | `rtrim(APP_URL,'/') . '/?code=' . rawurlencode($share_code)` |
| Entry redirect | `index.php` | `header('Location: client/dashboard.php?code=' . rawurlencode($code) . '&lang=' . urlencode($lang))` |
| Attempt log width | `client_share_code_attempts.code_attempted` | `VARCHAR(20)` — holds the 17-char code with 3 spare |
| Rate limiting | `index.php` + `client/dashboard.php` | per-IP total cap (20/5 min) + distinct-failed-code cap (5/5 min), enforced **before** the format check |
| Client-visible label | `client/lang.php` | `dash.code_label` = "Code" (EN) / "代码" (ZH); `landing.code_label` = "Project Code" |

#### The red-line rules

| # | Rule |
|---|---|
| **R1** | **`projects.share_code` stays `CHAR(17)` and stays 5-5-5.** No migration, no re-generation of existing codes, no format change. |
| **R2** | **Existing share codes keep working unchanged.** A code issued before v2.0 must resolve to the same project after v2.0. No re-issue, no invalidation, no "your link expired" path. **The only exception is the pre-existing, deliberate "Rotate code" action** (see the carve-out below) — which the v2.0 work must **carry over unchanged**. |
| **R3** | **The client-facing URL shape stays `/?code=<5-5-5>`.** `buildClientShareUrl()` output is byte-identical before and after v2.0. |
| **R4** | **`isValidShareCodeFormat()` regex is unchanged.** `/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/` |
| **R5** | **The `<input id="code">` `pattern` attribute is unchanged.** |
| **R6** | **`assets/js/share-code-format.js` logic is unchanged** (including the 15-char cap and dash positions). |
| **R7** | **The lookup remains an exact match on the full string.** No slugification, no stripping of dashes, no case-folding at lookup. |
| **R8** | **The `client_share_code_attempts` throttle is unchanged** — same caps, same ordering (throttle **before** format check), same "success clears failure history" behaviour, same `code_attempted` truncation to 20. |
| **R9** | **`dash.code_label` / `landing.code_label` translations are unchanged.** The client reads this label in Chinese. |
| **R10** | **The redirect target from `index.php` keeps the `?code=…&lang=…` query string.** Slug routing (§C.2) must not disturb it. |

#### What the v2.0 redesign must NOT do to this flow

- ❌ Do **not** move the client portal behind a slug that *embeds* the code
  (e.g. `/client/AX498-99ZB2-92C3J`). That would change the URL the client
  received and break every link already sent out. Slug routing applies to the
  **internal application pages only** (§C.2).
- ❌ Do **not** change `buildClientShareUrl()` to emit a slug-shaped URL.
- ❌ Do **not** "modernise" the share code to a longer/shorter/slug format.
- ❌ Do **not** hash the share code or store a slug instead of it.
- ❌ Do **not** remove the `CHAR(17)` width assumption from the schema or from
  `recordShareCodeAttempt()`'s `substr($code, 0, 20)`.
- ❌ Do **not** let the slug router treat `?code=…` as a path segment. The
  client entry point stays at the **domain root** (`/?code=…`).

#### The one thing the dev must actually verify

The slug router (§C.2) introduces path-based dispatch. The client entry point
`index.php` is the **root** route and is reached as `/?code=XXXXX-XXXXX-XXXXX`.
The router must therefore:

1. Serve `/` (with any query string) → the client code-entry page.
2. **Never** treat the `code` query parameter as a slug.
3. Forward `?code=…` to the portal **unchanged** — same value, same encoding.
4. Keep the rate-limit check running on the **GET-with-`?code=`** path exactly
   as today (the direct-link branch is a separate rate-limit entry point from
   the form-POST branch — see `index.php` L69–105).

#### Carve-out — the "Rotate code" action is an intentional invalidation

> ⚠️ **Read this before touching `project_edit.php`.** v1.8.0 already ships a
> **"Rotate code"** feature (issue-023) in `project_edit.php`:
>
> - It generates a **new** code with `generateShareCode()`.
> - It sets `share_code = ?` and `share_code_rotated_at = NOW()`.
> - It shows the user a confirmation warning: *"The previous URL is now
>   invalid."*
> - It redirects to `project_edit.php?id=<id>` with the flash message
>   *"Share code rotated. The previous URL is now invalid."*
>
> **This is deliberate invalidation by the account owner, and v2.0 must carry
> it over unchanged.** Rule R2 ("existing codes keep working") is about the
> **migration itself** not silently breaking codes — it is **not** a mandate
> to remove the rotation feature.
>
> What the v2.0 work must do:
>
> - Keep the rotation POST branch working.
> - Keep generating the new code with `generateShareCode()` (5-5-5).
> - Keep `share_code_rotated_at` and the "Last rotated: … (N days ago)" display.
> - Keep the confirmation dialog and the flash message.
> - **Change only the redirect target**: `project_edit.php?id=<id>` →
>   `/projects/<slug>/edit`.
>
> The "90 days since rotation" nudge shown in the `.share-rotation-card`
> must survive the redesign. It is part of the v1.8.0 UI and belongs in the
> §9 traceability matrix.

#### The share-code UI must also be preserved

The share-code UI in `project_edit.php` and `project_detail.php` contains:

| Element | Source | Mockup anchor | Status in v2.0 |
|---|---|---|---|
| The share code itself (`<code class="share-code-display">`) | `project_edit.php` | `project-form.html` — `.share-box` | **Preserve** |
| The "Last rotated: …" line | `project_edit.php` | `project-form.html` — `.share-box` | **Preserve** |
| The "Rotate code" button + confirmation dialog | `project_edit.php` | `project-form.html`, `project-detail.html` | **Preserve** |
| The "Copy code" control | `buildClientShareUrl()` | `project-detail.html` — `.share-box` | **Preserve** |
| The "Copy link" control (full `/?code=…` URL) | `buildClientShareUrl()` | `project-detail.html` — `.share-box` | **Preserve** |
| The "Rotating the code invalidates the current link" warning | `project_detail.php` | `project-detail.html` — `.alert--warning` | **Preserve** |
| The 90-days-since-rotation nudge | `project_edit.php` | — (not in the mockup) | **Preserve** — carry over from the v1.8.0 markup |
| The delete-confirmation "type the Project Code" input | `project_delete.php` | `project-form.html` — delete modal | **Preserve** |

> **The mockup already covers the rotation UI** (it was verified in
> `project-detail.html` and `project-form.html`). The **only** element with no
> mockup equivalent is the **"90 days since rotation" nudge** — the dev must
> carry that one over from the v1.8.0 `project_edit.php` markup.

### C.2 Requirement 2 — v2.0 pages are addressed by slug

#### The current situation

Every internal page is a top-level `.php` file, and the URL is the file name:

```
/dashboard.php
/projects.php
/project_detail.php?id=12
/task_edit.php?id=34&project_id=12
```

That is the "file-name URL" the product owner wants replaced with slugs.

#### What "slug" means here (precise definition)

A **slug** is a stable, lowercase, hyphen-separated, human-readable path
segment that identifies a page or a record.

| Kind | Example | URL |
|---|---|---|
| **Page slug** (static route) | `projects` | `/projects` |
| **Record slug** (dynamic route) | `north-riverside-housing` | `/projects/north-riverside-housing` |
| **Action slug** | `new`, `edit` | `/projects/new`, `/projects/north-riverside-housing/edit` |

#### Target URL map — the complete list

| v1.8.0 URL | v2.0 slug URL | Route kind | Notes |
|---|---|---|---|
| `/index.php` | `/` | static | Client code entry (root). **Stays at the domain root.** |
| `/login.php` | `/login` | static | |
| `/register.php` | `/register` | static | |
| `/forgot_password.php` | `/forgot-password` | static | |
| `/reset_password.php?token=…` | `/reset-password?token=…` | static | **Query string preserved** |
| `/dashboard.php` | `/dashboard` | static | |
| `/projects.php` | `/projects` | static | |
| `/project_detail.php?id=12` | `/projects/<slug>` | record | Slug resolves to the same project as `id=12` |
| `/project_edit.php?id=12` | `/projects/<slug>/edit` | action | Same record slug |
| `/project_edit.php` (new) | `/projects/new` | action | New-record route |
| `/tasks.php` | `/tasks` | static | Query filters (`?project_id=`, `?status=`) preserved |
| `/task_detail.php?id=34` | `/tasks/<slug>` | record | |
| `/task_edit.php?id=34` | `/tasks/<slug>/edit` | action | |
| `/task_edit.php` (new) | `/tasks/new` | action | |
| `/tasks.php?project_id=12` | `/tasks?project_id=<id>` | static | **The project filter stays numeric** — see R14 |
| `/contacts.php` | `/contacts` | static | |
| `/contact_detail.php?id=56` | `/contacts/<slug>` | record | |
| `/contact_edit.php?id=56` | `/contacts/<slug>/edit` | action | |
| `/contact_edit.php` (new) | `/contacts/new` | action | |
| `/profile.php` | `/profile` | static | |
| `/logout.php` | `/logout` | static | |
| `/project_delete.php` (POST) | `/projects/<slug>/delete` (POST) | action | **POST-only**, unchanged semantics |
| `/client/dashboard.php?code=…` | `/client/dashboard.php?code=…` | — | **UNCHANGED — see §C.1** |

#### Routing rules

| # | Rule |
|---|---|
| **R11** | **The canonical URL is the slug URL.** The old `.php` URL must **301-redirect** to the slug URL. Not a 302, not an internal rewrite that leaves the old URL in the address bar — the address bar must show the slug. |
| **R12** | **Every `.php` URL keeps working forever** via a 301 to its slug equivalent, with the **query string preserved** (`project_detail.php?id=12&foo=bar` → `/projects/<slug>?foo=bar`). Old bookmarks, the emails in the client's inbox, and the `?code=` links all survive. |
| **R13** | **A slug that does not resolve returns the normal not-found page** (404), never a 500 and never a silent redirect to a list page. |
| **R14** | **Record slugs are resolved server-side by slug column, not by parsing the old numeric ID.** The numeric `id` may still be accepted as a fallback, but the slug is the canonical identifier. Filters that reference a record by relation (`?project_id=12`) **keep using the numeric id** — they are query parameters, not route segments. |
| **R15** | **Slugs are immutable once assigned.** A record whose name changes keeps its slug. This is what makes a URL safe to send to a client or to put in a browser bookmark. |
| **R16** | **Slug generation is deterministic and collision-safe:** lowercase the name, strip accents/diacritics, replace any run of non-alphanumeric characters with a single `-`, trim leading/trailing `-`, cap at 60 chars. If the result already exists, append `-2`, `-3`, … until unique. |
| **R17** | **The slug is stored in its own column** (`slug VARCHAR(80) NOT NULL`, `UNIQUE`), **not derived on the fly from the name.** Deriving on the fly breaks R15 the moment the name is edited, and breaks any URL already sent out. |
| **R18** | **Every page that renders a record links to it by slug.** Project list → `/projects/<slug>`; task list → `/tasks/<slug>`; contact list → `/contacts/<slug>`. |
| **R19** | **The slug is optional in the record list UI** but must be visible somewhere the user can copy it (the share/copy control on a project, for example) — it is a shareable identifier. |
| **R20** | **The `?code=` client entry point is NOT a slug route** and must never be routed through the slug resolver. |

#### Why a stored `slug` column is mandatory (not optional)

> A slug derived from the name at render time is a **broken URL generator**.
> "North Riverside Housing" renamed to "Riverside North Residences" would
> change `/projects/north-riverside-housing` into `/projects/riverside-north-residences`,
> and every link already sent to a client, every bookmark, every Slack message,
> and every search-engine link would 404. **R15 + R17 together are the whole
> point.** The dev must add the column; it is not a nice-to-have.

#### Schema changes this appendix requires

> ⚠️ **This is the one place where v2.0 touches the database.** §12 ("Out of
> scope") says "no columns" — **this appendix overrides that statement for the
> three `slug` columns below and nothing else.** No other schema change is
> permitted.

| Table | Column | Type | Notes |
|---|---|---|---|
| `projects` | `slug` | `VARCHAR(80) NOT NULL` | `UNIQUE KEY uq_projects_slug (slug)`. Backfilled from `name`. |
| `tasks` | `slug` | `VARCHAR(80) NOT NULL` | `UNIQUE KEY uq_tasks_slug (slug)`. Backfilled from `name`. |
| `contacts` | `slug` | `VARCHAR(80) NOT NULL` | `UNIQUE KEY uq_contacts_slug (slug)`. Backfilled from `name`. |

Migration file: `database/migrations/20261022_add_slugs.sql`, following the
existing project convention — guarded by `information_schema.COLUMNS` /
`information_schema.STATISTICS` checks so it is idempotent, and it must:

1. Add the three nullable columns first (`VARCHAR(80) NULL`).
2. Backfill each row with the slug generated from its current `name`,
   de-duplicating with the `-2`, `-3` suffix rule (R16).
3. Add the `UNIQUE` indexes.
4. Convert to `NOT NULL`.

> **The backfill must be deterministic and idempotent**: re-running the
> migration must not renumber slugs. The same row must get the same slug on
> every run, so the suffix counter must be computed over the *existing*
> slug values in a stable order (e.g. `ORDER BY id`), never over a
> randomly-ordered set.

**Slug uniqueness is per table, not global.** `projects` and `tasks` may both
have a slug `site-survey` without conflict — they live in different tables and
are addressed under different route prefixes (`/projects/site-survey` vs
`/tasks/site-survey`).

#### Server configuration changes this appendix requires

Both web servers must be updated. **The dev must not assume `.htaccess` alone
covers production** — see the table below.

| Server | Where | Change |
|---|---|---|
| **Apache (production, cPanel/Hostinger)** | `.htaccess` (repo root) | The existing catch-all `RewriteRule ^(.*)$ index.php [QSA,L]` already sends unknown paths to `index.php`. **The dev must add explicit slug → PHP-file rules BEFORE that catch-all**, e.g. `/projects` → `projects.php`, `/projects/<slug>` → `project_detail.php`, `/tasks/<slug>/edit` → `task_edit.php`. Then a PHP-side 301 handles the old `.php` → slug redirect (R12). |
| **nginx (dev / Docker)** | `docker/nginx.conf` | `location / { try_files $uri $uri/ /index.php?$query_string; }` already forwards unknown paths to `index.php`, so the front controller can dispatch. But the `location ~ \.php$` block and the `/client/` blocks must keep working unchanged — see R21. |
| **Production `.htaccess` blocks** | `.htaccess` | `<DirectoryMatch "/(includes\|database\|docs\|vendor)/">` must keep denying those directories, and the `.md`/`.sql` deny rules must stay. The slug rules must not weaken any deny rule. |

**Order matters in `.htaccess`:** the slug rules must come **before** the
catch-all, and the existing security denies (`<DirectoryMatch>`,
`<FilesMatch>`) must keep applying. A slug rule that shadows a deny rule is a
security regression.

#### The front controller

The router lives in the root `index.php` (which is already the front controller
for the `/` route) — or, if the dev prefers, in a dedicated
`includes/Router.php` included by `index.php`.

```php
// Pseudo-code — the dev's implementation may differ, the BEHAVIOUR may not.
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path   = '/' . trim(rawurldecode($path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// The client share-code entry point lives at the domain ROOT and is NOT
// a slug route. `/?code=XXXXX-XXXXX-XXXXX` must reach the code-entry page
// with the query string intact, and must never enter the slug resolver.
if ($path === '/' ) {
    require __DIR__ . '/index.php';   // ← circular! see note below
}
```

> **Implementation note.** The current root `index.php` *is* the client code
> entry page. A front controller cannot simply `require` itself. The dev must
> pick one of these two shapes:
>
> - **(a) Extract the landing page** into `includes/landing.php` (or
>   `public/landing.php`) and have the root `index.php` become a thin
>   dispatcher that requires it for the `/` route. This is the cleaner option
>   and is what the pseudo-code above assumes.
> - **(b) Keep `index.php` as the landing page** and put the dispatcher in a
>   separate entry file (e.g. `router.php`) that the web server points unknown
>   paths at. `index.php` then stays byte-compatible for the `/` route and for
>   the `.htaccess` catch-all.
>
> Option **(a)** is recommended because it keeps **one** front-controller
> concept. Either way, the **`?code=` handling code in `index.php` must be
> moved verbatim** — no logic change, no reformatting, no "while we are here"
> edits to the rate-limit ordering.

#### Route table (complete)

| Method | Slug path | Target file | Auth | Notes |
|---|---|---|---|---|
| GET/POST | `/` | landing page | Public | `?code=…` handled here (R20) |
| GET/POST | `/login` | `login.php` | Public | |
| GET/POST | `/register` | `register.php` | Public | |
| GET/POST | `/forgot-password` | `forgot_password.php` | Public | |
| GET/POST | `/reset-password` | `reset_password.php` | Public | `?token=` preserved |
| GET | `/logout` | `logout.php` | Auth | |
| GET | `/dashboard` | `dashboard.php` | Auth | |
| GET | `/projects` | `projects.php` | Auth | |
| GET | `/projects/new` | `project_edit.php` | Auth | New record |
| GET | `/projects/<slug>` | `project_detail.php` | Auth | Resolve `slug` → project |
| GET | `/projects/<slug>/edit` | `project_edit.php` | Auth | |
| POST | `/projects/<slug>/delete` | `project_delete.php` | Auth | Typed-confirmation check unchanged |
| GET | `/tasks` | `tasks.php` | Auth | `?project_id=`, `?status=` preserved |
| GET | `/tasks/new` | `task_edit.php` | Auth | New record |
| GET | `/tasks/<slug>` | `task_detail.php` | Auth | |
| GET | `/tasks/<slug>/edit` | `task_edit.php` | Auth | `?parent_id=` preserved |
| GET | `/contacts` | `contacts.php` | Auth | |
| GET | `/contacts/new` | `contact_edit.php` | Auth | New record |
| GET | `/contacts/<slug>` | `contact_detail.php` | Auth | |
| GET | `/contacts/<slug>/edit` | `contact_edit.php` | Auth | |
| GET | `/profile` | `profile.php` | Auth | |
| — | `/client/dashboard.php?code=…` | `client/dashboard.php` | Public (code) | **UNCHANGED** |

#### Legacy `.php` → slug 301 map (the compatibility table)

| Old URL | 301 target |
|---|---|
| `/dashboard.php` | `/dashboard` |
| `/projects.php` | `/projects` |
| `/project_detail.php?id=12` | `/projects/<slug-of-12>` |
| `/project_edit.php?id=12` | `/projects/<slug-of-12>/edit` |
| `/project_edit.php` | `/projects/new` |
| `/tasks.php` | `/tasks` |
| `/task_detail.php?id=34` | `/tasks/<slug-of-34>` |
| `/task_edit.php?id=34` | `/tasks/<slug-of-34>/edit` |
| `/task_edit.php` | `/tasks/new` |
| `/contacts.php` | `/contacts` |
| `/contact_detail.php?id=56` | `/contacts/<slug-of-56>` |
| `/contact_edit.php?id=56` | `/contacts/<slug-of-56>/edit` |
| `/contact_edit.php` | `/contacts/new` |
| `/profile.php` | `/profile` |
| `/logout.php` | `/logout` |
| `/login.php` | `/login` |
| `/register.php` | `/register` |
| `/forgot_password.php` | `/forgot-password` |
| `/reset_password.php?token=…` | `/reset-password?token=…` |
| `/project_delete.php` (POST) | `/projects/<slug>/delete` (POST) |

> The 301 must be issued **before** any session/permission logic runs, so an
> old bookmark never renders the page body at the old URL and then jumps.

#### Internal link migration — every hardcoded target

The dev must replace every hardcoded `.php` reference. The audit found these
categories:

| Category | Where | Example | Action |
|---|---|---|---|
| Nav links | `includes/header.php` | `href="dashboard.php"` | → `href="/dashboard"` |
| Dropdown links | `includes/header.php` | `href="profile.php"`, `href="logout.php"` | → slug URLs |
| `redirect()` calls | 20 call sites | `redirect('projects.php', …)` | → `redirect('/projects', …)` |
| `redirect()` with id | 2 call sites | `redirect('project_detail.php?id=' . $id, …)` | → `redirect('/projects/' . $slug, …)` — **the slug must be available at the call site** |
| Form `action` | `project_edit.php`, `project_delete.php`, `login.php` | `action="project_edit.php?id=…"` | → `action="/projects/<slug>/edit"` |
| `Location:` headers | `index.php` (×2) | `Location: client/dashboard.php?code=…` | **UNCHANGED** (R10) |
| Email reset link | `includes/EmailService.php` (×2) | `APP_URL . "/reset_password.php?token="` | → `APP_URL . "/reset-password?token="` |
| `header.php` nav active-state check | `includes/header.php` | compares `$_SERVER['PHP_SELF']` | → compare against the route slug instead |

> **`redirect('project_detail.php?id=' . $id)` is the tricky one.** The
> redirect helper only receives a URL string; the slug must be looked up or
> carried through. The dev must ensure the slug is available at each call site
> (it usually is — the row was just fetched). Where it is not, the dev must
> fetch it rather than re-derive it from the name.

#### **Critical — asset paths must become root-absolute**

> This is the single most likely thing to break, because it fails **visually
> on every page** and is easy to miss in a per-page review.

Today every page references its assets with a **document-relative** path:

```html
<!-- includes/header.php L44-47 -->
<link rel="icon"       href="assets/image/project-favicon.png">
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/subtask-manager.js" defer></script>

<!-- client/dashboard.php L104-106, 171-173, 337-339 -->
<link rel="icon"       href="../assets/image/project-favicon.png">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="assets/client.css">

<!-- client/dashboard.php L507 -->
<script src="assets/js/subtask-toggle.js" defer></script>

<!-- index.php L148-150, L209 -->
<link rel="icon"       href="assets/image/project-favicon.png">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="client/assets/client.css">
<script src="assets/js/share-code-format.js" defer></script>
```

With the current flat layout (`/dashboard.php`) those resolve to
`/assets/...` and everything works. **With slugs, they break:**

| Requested from | Relative `assets/css/style.css` resolves to | Result |
|---|---|---|
| `/dashboard.php` (current) | `/assets/css/style.css` | ✅ |
| `/dashboard` (new) | `/assets/css/style.css` | ✅ (still one segment) |
| `/projects` (new) | `/assets/css/style.css` | ✅ (still one segment) |
| `/projects/new` (new) | `/projects/assets/css/style.css` | ❌ **404** |
| `/projects/north-riverside-housing` (new) | `/projects/north-riverside-housing/assets/css/style.css` | ❌ **404** |
| `/tasks/my-task/edit` (new) | `/tasks/my-task/edit/assets/css/style.css` | ❌ **404** |

**Verified asset inventory on disk (do not guess these):**

| Asset | Actual path | Referenced from |
|---|---|---|
| Main stylesheet | `assets/css/style.css` | `includes/header.php`, `index.php`, `client/dashboard.php` |
| Client stylesheet | `client/assets/client.css` | `client/dashboard.php`, `index.php` |
| Favicon | `assets/image/project-favicon.png` | `includes/header.php`, `index.php`, `client/dashboard.php` |
| Main JS | `assets/js/script.js` | `includes/footer.php` |
| Subtask manager JS | `assets/js/subtask-manager.js` | `includes/header.php` |
| Share-code formatter JS | `assets/js/share-code-format.js` | `index.php` |
| Subtask toggle JS | `client/assets/js/subtask-toggle.js` | `client/dashboard.php` |

**The fix — every asset reference becomes root-absolute:**

| File | Line | Current | v2.0 |
|---|---|---|---|
| `includes/header.php` | 44 | `href="assets/image/project-favicon.png"` | `href="/assets/image/project-favicon.png"` |
| `includes/header.php` | 45 | `href="assets/css/style.css"` | `href="/assets/css/style.css"` |
| `includes/header.php` | 47 | `src="assets/js/subtask-manager.js"` | `src="/assets/js/subtask-manager.js"` |
| `includes/footer.php` | 9 | `src="assets/js/script.js"` | `src="/assets/js/script.js"` |
| `index.php` | 148 | `href="assets/image/project-favicon.png"` | `href="/assets/image/project-favicon.png"` |
| `index.php` | 149 | `href="assets/css/style.css"` | `href="/assets/css/style.css"` |
| `index.php` | 150 | `href="client/assets/client.css"` | `href="/client/assets/client.css"` |
| `index.php` | 209 | `src="assets/js/share-code-format.js"` | `src="/assets/js/share-code-format.js"` |
| `client/dashboard.php` | 104, 171, 337 | `href="../assets/image/project-favicon.png"` | `href="/assets/image/project-favicon.png"` |
| `client/dashboard.php` | 105, 172, 338 | `href="../assets/css/style.css"` | `href="/assets/css/style.css"` |
| `client/dashboard.php` | 106, 173, 339 | `href="assets/client.css"` | `href="/client/assets/client.css"` |
| `client/dashboard.php` | 507 | `src="assets/js/subtask-toggle.js"` | `src="/client/assets/js/subtask-toggle.js"` |

> **Note on `client/dashboard.php`.** It sits in `client/` and today uses
> `../assets/…` for the shared assets and `assets/…` for the client-local ones.
> Both resolve correctly **today** because the file is always served from
> `/client/dashboard.php`. **The client portal URL is NOT changing** (§C.1), so
> `client/dashboard.php` keeps working — but the references still must become
> explicit and absolute, because a relative `../assets/…` is one path segment
> away from breaking if the portal is ever mounted at a different depth.
> Make them root-absolute so the file is depth-independent.

> **`index.php` L150 is a special case.** It is a **root-level** page and
> references `client/assets/client.css` — the client stylesheet — for the
> landing page. That is **correct today and stays correct** as
> `href="/client/assets/client.css"`. Do **not** "fix" it to `/assets/client.css`
> — that file does not exist.

#### **R21 — The `/client/` nginx block and `.htaccess` deny rules must survive**

`docker/nginx.conf` has a restrictive block for `/client/`:

```nginx
location ~ ^/client/(?!index\.php$|dashboard\.php$).+\.php$ {
    return 404;
}
location /client/ { try_files $uri $uri/ /client/index.php; }
```

The slug work **must not** weaken this. `client/lang.php` is an include, not a
public endpoint — it must stay unreachable over HTTP. Any new rewrite rule that
routes `/client/<anything>` through the slug front controller would expose it.

#### AJAX endpoint URLs are NOT slugs

| Endpoint | URL | Why it stays |
|---|---|---|
| `ajax/complete_task.php` | `/ajax/complete_task.php` | Fetched by JS with a hardcoded path |
| `ajax/update_task_status.php` | `/ajax/update_task_status.php` | Same |
| `ajax/update_task_progress.php` | `/ajax/update_task_progress.php` | Same |
| `ajax/parent_hold.php` | `/ajax/parent_hold.php` | Same — form `action` attribute |
| `ajax/update_status_reason.php` | `/ajax/update_status_reason.php` | Same |
| `ajax/get_project_contacts.php` | `/ajax/get_project_contacts.php` | Same |
| `ajax/update_subtask_hours.php` (if present) | `/ajax/update_subtask_hours.php` | Same |

> AJAX endpoints are a **machine-to-machine contract** consumed by hardcoded
> paths in `assets/js/*.js` and `onclick`/`oninput` attributes in the PHP
> pages. They are **not** user-facing routes and are **not** slugs. Their URLs
> do not change. Renaming them is a separate issue and is out of scope.

#### What the slug work must NOT change

- ❌ The client share-code flow, in any way (§C.1).
- ❌ The AJAX endpoint paths and their request/response contracts.
- ❌ Any `?code=`, `?token=`, `?project_id=`, `?status=`, `?parent_id=`,
  `?id=` **query parameter name**.
- ❌ Any **query parameter** at all — slugs replace **path segments**, not
  query strings. `/tasks?project_id=12` stays exactly that.
- ❌ The `dash.*` / `landing.*` i18n key set (§8.3).
- ❌ Any security control: CSRF, session handling, the rate limits, the
  security headers, the `.htaccess` / nginx deny rules.
- ❌ The `include`/`require` path style. Pages in `client/` use
  `require_once __DIR__ . '/../includes/functions.php'`; the slug work does not
  change the directory layout, so these stay valid.
- ❌ The `share_code` column width, format, or any code already issued.
- ❌ The `database/*_test.php` test *contracts*. The dev may **update** a test
  to match the new URL shapes, but must do it **deliberately** — see the
  validation table in §11 and the test list in §C.6.

#### The mockup now shows the real 5-5-5 format (verified)

> **Correction applied to the mockup.** The v2.0 mockup originally displayed a
> share code of `HRP-2026-014` and a code input that said *"Share codes are
> 6–12 characters, case-insensitive"*. **Both contradicted production** and
> would have taught the dev the wrong contract. They have been corrected:
>
> | Element | Was | Now | Production reference |
> |---|---|---|---|
> | Share code value | `HRP-2026-014` | `AX498-99ZB2-92C3J` | matches `/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/` |
> | Code input `pattern` | *(absent)* | `[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}` | `index.php` L182 |
> | Code input `maxlength` | `12` | `17` | 17 chars = 15 alphanumeric + 2 dashes |
> | Code input `placeholder` | `ABC123` | `AAAAA-AAAAA-AAAAA` | `landing.code_placeholder` |
> | Code input `title` | *(absent)* | `Format: 5-5-5 alphanumeric, e.g. AX498-99ZB2-92C3J` | `index.php` L183 |
> | Code help text | *"6–12 characters, case-insensitive"* | *"Format: 5 characters, dash, 5, dash, 5."* | `landing.code_help` |
> | Input `id` | `share_code` | `code` | production uses `id="code"` — required by `share-code-format.js` |
> | Delete-confirmation placeholder | `HRP-2026-014` | `AX498-99ZB2-92C3J` | `project_delete.php` uses `hash_equals` on the real code |
>
> **The dev must copy the production `pattern`, `maxlength`, `title` and
> `id` verbatim** — the mockup now matches them exactly.
>
> ⚠️ **`id="code"` is not optional.** `assets/js/share-code-format.js` opens
> with `document.getElementById('code')`. If the id changes, the live
> uppercase-and-dash formatter silently stops working and the client can type
> a malformed code. Keep `id="code" name="code"`.

### C.3 Interaction with the v2.0 visual redesign

The slug work and the visual redesign are **two independent workstreams in
one release**. They touch overlapping files, so the dev must sequence them:

| Order | Workstream | Rationale |
|---|---|---|
| 1 | **Schema + slug generation + routing** | The URL layer must exist before the UI can link to it |
| 2 | **Legacy `.php` → slug 301 redirects** | Compatibility must be in place before any new link is emitted |
| 3 | **Internal link migration + root-absolute assets** | This is what makes the new URLs actually work |
| 4 | **Visual redesign (the §7 screens)** | The screens can then be built against stable URLs |

> **The mockup already uses slug-like file names** (`projects.html`,
> `task-detail.html`, `client-dashboard.html`). That is a coincidence of the
> mockup's own file naming, not a URL contract. The mockup's internal links
> are relative (`projects.html`) because the mockup is browsed from the file
> system. **Do not read the mockup's file names as the target URL scheme.**

### C.4 Naming convention for slugs

| Entity | Slug source | Example |
|---|---|---|
| Project | `projects.name` | `North Riverside Housing` → `north-riverside-housing` |
| Task | `tasks.name` | `MEP rough-in` → `mep-rough-in` |
| Contact | `contacts.name` | `Somchai Prasert` → `somchai-prasert` |

**Normalisation algorithm (R16), in order:**

1. `mb_strtolower()` the name.
2. Transliterate/strip diacritics (`iconv('UTF-8', 'ASCII//TRANSLIT', …)` then
   remove non-ASCII).
3. Replace every run of characters that are not `[a-z0-9]` with a single `-`.
4. Trim leading and trailing `-`.
5. Truncate to 60 characters, then trim a trailing `-` again.
6. If empty (a name made entirely of symbols), fall back to `item-<id>`.
7. If taken, append `-2`, `-3`, … until unique **within the same table**.

The dev must implement this **once**, as a shared helper (e.g.
`slugify(string $name): string` in `includes/functions.php`) and call it from
the create/edit paths. The migration backfill must produce **byte-identical**
slugs to the runtime helper, or existing rows and new rows will disagree.

### C.5 Slug and record-edit behaviour

| Situation | Behaviour |
|---|---|
| Record created | Slug generated from the name at creation time and stored |
| Record name edited | **Slug is NOT regenerated.** The URL stays valid (R15) |
| Record name edited to something whose slug would collide | No error — the slug simply does not change |
| Two records with the same name | Second gets `name-2`, `name-3`, … |
| Slug manually overridden by the user | Optional; if allowed, it must be validated against the same normalisation rules and uniqueness check |
| Record deleted | Slug is freed (row is gone). A new record may reuse it — acceptable |

> The dev may add a "slug" input to the record forms so the user can override
> the generated value. This is **optional**. If added, it must be sanitised
> with the same normalisation rules and validated for uniqueness, and it must
> be escaped with `e()` on output — same as every other field.

### C.6 Files the slug work touches

| File | Change |
|---|---|
| `database/migrations/20261022_add_slugs.sql` | **NEW** — add + backfill + index the three slug columns |
| `database/schema.sql` | Add the three `slug` columns and their `UNIQUE` keys (so a fresh install has them) |
| `includes/functions.php` | Add `slugify()`, `generateUniqueSlug()`, and a slug lookup helper |
| `includes/Router.php` (or the root `index.php`) | **NEW** — the front controller / route table |
| `includes/header.php` | Slug nav links + **root-absolute** asset paths |
| `includes/footer.php` | Root-absolute asset paths |
| `index.php` | Move the landing page out (or point the dispatcher at it); move the `?code=` logic **verbatim**; root-absolute asset paths (L148-150, L209) |
| `projects.php`, `project_detail.php`, `project_edit.php`, `project_delete.php` | Slug-aware queries + slug links |
| `tasks.php`, `task_detail.php`, `task_edit.php` | Slug-aware queries + slug links |
| `contacts.php`, `contact_detail.php`, `contact_edit.php` | Slug-aware queries + slug links |
| `profile.php`, `login.php`, `register.php`, `forgot_password.php`, `reset_password.php`, `logout.php` | Slug route targets |
| `includes/EmailService.php` | Reset link → `/reset-password?token=…` |
| `client/dashboard.php` | **Asset paths only** (L104-106, 171-173, 337-339, 507 → root-absolute) — the URL and the share-code logic stay unchanged |
| `.htaccess` | Slug rewrite rules **before** the catch-all; existing denies preserved |
| `docker/nginx.conf` | Verify the `location /` fallback and that the `/client/` block is untouched |
| `assets/js/*.js` | **No change** to AJAX endpoint paths |
| `database/*_test.php` | Update URL-shaped assertions **deliberately** (see §C.7) |

### C.7 Tests the slug work must add or update

| # | Test | Assertion |
|---|---|---|
| T1 | **Share-code regression** | A code generated before v2.0 still resolves to the same project after v2.0. The existing `database/client_share_code_protection_test.php` must still pass **unchanged** in its share-code sections |
| T2 | **Share-code format unchanged** | `generateShareCode()` still returns `/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/` and is exactly 17 chars |
| T3 | **`share_code` column still `CHAR(17)`** | Schema assertion — the migration must not have widened or narrowed it |
| T4 | **Slug generation** | `slugify()` unit tests: case, diacritics, punctuation, runs of separators, length cap, empty fallback |
| T5 | **Slug uniqueness** | Two records with the same name get `x` and `x-2` |
| T6 | **Migration idempotency** | Running the slug migration twice produces identical slugs (no renumbering) |
| T7 | **Slug immutability** | Renaming a record does not change its slug |
| T8 | **Legacy 301s** | Every `.php` URL in the §C.2 legacy map returns **301** with the correct `Location` |
| T9 | **Query strings preserved** | `/project_detail.php?id=12&foo=bar` → 301 → `/projects/<slug>?foo=bar` |
| T10 | **Client entry point unaffected** | `/?code=<valid>` still reaches the portal; the code value is byte-identical through the redirect |
| T11 | **Client entry point is not a slug route** | `/?code=…` does not enter the slug resolver and does not 404 |
| T12 | **Unknown slug → 404** | `/projects/does-not-exist` returns 404, not 500, not a redirect to the list |
| T13 | **Assets resolve on nested routes** | `/assets/css/style.css` returns 200 when requested from `/projects/<slug>/edit` |
| T14 | **`/client/lang.php` still blocked** | The nginx `.htaccess` deny rules are not weakened by the slug rules |
| T15 | **Query parameter names unchanged** | `?project_id=`, `?status=`, `?parent_id=`, `?token=`, `?code=` all still work |

### C.8 Out of scope for the routing work

- ❌ Slugging the **client portal**. The client URL stays `/?code=…`.
- ❌ Changing the **share-code format**.
- ❌ Slugging the **AJAX endpoints**.
- ❌ Pretty URLs with **trailing slashes** (`/projects/`). Pick one form
  (no trailing slash, as specified) and 301 the other to it.
- ❌ A **URL-shortening service** or any third-party redirect.
- ❌ **Nested resource paths** beyond one level (`/projects/<slug>/edit` is the
  deepest). A future `/projects/<slug>/tasks` is a separate issue.
- ❌ **Removing the old `.php` files.** They remain the routing targets. Only
  their *URLs* change.
