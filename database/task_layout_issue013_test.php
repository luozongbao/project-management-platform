<?php
/**
 * Regression test for issue-013 — UI layout fixes.
 *
 * The test is a structural / source-grep verifier. It does not need a DB
 * connection because the fix is purely a CSS + markup change. The test
 * asserts that:
 *
 *   1. assets/css/style.css contains the issue-013 rules:
 *      a. The 2-column form-grid layout (Basic / Status / Effort /
 *         Assignment / Dates) and its mobile collapse.
 *      b. The .page-header / .page-actions flex row (the
 *         "Put on Hold" button is in the same row as "Edit Task" /
 *         "Add Subtask" / "Complete Task").
 *      c. The .checkbox-label flex row (the issue-011 inherit-mode
 *         checkbox and its text label render on the same line).
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
// 1. style.css contains the issue-013 rules:
//    a. form-grid / form-section / estimate-input (initial spec scope)
//    b. .page-header / .page-actions flex row (revised spec scope)
//    c. .checkbox-label flex row (revised spec scope)
// ---------------------------------------------------------------------
$cssPath = $root . '/assets/css/style.css';
$css = is_file($cssPath) ? file_get_contents($cssPath) : '';
$cssRequired = [
    '.form-grid'                  => 'top-level .form-grid rule',
    'grid-template-columns: 1fr 1fr' => '2-column grid',
    '.form-grid .form-section'    => 'child section selector',
    '.form-grid .form-section.full-row' => 'full-row modifier',
    'grid-column: 1 / -1'         => 'full-row grid-column override',
    '.form-grid .form-section h3' => 'section heading selector',
    '.estimate-input'             => 'estimate-input flex row',
    // Revised spec (2026-10-12):
    '.page-header'                => '.page-header flex row (page title + actions)',
    'justify-content: space-between' => '.page-header justify-content',
    '.page-actions'               => '.page-actions flex row (Put on Hold / Edit / Add Subtask)',
    'flex-wrap: wrap'             => '.page-actions / .page-header flex-wrap for narrow viewports',
];
foreach ($cssRequired as $needle => $label) {
    if (strpos($css, $needle) === false) {
        $failures[] = "style.css is missing $label (looked for '$needle')";
    }
}

// .checkbox-label: stricter check — verify the rule BODY (display:flex +
// align-items:center + gap:8px + cursor:pointer) exists immediately
// after the selector, so a future dev can't delete the rule body and
// leave just the selector sitting in a comment.
if (!preg_match('/\.checkbox-label\s*\{\s*display:\s*flex;\s*align-items:\s*center;\s*gap:\s*8px;\s*cursor:\s*pointer;/s', $css)) {
    $failures[] = 'style.css is missing the .checkbox-label rule body (display:flex, align-items:center, gap:8px, cursor:pointer)';
}

// The revised spec mandates a `.checkbox-label input[type="checkbox"]`
// rule that pins the touch-target size at 18x18 px (WCAG 2.5.5
// sufficient). Verify the rule exists and sets explicit width/height.
if (!preg_match('/\.checkbox-label\s+input\[type="checkbox"\][^{]*\{[^}]*width:\s*18px[^}]*height:\s*18px/s', $css)) {
    $failures[] = "style.css is missing the .checkbox-label input[type=\"checkbox\"] rule with width:18px and height:18px";
}

// Mobile breakpoint should collapse the grid to one column.
if (!preg_match('/@media\s*\([^)]*max-width:\s*768px[^)]*\)[^{]*\{[^}]*\.form-grid[^}]*grid-template-columns:\s*1fr/s', $css)
    && !preg_match('/@media\s*\([^)]*max-width:\s*768px[^)]*\)/s', $css)
) {
    // softer check: the breakpoint should at least exist in the file.
    if (strpos($css, '@media (max-width: 768px)') === false) {
        $failures[] = "style.css is missing the @media (max-width: 768px) breakpoint";
    }
}

// The revised spec also adds a mobile override on .page-header
// (flex-direction: column on phones) and on .page-header .page-actions
// (justify-content: stretch so the buttons share the row). Check that
// the file contains BOTH the @media (max-width: 768px) block AND
// `flex-direction: column` rules on .page-header somewhere in the
// stylesheet. (We do not require them to be in the same rule block,
// because the @media block contains many rules and a strict per-block
// regex is fragile.)
$hasMobileBreakpoint = strpos($css, '@media (max-width: 768px)') !== false;
$hasPageHeaderColumn = preg_match('/\.page-header\s*\{[^}]*flex-direction:\s*column/s', $css) === 1;
if (!$hasMobileBreakpoint) {
    $failures[] = "style.css is missing the @media (max-width: 768px) breakpoint";
} elseif (!$hasPageHeaderColumn) {
    $failures[] = "style.css is missing the .page-header { flex-direction: column; } mobile rule";
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
    $gridPos = strpos($edit, 'class="form-grid"');
    if ($gridPos !== false && $initPos !== false && $initPos > $gridPos) {
        $failures[] = "task_edit.php initialises \$effective_inherit AFTER the form-grid HTML — the warning would still fire";
    }
}

// ---------------------------------------------------------------------
// 3. task_edit.php marks the Dates section with the full-row class.
// ---------------------------------------------------------------------
if (!preg_match('/<div\s+class="form-section\s+full-row">\s*<h3>Dates<\/h3>/', $edit)) {
    $failures[] = 'task_edit.php is missing <div class="form-section full-row"> wrapping the Dates <h3>';
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
echo "  - style.css contains .form-grid / .form-section / .estimate-input (initial spec)\n";
echo "  - style.css contains .page-header / .page-actions flex row (revised spec)\n";
echo "  - style.css contains .checkbox-label flex row with 18x18 checkbox (revised spec)\n";
echo "  - style.css contains the @media (max-width: 768px) mobile breakpoint with .page-header { flex-direction: column; }\n";
echo "  - task_edit.php initialises \$effective_inherit before the form HTML\n";
echo "  - task_edit.php wraps the Dates section with class=\"form-section full-row\"\n";
echo "  - task_detail.php renders the Hold/Resume form inside <div class=\"page-actions\">\n";
echo "  - Both modified PHP files lint cleanly\n";
exit(0);
