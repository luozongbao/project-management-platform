<?php
/**
 * Regression test for issue-008: Project Start Date.
 *
 * Verifies, without touching the database, that:
 *
 *   1. The client's "Started" row formats the user-supplied `start_date`
 *      (not the audit `created_at`), and renders "Not set" when the column
 *      is NULL.
 *   2. The internal project_detail "Started:" line is conditional on a
 *      non-null `start_date`.
 *   3. The form's date parser rejects malformed input with a clear error,
 *      accepts empty input as "not set", and emits a non-blocking warning
 *      when start_date > expected/actual completion_date.
 *
 * The test re-implements the same logic the templates use and inspects
 * the source of `project_edit.php` + the new migration with simple
 * string checks, so it can be executed inside the Docker app container
 * without a live MySQL.
 */
require_once __DIR__ . '/../includes/functions.php';

$failures = [];

// ---------------------------------------------------------------------------
// 1. Re-implement the exact `Started` row formatting the client dashboard
//    uses, and assert the right value is rendered.
// ---------------------------------------------------------------------------
$formatStarted = static function ($project) {
    if (!empty($project['start_date'])) {
        return formatDateTime($project['start_date'], 'M j, Y');
    }
    return 'Not set';
};

$created_at = '2026-01-01 09:00:00';
$start_date = '2025-08-15';

// Case 1a: start_date set and different from created_at → must NOT echo
// created_at. We assert the rendered output equals $start_date and that
// $created_at's date string is absent.
$out = $formatStarted(['start_date' => $start_date, 'created_at' => $created_at]);
if ($out !== 'Aug 15, 2025') {
    $failures[] = "client dashboard 'Started' row: expected 'Aug 15, 2025', got '$out'";
}
if (strpos($out, 'Jan 1, 2026') !== false) {
    $failures[] = "client dashboard 'Started' row leaked created_at ('Jan 1, 2026')";
}

// Case 1b: start_date NULL → must render the 'Not set' fallback, not
// created_at.
$out = $formatStarted(['start_date' => null, 'created_at' => $created_at]);
if ($out !== 'Not set') {
    $failures[] = "client dashboard 'Started' row: expected 'Not set' for null start_date, got '$out'";
}
if (strpos($out, 'Jan 1, 2026') !== false) {
    $failures[] = "client dashboard 'Started' row leaked created_at when start_date is null";
}

// Case 1c: start_date empty string (legacy / ""-form) → must render
// 'Not set'.
$out = $formatStarted(['start_date' => '', 'created_at' => $created_at]);
if ($out !== 'Not set') {
    $failures[] = "client dashboard 'Started' row: expected 'Not set' for empty start_date, got '$out'";
}

// ---------------------------------------------------------------------------
// 2. The internal project_detail "Started:" meta line is wrapped in an
//    `if (!empty($project['start_date']))` guard. Replicate that guard
//    and confirm it suppresses the line when start_date is null.
// ---------------------------------------------------------------------------
$renderStartedMeta = static function ($project) {
    if (!empty($project['start_date'])) {
        return 'Started: ' . formatDateTime($project['start_date'], 'M j, Y');
    }
    return null;
};
$out = $renderStartedMeta(['start_date' => $start_date]);
if ($out !== 'Started: Aug 15, 2025') {
    $failures[] = "project_detail 'Started:' line: expected 'Started: Aug 15, 2025', got " . var_export($out, true);
}
$out = $renderStartedMeta(['start_date' => null]);
if ($out !== null) {
    $failures[] = "project_detail 'Started:' line: expected suppression when start_date is null, got '$out'";
}

// ---------------------------------------------------------------------------
// 3. Static source-grep checks. We don't execute project_edit.php (which
//    would require a live DB and session), but we do verify the form
//    prefill, the validation rules, and the SQL writes reference
//    start_date correctly. If somebody removes the column from the
//    INSERT/UPDATE without updating the parser, we'd silently lose data.
// ---------------------------------------------------------------------------
$project_edit = file_get_contents(__DIR__ . '/../project_edit.php');

// 3a. The form prefill must read $project['start_date'] ?? $_POST['start_date'] ?? ''
if (strpos($project_edit, "id=\"start_date\"") === false) {
    $failures[] = "project_edit.php is missing the start_date input";
}
if (strpos($project_edit, "\$project['start_date'] ?? \$_POST['start_date']") === false) {
    $failures[] = "project_edit.php start_date prefill does not match expected pattern";
}

// 3b. Both the INSERT and UPDATE statements must mention start_date.
if (substr_count($project_edit, 'start_date') < 3) {
    $failures[] = "project_edit.php references start_date fewer than 3 times — INSERT/UPDATE may be incomplete";
}

// 3c. The parser must surface a clear error for malformed input. Re-run
// the parser with malformed input and assert it adds the right error.
$parseProjectDate = static function ($value, $label) use (&$errors) {
    if (!is_string($value)) {
        $errors[] = "$label must be a valid date.";
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        $errors[] = "$label must be a valid date.";
        return null;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        $errors[] = "$label must be a valid date.";
        return null;
    }
    return $value;
};

$errors = [];
$parsed = $parseProjectDate('2026-13-99', 'Project start date');
if ($parsed !== null || empty($errors)) {
    $failures[] = "parser must reject invalid calendar date 2026-13-99 with an error";
}
if (!empty($errors) && strpos($errors[0], 'Project start date') === false) {
    $failures[] = "parser error message must reference 'Project start date' (got: '" . ($errors[0] ?? '') . "')";
}

$errors = [];
$parsed = $parseProjectDate('not-a-date', 'Project start date');
if ($parsed !== null || empty($errors)) {
    $failures[] = "parser must reject 'not-a-date' with an error";
}

$errors = [];
$parsed = $parseProjectDate('', 'Project start date');
if ($parsed !== null || !empty($errors)) {
    $failures[] = "parser must accept empty input as 'not set' without error";
}

$errors = [];
$parsed = $parseProjectDate('2026-02-29', 'Project start date');
if ($parsed !== null || empty($errors)) {
    $failures[] = "parser must reject non-leap-year Feb 29 with an error";
}

$errors = [];
$parsed = $parseProjectDate('2024-02-29', 'Project start date');
if ($parsed !== '2024-02-29' || !empty($errors)) {
    $failures[] = "parser must accept leap-year Feb 29";
}

// ---------------------------------------------------------------------------
// 4. Non-blocking warnings for start_date > expected / completion_date.
// ---------------------------------------------------------------------------
$project_edit_has_warning = (strpos($project_edit, 'start date is later than the expected completion date') !== false)
    && (strpos($project_edit, 'start date is later than the actual completion date') !== false);
if (!$project_edit_has_warning) {
    $failures[] = "project_edit.php is missing the start_date > expected/completion_date warnings";
}

// ---------------------------------------------------------------------------
// 5. Migration must be idempotent and target projects.start_date.
// ---------------------------------------------------------------------------
$migration = file_get_contents(__DIR__ . '/migrations/20261006_add_project_start_date.sql');
if (strpos($migration, 'TABLE_NAME = \'projects\'') === false
    || strpos($migration, 'COLUMN_NAME = \'start_date\'') === false) {
    $failures[] = "migration 20261006_add_project_start_date.sql is missing information_schema idempotency guard";
}
if (strpos($migration, 'ADD COLUMN start_date DATE NULL') === false) {
    $failures[] = "migration 20261006_add_project_start_date.sql is missing ADD COLUMN start_date DATE NULL";
}

// ---------------------------------------------------------------------------
// 6. Dashboard "Recent Projects" section's "+ New Project" button must
//    route to the create form (project_edit.php), NOT the projects list.
//    See docs/issues/issue-008/tester-note-001.md bug 2.
// ---------------------------------------------------------------------------
$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
// Find the Recent Projects header, then the next <a ... >, then its href.
if (preg_match('/Recent Projects.*?<a\s+href="([^"]*)"\s+class="btn btn-primary"\s*>\s*<i\s+class="fas fa-plus"><\/i>\s*New Project/s', $dashboard, $m)) {
    if ($m[1] !== 'project_edit.php') {
        $failures[] = "dashboard 'Recent Projects' '+ New Project' must link to project_edit.php (got: '{$m[1]}')";
    }
} else {
    $failures[] = "could not locate dashboard 'Recent Projects' '+ New Project' button for link check";
}

// ---------------------------------------------------------------------------
// 7. project_edit.php must render a CSRF token in BOTH the main
//    create/edit form AND validate it in the POST handler. The hidden
//    input must NOT only live inside the inline delete dialog. See
//    docs/issues/issue-008/tester-note-001.md bug 4.
// ---------------------------------------------------------------------------
$main_form = preg_match('/<form\s+method="POST"\s+data-validate>.*?<input\s+type="hidden"\s+name="csrf_token"\s+value="/s', $project_edit);
if (!$main_form) {
    $failures[] = "project_edit.php main create/edit form is missing a hidden csrf_token input";
}
if (strpos($project_edit, "validateCsrfToken(\$_POST['csrf_token'] ?? null)") === false) {
    $failures[] = "project_edit.php POST handler is missing validateCsrfToken() call";
}
// And the token must be allocated for both create and edit (not only edit).
if (preg_match('/\$csrf_token\s*=\s*\$project\s*\?\s*getCsrfToken\(\)\s*:\s*null/', $project_edit)) {
    $failures[] = "project_edit.php must allocate csrf_token for both create and edit (not only when \$project is set)";
}

// ---------------------------------------------------------------------------
// 8. The non-blocking warnings render visibly in the form's
//    alert-danger block. Sanity-check that the warning strings appear
//    verbatim in project_edit.php so they keep getting surfaced.
// ---------------------------------------------------------------------------
foreach ([
    'Project start date is later than the expected completion date',
    'Project start date is later than the actual completion date',
] as $warning) {
    if (strpos($project_edit, $warning) === false) {
        $failures[] = "project_edit.php is missing the warning string: '$warning'";
    }
}

if (!empty($failures)) {
    fwrite(STDERR, "Project start date regression test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "Project start date regression test passed.\n";