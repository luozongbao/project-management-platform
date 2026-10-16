# v1.6 — Code & Config Audit and Cleanup

**Status:** Implemented
**Type:** Tech debt / Hardening (no new features)
**Priority:** Low–Medium
**Affects:** `docker-compose.yml`, `database/subtask_functions.sql`, `task_edit.php`, `task_detail.php`, `contact_edit.php`, `project_edit.php`, `assets/css/style.css`, the four test files that still call `shell_exec('php …')`, `RELEASE.md`, `docs/sprint/v1.6.md` (new)

## Problem

After nine shipped issues (003, 004, 005, 006, 007, 008, 009, 010, 011, 012, 013)
and three releases (v1.2.1, v1.5.0, v1.5.1), the codebase has accumulated a
small pile of dead code, dead config, and rule duplication that does not
block any user feature but is starting to confuse the next dev. The four
biggest items are:

1. **`database/subtask_functions.sql` is dead code.** The file
   (117 lines) defines a recursive `CalculateTaskCompletion` stored
   function and three `UpdateParentTaskCompletion*` triggers. It is
   still bind-mounted into the `db` container as
   `02-subtask.sql` in `docker-compose.yml:74`. **It is not applied.**
   MariaDB ≥ 10.5 rejects recursive stored functions, and the app's
   parent rollup is computed in PHP via
   `calculateTaskCompletionMap()` in `includes/functions.php`. The live
   database has zero triggers (`SHOW TRIGGERS;` returns an empty
   result set). The file is misleading the next person who looks at
   the `db` service definition.
2. **Per-page `<style>` blocks duplicate global CSS rules.** A
   line-count of the four per-page CSS blocks (those inside the PHP
   files, not `assets/css/style.css`) shows:

   | File | Per-page CSS lines |
   |---|---|
   | `task_edit.php` | 239 |
   | `task_detail.php` | 367 |
   | `contact_edit.php` | 235 |
   | `project_edit.php` | 92 |
   | **Total** | **933** |

   Most of these duplicate rules that now live in
   `assets/css/style.css` (the v1.5.1 work consolidated
   `.form-grid`, `.page-header`, `.page-actions`, `.checkbox-label`,
   `.estimate-input`, and the `@media (max-width: 768px)` mobile
   collapse into the global stylesheet). The per-page rules are
   either identical to the global rules (one wins by load order, the
   other is dead) or marginally differ (e.g. `.form-grid` padding
   `20px` vs `24px`). They are an active liability: the next CSS
   change will be applied in two places, and the two places will
   drift.
3. **`shell_exec('php …')` in three regression tests.** The live-render
   probes in `database/task_inherit_subtask_hours_test.php:431`,
   `database/task_layout_issue013_test.php:176`, and
   `client/toggle_subtasks_test.php:170` shell out to a fresh PHP
   process to render a page and capture the HTML. They work today
   because the `pmp-app` container ships with `php` on `PATH`, but:
   - the `pmp-app` container has no `mariadb` / `mysql` client (the
     v1.5.0 tester feedback removed that path from
     `client/toggle_subtasks_test.php:368`); the next dev who tries
     to add a live-render probe that does DB setup will hit the
     same silent-no-op trap;
   - the probes write to `/tmp` via `tempnam()` and never clean up
     on failure; a CI run that fails halfway leaves a stray
     `phpXXXXXX` file;
   - they depend on a particular working directory (`chdir(__DIR__`
     in the proxy), and the inherited `__DIR__` of the *test* file
     is not always the same as the proxy file's `__DIR__`.
4. **No `v1.6` plan in `RELEASE.md` / no `docs/sprint/` folder.**
   `RELEASE.md` stops at v1.5.1. The remaining open backlog after
   issues 004 / 009 are marked Implemented is empty; the next dev
   who walks in has no roadmap, no estimate, and no decision log.

## Goals

- Remove the dead `subtask_functions.sql` from the deployment so a
  new reader of `docker-compose.yml` is not misled into thinking
  triggers are installed.
- Collapse the per-page CSS blocks down to the unique bits that are
  not yet in `assets/css/style.css`; ship the duplicates in a
  follow-up pass.
- Replace the `shell_exec('php …')` test pattern with a
  in-process render where the dependencies allow it, and document
  the pattern where they do not.
- Stand up `docs/sprint/v1.6.md` and a v1.6.0 entry in `RELEASE.md`
  so the release train has a place to land.

## Non-goals

- No new user-facing features. v1.6 is cleanup only.
- No database migrations. Nothing in the schema changes.
- No new translations. No new JS files. No new AJAX endpoints.
- No change to the public client portal beyond the dead-code removal.
- No mass reformat (e.g. switching the whole project to PSR-12, or
  refactoring the procedural pages into a framework). Out of scope;
  flag as a possible v2.0 task.

## Proposed changes

### 1. Remove `subtask_functions.sql` from the deployment

**Decision:** delete the file, drop the volume mount from
`docker-compose.yml`, and add a one-line note in
`database/README.md` (or the top of `schema.sql`) explaining that
parent rollups are computed in PHP.

**Files touched:**

- `docker-compose.yml` — remove the `02-subtask.sql` volume mount
  on the `db` service (line 74):
  ````yaml
  # REMOVE this line:
  - ./database/subtask_functions.sql:/docker-entrypoint-initdb.d/02-subtask.sql:ro
  ````
- `database/subtask_functions.sql` — delete (or keep as
  `database/subtask_functions.sql.disabled` if you want a
  historical record; the v1.5.0 memory note says the file was
  already considered dead).

**Why now:** the file is misleading; the volume mount makes a
new reader think triggers are installed; the live DB has none. A
volume mount that no-ops on every deploy is a tax on every future
schema migration (the container has to be rebuilt to drop the mount,
and forgetting the rebuild silently re-applies the file on the
next fresh install).

**Acceptance:**

- A fresh `docker compose down -v && docker compose up -d --build`
  on a clean checkout produces a database with zero triggers
  (`SHOW TRIGGERS;` empty).
- A grep of `docker-compose.yml` for `subtask` returns zero matches.
- The file `database/subtask_functions.sql` is no longer in the
  working tree (or is renamed to `.disabled` with a header
  explaining why).

### 2. Collapse per-page CSS duplication

**Decision:** for each of the four files, extract the rules that
are already present (verbatim or near-verbatim) in
`assets/css/style.css`, and leave only the per-page-unique rules
in the `<style>` block.

**Audit of overlap** (run with `diff -u <(sed -n '/<style>/,/<\/style>/p' file.php | grep -oE '\.[a-z][a-z0-9_-]+') <(grep -oE '\.[a-z][a-z0-9_-]+' assets/css/style.css)`):

| File | Likely-duplicate selectors | Per-page-unique |
|---|---|---|
| `task_edit.php` | `.form-grid`, `.form-section`, `.estimate-input`, `.checkbox-label`, `.page-header`, `.page-actions` | `.task-edit-grid` (if any), per-page responsive tweaks |
| `task_detail.php` | `.page-header`, `.page-actions`, `.inline-form` (if in global), `.form-grid` | `.task-detail-grid`, custom badge styles |
| `contact_edit.php` | `.form-grid`, `.form-section`, `.checkbox-label` | `.contact-form`, `.project-checkboxes` |
| `project_edit.php` | `.form-grid`, `.form-section` | `.share-code` (if not yet in global) |

**Files touched:**

- `task_edit.php` — strip the duplicated rules from the `<style>`
  block; keep only the ones not present in `style.css`. Net
  reduction ≈ 150–180 lines.
- `task_detail.php` — same, net reduction ≈ 250–300 lines.
- `contact_edit.php` — same, net reduction ≈ 150–180 lines.
- `project_edit.php` — same, net reduction ≈ 60–70 lines.

**Acceptance:**

- Total per-page CSS line count after the change is below 250
  lines (today: 933).
- A `grep` of `style.css` for the rule selectors shows the global
  rule is the one being used (load order: global loads first,
  per-page loads later — so per-page is the one that wins only
  if it diverges).
- All four form pages render identically before and after the
  change (visual diff via screenshot, or a Playwright snapshot
  if available).
- `database/task_layout_issue013_test.php` still passes (it
  source-greps the global rules, not the per-page ones).

**Risk:** the per-page blocks may contain rules that are *not* in
the global stylesheet today but should be, or rules that
intentionally override the global. The audit step is the
acceptance test: for each duplicate selector, confirm the
computed style is the same before and after the change. If
not, the per-page rule stays put with a `/* per-page override of
.form-grid in style.css */` comment.

### 3. Tame the `shell_exec('php …')` test pattern

The three live-render probes work today, but they share three
fragilities (see Problem §3). A targeted refactor:

- **For probes that need a fresh process** (e.g. cross-process
  isolation, or to defeat PHP's per-request OPcache sharing):
  document the pattern in `database/README.md` and
  `client/README.md` (new files, or top-of-file docblock if no
  README exists). The pattern: build the proxy file with
  `tempnam(sys_get_temp_dir(), 'pmp-test-')`; clean up via
  `register_shutdown_function`; require the live `Database.php`
  in the proxy so DB connectivity is the same as the app.
- **For probes that don't need a fresh process** (e.g. they only
  `include` the page and assert on the rendered HTML, with no
  side effects on subsequent tests): replace with an in-process
  `ob_start(); include …; $html = ob_get_clean();` call. Wrap in
  try/catch to swallow `exit()` calls in pages that call
  `requireLogin()`; for those, stub the auth helper in a
  prepended file or skip the live render entirely (the source-grep
  assertions in the existing tests are sufficient for the
  CSS-class fixes in v1.5.1).
- **For the `shell_exec('mariadb …')` pattern** that was removed
  from `client/toggle_subtasks_test.php` during v1.5.0: add a
  comment in `database/README.md` (new) warning the next dev
  that the `pmp-app` container has no DB CLI; always go through
  `Database::execute()`.

**Files touched:**

- `database/README.md` — new, documents the regression-test
  pattern and the `shell_exec` warnings.
- `client/README.md` — new, same on the client side.
- The three test files — apply the targeted fixes above. Do not
  refactor wholesale; the goal is to remove fragility, not
  rewrite.

**Acceptance:**

- `grep -r "shell_exec" database/ client/` returns only the
  three documented callsites (no new additions).
- All seven regression tests still pass:
  `task_inherit_subtask_hours_test`,
  `task_parent_status_derived_test`,
  `task_completion_test`, `contacts_columns_test`,
  `project_start_date_test`, `client/toggle_subtasks_test`,
  `task_layout_issue013_test`.

### 4. Stand up `docs/sprint/v1.6.md` and a v1.6.0 entry in `RELEASE.md`

**New file:** `docs/sprint/v1.6.md` with:

- **Goal** — ship the three cleanup items above as a single v1.6.0
  release. No features.
- **Dependency graph** — items 1 and 3 are independent; item 2 is
  independent of both; item 4 lands last. They can ship in any
  order, but item 1 should land first because it touches
  `docker-compose.yml` and a fresh `down -v && up` is the cleanest
  way to verify items 2 and 3 against a real DB.
- **Effort estimate** — item 1: ~30 min (delete file, edit
  compose); item 2: ~2–3 hours (audit + per-file strip + visual
  diff); item 3: ~1–2 hours (audit + pattern doc + targeted
  refactor); item 4: ~30 min (new files + RELEASE entry).
- **Risk register** — see "Risks" below.

**New section in `RELEASE.md`** (top of file, before v1.5.1):

````markdown
## v1.6.0 — TBD

Tech-debt release. Removes `subtask_functions.sql` from the
deployment, collapses per-page CSS duplication, and tames the
`shell_exec('php …')` test pattern. No new features, no schema
changes, no JS / translation / AJAX changes. See
[docs/issues/issue-014.md](docs/issues/issue-014.md) and
[docs/sprint/v1.6.md](docs/sprint/v1.6.md).
````

## Files changed (summary)

- `docker-compose.yml` — remove the `02-subtask.sql` mount.
- `database/subtask_functions.sql` — delete (or rename to
  `.disabled`).
- `task_edit.php`, `task_detail.php`, `contact_edit.php`,
  `project_edit.php` — strip duplicate per-page CSS.
- `assets/css/style.css` — no change (or add a rule that the
  per-page block was depending on, if the audit reveals one).
- The three test files that call `shell_exec` — targeted fixes.
- `database/README.md` (new) and `client/README.md` (new) — test
  pattern docs.
- `docs/sprint/v1.6.md` (new) — sprint plan.
- `RELEASE.md` — new v1.6.0 section.

## Risks

- **Per-page CSS strip may break a visual that depends on a
  per-page override.** Mitigation: the audit step is the
  acceptance test (visual diff before/after, and the
  `task_layout_issue013_test.php` source-grep test still passes).
- **A fresh deploy with the `02-subtask.sql` mount removed may
  fail on a database that already has those triggers from a
  pre-v1.6 install.** The live DB has zero triggers today, so
  this is a non-issue for the current install; for a hypothetical
  upgrader, the migration is "DROP TRIGGER IF EXISTS …" run once,
  then drop the file. Document this in the upgrade notes.
- **In-process `include` of a page that calls `requireLogin()`**
  will `exit()` before any HTML is emitted. The two existing
  patterns are: (a) stub the helper via `auto_prepend_file`, or
  (b) skip the live render and rely on source-grep. v1.5.1
  already established (b) as the right level of coverage for
  CSS-class fixes; do not regress to (a) without a strong
  reason.

## Out of scope (flagged for future tickets)

- Move `APP_URL` and the SMTP constants out of `config.php` and
  into `.env` so the `docker/entrypoint.sh` auto-generation is
  the only path to a working `config.php`. (This is a v2.0
  task — the current `config.php` is also a hand-editable file,
  per the comment at the top.)
- Switch the four procedural pages to a templating engine or
  framework. (v2.0.)
- Add an Nginx-level health check endpoint and wire it to the
  `web` container's `healthcheck`. (Cosmetic; the current
  `pmp-web` has no `healthcheck:` block in `docker-compose.yml`.)
- Translate the internal app to a second language. The current
  design (English-only internal, EN+ZH client) is the spec; do
  not break it.
