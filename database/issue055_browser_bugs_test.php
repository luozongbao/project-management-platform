<?php
/**
 * Regression test for issue-055 — browser-found bugs.
 *
 *   Bug 1  copy button dead on plain-HTTP origins (High)
 *   Bug 2  task form dates: time silently discarded (High)
 *   Bug 3  client dashboard language switcher: active state never applied
 *   Bug 4  forgot password: textbox styling + error placement
 *
 * The test is a structural / source-grep verifier plus a functional check of
 * the new parseFormDate() helper. It does not need a DB connection: the
 * fixes are markup, CSS and pure-function changes.
 *
 * Exit code 0 = all checks pass, 1 = at least one failed.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];

/**
 * Strip PHP comments from a source file so that prose in a doc block or a
 * `//` note can never satisfy (or break) a source-grep assertion.
 * Mirrors the strip_php_comments() helper used by install_wizard_test.php.
 */
function strip_php_comments(string $src): string
{
    $out = '';
    $len = strlen($src);
    $inSingle = false;
    $inDouble = false;
    $inLine = false;
    $inBlock = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $src[$i];
        $next = $i + 1 < $len ? $src[$i + 1] : '';

        if ($inLine) {
            if ($ch === "\n") {
                $inLine = false;
                $out .= $ch;
            }
            continue;
        }
        if ($inBlock) {
            if ($ch === '*' && $next === '/') {
                $inBlock = false;
                $i++;
            }
            continue;
        }
        if ($inSingle) {
            $out .= $ch;
            if ($ch === '\\') {
                if ($next !== '') {
                    $out .= $next;
                    $i++;
                }
            } elseif ($ch === "'") {
                $inSingle = false;
            }
            continue;
        }
        if ($inDouble) {
            $out .= $ch;
            if ($ch === '\\') {
                if ($next !== '') {
                    $out .= $next;
                    $i++;
                }
            } elseif ($ch === '"') {
                $inDouble = false;
            }
            continue;
        }

        if ($ch === '/' && $next === '/') {
            $inLine = true;
            $i++;
            continue;
        }
        if ($ch === '#') {
            $inLine = true;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $inBlock = true;
            $i++;
            continue;
        }
        if ($ch === "'") { $inSingle = true; $out .= $ch; continue; }
        if ($ch === '"') { $inDouble = true; $out .= $ch; continue; }

        $out .= $ch;
    }

    return $out;
}

/**
 * Strip JavaScript comments from a source fragment.
 *
 * A doc block that merely *mentions* document.execCommand('copy') must not
 * satisfy a check that the fallback is actually implemented, and a
 * commented-out fallback must not satisfy it either. Strings are preserved
 * so that a string literal containing "//" is not mangled.
 */
function strip_js_comments(string $src): string
{
    $out = '';
    $len = strlen($src);
    $inSingle = false;
    $inDouble = false;
    $inLine = false;
    $inBlock = false;
    $inRegex = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $src[$i];
        $next = $i + 1 < $len ? $src[$i + 1] : '';

        if ($inLine) {
            if ($ch === "\n") {
                $inLine = false;
                $out .= $ch;
            }
            continue;
        }
        if ($inBlock) {
            if ($ch === '*' && $next === '/') {
                $inBlock = false;
                $i++;
            }
            continue;
        }
        if ($inSingle) {
            $out .= $ch;
            if ($ch === '\\' && $next !== '') {
                $out .= $next;
                $i++;
            } elseif ($ch === "'") {
                $inSingle = false;
            }
            continue;
        }
        if ($inDouble) {
            $out .= $ch;
            if ($ch === '\\' && $next !== '') {
                $out .= $next;
                $i++;
            } elseif ($ch === '"') {
                $inDouble = false;
            }
            continue;
        }
        if ($inRegex) {
            $out .= $ch;
            if ($ch === '\\' && $next !== '') {
                $out .= $next;
                $i++;
            } elseif ($ch === '/') {
                $inRegex = false;
            }
            continue;
        }

        if ($ch === '/' && $next === '/') {
            $inLine = true;
            $i++;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $inBlock = true;
            $i++;
            continue;
        }
        // A regex literal is only possible where an expression may start;
        // the fragments inspected here are simple enough that treating
        // "/" as a regex start is safe, and the char class excludes
        // anything that would swallow a comment marker.
        if ($ch === '/') {
            $inRegex = true;
            $out .= $ch;
            continue;
        }
        if ($ch === "'") { $inSingle = true; $out .= $ch; continue; }
        if ($ch === '"') { $inDouble = true; $out .= $ch; continue; }

        $out .= $ch;
    }

    return $out;
}

function read_source(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Return the source window of an HTML element that starts with $openTag,
 * bounded by the next $closeTag.
 *
 * A plain `<input[^>]*>` regex cannot be used here: the markup contains a
 * PHP short-echo tag inside the value attribute, and its closing `>` is a
 * literal `>` in the rendered HTML, so `[^>]*` stops early and the
 * attributes that follow it (autocomplete, autofocus) are invisible to the
 * matcher. Scanning a bounded window instead is whitespace- and
 * PHP-tag-agnostic.
 */
function element_window(string $src, string $openTag, int $maxLen = 600): string
{
    $pos = strpos($src, $openTag);
    if ($pos === false) {
        return '';
    }
    return substr($src, $pos, min($maxLen, strlen($src) - $pos));
}

/**
 * Return the source of the element that CONTAINS $marker: the text from the
 * preceding `<tag` up to (and including) $marker.
 *
 * Used to inspect the attributes that sit BEFORE an id (e.g.
 * `<input class="input" type="date" id="completion_date"`), where a forward
 * scan would find the id first and lose the type.
 */
function element_around(string $src, string $marker, int $lookBack = 400): string
{
    $pos = strpos($src, $marker);
    if ($pos === false) {
        return '';
    }
    $start = max(0, $pos - $lookBack);
    $head = substr($src, $start, $pos - $start);
    $tagPos = strrpos($head, '<');
    // The `<` of a PHP short-echo tag is not an HTML tag
    // start — skip back past it until a real `<` is found.
    while ($tagPos !== false && substr($head, $tagPos, 2) === '<?') {
        $tagPos = $tagPos === false ? false : strrpos(substr($head, 0, $tagPos), '<');
    }
    if ($tagPos === false) {
        return substr($src, $start, $pos - $start + strlen($marker));
    }
    return substr($src, $start + $tagPos, $pos - $start - $tagPos + strlen($marker));
}

// =====================================================================
// Bug 1 — copy button
// =====================================================================
$scriptPath = $root . '/assets/js/script.js';
$script = read_source($scriptPath);

if ($script === '') {
    $failures[] = 'assets/js/script.js is missing or unreadable';
}

// 1a. A shared helper must exist and own the fallback chain.
// The check is anchored on the exact signature so that renaming or
// removing the helper trips the net (a bare prefix match would still
// accept "pmpCopyText_DISABLED").
if (!preg_match('/function\s+pmpCopyText\s*\(\s*text\s*\)/', $script)) {
    $failures[] = 'assets/js/script.js is missing the shared pmpCopyText() helper';
} else {
    // Scope every fallback check to the helper's own body: a stray
    // execCommand call elsewhere in the file must not satisfy the net.
    $helperStart = strpos($script, 'function pmpCopyText(');
    $helperEnd = strpos($script, 'window.pmpCopyText', $helperStart);
    if ($helperEnd === false) {
        $helperEnd = $helperStart + 2000;
    }
    // Strip comments first: the doc block mentions execCommand in prose,
    // and a commented-out fallback must not satisfy the net.
    $helper = strip_js_comments(substr($script, $helperStart, $helperEnd - $helperStart));

    // The Clipboard API is preferred, execCommand is the fallback.
    $clipPos = strpos($helper, 'navigator.clipboard');
    $execPos = strpos($helper, "document.execCommand('copy')");
    if ($clipPos === false) {
        $failures[] = 'pmpCopyText() does not use navigator.clipboard';
    }
    if ($execPos === false) {
        $failures[] = 'pmpCopyText() has no document.execCommand(\'copy\') fallback';
    }
    if ($clipPos !== false && $execPos !== false && $execPos < $clipPos) {
        $failures[] = 'pmpCopyText() should prefer the Clipboard API and fall back to execCommand';
    }
    // The fallback must be wrapped in try/catch and must remove its
    // scratch textarea — a rejected execCommand otherwise leaves a
    // stray node in the DOM.
    if (!preg_match('/try\s*\{[^}]*execCommand/s', $helper)) {
        $failures[] = 'pmpCopyText() does not guard document.execCommand() with try/catch';
    }
    if (strpos($helper, 'removeChild(ta)') === false) {
        $failures[] = 'pmpCopyText() does not remove the temporary textarea';
    }
    // The helper must return a Promise so callers can await success and
    // react to failure.
    if (strpos($helper, 'new Promise') === false) {
        $failures[] = 'pmpCopyText() must return a Promise so callers can handle failure';
    }
}

// 1b. initCopy() must use the helper and must not lie about success.
if (strpos($script, 'function initCopy') === false) {
    $failures[] = 'assets/js/script.js is missing initCopy()';
} else {
    $initPos = strpos($script, 'function initCopy(');
    if ($initPos === false) {
        $initPos = strpos($script, 'function initCopy (');
    }
    $initBlock = substr($script, $initPos, 2000);
    if (strpos($initBlock, 'pmpCopyText') === false) {
        $failures[] = 'initCopy() does not delegate to pmpCopyText()';
    }
    if (strpos($initBlock, '.catch') === false) {
        $failures[] = 'initCopy() has no .catch() — a rejected clipboard write would still claim "Copied"';
    }
    if (strpos($initBlock, 'Copy failed') === false) {
        $failures[] = 'initCopy() has no failure state — the button would lie when the copy fails';
    }
    if (strpos($initBlock, 'Copied') === false) {
        $failures[] = 'initCopy() has no success state';
    }
    // The original label must be restored, and the transient state classes
    // removed, so the button does not stay permanently tinted.
    if (!preg_match('/setTimeout\s*\(/', $initBlock)) {
        $failures[] = 'initCopy() does not restore the original label after the confirmation timeout';
    }
    if (strpos($initBlock, 'is-copy-failed') === false) {
        $failures[] = 'initCopy() does not remove the is-copy-failed class when restoring the label';
    }
    // initCopy() itself must stay synchronous — turning it into an async
    // callback would race the listener registration with DOMContentLoaded.
    if (preg_match('/async\s+function\s+initCopy/', $script)) {
        $failures[] = 'initCopy() must stay synchronous (only the copy operation is asynchronous)';
    }
}

// 1c. Only ONE copy implementation may exist in production JavaScript.
// project_detail.php used to carry its own inline execCommand copy.
$detailPath = $root . '/project_detail.php';
$detail = read_source($detailPath);

if ($detail === '') {
    $failures[] = 'project_detail.php is missing or unreadable';
} else {
    if (strpos($detail, 'function copyShareUrl') === false) {
        $failures[] = 'project_detail.php is missing the copyShareUrl() entry point';
    }
    if (strpos($detail, 'pmpCopyText') === false) {
        $failures[] = 'project_detail.php does not delegate to the shared pmpCopyText() helper';
    }
    // The inline implementation must no longer call execCommand directly.
    $copyPos = strpos($detail, 'function copyShareUrl');
    if ($copyPos !== false) {
        $copyBlock = strip_js_comments(substr($detail, $copyPos, 2000));
        // It must make a real call to the shared helper, not merely
        // mention its name.
        if (!preg_match('/(?<![A-Za-z0-9_$])pmpCopyText\s*\(/', $copyBlock)) {
            $failures[] = 'project_detail.php copyShareUrl() must call pmpCopyText(...)';
        }
        if (strpos($copyBlock, 'execCommand') !== false) {
            $failures[] = 'project_detail.php still has its own execCommand copy — it must delegate to pmpCopyText()';
        }
        if (strpos($copyBlock, '.catch') === false) {
            $failures[] = 'project_detail.php copyShareUrl() has no .catch() — it would claim "Copied" on failure';
        }
        if (strpos($copyBlock, 'Copy failed') === false) {
            $failures[] = 'project_detail.php copyShareUrl() has no failure state';
        }
    }
}

// 1d. The copy button's aria-label must match what it actually copies
// (the share code, not the URL).
if (strpos($detail, 'Copy link') === false && strpos($detail, 'Copy share code') === false) {
    $failures[] = 'project_detail.php is missing the share-copy button';
}
$editPath = $root . '/project_edit.php';
$editSrc = read_source($editPath);
if (strpos($editSrc, 'aria-label="Copy share code"') === false) {
    $failures[] = 'project_edit.php: the copy button must keep aria-label="Copy share code" (it copies the code, not the URL)';
}

// =====================================================================
// Bug 2 — task form dates
// =====================================================================
$functionsPath = $root . '/includes/functions.php';
$functions = read_source($functionsPath);

// 2a. parseFormDate() must exist and be a strict calendar validator.
if (strpos($functions, 'function parseFormDate') === false) {
    $failures[] = 'includes/functions.php is missing the parseFormDate() helper';
} else {
    $fnPos = strpos($functions, 'function parseFormDate');
    $fnBlock = substr($functions, $fnPos, 2000);
    if (strpos($fnBlock, 'checkdate') === false) {
        $failures[] = 'parseFormDate() must use checkdate() — createFromFormat() returns warnings, not false, for impossible dates';
    }
    if (!preg_match('~preg_match\s*\(~', $fnBlock)
        || strpos($fnBlock, '\\d{4}-\\d{2}-\\d{2}') === false) {
        $failures[] = 'parseFormDate() must enforce the exact Y-m-d shape';
    }
    if (strpos($fnBlock, 'must be a valid date') === false) {
        $failures[] = 'parseFormDate() must push a clear validation error';
    }
}

// 2b. task_edit.php must use parseFormDate() for BOTH date fields and
//     must store the parsed value.
$taskEditPath = $root . '/task_edit.php';
$taskEdit = read_source($taskEditPath);
if ($taskEdit === '') {
    $failures[] = 'task_edit.php is missing or unreadable';
} else {
    $code = strip_php_comments($taskEdit);

    if (substr_count($code, 'parseFormDate(') < 2) {
        $failures[] = 'task_edit.php must call parseFormDate() for both expected_completion_date and completion_date';
    }
    // The dead validation must be gone — createFromFormat() returns an
    // object carrying warnings for 2026-02-31, so the old guard never fired.
    if (strpos($code, 'DateTime::createFromFormat') !== false) {
        $failures[] = 'task_edit.php still uses DateTime::createFromFormat() for the date fields (it cannot reject impossible calendar dates)';
    }
    // The parsed value must be what is stored.
    if (!preg_match('/\$expected_completion_date\s*=\s*parseFormDate\s*\(/', $code)) {
        $failures[] = 'task_edit.php does not assign the parseFormDate() return value to $expected_completion_date';
    }
    if (!preg_match('/\$completion_date\s*=\s*parseFormDate\s*\(/', $code)) {
        $failures[] = 'task_edit.php does not assign the parseFormDate() return value to $completion_date';
    }
    // The auto-set completion date must match the DATE column precision.
    if (preg_match("/\\\$completion_date\s*=\s*date\(\s*'Y-m-d H:i:s'\s*\)/", $code)) {
        $failures[] = 'task_edit.php still auto-sets completion_date to a timestamp — the column is DATE';
    }

    // 2b-1. The error bag must be initialised BEFORE the first parseFormDate()
    //       call. parseFormDate() takes the bag by reference and is typed
    //       `array &$errors`; if the bag is still undefined/null at that
    //       point PHP raises
    //         "parseFormDate(): Argument #3 ($errors) must be of type array, null given"
    //       and the whole task form 500s on every submission. The CSRF
    //       branch below appends to the bag too, so the initialisation has
    //       to come before that branch as well.
    $errorsInit = strpos($code, '$errors = []');
    $firstParse = strpos($code, 'parseFormDate(');
    if ($errorsInit === false) {
        $failures[] = 'task_edit.php never initialises $errors — parseFormDate() needs an array bag';
    } elseif ($firstParse !== false && $errorsInit > $firstParse) {
        $failures[] = 'task_edit.php initialises $errors AFTER the first parseFormDate() call — '
                    . 'parseFormDate() takes the bag by reference and would raise a TypeError';
    }
    if ($errorsInit !== false) {
        // The bag must be initialised inside the POST branch, not merely
        // somewhere else in the file (e.g. before the request-method check).
        $postPos = strpos($code, "REQUEST_METHOD");
        if ($postPos === false) {
            $failures[] = 'task_edit.php is missing the POST request-method check';
        } elseif ($errorsInit < $postPos) {
            $failures[] = 'task_edit.php initialises $errors before the POST branch — the bag would be '
                        . 'reset away before parseFormDate() is called';
        }
    }
}

// 2c. The completion input must be type="date" and re-render as Y-m-d.
$completionTag = element_around($taskEdit, 'id="completion_date"');
if ($completionTag === '') {
    $failures[] = 'task_edit.php is missing the completion_date input';
} else {
    if (strpos($completionTag, 'type="date"') === false) {
        $failures[] = 'task_edit.php: the completion_date input must be type="date" (the column is DATE)';
    }
    if (strpos($completionTag, 'datetime-local') !== false) {
        $failures[] = 'task_edit.php: the completion_date input is still datetime-local';
    }
    // The re-render format must match the input type exactly, or the
    // browser silently shows an empty field.
    if (!preg_match('/id="completion_date".*?date\(\s*\'Y-m-d\'\s*,/s', $taskEdit)) {
        $failures[] = 'task_edit.php: completion_date must re-render with date(\'Y-m-d\', ...) to match type="date"';
    }
    if (preg_match('/id="completion_date".*?date\(\s*\'Y-m-d\\\\TH:i\'\s*,/s', $taskEdit)) {
        $failures[] = 'task_edit.php: completion_date still re-renders with a datetime format';
    }
}

// 2d. The expected-completion input must also be type="date".
$expectedTag = element_around($taskEdit, 'id="expected_completion_date"');
if ($expectedTag === '') {
    $failures[] = 'task_edit.php is missing the expected_completion_date input';
} elseif (strpos($expectedTag, 'type="date"') === false) {
    $failures[] = 'task_edit.php: the expected_completion_date input must be type="date"';
}

// 2e. Every writer of a completion_date must use day precision.
$writerTargets = [
    'ajax/complete_task.php'      => "date('Y-m-d')",
    'ajax/update_task_status.php' => "date('Y-m-d')",
];
foreach ($writerTargets as $file => $needle) {
    $src = strip_php_comments(read_source($root . '/' . $file));
    if ($src === '') {
        $failures[] = "$file is missing or unreadable";
        continue;
    }
    if (strpos($src, "date('Y-m-d H:i:s')") !== false) {
        $failures[] = "$file still writes a timestamp into the DATE completion_date column";
    }
    if (strpos($src, $needle) === false) {
        $failures[] = "$file does not write completion_date with day precision (date('Y-m-d'))";
    }
}

// 2f. parent_hold.php and syncProjectTaskCompletions() use SQL-side dates.
$parentHold = strip_php_comments(read_source($root . '/ajax/parent_hold.php'));
if ($parentHold === '') {
    $failures[] = 'ajax/parent_hold.php is missing or unreadable';
} else {
    if (preg_match('/completion_date\s*=\s*COALESCE\([^)]*NOW\(\)/', $parentHold)) {
        $failures[] = 'ajax/parent_hold.php still uses NOW() for completion_date — it must use CURDATE()';
    }
    if (strpos($parentHold, 'CURDATE()') === false) {
        $failures[] = 'ajax/parent_hold.php does not use CURDATE() for completion_date';
    }
}

$fnCode = strip_php_comments($functions);
if (preg_match('/completion_date\s*=\s*COALESCE\([^)]*NOW\(\)/', $fnCode)) {
    $failures[] = 'syncProjectTaskCompletions() still uses NOW() for completion_date — it must use CURDATE()';
}

// 2g. The mockup must agree with production (generated, never hand-edited).
$mockupPath = $root . '/docs/design/2.0/task-form.html';
$mockup = read_source($mockupPath);
if ($mockup === '') {
    $failures[] = 'docs/design/2.0/task-form.html is missing';
} else {
    if (strpos($mockup, 'type="datetime-local"') !== false) {
        $failures[] = 'the task-form mockup still uses type="datetime-local" — it reproduces the production defect';
    }
    if (!preg_match('/id="completion_date"[^>]*type="date"|type="date"[^>]*id="completion_date"/', $mockup)) {
        $failures[] = 'the task-form mockup does not use type="date" for completion_date';
    }
}
$buildSrc = read_source($root . '/docs/design/2.0/_build/screens_part4.py');
if (strpos($buildSrc, 'datetime-local') !== false) {
    $failures[] = 'docs/design/2.0/_build/screens_part4.py still renders datetime-local — regenerate the mockup';
}

// 2h. The schema must NOT have been widened to DATETIME (day-precision path).
$schema = read_source($root . '/database/schema.sql');
if ($schema !== '' && preg_match('/completion_date\s+DATETIME/i', $schema)) {
    $failures[] = 'database/schema.sql was widened to DATETIME — the recommended day-precision path leaves it as DATE';
}

// 2i. task_detail.php must render the completion date at day precision.
$taskDetail = read_source($root . '/task_detail.php');
if ($taskDetail !== '') {
    // Anchor on the render, not on the delay-logic checks that mention
    // completion_date earlier in the file.
    $renderPos = strpos($taskDetail, "formatDateTime(\$task['completion_date']");
    if ($renderPos === false) {
        $failures[] = 'task_detail.php does not render the task completion_date';
    } elseif (!preg_match('/formatDateTime\(\s*\$task\[\'completion_date\'\]\s*,\s*\'M j, Y\'\s*\)/', substr($taskDetail, $renderPos, 200))) {
        $failures[] = 'task_detail.php must render completion_date with the day format \'M j, Y\' (the column is DATE)';
    }
}

// =====================================================================
// Bug 3 — client dashboard language switcher
// =====================================================================
$clientCssPath = $root . '/client/assets/client.css';
$clientCss = read_source($clientCssPath);
if ($clientCss === '') {
    $failures[] = 'client/assets/client.css is missing or unreadable';
} else {
    // The active state must target the production element (an <a> with
    // .lang-switch-link), scoped so it outranks the old two-class rule.
    if (!preg_match('/\.client-body\s+\.lang-switch\.inline\s+\.lang-switch-link\.is-active\s*\{/', $clientCss)) {
        $failures[] = 'client.css is missing the .client-body .lang-switch.inline .lang-switch-link.is-active rule';
    }
    // The active chip must use the brand fill, matching the v2 mockup's
    // `.lang-switch button.is-active { background: var(--brand-600); }`.
    if (!preg_match('/\.client-body\s+\.lang-switch\.inline\s+\.lang-switch-link\.is-active\s*\{[^}]*background:\s*var\(--brand-600\)/s', $clientCss)) {
        $failures[] = 'the active language chip must use the brand fill (var(--brand-600)) to match the mockup';
    }
    // The superseded issue-051 white-chip rule must be gone — it is more
    // specific than a generic rule and would win.
    if (preg_match('/\.client-header__tools\s+\.lang-switch-link\.is-active\s*\{/', $clientCss)) {
        $failures[] = 'the issue-051 .client-header__tools white-chip rule is still active and overrides the mockup styling';
    }
    // A visible keyboard-focus state is required, and it must use the same
    // scoped selector as the active chip.
    if (!preg_match('/\.client-body\s+\.lang-switch\.inline\s+\.lang-switch-link:focus-visible\s*\{[^}]*outline:\s*2px\s+solid\s+var\(--brand-600\)/s', $clientCss)) {
        $failures[] = 'client.css is missing a visible .lang-switch-link:focus-visible outline scoped to .client-body .lang-switch.inline';
    }
}

// The switcher container must expose role="group".
$clientDash = read_source($root . '/client/dashboard.php');
if ($clientDash === '') {
    $failures[] = 'client/dashboard.php is missing or unreadable';
} else {
    if (!preg_match('/class="lang-switch inline"[^>]*role="group"/s', $clientDash)
        && !preg_match('/role="group"[^>]*class="lang-switch inline"/s', $clientDash)) {
        $failures[] = 'client/dashboard.php: the language switcher container must expose role="group"';
    }
    // The active link must carry aria-current="true".
    if (strpos($clientDash, 'aria-current="true"') === false) {
        $failures[] = 'client/dashboard.php: the active language link must carry aria-current="true"';
    }
    // The links must stay <a> elements (server-rendered, work without JS).
    // The href attribute contains a PHP short-echo tag whose `>` is a
    // literal `>` in the rendered HTML, so a `[^>]*` matcher would stop
    // before the class attribute. Scan the element window instead.
    $linkTag = element_around($clientDash, 'class="lang-switch-link', 400);
    if ($linkTag === '' || strpos($linkTag, '<a ') === false) {
        $failures[] = 'client/dashboard.php: the language switcher must keep its <a> links';
    }
}

// The labels must be ENG / 中文.
$lang = read_source($root . '/client/lang.php');
if ($lang !== '' && !preg_match("/'en'\s*=>\s*'ENG'/", $lang)) {
    $failures[] = 'client/lang.php: the English label must be "ENG" (matching the mockup)';
}
if ($lang !== '' && !preg_match("/'zh'\s*=>\s*'中文'/u", $lang)) {
    $failures[] = 'client/lang.php: the Chinese label must be "中文" (matching the mockup)';
}

// =====================================================================
// Bug 4 — forgot password
// =====================================================================
$forgot = read_source($root . '/forgot_password.php');
if ($forgot === '') {
    $failures[] = 'forgot_password.php is missing or unreadable';
} else {
    // The required marker.
    if (!preg_match('/<label for="email">Email Address\s*<span class="req"/', $forgot)) {
        $failures[] = 'forgot_password.php: the email label must carry the .req required marker';
    }
    // autocomplete + autofocus. The window is scanned rather than matched
    // with `[^>]*` because the value attribute contains a literal `>`.
    $emailInput = element_window($forgot, '<input class="input" type="email" id="email"');
    if ($emailInput === '') {
        $failures[] = 'forgot_password.php: the email input is missing';
    } else {
        if (strpos($emailInput, 'autocomplete="email"') === false) {
            $failures[] = 'forgot_password.php: the email input needs autocomplete="email"';
        }
        if (strpos($emailInput, 'autofocus') === false) {
            $failures[] = 'forgot_password.php: the email input needs autofocus';
        }
        if (strpos($emailInput, 'required') === false) {
            $failures[] = 'forgot_password.php: the email input must stay required';
        }
    }
    // The submitted value must be preserved.
    if (strpos($emailInput, "\$_POST['email']") === false) {
        $failures[] = 'forgot_password.php: the submitted email value must be preserved in the input';
    }
    // The button must have a top margin.
    if (!preg_match('/<button type="submit" class="btn btn--primary btn--lg btn--block" style="margin-top:\.5rem;">/', $forgot)) {
        $failures[] = 'forgot_password.php: the submit button needs the mockup\'s margin-top:.5rem';
    }
    // The error alert must come BEFORE the <h1> inside the card.
    $cardPos = strpos($forgot, '<div class="auth-card">');
    $h1Pos = strpos($forgot, '<h1>Forgot password</h1>');
    $errPos = strpos($forgot, 'alert alert--danger');
    if ($cardPos === false || $h1Pos === false || $errPos === false) {
        $failures[] = 'forgot_password.php: missing the auth-card, the heading or the error alert';
    } elseif ($errPos > $h1Pos) {
        $failures[] = 'forgot_password.php: the error alert must render ABOVE the "Forgot password" heading';
    }
    // The success alert must also come before its heading.
    $successPos = strpos($forgot, 'alert alert--success');
    $h1SuccessPos = strpos($forgot, '<h1>Reset Email Sent</h1>');
    if ($successPos === false || $h1SuccessPos === false) {
        $failures[] = 'forgot_password.php: missing the success alert or its heading';
    } elseif ($successPos > $h1SuccessPos) {
        $failures[] = 'forgot_password.php: the success alert must render ABOVE the "Reset Email Sent" heading';
    }
    // The error block must be before the form.
    $formPos = strpos($forgot, '<form method="POST"');
    if ($formPos !== false && $errPos !== false && $errPos > $formPos) {
        $failures[] = 'forgot_password.php: the error block must render before the form';
    }
    // The v2 shell toggle must remain.
    if (strpos($forgot, '$use_v2_shell') === false || strpos($forgot, '$content_bleed') === false) {
        $failures[] = 'forgot_password.php: the $use_v2_shell / $content_bleed toggle must remain';
    }
}

// register.php must place its error alert in the same position.
$register = read_source($root . '/register.php');
if ($register !== '') {
    $cardPos = strpos($register, '<div class="auth-card">');
    $h1Pos = strpos($register, '<h1>Create your account</h1>');
    $errPos = strpos($register, 'alert alert--danger');
    if ($cardPos === false || $h1Pos === false || $errPos === false) {
        $failures[] = 'register.php: missing the auth-card, the heading or the error alert';
    } elseif ($errPos > $h1Pos) {
        $failures[] = 'register.php: the error alert must render ABOVE the "Create your account" heading';
    }
    $successPos = strpos($register, 'alert alert--success');
    $h1SuccessPos = strpos($register, '<h1>Registration Successful!</h1>');
    if ($successPos !== false && $h1SuccessPos !== false && $successPos > $h1SuccessPos) {
        $failures[] = 'register.php: the success alert must render ABOVE the "Registration Successful!" heading';
    }
}

// =====================================================================
// Functional check — parseFormDate()
// =====================================================================
// Load only the helper (not the whole functions.php, which needs session
// and DB helpers) so the behaviour can be exercised directly.
if (strpos($functions, 'function parseFormDate') !== false) {
    $helperStart = strpos($functions, 'function parseFormDate');
    $helperEnd = strpos($functions, "\n}", $helperStart);
    if ($helperEnd !== false) {
        $helper = substr($functions, $helperStart, $helperEnd - $helperStart + 2);
        eval($helper);

        if (!function_exists('parseFormDate')) {
            $failures[] = 'parseFormDate() could not be loaded for the functional check';
        } else {
            $cases = [
                // [input, expected, expectedErrorCount]
                ['2026-10-22', '2026-10-22', 0],   // a valid date round-trips
                ['2026-02-29', null, 1],            // 2026 is not a leap year
                ['2026-02-31', null, 1],            // impossible calendar date
                ['2026-13-01', null, 1],            // impossible month
                ['2026-00-10', null, 1],            // impossible day
                ['2026-10-32', null, 1],            // impossible day
                ['2026-1-1',   null, 1],            // wrong shape
                ['2026-10-22T14:30', null, 1],      // datetime rejected (day precision)
                ['not a date', null, 1],
                ['',            null, 0],            // empty is allowed
                ['   ',         null, 0],            // whitespace-only is allowed
            ];

            foreach ($cases as [$input, $expected, $expectedErrors]) {
                $errors = [];
                $result = parseFormDate($input, 'Completion date', $errors);
                if ($result !== $expected) {
                    $failures[] = sprintf(
                        'parseFormDate(%s) returned %s, expected %s',
                        var_export($input, true),
                        var_export($result, true),
                        var_export($expected, true)
                    );
                }
                if (count($errors) !== $expectedErrors) {
                    $failures[] = sprintf(
                        'parseFormDate(%s) produced %d error(s), expected %d',
                        var_export($input, true),
                        count($errors),
                        $expectedErrors
                    );
                }
            }

            // A non-string value must be rejected, not silently coerced.
            $errors = [];
            $result = parseFormDate(['2026-10-22'], 'Completion date', $errors);
            if ($result !== null || count($errors) !== 1) {
                $failures[] = 'parseFormDate() must reject a non-string value';
            }
        }
    }
}

// =====================================================================
// Lint every PHP file touched by this issue.
// =====================================================================
$lintTargets = [
    $scriptPath,
    $detailPath,
    $editPath,
    $functionsPath,
    $taskEditPath,
    $root . '/task_detail.php',
    $root . '/forgot_password.php',
    $root . '/register.php',
    $root . '/client/dashboard.php',
    $root . '/ajax/complete_task.php',
    $root . '/ajax/update_task_status.php',
    $root . '/ajax/parent_hold.php',
];
$lint = '/usr/local/bin/php -l';
if (!is_executable($lint)) {
    $lint = 'php';
}
foreach ($lintTargets as $f) {
    $cmd = escapeshellcmd($lint) . ' -l ' . escapeshellarg($f) . ' 2>&1';
    $out = shell_exec($cmd);
    if (!is_string($out) || strpos($out, 'No syntax errors detected') === false) {
        $failures[] = 'Lint failed for ' . basename((string) $f) . ': ' . trim((string) $out);
    }
}

// =====================================================================
// Report
// =====================================================================
if ($failures) {
    fwrite(STDERR, "FAIL: issue-055 browser-found bugs regression test\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "PASS: issue-055 browser-found bugs regression test\n";
echo "  - Bug 1: pmpCopyText() owns the Clipboard API -> execCommand fallback; initCopy() and copyShareUrl() both delegate and report failure honestly\n";
echo "  - Bug 2: task dates are day precision end to end (parseFormDate(), type=\"date\", day-precision AJAX writers, CURDATE() in SQL, mockup regenerated)\n";
echo "  - Bug 3: the client language switcher's active chip uses the brand fill with role=\"group\", aria-current and a visible focus state\n";
echo "  - Bug 4: forgot_password.php / register.php render the alert above the heading, with the .req marker, autocomplete and autofocus\n";
echo "  - parseFormDate() rejects impossible calendar dates (2026-02-31) and malformed values\n";
echo "  - task_edit.php initialises the \$errors bag before the first parseFormDate() call (no TypeError)\n";
echo "  - All modified PHP files lint cleanly\n";
exit(0);
