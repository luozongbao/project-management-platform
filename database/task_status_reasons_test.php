<?php
/**
 * Regression test for issue-034 — on-hold + delay reasons (v1.8.0).
 *
 * Source-grep / drift-guard verifier mirroring the issue-013
 * `task_layout_issue013_test.php` style. We don't need a DB connection
 * because every check is a structural assertion on a file path.
 *
 * Checks:
 *   1. database/schema.sql declares BOTH new columns (on_hold_reason,
 *      delay_reason) inside the `tasks` CREATE TABLE block.
 *   2. database/migrations/20261019_add_task_status_reasons.sql exists
 *      and is the standard idempotent migration shape (information_schema
 *      count guard, no transaction wrapper).
 *   3. includes/functions.php declares the isTaskDelayed() helper used
 *      by the rest of the app (the per-page scripts and the filter
 *      WHERE-clause builder don't have to import it — it just has to
 *      exist somewhere reachable).
 *   4. dashboard.php renders BOTH new stat-cards (Delayed Tasks, On
 *      Hold Tasks) with the spec'd class hooks.
 *   5. client/dashboard.php renders a .reason-pill chip and the
 *      project-level delayed + on-hold counters.
 *   6. tasks.php has a Delayed filter option AND the missing_reason
 *      query parameter AND the "missing reason" badge icon.
 *   7. task_edit.php has the new reason validation (the two
 *      non-empty trimmed checks that gate the UPDATE/INSERT).
 *   8. task_detail.php renders the new Status Reason card with the
 *      inline-edit data attributes used by subtask-manager.js.
 *   9. [tester-note-001 Bug 8] task_edit.php's <script> block toggles
 *      the on-hold + delay reason groups in response to status / date
 *      changes, so the manager isn't surprised by a server-side error
 *      after click-save.
 *  10. [tester-note-001 Bug 9] .task-reason in BOTH CSS files declares
 *      a flex-basis: 100% / width: 100% / align-self: stretch rule so
 *      the chip drops to its own row instead of overlapping the title row.
 *  11. [tester-note-003 Bug 9 revised / 9b] The LIVE parent container
 *      must allow the chip onto its own row. ISSUE-053 UPDATE: the
 *      parent is no longer `.task-line` — that class was retired in
 *      issue-050 when the client portal moved to the v2 `.task-node`
 *      block, and a repo-wide grep now shows ZERO `.task-line`
 *      consumers in any *.php or *.js. The live equivalents are
 *      `.task-node__head` (flex-wrap: wrap) and `.task-node__reasons`
 *      (flex-direction: column) in assets/css/v2-components.css, plus
 *      a NEW guard that the retired `.task-line` rule must not creep
 *      back into either stylesheet. The intent is preserved and the
 *      assertion is made STRICTER (exact live-class checks + a
 *      re-introduction guard).
 *
 * Run:
 *   docker compose exec -T app php /var/www/html/database/task_status_reasons_test.php
 *
 * Exit code 0 = all checks pass, 1 = at least one failed.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];

function ok(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) {
        $failures[] = $msg;
    }
}

function read(string $path): string {
    return is_file($path) ? file_get_contents($path) : '';
}

// ---------------------------------------------------------------------
// 1. schema.sql declares the new columns. Both identifier styles are
//    accepted (backticked `on_hold_reason` and bare on_hold_reason)
//    because the existing schema file is inconsistent about quoting.
// ---------------------------------------------------------------------
$schema = read($root . '/database/schema.sql');
ok(strpos($schema, 'on_hold_reason') !== false,
    'database/schema.sql is missing `on_hold_reason` column declaration');
ok(strpos($schema, 'delay_reason') !== false,
    'database/schema.sql is missing `delay_reason` column declaration');

// Both columns should appear inside the `tasks` CREATE TABLE block.
// Strong check: the regex anchors on the CREATE TABLE header and looks
// for both columns before the next `);` close-paren at the same depth.
// The column list spans many lines and includes commas / comments, so
// we use [\s\S]*? (non-greedy across newlines) instead of [^)]*.
// MariaDB schemas in this project terminate the table block with `);`
// (no ENGINE clause at the end of the CREATE TABLE), so we anchor on
// the closing paren + semicolon.
ok((bool)preg_match(
    '/CREATE\s+TABLE\s+`?tasks`?\s*\([\s\S]*?on_hold_reason[\s\S]*?delay_reason[\s\S]*?\)\s*;/is',
    $schema
), 'database/schema.sql is missing the on_hold_reason + delay_reason columns inside the `tasks` CREATE TABLE block');

// ---------------------------------------------------------------------
// 2. The v1.8.0 migration exists and is idempotent.
// ---------------------------------------------------------------------
$migPath = $root . '/database/migrations/20261019_add_task_status_reasons.sql';
$mig = read($migPath);
ok(is_file($migPath),
    'database/migrations/20261019_add_task_status_reasons.sql is missing');
ok(strpos($mig, 'information_schema.COLUMNS') !== false,
    'v1.8.0 migration is missing the information_schema.COLUMNS count guard (must be idempotent)');
ok(strpos($mig, 'on_hold_reason') !== false,
    'v1.8.0 migration is missing the on_hold_reason column');
ok(strpos($mig, 'delay_reason') !== false,
    'v1.8.0 migration is missing the delay_reason column');
ok(strpos($mig, 'ADD COLUMN') !== false,
    'v1.8.0 migration is missing the ADD COLUMN statements');
ok(strpos($mig, 'TEXT') !== false || strpos($mig, 'text') !== false,
    'v1.8.0 migration should declare the new columns as TEXT');
ok(!preg_match('/START\s+TRANSACTION|BEGIN\b/i', $mig),
    'v1.8.0 migration must NOT use START TRANSACTION / BEGIN (DDL auto-commit; transactions are forbidden in this project\'s migration shape)');

// ---------------------------------------------------------------------
// 3. isTaskDelayed() helper exists in includes/functions.php.
// ---------------------------------------------------------------------
$fn = read($root . '/includes/functions.php');
ok(strpos($fn, 'function isTaskDelayed(') !== false,
    'includes/functions.php is missing the isTaskDelayed() helper');

// ---------------------------------------------------------------------
// 4. dashboard.php renders BOTH new stat-cards with the spec'd class
//    hooks.
//    v2.0 issue-035 Phase 1.4b: the stat-cards were renamed to the v2
//    component vocabulary -- `.stat-card-delayed` -> `.stat--delayed`
//    and `.stat-card-on-hold` -> `.stat--on-hold` -- to match
//    v2-components.css. The issue-034 intent (two distinct, colour-coded
//    stat-cards) is unchanged; only the class names moved.
// ---------------------------------------------------------------------
$dash = read($root . '/dashboard.php');
ok(strpos($dash, 'stat--delayed') !== false,
    'dashboard.php is missing the .stat--delayed stat-card');
ok(strpos($dash, 'stat--on-hold') !== false,
    'dashboard.php is missing the .stat--on-hold stat-card');
ok(strpos($dash, 'delayed_tasks_count') !== false,
    'dashboard.php is missing the $delayed_tasks_count query');
ok(strpos($dash, 'on_hold_tasks_count') !== false,
    'dashboard.php is missing the $on_hold_tasks_count query');
// The backfill banner conditional should exist (the spec calls for an
// orange banner above the stats grid whenever at least one reason is
// missing on a delayed or on_hold row).
ok(strpos($dash, 'missing_reason_total') !== false || strpos($dash, 'missing_reason') !== false,
    'dashboard.php is missing the missing_reason_total backfill banner wiring');

// Colour hooks for the delayed / on-hold cards now live in the extracted
// page stylesheet rather than an inline <style> block. Assert against
// v2-components.css, which owns the .stat--delayed / .stat--on-hold
// icon + value colouring, so the chip palette cannot silently drift.
$dash_css = read($root . '/assets/css/v2-components.css');
ok(strpos($dash_css, '.stat--delayed') !== false
    && strpos($dash_css, '.stat--on-hold') !== false,
    'v2-components.css is missing the .stat--delayed / .stat--on-hold colour hooks');

// ---------------------------------------------------------------------
// 5. client/dashboard.php renders a .reason-chip chip and the
//    project-level delayed + on_hold counters.
//
//    v2.0 issue-050: the page-local `.reason-pill` class was replaced by
//    the shared v2 component `.reason-chip` (defined in
//    assets/css/v2-components.css) with the `--on-hold` / `--delay`
//    modifiers carrying the colour coding. The assertion follows the
//    markup change but keeps its intent: the client dashboard must still
//    render a colour-coded reason chip, and BOTH reason kinds must be
//    distinguishable (issue-034 asked for two distinct chips).
// ---------------------------------------------------------------------
$cdash = read($root . '/client/dashboard.php');
ok(strpos($cdash, 'reason-chip') !== false,
    'client/dashboard.php is missing the .reason-chip chip rendering');
ok(strpos($cdash, 'reason-chip--on-hold') !== false
    && strpos($cdash, 'reason-chip--delay') !== false,
    'client/dashboard.php must render both reason-chip variants (--on-hold and --delay) so the two reason kinds stay colour-coded');
ok(strpos($cdash, 'on_hold_reason') !== false
    && strpos($cdash, 'delay_reason') !== false,
    'client/dashboard.php is missing the on_hold_reason / delay_reason SELECT columns');
ok(strpos($cdash, 'project_delayed_count') !== false
    || strpos($cdash, 'delayed_count') !== false,
    'client/dashboard.php is missing the project-level delayed counter');
ok(strpos($cdash, 'project_on_hold_count') !== false
    || strpos($cdash, 'on_hold_count') !== false,
    'client/dashboard.php is missing the project-level on_hold counter');

// ---------------------------------------------------------------------
// 6. tasks.php has the Delayed filter option, the missing_reason query
//    parameter, and the "missing reason" badge icon.
// ---------------------------------------------------------------------
$tasks = read($root . '/tasks.php');
ok(strpos($tasks, 'value="delayed"') !== false,
    'tasks.php is missing the Delayed filter <option value="delayed">');
ok(strpos($tasks, "missing_reason") !== false || strpos($tasks, 'missing_reason') !== false,
    'tasks.php is missing the missing_reason query parameter handling');
//    v2.0 issue-035 Phase 1.4b: the page-local `.status-reason-required`
//    class was replaced by the shared v2 component `.reason-missing`
//    (defined in assets/css/v2-components.css), so tasks.php now uses
//    the same vocabulary as the reason chips beside it. The assertion
//    is unchanged in intent: tasks.php must still render the "missing
//    reason" badge icon.
ok(strpos($tasks, 'reason-missing') !== false,
    'tasks.php is missing the "missing reason" badge icon');
ok(strpos($tasks, "expected_completion_date IS NOT NULL") !== false
    || strpos($tasks, 'expected_completion_date IS NOT NULL') !== false,
    'tasks.php is missing the expected_completion_date IS NOT NULL guard in the delayed-filter WHERE builder');
ok(strpos($tasks, '< CURDATE()') !== false || strpos($tasks, '< CURDATE()') !== false,
    'tasks.php is missing the < CURDATE() delayed-filter condition');

// ---------------------------------------------------------------------
// 7. task_edit.php has the new reason validation. We check for the two
//    non-empty trimmed checks that gate the UPDATE/INSERT (one for
//    on_hold, one for delay) and the textarea markup.
// ---------------------------------------------------------------------
$edit = read($root . '/task_edit.php');
ok(strpos($edit, "on_hold_reason_input") !== false || strpos($edit, 'on_hold_reason_input') !== false,
    'task_edit.php is missing the on_hold_reason POST parser');
ok(strpos($edit, "delay_reason_input") !== false || strpos($edit, 'delay_reason_input') !== false,
    'task_edit.php is missing the delay_reason POST parser');
ok(strpos($edit, "on_hold_reason") !== false,
    'task_edit.php is missing the on_hold_reason column reference in UPDATE/INSERT');
ok(strpos($edit, "delay_reason") !== false,
    'task_edit.php is missing the delay_reason column reference in UPDATE/INSERT');
ok((bool)preg_match('/<textarea[^>]*id=[\"\']on_hold_reason[\"\']/i', $edit),
    'task_edit.php is missing the <textarea id="on_hold_reason"> form field');
ok((bool)preg_match('/<textarea[^>]*id=[\"\']delay_reason[\"\']/i', $edit),
    'task_edit.php is missing the <textarea id="delay_reason"> form field');

// ---------------------------------------------------------------------
// 8. task_detail.php renders the new Status Reason card AND the
//    inline-edit data attributes used by subtask-manager.js.
// ---------------------------------------------------------------------
$tdetail = read($root . '/task_detail.php');
ok(strpos($tdetail, 'task-reason-card') !== false,
    'task_detail.php is missing the .task-reason-card sidebar card');
ok(strpos($tdetail, 'data-update-reason') !== false,
    'task_detail.php is missing the data-update-reason inline-edit textarea attribute');
ok(strpos($tdetail, 'data-save-reason') !== false,
    'task_detail.php is missing the data-save-reason Save button attribute');
ok(strpos($tdetail, 'data-save-status') !== false,
    'task_detail.php is missing the data-save-status result placeholder attribute');
ok(strpos($tdetail, 'on_hold_reason') !== false,
    'task_detail.php is missing the on_hold_reason column reference');
ok(strpos($tdetail, 'delay_reason') !== false,
    'task_detail.php is missing the delay_reason column reference');

// ---------------------------------------------------------------------
// 9. [tester-note-001 Bug 8] task_edit.php script block toggles the
//     on-hold + delay reason groups in response to status changes.
//     Without this JS, the textareas would stay hidden after the
//     manager picked "on_hold" or past-dated the expected_completion
//     date, surfacing only at save-time as a server-side error.
//     Anchored on the <script> block so we don't accidentally match
//     comments that just mention the names.
//
//     issue-047: the v2 conversion moved this toggle out of the page's
//     inline <script> and into the shared assets/js/script.js
//     (initStatusReason). The behaviour is unchanged, so the assertion
//     now accepts either location — but it still requires that the
//     toggle actually exists.
// ---------------------------------------------------------------------
preg_match(
    '/<script\b[\s\S]*?<\/script>/is',
    $edit,
    $taskEditScript
);
$taskEditScriptBlock = $taskEditScript[0] ?? '';
$sharedJs = read($root . '/assets/js/script.js');
$toggleSources = $taskEditScriptBlock . "\n" . $sharedJs;
ok(strpos($toggleSources, 'on_hold_reason_group') !== false
    || (strpos($toggleSources, 'data-reason-panel') !== false
        && strpos($toggleSources, 'on_hold') !== false
        && ((bool)preg_match('/panels\[key\]\.hidden/', $toggleSources)
            || (bool)preg_match('/panels\.on_hold\.hidden/', $toggleSources))),
    'task_edit.php is missing the on_hold reason visibility toggle (tester-note-001 Bug 8)');
ok(strpos($toggleSources, 'delay_reason_group') !== false
    || (strpos($toggleSources, 'data-reason-panel') !== false
        && strpos($toggleSources, 'delayed') !== false
        && ((bool)preg_match('/panels\[key\]\.hidden/', $toggleSources)
            || (bool)preg_match('/panels\.delayed\.hidden/', $toggleSources))),
    'task_edit.php is missing the delay reason visibility toggle (tester-note-001 Bug 8)');

// ---------------------------------------------------------------------
// 10. [tester-note-001 Bug 9] .task-reason in both CSS files declares
//     a flex-basis: 100% / width: 100% / align-self: stretch rule that
//     drops the chip onto its own row inside the task flex container.
//     Without one of these the chip overlapped the title
//     row on tasks.php (manager) and client/dashboard.php (portal).
//
//     ISSUE-053 UPDATE: the parent container is no longer `.task-line`.
//     That class was retired in issue-050 when the client portal moved
//     to the v2 `.task-node` block (client/assets/client.css:749-753
//     explicitly marks the legacy .task-line family as "retained only
//     until every remaining consumer is retagged"). A repo-wide grep now
//     shows ZERO `.task-line` consumers in any *.php or *.js. The chip is
//     rendered by assets/js/subtask-manager.js inside `.task-node__reasons`
//     (client portal) or a `.reason-inline-edit` <details> (owner app),
//     and carries an inline `style="display:block;margin:0;"` override,
//     so it is no longer a flex child at all.
//
//     The INTENT of this check is preserved and made STRICTER: the chip
//     must not overlap the title. That requires BOTH
//   (a) the chip rule to force a full-width own row, AND
//   (b) the live parent container to wrap.
//     We now assert (a) as before and (b) against the LIVE parent
//     classes rather than the retired `.task-line`.
// ---------------------------------------------------------------------
$clientCss = read($root . '/client/assets/client.css');
preg_match('/\.task-reason\s*\{[\s\S]*?\}/is', $clientCss, $clientCssRule);
$clientCssTaskReason = $clientCssRule[0] ?? '';
ok(strpos($clientCssTaskReason, 'flex-basis: 100%') !== false
    || strpos($clientCssTaskReason, 'width: 100%') !== false
    || strpos($clientCssTaskReason, 'align-self: stretch') !== false,
    'client/assets/client.css .task-reason is missing flex-basis: 100% / width: 100% / align-self: stretch layout fix (tester-note-001 Bug 9)');

$ownerCss = read($root . '/assets/css/style.css');
preg_match('/\.task-reason\s*\{[\s\S]*?\}/is', $ownerCss, $ownerCssRule);
$ownerCssTaskReason = $ownerCssRule[0] ?? '';
ok(strpos($ownerCssTaskReason, 'flex-basis: 100%') !== false
    || strpos($ownerCssTaskReason, 'width: 100%') !== false
    || strpos($ownerCssTaskReason, 'align-self: stretch') !== false,
    'assets/css/style.css .task-reason is missing flex-basis: 100% / width: 100% / align-self: stretch layout fix (tester-note-001 Bug 9)');

// ---------------------------------------------------------------------
// 11. [tester-note-003 Bug 9 revised] The live PARENT container must
//     declare `flex-wrap: wrap;`. W3C CSS Flexible Box §9 — flex-basis
//     only requests a main size; whether the chip lands on its own row
//     depends entirely on the parent's flex-wrap. Without this line
//     the chip's `flex-basis: 100%` is silently treated as a basis
//     request inside a `nowrap` row and the chip sits on row 1
//     covering the title. The drift guard in section 10 above only
//     verifies the chip rule; this section verifies the parent rule
//     that makes section 10 actually take effect.
//
//     ISSUE-053 UPDATE: `.task-line` is retired. The live equivalents are
//   - client portal: `.task-node__head` (the flex row holding the task
//     name) and `.task-node__reasons` (the reasons block) — both in
//     assets/css/v2-components.css:912 and :927.
//   - owner app:    the reason chip is rendered inside a `.reason-inline-edit`
//     <details> by assets/js/subtask-manager.js, and carries an inline
//     `style="display:block;margin:0;"` — i.e. it is a block, not a flex
//     child, so it cannot overlap by construction. The live owner-side
//     guard is that the `.status-reason-required` chip and the
//     `.reason-inline-edit` details stack, not overlap.
// ---------------------------------------------------------------------
preg_match('/\.task-node__head\s*\{[\s\S]*?\}/is', $dash_css, $v2HeadRule);
$v2HeadBody = $v2HeadRule[0] ?? '';
ok((bool)preg_match('/flex-wrap\s*:\s*wrap/i', $v2HeadBody),
    'assets/css/v2-components.css .task-node__head is missing flex-wrap: wrap — the task name row cannot wrap without it (issue-053 update of tester-note-003 Bug 9 revised)');

preg_match('/\.task-node__reasons\s*\{[\s\S]*?\}/is', $dash_css, $v2ReasonsRule);
$v2ReasonsBody = $v2ReasonsRule[0] ?? '';
ok(strpos($v2ReasonsBody, 'flex-direction: column') !== false,
    'assets/css/v2-components.css .task-node__reasons is missing flex-direction: column — the reason chips must stack vertically, not overlap the title (issue-053 update of tester-note-003 Bug 9b)');

// The retired class must NOT come back: a `.task-line` rule in either
// stylesheet means a stale rule was re-added instead of the live one
// being fixed. Comments are stripped first — the issue-053 notes
// mention `.task-line` in prose and must not trip the guard.
$clientCssRulesOnly = preg_replace('#/\*.*?\*/#s', '', $clientCss);
$ownerCssRulesOnly  = preg_replace('#/\*.*?\*/#s', '', $ownerCss);
ok(strpos($clientCssRulesOnly, '.task-line') === false,
    'client/assets/client.css still contains a .task-line rule — it was retired in issue-050 and must not be reintroduced (issue-053)');

ok(strpos($ownerCssRulesOnly, '.task-line') === false,
    'assets/css/style.css still contains a .task-line rule — it has no consumers and must not be reintroduced (issue-053)');

// =====================================================================
// 12. [issue-056] A task that is On Hold AND past its due date must be
//     editable. The two reasons are INDEPENDENT: the DB has two separate
//     nullable columns, task_detail.php renders both editors at once, and
//     isTaskDelayed() does not exclude on_hold from the delayed set.
//
//     The bug was in assets/js/script.js initStatusReason(): the delay
//     panel's visibility was derived from the STATUS ("hide it when the
//     status is on_hold") instead of from the DUE DATE. The panel was then
//     hidden AND its textarea disabled — a disabled control is not
//     submitted — so the server received an empty delay_reason and
//     rejected the save with "A delay reason is required...". The user
//     could never satisfy the rule. A dead-end form.
//
//     The server-side rule is CORRECT and must stay strict; these checks
//     guard the BROWSER behaviour.
// =====================================================================

// 12a. The old buggy exclusion must be gone. The exact expression
//      "v !== 'completed' && v !== 'on_hold'" was the bug: it made the
//      status (not the due date) drive the delay panel's visibility.
ok(strpos($sharedJs, "v !== 'completed' && v !== 'on_hold'") === false,
    'assets/js/script.js still excludes on_hold from the delay-reason panel — an on-hold + delayed task is unsavable (issue-056)');

// 12b. The JS must read the server-computed visibility flag rather than
//      re-deriving the delayed predicate from the status alone.
ok(strpos($sharedJs, 'data-reason-show') !== false,
    'assets/js/script.js does not read the server-computed reason-panel visibility flag (issue-056)');
ok((bool)preg_match(
    '/getAttribute\(\s*[\'"]data-reason-show[\'"]\s*\)\s*===\s*[\'"]1[\'"]/',
    $sharedJs
), 'assets/js/script.js does not derive the delayed predicate from data-reason-show === "1" (issue-056)');

// 12c. The on-hold panel and the delay panel must be decided
//      SEPARATELY. A single combined boolean per key is what allowed the
//      two conditions to be coupled in the first place.
ok((bool)preg_match('/panels\.on_hold\.hidden\s*=/', $sharedJs),
    'assets/js/script.js does not toggle the on-hold panel independently (issue-056)');
ok((bool)preg_match('/panels\.delayed\.hidden\s*=/', $sharedJs),
    'assets/js/script.js does not toggle the delay panel independently (issue-056)');

// 12d. The due-date listener. Without it, moving the expected completion
//      date into the past would not reveal the delay panel until a full
//      round-trip — and the re-render would still leave the field
//      unusable, reproducing the dead end.
ok((bool)preg_match(
    '~dateInput\.addEventListener\(\s*[\'"]change[\'"]~',
    $sharedJs
), 'assets/js/script.js has no change listener on the expected-completion-date input — moving the due date into the past would not reveal the delay panel (issue-056)');
ok((bool)preg_match(
    '~querySelector\(\s*[\'"]#expected_completion_date[\'"]\s*\)~',
    $sharedJs
), 'assets/js/script.js does not look up #expected_completion_date — the due date cannot drive the delay panel (issue-056)');

// 12d-bis. The STATUS also feeds the delayed predicate: a completed task is
//      never delayed, so switching the status to 'completed' must close the
//      delay panel even when the due date is still in the past. The
//      server-side flag is only the INITIAL value; once the user changes
//      the status the JS has to recompute the predicate. Without this the
//      delay panel stays open (and required) on a completed task, and the
//      next save fails with a spurious delay-reason error.
ok((bool)preg_match(
    '~statusSel\.addEventListener\(\s*[\'"]change[\'"][\s\S]{0,400}?refreshDelayedFlag~',
    $sharedJs
), 'assets/js/script.js does not recompute the delayed predicate on a status change — a completed task would keep the delay panel open and fail to save (issue-056)');

// The predicate must exclude ONLY 'completed'. Re-introducing the on_hold
// exclusion here would reintroduce the original bug in the browser.
ok((bool)preg_match(
    '/computeDelayed[\s\S]{0,600}?statusSel\.value\s*!==\s*[\'"]completed[\'"]/',
    $sharedJs
), 'assets/js/script.js does not exclude only "completed" from the delayed predicate (issue-056)');
ok(!preg_match(
    '/computeDelayed[\s\S]{0,600}?statusSel\.value\s*!==\s*[\'"]completed[\'"][\s\S]{0,80}?on_hold/s',
    $sharedJs
), 'assets/js/script.js excludes on_hold from the delayed predicate — an on-hold + delayed task is unsavable (issue-056)');

// 12e. task_edit.php must publish the server-computed flags for BOTH
//      panels. PHP stays the single source of truth for the delayed
//      predicate, so the JS does not re-derive the date rule a third time.
ok((bool)preg_match('/data-reason-panel="on_hold"[\s\S]{0,200}?data-reason-show=/', $edit),
    'task_edit.php does not emit data-reason-show on the on_hold reason panel (issue-056)');
ok((bool)preg_match('/data-reason-panel="delayed"[\s\S]{0,200}?data-reason-show=/', $edit),
    'task_edit.php does not emit data-reason-show on the delay reason panel (issue-056)');

// 12f. The server-computed delayed predicate must NOT exclude on_hold.
//      This is the authoritative half of the fix: if PHP reintroduced
//      the exclusion, the JS flag would be wrong no matter what the JS
//      does. Mirror of the render block in task_edit.php.
ok((bool)preg_match(
    '/\$show_delay_reason\s*=\s*\$current_status\s*!==\s*[\'"]completed[\'"]/',
    $edit
), 'task_edit.php no longer computes $show_delay_reason from the status (issue-056)');
ok(!preg_match(
    '/\$show_delay_reason\s*=[^;]{0,300}?on_hold/s',
    $edit
), 'task_edit.php excludes on_hold from $show_delay_reason — an on-hold + delayed task is unsavable (issue-056)');

// 12g. The server-side rules must stay STRICT. The trap to avoid is
//      "fixing" the symptom by relaxing the validation so the save goes
//      through; the server rule is correct and is mirrored in
//      task_detail.php and ajax/update_task_status.php.
ok(strpos($edit, 'An on-hold reason is required when status is On Hold.') !== false,
    'task_edit.php lost the on-hold reason requirement — it must stay strict (issue-056)');
ok(strpos($edit, 'A delay reason is required when the task is past its expected completion date.') !== false,
    'task_edit.php lost the delay reason requirement — it must stay strict (issue-056)');

// 12h. The canonical predicate must keep treating on_hold as delayed.
//      isTaskDelayed() is the shared helper; if someone "fixed" the bug
//      by excluding on_hold there, task_detail.php and the filters would
//      start disagreeing with each other.
preg_match('/function\s+isTaskDelayed\s*\([^)]*\)\s*:\s*bool\s*\{(.*?)\n\}/s', $fn, $isDelayedM);
$isDelayedBody = $isDelayedM[1] ?? '';
ok(strpos($isDelayedBody, 'isTaskDelayed') !== false || strpos($isDelayedBody, 'status') !== false,
    'could not locate the isTaskDelayed() body (issue-056)');
ok(!preg_match('/status[^\n;]{0,80}?!==\s*[\'"]completed[\'"][^\n;]{0,80}?&&\s*[^\n;]{0,40}?on_hold/s', $isDelayedBody),
    'isTaskDelayed() excludes on_hold from the delayed set — on_hold tasks past their due date must still count as delayed (issue-056)');

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
if ($failures) {
    fwrite(STDERR, "✗ task_status_reasons_test: " . count($failures) . " failure(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "    - $f\n");
    }
    exit(1);
}

echo "✓ task_status_reasons_test: all checks passed\n";
exit(0);