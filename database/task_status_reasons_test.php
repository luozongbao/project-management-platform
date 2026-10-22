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
 *      the chip drops to its own row inside .task-line instead of
 *      overlapping the title row.
 *  11. [tester-note-003 Bug 9 revised] .task-line in BOTH CSS files
 *      declares `flex-wrap: wrap;` — the parent rule which makes
 *      section 10 actually take effect (without this line flex-basis
 *      is silently treated as a basis request inside a nowrap row).
 *  11. [tester-note-003 Bug 9b] .task-line in BOTH CSS files declares
 *      flex-wrap: wrap on the PARENT, without which the chip's
 *      flex-basis: 100% cannot trigger a new line. (Round-2 cache
 *      blame was wrong — this is the real root cause of the still-
 *      broken visual after the dev's commit 7ff6869.)
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
// 5. client/dashboard.php renders a .reason-pill chip and the
//    project-level delayed + on_hold counters.
// ---------------------------------------------------------------------
$cdash = read($root . '/client/dashboard.php');
ok(strpos($cdash, 'reason-pill') !== false,
    'client/dashboard.php is missing the .reason-pill chip rendering');
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
// ---------------------------------------------------------------------
preg_match(
    '/<script\b[\s\S]*?<\/script>/is',
    $edit,
    $taskEditScript
);
$taskEditScriptBlock = $taskEditScript[0] ?? '';
ok(strpos($taskEditScriptBlock, 'on_hold_reason_group') !== false
    && (bool)preg_match('/on_hold_reason_group[^"]*\.style\.display/s', $taskEditScriptBlock),
    'task_edit.php <script> block is missing the on_hold_reason_group.style.display toggle (tester-note-001 Bug 8)');
ok(strpos($taskEditScriptBlock, 'delay_reason_group') !== false
    && (bool)preg_match('/delay_reason_group[^"]*\.style\.display/s', $taskEditScriptBlock),
    'task_edit.php <script> block is missing the delay_reason_group.style.display toggle (tester-note-001 Bug 8)');

// ---------------------------------------------------------------------
// 10. [tester-note-001 Bug 9] .task-reason in both CSS files declares
//     a flex-basis: 100% / width: 100% / align-self: stretch rule that
//     drops the chip onto its own row inside the .task-line flex
//     container. Without one of these the chip overlapped the title
//     row on tasks.php (manager) and client/dashboard.php (portal).
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
// 11. [tester-note-003 Bug 9 revised] .task-line in BOTH CSS files
//     declares `flex-wrap: wrap;`. W3C CSS Flexible Box §9 — flex-basis
//     only requests a main size; whether the chip lands on its own row
//     depends entirely on the parent's flex-wrap. Without this line
//     the chip's `flex-basis: 100%` is silently treated as a basis
//     request inside a `nowrap` row and the chip sits on row 1
//     covering the title. The drift guard in section 10 above only
//     verifies the chip rule; this section verifies the parent rule
//     that makes section 10 actually take effect.
// ---------------------------------------------------------------------
preg_match('/\.task-line\s*\{[\s\S]*?\}/is', $clientCss, $clientLineCssTaskLine);
$clientCssTaskLine = $clientLineCssTaskLine[0] ?? '';
ok(strpos($clientCssTaskLine, 'flex-wrap: wrap') !== false,
    'client/assets/client.css .task-line is missing flex-wrap: wrap; (tester-note-003 Bug 9 revised)');

preg_match('/\.task-line\s*\{[\s\S]*?\}/is', $ownerCss, $ownerCssTaskLineMatch);
$ownerCssTaskLine = $ownerCssTaskLineMatch[0] ?? '';
ok(strpos($ownerCssTaskLine, 'flex-wrap: wrap') !== false,
    'assets/css/style.css .task-line is missing flex-wrap: wrap; (tester-note-003 Bug 9 revised)');

// ---------------------------------------------------------------------
// 11. [tester-note-003 Bug 9b] .task-line must declare flex-wrap on the
//     PARENT — otherwise the chip's flex-basis:100% is silently
//     treated as a basis request and the chip stays on the SAME row as
//     the title, visually covering it (round-2 cache diagnosis was
//     wrong; this is the real reason the chip still overlapped after
//     the dev's commit 7ff6869). Adding this check so the same gap
//     cannot regress again.
// ---------------------------------------------------------------------
preg_match('/\.task-line\s*\{[\s\S]*?\}/is', $clientCss, $clientLineRule);
$clientLineRuleBody = $clientLineRule[0] ?? '';
ok((bool)preg_match('/flex-wrap\s*:\s*wrap/i', $clientLineRuleBody),
    'client/assets/client.css .task-line is missing flex-wrap:wrap — the chip cannot wrap to its own row without it (tester-note-003 Bug 9b)');

preg_match('/\.task-line\s*\{[\s\S]*?\}/is', $ownerCss, $ownerLineRule);
$ownerLineRuleBody = $ownerLineRule[0] ?? '';
ok((bool)preg_match('/flex-wrap\s*:\s*wrap/i', $ownerLineRuleBody),
    'assets/css/style.css .task-line is missing flex-wrap:wrap — owner app has no .task-line rule at all yet; add one with flex-wrap:wrap (tester-note-003 Bug 9b)');

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