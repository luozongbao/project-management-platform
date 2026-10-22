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

| # | Route | Access | v2.0 mockup file |
|---|---|---|---|
| 1 | `index.php` | Public | `landing.html` |
| 2 | `index.php?code=<code>` / POST code | Public | `landing.html` → `client-dashboard.html` |
| 3 | `login.php` | Public | `login.html` |
| 4 | `register.php` | Public | `register.html` |
| 5 | `forgot_password.php` | Public | `forgot-password.html` |
| 6 | `reset_password.php` | Public | `reset-password.html` |
| 7 | `dashboard.php` | Auth | `dashboard.html` |
| 8 | `projects.php` | Auth | `projects.html` |
| 9 | `project_detail.php` | Auth | `project-detail.html` |
| 10 | `project_edit.php` | Auth | `project-form.html` |
| 11 | `tasks.php` | Auth | `tasks.html` |
| 12 | `task_detail.php` | Auth | `task-detail.html` |
| 13 | `task_edit.php` | Auth | `task-form.html` |
| 14 | `contacts.php` | Auth | `contacts.html` |
| 15 | `contact_detail.php` | Auth | `contact-detail.html` |
| 16 | `contact_edit.php` | Auth | `contact-form.html` |
| 17 | `profile.php` | Auth | `profile.html` |
| 18 | `client/dashboard.php` | Public (code-keyed) | `client-dashboard.html` |

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

**Total tracked capabilities: 128.**

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
| `client/dashboard.php` | Restyle only — the `render_task_tree()` output contract and the `dash.*` i18n keys stay. |
| All `.php` view files | Markup updated to the component classes. **No PHP logic changes.** |

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

### 10.4 Things the dev must not do

- Do not rename or remove a `name=` / `id=` attribute that a PHP file reads.
- Do not change a form `action`, method or the field names it posts.
- Do not change any AJAX endpoint's request or response shape.
- Do not remove a required-attribute, a typed-confirmation check, or a rate limit.
- Do not inline a hex colour in a component rule — use the token.
- Do not introduce a new JS dependency. The mockup is dependency-free.

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

---

## 12. Out of scope

- Backend / schema changes. **v2.0 adds no columns, no tables, no endpoints.**
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
