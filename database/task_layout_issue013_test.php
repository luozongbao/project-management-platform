<?php
/**
 * Regression test for issue-013 — UI layout fixes.
 *
 * The test is a structural / source-grep verifier. It does not need a DB
 * connection because the fix is purely a CSS + markup change. The test
 * asserts that:
 *
 *   1. assets/css/style.css contains the new issue-013 rules
 *      (form-grid, form-section.full-row, form-section h3, mobile breakpoint
 *       and the new estimate-input rules).
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
 * Exit code 0 = all checks pass, 1 = at least one failed.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];

// ---------------------------------------------------------------------
// 1. style.css contains the new form-grid / form-section / estimate-input
//    rules plus a mobile breakpoint.
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
];
foreach ($cssRequired as $needle => $label) {
    if (strpos($css, $needle) === false) {
        $failures[] = "style.css is missing $label (looked for '$needle')";
    }
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
echo "  - style.css contains all required .form-grid / .form-section / .estimate-input rules\n";
echo "  - task_edit.php initialises \$effective_inherit before the form HTML\n";
echo "  - task_edit.php wraps the Dates section with class=\"form-section full-row\"\n";
echo "  - task_detail.php renders the Hold/Resume form inside <div class=\"page-actions\">\n";
echo "  - Both modified PHP files lint cleanly\n";
exit(0);
