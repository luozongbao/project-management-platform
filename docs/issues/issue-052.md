# issue-052 — Install wizard on the v2 component layer

**Date:** 2026-10-24
**Branch:** `v.2.0`
**Phase:** 1.5c (v2.0 migration, client + public surfaces)
**Status:** complete

---

## 1. Problem

`install.php` was the last page in the application still carrying its own
hand-rolled presentation layer: a **79-line inline `<style>` block** (lines
237–315 before the fix) defining a private copy of the button, alert, form
and step-indicator primitives.

Concretely, the page shipped its own:

| Legacy class | What it did | v2 replacement |
|---|---|---|
| `.install-container` | the card wrapper | `.install-card` |
| `.step-indicator` / `.step` / `.step.active` / `.step.completed` | numbered circles | `.stepper` / `.stepper__step` / `.is-active` / `.is-completed` |
| `.form-group` / `.form-group label` / `.form-group input,select` | form fields | `.field` / `.field label` / `.input` / `.select` |
| `.btn` / `.btn:hover` | buttons | `.btn` / `.btn--primary` / `.btn--secondary` / `.btn--lg` / `.btn--block` |
| `.alert` / `.alert-danger` / `.alert-success` | alerts | `.alert` / `.alert--danger` / `.alert--success` |
| (none) | — | `.install-panel`, `.install-list`, `.hint`, `.req` |

The problems this caused:

1. **Hard-coded colours.** `#007bff`, `#e9ecef`, `#f8d7da`, `#721c24`,
   `#ddd`, `#333` — none of them are design tokens. The page therefore
   rendered in Bootstrap-3 blue while the rest of the application rendered
   in the v2 brand blue, and it had **no dark-mode support at all**: the
   card stayed white and the text stayed `#333` regardless of the
   `prefers-color-scheme` setting.
2. **The v2 component layer was never loaded.** The page linked only
   `v2-tokens.css` and `style.css`. `assets/css/v2-components.css` is
   loaded exclusively by `includes/header.php` (behind `$use_v2_shell`),
   and the installer renders its own `<head>` — so none of the shared
   `.field` / `.input` / `.btn` / `.alert` rules were in the cascade at
   all. This is the same root cause as issue-050 (client dashboard) and
   issue-051 (landing page).
3. **Duplicated primitives.** Every primitive the page used existed twice:
   once in the v2 component layer, once inline. They had already drifted
   (the inline `.btn` had no `.btn--primary` modifier at all).

### Symptom table

| # | Symptom | Root cause |
|---|---|---|
| S1 | Installer renders in a different colour language from every other page | hard-coded hex colours, no tokens |
| S2 | Installer ignores dark mode entirely | no `prefers-color-scheme` handling, hard-coded `#fff` / `#333` |
| S3 | The step indicator gave no hint what each step was for | bare numbered circles, no labels |
| S4 | Wizard fields stacked one per row, wasting vertical space | single-column `.form-group` |
| S5 | 79 lines of CSS shipping inside every installer response | inline `<style>` |

---

## 2. Root cause

`install.php` predates the v2 design system and was never migrated, for the
same reason the client dashboard and landing page were not: they render
their own `<head>` and therefore miss the `$use_v2_shell` gate in
`includes/header.php` that pulls in `v2-components.css`.

---

## 3. The fix

### 3.1 `install.php` — inline `<style>` block removed

The entire 79-line `<style>` block is deleted. The page now links the
canonical four-layer cascade:

```
v2-tokens.css  ->  style.css  ->  v2-components.css  ->  pages/install.css
```

with the first three via `asset_url()` (as before) and the new page
stylesheet likewise.

### 3.2 `install.php` — markup rebuilt on the v2 vocabulary

| Legacy markup | New markup |
|---|---|
| `<div class="install-container">` | `<div class="install-shell"><div class="install-card">` |
| `<h1 style="text-align:center;color:#333">` | `<h1>` + `.install-card__logo` badge |
| `<div class="step-indicator"><div class="step active">1</div>…` | `<ol class="stepper">` with `.stepper__step` / `.stepper__dot` / `.stepper__label` |
| `<div class="form-group">` | `<div class="field">` |
| `<label>` (implicit) | `<label>` + `<span class="req">*</span>` for required fields |
| `<input type="text">` | `<input class="input" type="text">` |
| `<select>` | `<select class="select">` |
| `<div class="alert alert-danger"><ul>…` | `<div class="alert alert--danger" role="alert">` + `.alert__body` + `.alert__title` + `.alert__list` |
| `<div class="alert alert-success">` | `<div class="alert alert--success" role="alert">` + `.alert__body` + `.alert__title` |
| `<a class="btn">` | `<a class="btn btn--primary btn--lg btn--block">` |
| `<button class="btn">` | `<button class="btn btn--primary btn--lg btn--block">` |
| bare `<ul>` requirement lists | `<ul class="install-list">` with `.fas fa-circle-check` markers |
| (none) | `<div class="install-panel">` wraps the Requirements block |

**Accessibility improvements** carried by the new markup:

- the step indicator is an ordered list (`<ol>`) with
  `aria-label="Installation progress"`;
- error and success panels carry `role="alert"`;
- required fields are marked with `<span class="req">*</span>` (rendered in
  `var(--danger-500)`) instead of relying on the `required` attribute
  alone.

### 3.3 `assets/css/pages/install.css` — new page stylesheet

A new file, `assets/css/pages/install.css` (the same directory and
convention as the other eleven page stylesheets). It contains only what the
shared component layer does not provide:

- `.install-body` / `.install-shell` / `.install-card` — the page shell,
  modelled on the landing page's `.landing` / `.landing__card` so the two
  public, unauthenticated entry points of the application look like the
  same product.
- `.install-card__logo` — the 64px brand-gradient badge (mirrors
  `.landing__logo`).
- `.stepper*` — the step indicator, including the connector line between
  consecutive dots and the `is-completed` state (a green check + a green
  connector).
- `.install-list`, `.install-panel`, `.install-next*`, `.alert__list` — the
  content blocks.
- A `@media (max-width: 640px)` block that stacks the two-column form grid
  into one column, tightens the card padding and hides the step labels.

**No mockup exists for this page.** The v2.0 mockup set covers the landing
page and the four auth screens but not the installer, so the visual
language is borrowed from the landing page — which was the agreed option
for this issue.

### 3.4 Two-column wizard forms

`.form-grid.form-grid--2` lays the step-2 and step-3 fields out two-up.
The SMTP Password field is the exception: it carries `.form-section`
(`grid-column: 1 / -1`) so it spans the full width, matching the fact that
it is the last and most sensitive field.

### 3.5 Red lines observed

| Red line | Status |
|---|---|
| `<link rel="icon" type="image/png" href="/assets/image/project-favicon.png">` must appear **verbatim** in install.php (`probe_diag_test.php`) | preserved — exact tag kept |
| A hidden `csrf_token` input must be present in every mutating form (`install_wizard_test.php`) | preserved — both step-2 and step-3 forms |
| `startSession()` must be called before `<!DOCTYPE html>` (`install_wizard_test.php`) | preserved — the whole PHP logic block is byte-identical |
| The `db_name` regex validation, the `var_export()` config writer, the `SERVER_NAME`-based `APP_URL`, the absence of `addslashes()` and `HTTP_HOST` | preserved — the PHP logic block is byte-identical |
| The two-stage gate (`file_exists(__DIR__ . '/config.php')`) | preserved |
| No `name=` / `id=` renames on fields read by the unchanged PHP | preserved — `db_host`, `db_name`, `db_user`, `db_pass`, `smtp_host`, `smtp_port`, `smtp_protocol`, `smtp_user`, `smtp_pass` all unchanged |
| `install.php` is exempt from the slug 301 rewrite (`slug_http_test.php`) | untouched — no change to `docker/nginx.conf` |

**The PHP logic block (lines 1–227) was not modified at all.** The change is
confined to the HTML template (lines 228+) and the new stylesheet.

---

## 4. Verification

### 4.1 Static checks

| Check | Result |
|---|---|
| `php -l install.php` | No syntax errors detected |
| Undefined CSS tokens in `install.css` | **0** (all `var(--…)` resolve against `v2-tokens.css`) |
| Unresolved classes in `install.php` | **0** |
| Regression sweep (`database/*_test.php` + `client/*_test.php`) | **20 PASS / 0 FAIL** |
| `probe_diag_test.php` (favicon tag + md5 pins) | passed |

The class-resolution check:

```bash
cat assets/css/v2-tokens.css assets/css/style.css \
    assets/css/v2-components.css assets/css/pages/install.css > /tmp/all.css
grep -ohE 'class="[a-z0-9_ -]*"' install.php \
  | sed 's/class="//;s/"//' | tr ' ' '\n' \
  | grep -vE '^$|^fas$|^far$|^fab$|^fa-' | sort -u > /tmp/cls.txt
while read c; do grep -q "\.$c[^a-zA-Z0-9_-]" /tmp/all.css || echo "MISSING: .$c"; done < /tmp/cls.txt
```

### 4.2 Browser verification (VS Code integrated browser)

The installer is gated behind `file_exists(config.php)`, so the real page
cannot be reached on a configured instance. It was verified by **temporarily
moving `config.php` aside**, exercising all three steps, then restoring it
byte-identically (`diff` against a backup → no differences).

| Step | State | Result |
|---|---|---|
| 1 | welcome | renders; stepper dot 1 active (brand blue), 2 and 3 inactive |
| 2 | database form | stepper dot 1 **completed** (green check), dot 2 active; two-column grid |
| 3 | SMTP form | dots 1 and 2 completed, dot 3 active; SMTP Password spans full width |
| 2 | validation error | `.alert--danger` renders with the error list; submitted values are preserved |
| 2 | DB unreachable | `.alert--danger` with the generic (non-leaking) message |
| success | completion panel | `.alert--success` with the next-steps list and the primary CTA |

Computed styles (light mode), matching the landing page's visual language:

| Element | Property | Value |
|---|---|---|
| `.install-body` | background | `rgb(249, 250, 252)` (`--gray-50`) |
| `.install-card` | background | `rgb(255, 255, 255)` (`--gray-0`) |
| `.install-card` | border | `0.8px solid rgb(226, 231, 240)` (`--gray-200`) |
| `.install-card` | border-radius | `20px` (`--r-xl`) |
| `.install-card` | padding | `40px` (`--sp-10`) |
| `.install-card` | max-width | `620px` |
| `.install-card__logo` | size | `64px × 64px`, brand gradient, radius `12px` |
| `.install-card h1` | color | `rgb(17, 24, 39)` (`--gray-900`) |
| `.stepper__step.is-active .stepper__dot` | background | `rgb(31, 74, 224)` (`--brand-500`) |
| `.stepper__step.is-completed .stepper__dot` | background | `rgb(22, 163, 74)` (`--success-500`) |
| `.btn--primary` | background | `rgb(31, 74, 224)` (`--brand-500`) |
| `.alert--danger` | background / color / border | `rgb(251, 220, 220)` / `rgb(160, 27, 27)` / `rgb(243, 194, 194)` |

Dark mode (`prefers-color-scheme: dark`):

| Element | Property | Value |
|---|---|---|
| `.install-body` | background | `rgb(18, 22, 31)` |
| `.install-card` | background | `rgb(12, 16, 24)` |
| `.install-card` | border-color | `rgb(38, 45, 60)` |
| `.install-card h1` | color | `rgb(241, 244, 249)` |
| `.field label` | color | `rgb(198, 206, 221)` |
| `.input` | background | `rgb(12, 16, 24)` |
| `.btn--primary` | background | `rgb(31, 74, 224)` |

Responsive (390 × 844): the form grid collapses to a single column
(`grid-template-columns: 293.6px`), the step labels are hidden, and there
is **no horizontal overflow** (`scrollWidth 375 ≤ innerWidth 390`).

Console: **0 errors, 0 failed requests** on every step.

### 4.3 HTTP checks

```
/install.php                 -> 200
/install.php?step=2          -> 200
/install.php?step=3          -> 200
/assets/css/pages/install.css -> 200
```

### 4.4 Cleanup

The temporary preview harness (`_install_success_preview.php`) was deleted
and `config.php` was restored byte-identically (`diff` → no differences).
No stray files remain in the working tree.

---

## 5. Design decisions

1. **Borrow the landing page's visual language.** There is no installer
   mockup, so the page reuses the landing page's card geometry (620px vs.
   520px — the installer needs more room for two-column fields), the
   brand-gradient logo badge and the neutral `--gray-50` page background.
   The two public entry points now read as the same product.
2. **Keep the step indicator, but label it.** The old bare circles told the
   user nothing about what steps 2 and 3 were. Adding `.stepper__label`
   ("Welcome / Database / Email") makes the wizard legible at a glance.
   On mobile the labels are hidden to preserve the horizontal space.
3. **Two-column forms.** The wizard fields are short (host, port, name);
   a single column forced a lot of scrolling for a 3-step flow. The SMTP
   Password field spans the full width as the one deliberate exception.
4. **Requirements get their own panel.** The original page had two
   adjacent `<ul>`s in the same visual register ("This process will…" and
   "Requirements:"). The second is now in a `.install-panel` so the two
   lists are distinguishable.

---

## 6. Follow-ups

### 6.1 Pre-existing defect found (NOT fixed by this issue)

While exercising step 2, the wizard failed to advance with:

```
Install wizard DB step failed: SQLSTATE[HY000] [2002] No such file or directory
```

Cause: `install.php:108` reads the schema with a **relative** path —

```php
$schema = file_get_contents('database/schema.sql');
```

The path is resolved against the **current working directory** of the PHP
process, not against `__DIR__`. Under nginx/php-fpm the CWD is not
guaranteed to be the document root, so the read fails and the wizard
cannot advance past step 2.

This is a **pre-existing bug**, unrelated to the v2 styling work — the
offending line is unchanged from `HEAD` (`git show HEAD:install.php` line
108 is identical) and the fix belongs to a separate issue, not to a
cosmetic conversion.

The fix would be:

```php
$schema = file_get_contents(__DIR__ . '/database/schema.sql');
```

### 6.2 The legacy primitives are now fully dead

With the installer converted, **no page in the application** references
`.btn-primary`, `.btn-secondary`, `.btn-success`, `.btn-danger`,
`.btn-block`, `.alert-danger`, `.alert-success`, `.alert-info`,
`.alert-warning` or `.form-group` any more (verified by a repo-wide grep
across every `*.php` file). Their rules in `assets/css/style.css` are
therefore candidates for the issue-053 dead-rule cleanup.

### 6.3 The installer still has no localization

Unlike the client portal, the installer is English-only. Adding a
`client/lang.php`-style catalog would be a behaviour change and is out of
scope for the v2 migration.
