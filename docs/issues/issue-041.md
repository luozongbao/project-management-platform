# issue-041 — project_detail.php v2 conversion + app-shell opt-in bug

**Date:** 2026-10-23
**Branch:** `v.2.0`
**Status:** done (pending visual confirmation)

## Summary

Two pieces of work:

1. **issue-041a — `project_detail.php` converted to the v2 component layer.**
   959 lines -> 506 lines. All 467 lines of inline `<style>` extracted to
   `assets/css/pages/project_detail.css` (88 lines).

2. **issue-041b — `$use_v2_shell` opt-in bug, six pages.**
   A pre-existing bug that made six pages render outside the app shell.

## issue-041b: the shell bug (the important one)

### Symptom

The user supplied a screenshot of `/projects/858` and `/projects/7`. Both rendered
as **completely unstyled markup**: no sidebar, no cards, no table styling, no
colours, full-bleed content. Icons appeared as bare glyphs.

### Root cause

`includes/header.php` line 94 gates the entire v2 shell on an opt-in variable:

```php
$__v2_shell = !empty($use_v2_shell) && isset($show_nav) && $show_nav && isLoggedIn();
```

Without `$use_v2_shell`, `header.php` emits neither the `.app-shell` wrapper nor
the `.sidebar`, so the page has no layout context at all.

`project_detail.php` set `$show_nav = true` but **never set `$use_v2_shell`**.
Verified pre-existing via `git show HEAD:project_detail.php` — the variable was
already absent before the v2 conversion began.

### Affected pages

| Page | Before | After |
|---|---|---|
| dashboard, projects, tasks, contacts, profile | ok | ok |
| project_detail | **broken** | fixed |
| task_detail | **broken** | fixed |
| contact_detail | **broken** | fixed |
| project_edit | **broken** | fixed |
| task_edit | **broken** | fixed |
| contact_edit | **broken** | fixed |

Verified live, not just by grep: `task_detail.php` emitted **0** occurrences of
`sidebar__nav` before the fix and 1 after.

The same flag also gates `v2-components.css` (header.php line 71) and the footer
closing tag (`includes/footer.php` line 13), so a page missing it loses the whole
v2 layer, not just the sidebar.

### The mistake I made while fixing it

Worth recording, because it cost three iterations and the failure modes were
misleading each time.

The first attempt inserted the variable **after** the data layer's closing `?>`:

```php
$show_nav = true;
?>

// comment
$use_v2_shell = true;      <-- literal text, never executed
<?php include 'includes/header.php'; ?>
```

PHP emits the text after `?>` verbatim, so the variable was never assigned. The
page still rendered without a sidebar — correctly, since the assignment never
ran — but the failure looked identical to "the fix didn't work", which sent me
looking for a variable-stripping mechanism that did not exist.

The second attempt left **two** `?>` closers, so everything between them became
literal text. The third removed one `?>` too many, deleting the data layer's own
closer and turning the page into a parse error (161-byte fatal-error responses).

**How I actually found it:** a temporary probe comment injected after the header
include, printing the runtime values:

```
<!--PROBE use_v2_shell='UNSET' show_nav=true loggedin=true shell=false-->
```

`use_v2_shell=UNSET` at runtime, despite being present in the file, is what
proved it was a parse-structure problem rather than a logic problem. Reading the
source again with `cat -A` then showed the duplicate `?>` immediately.

**Lesson:** when a variable is *provably* set in source but reads `UNSET` at
runtime, the problem is PHP parse structure, not scoping or logic. Print the
runtime value rather than reading the file a fourth time.

### Why no test caught this

The regression suite is cascade-blind and selector-blind: it asserts HTTP status
and data, never computed style or rendered structure. `php -l` passed the whole
time because the file was syntactically valid — it simply assigned nothing.

Added to the live check for every page conversion:

```bash
curl -s -H "Cookie: PHPSESSID=$S" "$url" | grep -c sidebar__nav   # expect >= 1
curl -s -H "Cookie: PHPSESSID=$S" "$url" | grep -c use_v2_shell    # expect 0 (no leak)
```

Checking that `use_v2_shell` does not appear in the *output* is what catches the
"inserted outside PHP" mistake specifically.

## issue-041a: the v2 conversion

### Scope

| Aspect | Before | After |
|---|---|---|
| File length | 959 lines | 506 lines |
| Inline `<style>` | 467 lines (2 blocks) | 0 |
| Page stylesheet | none | `assets/css/pages/project_detail.css`, 88 lines |
| Layout | v1.8.0 `.project-content-grid` | `.grid-sidebar` two-column (mockup) |
| Data layer (lines 1-93) | — | reused **verbatim** |

The data layer was extracted from the original file and reinserted unchanged.
This page reads `$_GET['id']` and was already correct for issue-040's id URLs.

### CSS extraction

`assets/css/pages/project_detail.css` holds only what `v2-components.css` does
not define:

- `.share-box` — appears in the mockup, defined in no stylesheet
- `.pd-stats` — the mockup runs the 4 stats 2x2; `.stat-grid` is 4-up
- `.pd-task-sub` — the secondary line under a task name
- `.pd-scope-list` — scope is free text, one item per line
- `.pd-share-hint`, `.pd-share-note`

All 13 CSS tokens used were validated against `v2-tokens.css` and then checked
individually, because the aggregate check had produced false greens before.

### Removed as obsolete

The `progress-circle` JS set `--percentage` and hardcoded hex border colours.
v2's `.progress` + `.progress--success/warning/danger` derive width and tone in
CSS; tone is computed inline from `$pct`.

### Bug found and fixed during conversion

`copyShareUrl()` read `document.getElementById('shareUrl')`, an input that the
v2 markup does not have. It would have silently copied nothing. The URL now
lives on the code element as `data-share-url`.

The button's "Copied" confirmation was also unreachable: the old code branched on
`document.activeElement`, which is not reliably the button after a click. It now
takes the button as an argument and shows feedback unconditionally.

### Rotate share code — deliberately not moved here

The mockup shows a rotate action on this page. It is not reproduced. Rotating
requires POST + CSRF + a typed confirmation, and that form lives in
`project_edit.php`. Copying the button would duplicate a three-part safety
check — exactly the kind of second path that gets missed when the rules change.
The share card links to the edit page instead, and the CSS file records why.

## Verification

- `php -l` clean on all six modified files.
- `parse.py`: **none** for both mismatches and unclosed tags, on populated and
  empty projects.
- All 65 classes resolve against loaded stylesheets. The only undefined ones are
  Font Awesome icons, all valid FA6 names for the pinned 6.0.0 CDN build.
- Regression sweep: **18 PASS / 2 FAIL** — identical to baseline. Both failures
  pre-existing (`client_share_code_protection_test.php`,
  `security_headers_test.php`), unrelated.
- `slug_http_test.php`: **60 passed, 0 failed**.
- All 14 authenticated pages return HTTP 200 with `sidebar__nav` present and no
  `use_v2_shell` leak.
- Every link emitted on the new page is numeric and resolves 200.
- Empty-state branches exercised with a temporary fixture (since deleted): all
  four render, plus 3 "Not set" labels.
- Red lines untouched: `share-code-format.js`, `style.css`, `header.php`,
  `footer.php`, `project_edit.php` behaviour, nginx, `.htaccess`, `client/`.

## Visual confirmation

Outstanding. The container has no browser, so layout and spacing need the user's
eyes on `/projects/858` and `/projects/7`.
