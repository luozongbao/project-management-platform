#!/usr/bin/env python3
"""Design-system reference sheet + implementation notes."""

from build import (page, write, stat, status_badge, reason_chip, task_node,
                   escape, reason_missing_inline)


def _swatch(name, var):
    return f"""            <div class="swatch">
              <div class="swatch__chip" style="background:var({var});"></div>
              <div class="swatch__meta"><strong>{name}</strong><br><code>{var}</code></div>
            </div>"""


def screen_components():
    brand = [_swatch(f"Brand {n}", f"--brand-{n}") for n in (50, 100, 200, 300, 400, 500, 600, 700, 800, 900)]
    gray = [_swatch(f"Gray {n}", f"--gray-{n}") for n in (0, 50, 100, 200, 300, 400, 500, 600, 700, 800, 900)]
    sem = [_swatch(f"Success {n}", f"--success-{n}") for n in (100, 500, 700)]
    sem += [_swatch(f"Warning {n}", f"--warning-{n}") for n in (100, 500, 700)]
    sem += [_swatch(f"Danger {n}", f"--danger-{n}") for n in (100, 500, 700)]
    sem += [_swatch(f"Info {n}", f"--info-{n}") for n in (100, 500, 700)]

    types = [
        ("3xl", "--fs-3xl", "2.25rem", "Display / hero numbers"),
        ("2xl", "--fs-2xl", "1.75rem", "Page titles"),
        ("xl", "--fs-xl", "1.375rem", "Section headings, stat values"),
        ("lg", "--fs-lg", "1.125rem", "Card titles"),
        ("base", "--fs-base", "1rem", "Body copy, inputs"),
        ("sm", "--fs-sm", "0.875rem", "Secondary text, table cells"),
        ("xs", "--fs-xs", "0.75rem", "Labels, hints, meta"),
    ]
    type_rows = "\n".join(f"""            <div class="type-row">
              <div class="type-row__spec">{name} · {var}<br>{size}</div>
              <div style="font-size:var({var});">The quick brown fox jumps over the lazy dog</div>
              <div class="text-xs text-muted" style="margin-left:auto;">{use}</div>
            </div>""" for name, var, size, use in types)

    body = f"""      <div class="spec-hero">
        <h1>Design System &mdash; v2.0</h1>
        <p>The single source of truth for the v2.0 look. Every colour, size and component
        used across the 18 screens is declared here as a CSS custom property and
        implemented as a reusable class. The dev transplants this file into
        <code>assets/css/style.css</code>.</p>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-palette"></i> Brand palette</h2></div>
        <div class="card__body">
          <div class="token-grid">
{chr(10).join(brand)}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-circle-half-stroke"></i> Neutrals</h2></div>
        <div class="card__body">
          <div class="token-grid">
{chr(10).join(gray)}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-traffic-light"></i> Semantic colours</h2></div>
        <div class="card__body">
          <div class="token-grid">
{chr(10).join(sem)}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-i-cursor"></i> Typography</h2></div>
        <div class="card__body">
          <p class="text-sm text-muted" style="margin-top:0;">Font stack: <code>--font-sans</code> (Inter with system fallbacks).
          Monospace: <code>--font-mono</code> for share codes and typed confirmations.</p>
          <div style="margin-top:1rem;">
{type_rows}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-hand-pointer"></i> Buttons</h2></div>
        <div class="card__body">
          <div class="chip-row">
            <button class="btn btn--primary" type="button"><i class="fas fa-floppy-disk"></i> Primary</button>
            <button class="btn btn--secondary" type="button">Secondary</button>
            <button class="btn btn--ghost" type="button">Ghost</button>
            <button class="btn btn--success" type="button"><i class="fas fa-circle-check"></i> Success</button>
            <button class="btn btn--warning" type="button"><i class="fas fa-pause"></i> Warning</button>
            <button class="btn btn--danger" type="button"><i class="fas fa-trash"></i> Danger</button>
          </div>
          <div class="chip-row" style="margin-top:1rem;">
            <button class="btn btn--primary btn--sm" type="button">Small</button>
            <button class="btn btn--primary btn--lg" type="button">Large</button>
            <button class="btn btn--secondary btn--icon" type="button"><i class="fas fa-pen"></i></button>
            <button class="btn btn--secondary btn--block" type="button">Block</button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-tags"></i> Status &amp; badges</h2></div>
        <div class="card__body">
          <div class="chip-row">
            {status_badge("not_started")}
            {status_badge("in_progress")}
            {status_badge("completed")}
            {status_badge("on_hold")}
            {status_badge("delayed")}
          </div>
          <div class="chip-row" style="margin-top:1rem;">
            <span class="badge badge--neutral">Neutral</span>
            <span class="badge badge--info">Info</span>
            <span class="badge badge--success">Success</span>
            <span class="badge badge--warning">Warning</span>
            <span class="badge badge--danger">Danger</span>
            <span class="badge badge--brand">Brand</span>
            <span class="badge badge--outline">Outline</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-comment-dots"></i> Reason chips (v1.8.0 parity)</h2></div>
        <div class="card__body">
          <p class="text-sm text-muted" style="margin-top:0;">Delay reasons render red; on-hold reasons render orange.
          Both are shown on the internal task list and on the client portal.</p>
          {reason_chip("delay", "fas fa-clock-rotate-left", "Delay",
                       "Heavy monsoon rainfall blocked the survey for 12 days.")}
          <div style="height:.5rem;"></div>
          {reason_chip("on-hold", "fas fa-pause", "On hold",
                       "Awaiting municipal authority scheduling.")}
          <div style="height:1rem;"></div>
          <p class="text-sm text-muted">A task that is delayed or on hold but has <strong>no reason recorded</strong>
          carries the missing-reason marker next to its status badge.</p>
          <div class="chip-row">
            {status_badge("on_hold", reason_missing="on_hold")}
            {status_badge("in_progress", label="In Progress", reason_missing="delay")}
          </div>
          <div style="height:.75rem;"></div>
          {reason_missing_inline("Missing &mdash; fill in to explain the slip to the client.")}
          <div style="height:.75rem;"></div>
          <p class="text-sm text-muted">Inline editor for one reason field (production <code>.reason-inline-edit</code>).</p>
          <details class="reason-inline-edit" open>
            <summary>Add reason</summary>
            <textarea class="textarea" rows="2" maxlength="2000" placeholder="Why is this task on hold?"></textarea>
            <button class="btn btn--sm btn--primary" type="button"><i class="fas fa-floppy-disk"></i> Save</button>
            <span data-save-status aria-live="polite"></span>
          </details>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-hourglass-half"></i> Not-estimated marker (v1.8.0 parity)</h2></div>
        <div class="card__body">
          <p class="text-sm text-muted" style="margin-top:0;">Shown on a client-portal task whose estimate cannot be
          resolved because no sub-task carries one.</p>
          <span class="not-estimated"><i class="fas fa-clock"></i> Not estimated (no sub-task estimates)</span>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-list-check"></i> Task node</h2></div>
        <div class="card__body">
          <ul class="task-tree">
{task_node("MEP rough-in", "in_progress", 55, meta='<div class="task-node__meta"><span><i class="fas fa-user"></i> Nok Chantarak</span><span><i class="fas fa-calendar"></i> Due Oct 22, 2026</span></div>')}
{task_node("Permit renewal", "on_hold", 0, on_hold=True, delayed=True, reasons=reason_chip("on-hold", "fas fa-pause", "On hold", "Awaiting authority scheduling."), meta='<div class="task-node__meta"><span><i class="fas fa-user"></i> Zongbao M.</span></div>')}
{task_node("Site survey", "completed", 100, done=True, meta='<div class="task-node__meta"><span><i class="fas fa-user"></i> Zongbao M.</span></div>')}
          </ul>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-bell"></i> Alerts</h2></div>
        <div class="card__body">
          <div class="alert alert--info"><i class="fas fa-circle-info"></i><div class="alert__body"><div class="alert__title">Information</div>Neutral system message.</div></div>
          <div class="alert alert--success"><i class="fas fa-circle-check"></i><div class="alert__body"><div class="alert__title">Success</div>Action completed.</div></div>
          <div class="alert alert--warning"><i class="fas fa-triangle-exclamation"></i><div class="alert__body"><div class="alert__title">Warning</div>Action needs attention.</div></div>
          <div class="alert alert--danger"><i class="fas fa-triangle-exclamation"></i><div class="alert__body"><div class="alert__title">Danger</div>Action is destructive.</div></div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-list"></i> Table</h2></div>
        <div class="card__body card__body--flush">
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Task</th><th>Status</th><th>Progress</th><th></th></tr></thead>
              <tbody>
                <tr>
                  <td><strong>MEP rough-in</strong></td>
                  <td>{status_badge("in_progress")}</td>
                  <td style="min-width:140px;"><div class="progress"><div class="progress__fill" style="width:55%"></div></div></td>
                  <td><div class="row-actions"><button class="btn btn--sm btn--secondary btn--icon" type="button"><i class="fas fa-pen"></i></button></div></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-sliders"></i> Form controls</h2></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label for="c-input">Input</label>
              <input class="input" type="text" id="c-input" value="Harbour Point Renovation">
            </div>
            <div class="field">
              <label for="c-select">Select</label>
              <select class="select" id="c-select"><option>In Progress</option></select>
            </div>
            <div class="field form-section">
              <label for="c-textarea">Textarea</label>
              <textarea class="textarea" id="c-textarea" rows="3">Scope of work description.</textarea>
            </div>
            <div class="field form-section">
              <label>Range slider</label>
              <div class="range-row">
                <input type="range" min="0" max="100" step="5" value="55" data-range-sync="#c-pct" data-range-out="#c-pct-out">
                <input class="input" type="number" id="c-pct" value="55" style="width:90px;">
                <span class="range-value" id="c-pct-out">55%</span>
              </div>
            </div>
            <div class="field form-section">
              <label class="checkbox"><input type="checkbox" checked><span>Inherit subtask hours</span></label>
            </div>
            <div class="field">
              <label for="c-invalid">Invalid field</label>
              <input class="input" type="email" id="c-invalid" value="not-an-email">
              <span class="error-text"><i class="fas fa-circle-exclamation"></i> Enter a valid email address.</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-inbox"></i> Empty state</h2></div>
        <div class="card__body">
          <div class="empty-state">
            <div class="empty-state__icon"><i class="fas fa-folder-open"></i></div>
            <h3>No Projects Yet</h3>
            <p>Create your first project to start tracking tasks, contacts and client visibility.</p>
            <button class="btn btn--primary" type="button"><i class="fas fa-plus"></i> Add Project</button>
          </div>
        </div>
      </div>"""

    return page("Design System", body, bare=True, body_class="spec-page")


def screen_design_notes():
    body = """      <div class="spec-hero">
        <h1>v2.0 Design &amp; Implementation Notes</h1>
        <p>How the mockup maps onto the production PHP application: token transplant,
        component contract, migration order, accessibility rules and the i18n plan.</p>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-layer-group"></i> 1. Layer model</h2></div>
        <div class="card__body">
          <p>The v1.8.0 frontend is a single <code>assets/css/style.css</code> (984 lines, Bootstrap-4-era
          hardcoded colours, per-page inline CSS duplicated across files) plus a top navbar shell in
          <code>includes/header.php</code>.</p>
          <p>v2.0 replaces this with three layers:</p>
          <table class="spec-table">
            <thead><tr><th>Layer</th><th>File</th><th>Responsibility</th></tr></thead>
            <tbody>
              <tr><td>Tokens</td><td><code>assets/css/v2-tokens.css</code></td><td>All colour, type, spacing, radius, shadow, z-index and layout variables. Zero selectors beyond <code>:root</code>.</td></tr>
              <tr><td>Base + components</td><td><code>assets/css/style.css</code> (rewritten)</td><td>Reset, typography, layout primitives and every component class used by the mockup.</td></tr>
              <tr><td>Page CSS</td><td><code>assets/css/pages/*.css</code></td><td>Only genuinely page-specific rules (e.g. client portal grid). Everything else is a component class.</td></tr>
            </tbody>
          </table>
          <p class="text-sm text-muted" style="margin-bottom:0;">Rule of thumb: if a rule appears on more than one screen it belongs in the component layer.</p>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-arrow-right-arrow-left"></i> 2. Shell migration</h2></div>
        <div class="card__body">
          <p>The current shell is a top navbar. v2.0 moves to a <strong>fixed left sidebar + topbar</strong> layout:</p>
          <table class="spec-table">
            <thead><tr><th>v1.8.0 element</th><th>v2.0 element</th><th>Note</th></tr></thead>
            <tbody>
              <tr><td><code>.navbar</code> / <code>.nav-brand</code></td><td><code>.sidebar__brand</code></td><td>Brand icon + APP_NAME + version.</td></tr>
              <tr><td><code>.nav-menu</code> + <code>.nav-link</code></td><td><code>.sidebar__nav</code> + <code>.sidebar__link</code></td><td>Same four destinations, plus optional count badge.</td></tr>
              <tr><td><code>.nav-dropdown</code> (user)</td><td><code>.dropdown</code> in <code>.topbar__actions</code></td><td>Profile + Logout unchanged.</td></tr>
              <tr><td><code>.app-about</code> details panel</td><td>About <code>&lt;details&gt;</code> in the topbar</td><td>Version, "Built by", release summary.</td></tr>
              <tr><td><code>.main-content.with-nav</code></td><td><code>.content</code> inside <code>.app-main</code></td><td>Padding is now token-driven.</td></tr>
              <tr><td>floating About (logged-out)</td><td>Retained on public pages</td><td>Must stay reachable without JS.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-list-check"></i> 3. Implementation order</h2></div>
        <div class="card__body">
          <ol style="margin:0;padding-left:1.25rem;line-height:2;">
            <li>Land <code>v2-tokens.css</code> and the reset/base layer. Nothing else changes visually yet.</li>
            <li>Land the component layer (buttons, cards, forms, tables, badges, statuses, reason chips).</li>
            <li>Replace the shell in <code>includes/header.php</code> / <code>includes/footer.php</code> with the sidebar + topbar.</li>
            <li>Convert pages in order of risk: <code>dashboard.php</code> → <code>projects.php</code> → <code>tasks.php</code> → <code>contacts.php</code> → detail pages → forms → auth pages → <code>client/dashboard.php</code>.</li>
            <li>Run the existing <code>database/*_test.php</code> suite after each page conversion &mdash; those tests assert on HTML structure, so they will catch markup regressions.</li>
            <li>Delete the per-page inline <code>&lt;style&gt;</code> blocks as each page is converted.</li>
            <li>Only then remove the v1.8.0 rules from <code>style.css</code>.</li>
          </ol>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-universal-access"></i> 4. Accessibility checklist</h2></div>
        <div class="card__body">
          <table class="spec-table">
            <thead><tr><th>Rule</th><th>Requirement</th><th>Where verified</th></tr></thead>
            <tbody>
              <tr><td>Contrast</td><td>Text ≥ 4.5:1, large text and UI borders ≥ 3:1</td><td>Status token pairs in <code>v2-tokens.css</code> §1</td></tr>
              <tr><td>Focus</td><td>Every interactive element shows a visible focus ring; never <code>outline:none</code> without a replacement</td><td><code>.input:focus</code>, <code>.btn:focus-visible</code></td></tr>
              <tr><td>Keyboard</td><td>All actions reachable by keyboard; modals trap focus and close on <kbd>Esc</kbd></td><td>Delete-project modal, hold-task modal</td></tr>
              <tr><td>Labels</td><td>Every input has a <code>&lt;label for&gt;</code>; required fields marked with <code>.req</code> + <code>aria-required</code></td><td>All forms</td></tr>
              <tr><td>ARIA</td><td>Modals use <code>role="dialog" aria-modal="true"</code>; breadcrumbs use <code>aria-label</code>; icon-only buttons carry <code>aria-label</code></td><td>All modals, breadcrumbs</td></tr>
              <tr><td>Motion</td><td>Honour <code>prefers-reduced-motion</code>; transitions ≤ 320 ms</td><td><code>--dur-*</code> tokens</td></tr>
              <tr><td>Status colour</td><td>Status is never conveyed by colour alone &mdash; every badge has a text label</td><td>Status badges, reason chips</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-language"></i> 5. Internationalisation plan</h2></div>
        <div class="card__body">
          <p>The internal app is currently English-only; the client portal is bilingual
          (ENG / &#20013;&#25991;) via <code>client/lang.php</code>. v2.0 keeps the client portal bilingual
          and prepares the internal app for it without forcing the migration.</p>
          <ul style="line-height:1.9;margin-bottom:0;">
            <li>Every client-facing string in the mockup carries <code>data-i18n="English|&#20013;&#25991;"</code> as the interim mechanism.</li>
            <li>Production keeps the existing <code>t('dash.*')</code> key namespace &mdash; no new key scheme is introduced.</li>
            <li>The mockup adds keys for the v1.8.0 reason strings: <code>dash.delay_reason</code>, <code>dash.on_hold_reason</code>, <code>dash.delayed_count</code>, <code>dash.on_hold_count</code>.</li>
            <li>Language choice persists in a 1-year cookie (<code>client_set_lang_cookie</code>) &mdash; the mockup uses <code>localStorage</code> for demo only.</li>
          </ul>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-mobile-screen"></i> 6. Responsive breakpoints</h2></div>
        <div class="card__body">
          <table class="spec-table">
            <thead><tr><th>Breakpoint</th><th>Behaviour</th></tr></thead>
            <tbody>
              <tr><td>≥ 1200px</td><td>Full layout. Sidebar 260px expanded, 340px detail column.</td></tr>
              <tr><td>992&ndash;1199px</td><td>Sidebar collapses to icons (76px); detail column drops below the main column.</td></tr>
              <tr><td>768&ndash;991px</td><td>Sidebar becomes an off-canvas drawer; stat grid drops to 2 columns; filter bar wraps.</td></tr>
              <tr><td>&lt; 768px</td><td>Single column; tables scroll horizontally via <code>.table-wrap</code>; stat grid 1 column.</td></tr>
              <tr><td>&lt; 480px</td><td>Client hero stats stack; page actions wrap to full width.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-circle-half-stroke"></i> 7. Dark mode</h2></div>
        <div class="card__body">
          <p>Dark mode is provided through <code>prefers-color-scheme</code> and token overrides only &mdash;
          no component rule is duplicated. The v2.0 mockup ships the light theme; the token
          overrides for dark are specified in <code>v2-tokens.css</code> §10 and are opt-in for
          the production implementation.</p>
          <p class="text-sm text-muted" style="margin-bottom:0;">Status pairs must be re-checked against the
          dark surfaces &mdash; the light-theme pairs are guaranteed AA, the dark pairs are the dev's
          responsibility to verify before shipping.</p>
        </div>
      </div>

      <div class="card">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-shield-halved"></i> 8. Red line</h2></div>
        <div class="card__body">
          <div class="alert alert--danger">
            <i class="fas fa-triangle-exclamation"></i>
            <div class="alert__body">
              <div class="alert__title">v2.0 is a restyle, never a refactor of behaviour</div>
              No route, field, action, permission, CSRF token, rate limit, share-code flow,
              validation rule or email notification may be removed or weakened by the redesign.
            </div>
          </div>
          <p style="margin-bottom:0;">The authoritative route-by-route and field-by-field mapping is
          <a href="../../issues/issue-035.md">docs/issues/issue-035.md</a> &sect;9 (Traceability Matrix).
          Any screen change that cannot be traced to that matrix is out of scope.</p>
        </div>
      </div>"""

    return page("Design Notes", body, bare=True, body_class="spec-page")
