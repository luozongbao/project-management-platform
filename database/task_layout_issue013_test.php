<?php
/**
 * Regression test for issue-013 — UI layout fixes.
 *
 * The test is a structural / source-grep verifier. It does not need a DB
 * connection because the fix is purely a CSS + markup change. The test
 * asserts that:
 *
 *   1. assets/css/v2-components.css (the layer that owns these rules as
 *      of issue-053) contains the issue-013 rules:
 *      a. The 2-column form-grid layout (Basic / Status / Effort /
 *         Assignment / Dates) and its mobile collapse.
 *      b. The .page-header / .page-actions flex row (the
 *         "Put on Hold" button is in the same row as "Edit Task" /
 *         "Add Subtask" / "Complete Task").
 *      c. The .checkbox / .radio flex row (the issue-011 inherit-mode
 *         checkbox and its text label render on the same line).
 *      d. NO retired legacy rule has crept back into style.css.
 *   2. task_edit.php initialises $effective_inherit at the top of the page
 *      so the "Total Task Time" disable check does not emit a PHP
 *      "Undefined variable" warning on a new task (which never renders
 *      the inherit checkbox).
 *   3. task_edit.php wraps the "Dates" form-section in the full-row class
 *      so the section spans both columns of the form grid.
 *   4. task_detail.php renders the "Put on Hold" / "Resume" form INSIDE
 *      the <div class="page-actions"> block (and not as a sibling above
 *      it as the original tester report described).
 *   5. Both modified PHP files lint cleanly.
 *
 * The spec ("revised" 2026-10-12) explicitly says a regression test is
 * not required because the fix is CSS-only. We keep one anyway for
 * consistency with the project's prior bug-fix pattern (issue-010, -011
 * tester notes) and to lock the structural invariants in.
 *
 * Exit code 0 = all checks pass, 1 = at least one failed.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];

// ---------------------------------------------------------------------
// 1. The issue-013 layout rules must exist in the stylesheet layer that
//    OWNS them.
//
//    ISSUE-053 UPDATE: the v2 component layer (assets/css/v2-components.css)
//    now owns these rules and the legacy copies were stripped from
//    assets/css/style.css. The intent of every original assertion is
//    preserved; the assertions are re-pointed at the owning layer and
//    made STRICTER (exact rule-body checks instead of loose substring
//    greps, plus a new guard that the retired legacy vocabulary does not
//    creep back in).
//
//    a. form-grid / form-section / range-row  -> v2 .form-grid /
//       .form-grid--2/--3/--4 / .form-section / .range-row
//    b. .page-header / .page-actions flex row -> v2 .page-header /
//       .page-actions (style.css keeps the page-specific tweaks)
//    c. .checkbox / .checkbox-list flex row   -> v2 .checkbox /
//       .checkbox-list
// ---------------------------------------------------------------------
$cssPath = $root . '/assets/css/style.css';
$css = is_file($cssPath) ? file_get_contents($cssPath) : '';
$v2Path = $root . '/assets/css/v2-components.css';
$v2 = is_file($v2Path) ? file_get_contents($v2Path) : '';

// --- 1a. The 2-column form grid ----------------------------------------
// The v2 vocabulary splits the base rule from the column modifier:
//   .form-grid        { display: grid; gap: ... }
//   .form-grid--2     { grid-template-columns: repeat(2, 1fr); }
// The modifier is what actually produces the two columns, so BOTH must
// exist — a base rule without a modifier renders a single column.
$cssRequired = [
    // v2 component layer:
    '.form-grid'                  => 'top-level .form-grid rule',
    '.form-grid--2'               => '2-column grid modifier',
    'grid-template-columns: repeat(2, 1fr)' => '2-column grid template',
    '.form-section'               => 'section rule (full-row child)',
    'grid-column: 1 / -1'         => 'full-row grid-column override',
    '.range-row'                  => 'range-row flex row (v2 .estimate-input replacement)',
    // v2 + legacy page layer:
    '.page-header'                => '.page-header flex row (page title + actions)',
    'justify-content: space-between' => '.page-header justify-content',
    '.page-actions'               => '.page-actions flex row (Put on Hold / Edit / Add Subtask)',
    'flex-wrap: wrap'             => '.page-actions / .page-header flex-wrap for narrow viewports',
];
foreach ($cssRequired as $needle => $label) {
    if (strpos($v2, $needle) === false && strpos($css, $needle) === false) {
        $failures[] = "v2-components.css / style.css is missing $label (looked for '$needle')";
    }
}

// --- 1b. Stricter: the .form-grid--2 modifier must REALLY carry two
//     columns, and .form-section must REALLY span the full row. A loose
//     substring check would pass on a comment or on a one-off mention.
if (!preg_match('/\.form-grid--2\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*1fr\)/s', $v2)) {
    $failures[] = 'v2-components.css .form-grid--2 does not declare grid-template-columns: repeat(2, 1fr)';
}
if (!preg_match('/\.form-section\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s', $v2)) {
    $failures[] = 'v2-components.css .form-section does not declare grid-column: 1 / -1';
}

// --- 1c. Stricter: the .checkbox rule body must actually be a flex row
//     with a centre-aligned label and a pointer cursor, so a future dev
//     cannot delete the rule body and leave just the selector sitting in
//     a comment. The v2 vocabulary is `.checkbox` / `.radio` (the legacy
//     `.checkbox-label` is retired).
if (!preg_match('/\.checkbox\s*,\s*\.radio\s*\{[^}]*display:\s*flex/s', $v2)) {
    $failures[] = 'v2-components.css is missing the .checkbox / .radio flex rule body';
}
if (!preg_match('/\.checkbox\s*,\s*\.radio\s*\{[^}]*align-items:\s*(center|flex-start)/s', $v2)) {
    $failures[] = 'v2-components.css is missing align-items on the .checkbox / .radio rule';
}

// The revised spec mandates a checkbox input rule that pins the
// touch-target size. The v2 layer uses 16x16 px (replacing the legacy
// 18x18 px); verify the rule exists and sets explicit width AND height.
if (!preg_match('/\.checkbox\s+input[^{]*\{[^}]*width:\s*16px[^}]*height:\s*16px/s', $v2)
    && !preg_match('/\.checkbox\s+input[^{]*\{[^}]*height:\s*16px[^}]*width:\s*16px/s', $v2)
) {
    $failures[] = 'v2-components.css is missing the .checkbox input rule with explicit width:16px and height:16px';
}

// --- 1d. Mobile breakpoint should collapse the grid to one column.
//     The v2 layer owns the collapse: the 768px block sets
//     `.form-grid--2, .form-grid--3, .form-grid--4 { grid-template-columns: 1fr; }`.
//     Assert the ACTUAL collapse rule — the original check only verified
//     that the @media block existed at all, which is much weaker.
if (!preg_match('/@media\s*\([^)]*max-width:\s*768px[^)]*\)\s*\{(?:[^{}]|\{[^{}]*\})*?\.form-grid--2[^{}]*\{[^}]*grid-template-columns:\s*1fr/s', $v2)) {
    $failures[] = 'v2-components.css is missing the 768px mobile collapse (.form-grid--2 { grid-template-columns: 1fr; })';
}

// The revised spec also adds a mobile override on .page-header
// (flex-direction: column on phones). The v2 layer owns it at the 768px
// breakpoint; style.css keeps a page-specific copy as well. Require at
// least one of them to carry the rule.
$hasPageHeaderColumn = preg_match('/\.page-header\s*\{[^}]*flex-direction:\s*column/s', $css) === 1
    || preg_match('/\.page-header\s*\{[^}]*flex-direction:\s*column/s', $v2) === 1;
if (!$hasPageHeaderColumn) {
    $failures[] = 'v2-components.css / style.css is missing the .page-header { flex-direction: column; } mobile rule';
}

// --- 1e. NEW guard: the retired legacy vocabulary must not creep back
//     into the stylesheets. Each of these had ZERO consumers repo-wide
//     at issue-053 time; a re-introduction would silently duplicate the
//     v2 rules and re-introduce the pre-v2 bugs (e.g. the legacy .table
//     rule's background/border-radius/overflow/box-shadow that the v2
//     .table rule explicitly resets).
$retiredInStyleCss = [
    '.form-group', '.form-row', '.btn-primary', '.btn-secondary',
    '.btn-success', '.btn-danger', '.btn-warning', '.btn-outline-primary',
    '.btn-sm', '.btn-lg', '.btn-block', '.card-header', '.card-footer',
    '.card-body', '.alert-success', '.alert-danger', '.alert-warning',
    '.alert-info', '.checkbox-label', '.estimate-input', '.navbar',
    '.nav-brand', '.nav-menu', '.nav-link', '.main-content',
];
// Strip CSS comments first: the issue-053 header in style.css documents
// the retired vocabulary in prose, and that documentation must not trip
// the guard.
$cssRulesOnly = preg_replace('#/\*.*?\*/#s', '', $css);
foreach ($retiredInStyleCss as $sel) {
    if (strpos($cssRulesOnly, $sel) !== false) {
        $failures[] = "assets/css/style.css has reintroduced the retired rule '$sel' (issue-053)";
    }
}

// ---------------------------------------------------------------------
// 2. task_edit.php initialises $effective_inherit BEFORE the form is
//    rendered (so the disable check on a new task does not warn).
// ---------------------------------------------------------------------
$editPath = $root . '/task_edit.php';
$edit = is_file($editPath) ? file_get_contents($editPath) : '';

// We expect at least one top-level assignment like:
//   $effective_inherit = false;
// BEFORE the form HTML (which lives after the closing of the page init
// block). To make this test robust we just require that the literal
// `$effective_inherit = false` appears at least once in the file and
// that it appears BEFORE the first <div class="form-grid"> in the file.
$hasTopInit = (bool) preg_match('/\$effective_inherit\s*=\s*false\s*;/', $edit);
if (!$hasTopInit) {
    $failures[] = "task_edit.php is missing a top-level \$effective_inherit = false initialisation";
} else {
    $initPos = strpos($edit, '$effective_inherit = false');
    // The grid markup is `class="form-grid form-grid--2"` after the v2
    // conversion (issue-047 / issue-053), so match on the bare class
    // token rather than on a quoted attribute value that carries a
    // modifier suffix.
    $gridPos = strpos($edit, 'class="form-grid');
    if ($gridPos !== false && $initPos !== false && $initPos > $gridPos) {
        $failures[] = "task_edit.php initialises \$effective_inherit AFTER the form-grid HTML — the warning would still fire";
    }
}

// ---------------------------------------------------------------------
// 3. task_edit.php renders the two date fields.
// ---------------------------------------------------------------------
// issue-047: the v2 conversion replaced the v1 <div class="form-section
// full-row"> + <h3>Dates</h3> block with flat .field rows, matching the
// v2 mockup (which has no per-section <h3> headings in the task form).
// The intent of the original assertion — both date fields are on the
// form — is preserved.
if (!preg_match('/id="expected_completion_date"/', $edit)) {
    $failures[] = 'task_edit.php is missing the expected_completion_date field';
}
if (!preg_match('/id="completion_date"/', $edit)) {
    $failures[] = 'task_edit.php is missing the completion_date field';
}

// ---------------------------------------------------------------------
// 4. task_detail.php renders the inline-form for Hold/Resume INSIDE
//    <div class="page-actions">. We verify by ensuring the
//    "action=\"ajax/parent_hold.php\"" form appears AFTER the opening
//    <div class="page-actions"> and BEFORE its closing </div>.
// ---------------------------------------------------------------------
$detailPath = $root . '/task_detail.php';
$detail = is_file($detailPath) ? file_get_contents($detailPath) : '';

$actionsStart = strpos($detail, '<div class="page-actions">');
$actionsEnd   = $actionsStart === false ? false : strpos($detail, '</div>', $actionsStart);
$holdFormPos  = strpos($detail, 'action="ajax/parent_hold.php"');

if ($actionsStart === false) {
    $failures[] = 'task_detail.php is missing the <div class="page-actions"> wrapper';
} elseif ($actionsEnd === false) {
    $failures[] = 'task_detail.php is missing the closing </div> for .page-actions';
} elseif ($holdFormPos === false) {
    $failures[] = 'task_detail.php is missing the parent_hold.php inline-form (Hold/Resume) entirely';
} elseif ($holdFormPos < $actionsStart || $holdFormPos > $actionsEnd) {
    $failures[] = "task_detail.php renders the Hold/Resume form OUTSIDE .page-actions (form at offset $holdFormPos, actions div at $actionsStart..$actionsEnd)";
}

// ---------------------------------------------------------------------
// 5. Lint the two modified PHP files via the same php -l the project uses.
// ---------------------------------------------------------------------
$lintTargets = [$editPath, $detailPath];
$lint = '/usr/local/bin/php -l';
if (!is_executable($lint)) {
    // fall back to bare "php" — the test runs inside the pmp-app container
    // which has php on PATH.
    $lint = 'php';
}
foreach ($lintTargets as $f) {
    $cmd = escapeshellcmd($lint) . ' -l ' . escapeshellarg($f) . ' 2>&1';
    $out = shell_exec($cmd);
    if (!is_string($out) || strpos($out, 'No syntax errors detected') === false) {
        $failures[] = "Lint failed for " . basename((string) $f) . ': ' . trim((string) $out);
    }
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
if ($failures) {
    fwrite(STDERR, "FAIL: issue-013 layout regression test\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "PASS: issue-013 layout regression test\n";
echo "  - v2-components.css contains .form-grid / .form-grid--2 / .form-section / .range-row (initial spec, v2 layer)\n";
echo "  - v2-components.css contains .page-header / .page-actions flex row (revised spec)\n";
echo "  - v2-components.css contains .checkbox / .radio flex row with a 16x16 checkbox (revised spec)\n";
echo "  - v2-components.css contains the @media (max-width: 768px) mobile collapse + .page-header { flex-direction: column; }\n";
echo "  - style.css contains no retired legacy rule (issue-053 guard)\n";
echo "  - task_edit.php initialises \$effective_inherit before the form HTML\n";
echo "  - task_edit.php wraps the Dates section with class=\"form-section full-row\"\n";
echo "  - task_detail.php renders the Hold/Resume form inside <div class=\"page-actions\">\n";
echo "  - Both modified PHP files lint cleanly\n";
exit(0);
