#!/usr/bin/env python3
"""Screen definitions for the v2.0 mockup. Imported by build.py."""

from build import (APP_NAME, APP_VERSION, page, write, stat, status_badge,
                   reason_chip, task_node, escape)


# ===========================================================================
# 1. DESIGN INDEX  (docs/design/2.0/index.html)
# ===========================================================================
def screen_index():
    screens = [
        ("landing.html", "Public Landing / Share Code Entry", "fas fa-key",
         "Public entry point. Share-code form, language switch, owner sign-in link.",
         ["Public", "Code entry"]),
        ("login.html", "Sign In", "fas fa-right-to-bracket",
         "Username + password. Rate-limit message, forgot-password link.",
         ["Public", "Auth"]),
        ("register.html", "Create Account", "fas fa-user-plus",
         "Name, username, email. First-run account creation.",
         ["Public", "Auth"]),
        ("forgot-password.html", "Forgot Password", "fas fa-key",
         "Email-based reset request with attempt throttling.",
         ["Public", "Auth"]),
        ("reset-password.html", "Set New Password", "fas fa-lock-open",
         "New password + confirmation, token-validated.",
         ["Public", "Auth"]),
        ("dashboard.html", "Dashboard", "fas fa-gauge-high",
         "6 stat cards incl. delayed / on-hold, 3 widgets, v1.8.0 backfill banner.",
         ["Internal", "Overview"]),
        ("projects.html", "Projects", "fas fa-folder-open",
         "Project cards, status filter, typed-confirmation delete.",
         ["Internal", "Projects"]),
        ("project-detail.html", "Project Detail", "fas fa-folder-open",
         "Share code block, 4 stats, project contacts, project tasks.",
         ["Internal", "Projects"]),
        ("project-form.html", "Project Create / Edit", "fas fa-pen-to-square",
         "Full project form, share-code rotation, destructive delete.",
         ["Internal", "Projects"]),
        ("tasks.html", "Tasks", "fas fa-tasks",
         "4 stat cards, search + project + status filters, reason chips.",
         ["Internal", "Tasks"]),
        ("task-detail.html", "Task Detail", "fas fa-list-check",
         "Page actions, overview, parent, subtasks, details, reason, activity.",
         ["Internal", "Tasks"]),
        ("task-form.html", "Task Create / Edit", "fas fa-list-check",
         "Full task form incl. dual reason textareas + completion slider.",
         ["Internal", "Tasks"]),
        ("contacts.html", "Contacts", "fas fa-address-book",
         "4 stat cards, search + project filter, contact cards.",
         ["Internal", "Contacts"]),
        ("contact-detail.html", "Contact Detail", "fas fa-user",
         "3 stats, recent tasks, contact info, social links, projects.",
         ["Internal", "Contacts"]),
        ("contact-form.html", "Contact Create / Edit", "fas fa-address-card",
         "12 contact fields + project assignment checkboxes.",
         ["Internal", "Contacts"]),
        ("profile.html", "My Profile", "fas fa-user-edit",
         "4 stats, profile form, change-password form, action buttons.",
         ["Internal", "Account"]),
        ("client-dashboard.html", "Client Portal", "fas fa-share-nodes",
         "Public read-only project view. Info grid, hero %, task tree, reason pills.",
         ["Public", "Client"]),
        ("components.html", "Design System", "fas fa-palette",
         "Tokens, buttons, forms, badges, alerts, tables — the reference sheet.",
         ["Reference"]),
    ]

    cards = []
    for href, title, icon, desc, tags in screens:
        tag_html = "".join(f'<span class="badge badge--outline">{t}</span>' for t in tags)
        cards.append(f"""        <a class="screen-card" href="{href}">
          <div class="screen-card__thumb"><i class="{icon}"></i></div>
          <div class="screen-card__body">
            <h3 class="screen-card__title">{title}</h3>
            <p class="screen-card__desc">{desc}</p>
            <div class="screen-card__tags">{tag_html}</div>
          </div>
        </a>""")

    body = f"""      <div class="spec-hero">
        <h1>Project Management System &mdash; v2.0 UI Kit</h1>
        <p>Complete, non-lossy frontend redesign of every screen in the platform.
        This browsable mockup is the visual contract for the v2.0 implementation:
        no current feature is dropped, and every field, action, state and
        empty/error condition has a designed counterpart.</p>
        <div class="spec-hero__meta">
          <div><strong>{len(screens)}</strong> screens designed</div>
          <div><strong>18</strong> legacy routes mapped</div>
          <div><strong>AA</strong> WCAG contrast target</div>
          <div><strong>ENG / 中文</strong> bilingual ready</div>
        </div>
      </div>

      <div class="page-header">
        <div class="page-header__text">
          <h1><i class="fas fa-layer-group"></i> Screen gallery</h1>
          <p class="page-header__sub">Every screen is a standalone static HTML file &mdash; click through to review.</p>
        </div>
        <div class="page-actions">
          <a class="btn btn--secondary" href="components.html"><i class="fas fa-palette"></i> Design system</a>
          <a class="btn btn--primary" href="docs-design-notes.html"><i class="fas fa-file-lines"></i> Design notes</a>
        </div>
      </div>

      <div class="screen-grid">
{chr(10).join(cards)}
      </div>

      <div class="card" style="margin-top:2rem;">
        <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-shield-halved"></i> Red line &mdash; feature completeness</h2></div>
        <div class="card__body">
          <p class="text-sm">The v2.0 redesign is a <strong>restyle and re-layout only</strong>. It must not
          introduce, remove, rename or merge any business capability. The authoritative
          mapping from every v1.8.0 field and action to its v2.0 location lives in
          <a href="../../issues/issue-035.md">docs/issues/issue-035.md</a> &sect;9 (Traceability Matrix).</p>
          <div class="chip-row" style="margin-top:1rem;">
            <span class="badge badge--success"><i class="fas fa-check"></i> 18/18 routes covered</span>
            <span class="badge badge--success"><i class="fas fa-check"></i> 0 fields dropped</span>
            <span class="badge badge--success"><i class="fas fa-check"></i> 0 actions dropped</span>
            <span class="badge badge--success"><i class="fas fa-check"></i> v1.8.0 delayed/on-hold reasons carried forward</span>
            <span class="badge badge--success"><i class="fas fa-check"></i> Share-code security flow preserved</span>
          </div>
        </div>
      </div>"""

    return page("Design Index", body, bare=True, body_class="spec-page")


# ===========================================================================
# 2. PUBLIC LANDING (index.php)
# ===========================================================================
def screen_landing():
    body = """  <div class="landing">
    <div class="landing__card">
      <div class="landing__logo"><i class="fas fa-project-diagram"></i></div>
      <h1>Project Tracking</h1>
      <p class="text-muted text-sm" style="margin-bottom:1.5rem;">
        Enter the project share code you received to view live progress.
      </p>

      <div class="alert alert--danger" role="alert">
        <i class="fas fa-circle-exclamation"></i>
        <div class="alert__body">
          <div class="alert__title">Invalid share code</div>
          The code you entered is not valid or has expired. Please check and try again.
        </div>
      </div>

      <form action="#" method="post" style="text-align:left;">
        <div class="field" style="margin-bottom:1rem;">
          <label for="share_code">Share Code</label>
          <input class="input landing__code-input" type="text" id="share_code" name="code"
                 placeholder="ABC123" maxlength="12" autocomplete="off" required>
          <span class="hint">Share codes are 6&ndash;12 characters, case-insensitive.</span>
        </div>
        <button class="btn btn--primary btn--lg btn--block" type="submit">
          <i class="fas fa-arrow-right"></i> View Project
        </button>
      </form>

      <div class="auth-card__divider">or</div>

      <a class="btn btn--secondary btn--block" href="login.html">
        <i class="fas fa-user-lock"></i> Owner Sign In
      </a>

      <div class="landing__foot">
        <div class="lang-switch" data-lang-switch role="group" aria-label="Language">
          <button type="button" data-lang-set="en" class="is-active">ENG</button>
          <button type="button" data-lang-set="zh">中文</button>
        </div>
      </div>
    </div>
  </div>"""

    return page("Track Project", body, bare=True)


# ===========================================================================
# 3. LOGIN
# ===========================================================================
def screen_login():
    body = """  <div class="auth-shell">
    <aside class="auth-aside">
      <div class="auth-aside__brand">
        <span class="auth-aside__brand-icon"><i class="fas fa-project-diagram"></i></span>
        Project Management System
      </div>
      <div>
        <h2 class="auth-aside__headline">Every project,<br>every task,<br>one clear view.</h2>
        <p class="auth-aside__sub">Track delivery, surface delays before they bite, and share live progress with your clients.</p>
        <ul class="auth-aside__points">
          <li><i class="fas fa-circle-check"></i><span>Weighted completion across projects, tasks and subtasks</span></li>
          <li><i class="fas fa-circle-check"></i><span>Mandatory delay &amp; on-hold reasons &mdash; nothing goes quiet</span></li>
          <li><i class="fas fa-circle-check"></i><span>Secure share codes for client read-only portals</span></li>
          <li><i class="fas fa-circle-check"></i><span>Bilingual (ENG / &#20013;&#25991;) client portal</span></li>
        </ul>
      </div>
      <div class="auth-aside__foot">v2.0 &middot; Project Management System</div>
    </aside>

    <main class="auth-main">
      <div class="auth-card">
        <h1>Welcome back</h1>
        <p class="auth-card__sub">Sign in to manage your projects, tasks and contacts.</p>

        <div class="alert alert--danger" role="alert">
          <i class="fas fa-circle-exclamation"></i>
          <div class="alert__body">Invalid username or password.</div>
        </div>

        <form action="#" method="post">
          <div class="field">
            <label for="username">Username</label>
            <input class="input" type="text" id="username" name="username" value="zongbao"
                   autocomplete="username" required autofocus>
          </div>
          <div class="field">
            <label for="password">Password</label>
            <div class="input-group">
              <input class="input" type="password" id="password" name="password"
                     autocomplete="current-password" required>
              <button class="btn btn--secondary" type="button" title="Show password">
                <i class="far fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="auth-links" style="margin-bottom:1.25rem;">
            <label class="checkbox">
              <input type="checkbox" name="remember">
              <span>Remember me</span>
            </label>
            <a href="forgot-password.html">Forgot password?</a>
          </div>

          <button class="btn btn--primary btn--lg btn--block" type="submit">
            <i class="fas fa-right-to-bracket"></i> Sign In
          </button>
        </form>

        <div class="auth-card__divider">New here?</div>

        <a class="btn btn--secondary btn--block" href="register.html">
          <i class="fas fa-user-plus"></i> Create an account
        </a>

        <div class="auth-card__foot">
          <a href="landing.html"><i class="fas fa-key"></i> Client? Enter your share code</a>
        </div>
      </div>
    </main>
  </div>"""

    return page("Sign In", body, bare=True)


# ===========================================================================
# 4. REGISTER
# ===========================================================================
def screen_register():
    body = """  <div class="auth-shell">
    <aside class="auth-aside">
      <div class="auth-aside__brand">
        <span class="auth-aside__brand-icon"><i class="fas fa-project-diagram"></i></span>
        Project Management System
      </div>
      <div>
        <h2 class="auth-aside__headline">Set up your<br>workspace in<br>under a minute.</h2>
        <p class="auth-aside__sub">One owner account gives you projects, tasks, subtasks, contacts and a client-facing share portal.</p>
        <ul class="auth-aside__points">
          <li><i class="fas fa-circle-check"></i><span>Projects with budget, scope and three milestone dates</span></li>
          <li><i class="fas fa-circle-check"></i><span>Tasks and subtasks with completion tracking</span></li>
          <li><i class="fas fa-circle-check"></i><span>Contacts linked to projects and tasks</span></li>
        </ul>
      </div>
      <div class="auth-aside__foot">v2.0 &middot; Project Management System</div>
    </aside>

    <main class="auth-main">
      <div class="auth-card">
        <h1>Create your account</h1>
        <p class="auth-card__sub">Already have an account? <a href="login.html">Sign in</a>.</p>

        <div class="alert alert--info" role="alert">
          <i class="fas fa-circle-info"></i>
          <div class="alert__body">
            <div class="alert__title">Username already taken</div>
            Please choose a different username and try again.
          </div>
        </div>

        <form action="#" method="post">
          <div class="field">
            <label for="name">Full Name <span class="req" aria-hidden="true">*</span></label>
            <input class="input" type="text" id="name" name="name" value="Zongbao M." required>
          </div>
          <div class="field">
            <label for="username">Username <span class="req" aria-hidden="true">*</span></label>
            <input class="input" type="text" id="username" name="username"
                   placeholder="e.g. zongbao" autocomplete="username" required>
            <span class="hint">Letters, numbers, underscore. 3&ndash;50 characters.</span>
          </div>
          <div class="field">
            <label for="email">Email Address <span class="req" aria-hidden="true">*</span></label>
            <input class="input" type="email" id="email" name="email"
                   placeholder="you@example.com" autocomplete="email" required>
          </div>

          <button class="btn btn--primary btn--lg btn--block" type="submit" style="margin-top:1rem;">
            <i class="fas fa-user-plus"></i> Create Account
          </button>
        </form>

        <div class="auth-card__foot">
          <a href="landing.html"><i class="fas fa-key"></i> Client? Enter your share code</a>
        </div>
      </div>
    </main>
  </div>"""

    return page("Create Account", body, bare=True)


# ===========================================================================
# 5. FORGOT PASSWORD
# ===========================================================================
def screen_forgot():
    body = """  <div class="auth-shell">
    <aside class="auth-aside">
      <div class="auth-aside__brand">
        <span class="auth-aside__brand-icon"><i class="fas fa-project-diagram"></i></span>
        Project Management System
      </div>
      <div>
        <h2 class="auth-aside__headline">Locked out?</h2>
        <p class="auth-aside__sub">Enter the email address on your account and we will send you a link to set a new password.</p>
        <ul class="auth-aside__points">
          <li><i class="fas fa-circle-check"></i><span>Reset links expire after a short window</span></li>
          <li><i class="fas fa-circle-check"></i><span>Requests are rate-limited per account</span></li>
        </ul>
      </div>
      <div class="auth-aside__foot">v2.0 &middot; Project Management System</div>
    </aside>

    <main class="auth-main">
      <div class="auth-card">
        <h1>Forgot password</h1>
        <p class="auth-card__sub">We will email you a password reset link.</p>

        <div class="alert alert--success" role="alert">
          <i class="fas fa-circle-check"></i>
          <div class="alert__body">
            <div class="alert__title">Check your email</div>
            If an account exists for <strong>zongbao@example.com</strong>, a reset link has been sent.
          </div>
        </div>

        <div class="alert alert--warning" role="alert">
          <i class="fas fa-triangle-exclamation"></i>
          <div class="alert__body">
            <div class="alert__title">Too many attempts</div>
            You have requested 3 password resets in the last hour. Please wait before trying again.
          </div>
        </div>

        <form action="#" method="post">
          <div class="field">
            <label for="email">Email Address <span class="req" aria-hidden="true">*</span></label>
            <input class="input" type="email" id="email" name="email"
                   placeholder="you@example.com" autocomplete="email" required autofocus>
          </div>
          <button class="btn btn--primary btn--lg btn--block" type="submit" style="margin-top:.5rem;">
            <i class="fas fa-paper-plane"></i> Send Reset Link
          </button>
        </form>

        <div class="auth-card__foot">
          <a href="login.html"><i class="fas fa-arrow-left"></i> Back to sign in</a>
        </div>
      </div>
    </main>
  </div>"""

    return page("Forgot Password", body, bare=True)


# ===========================================================================
# 6. RESET PASSWORD
# ===========================================================================
def screen_reset():
    body = """  <div class="auth-shell">
    <aside class="auth-aside">
      <div class="auth-aside__brand">
        <span class="auth-aside__brand-icon"><i class="fas fa-project-diagram"></i></span>
        Project Management System
      </div>
      <div>
        <h2 class="auth-aside__headline">Choose a new<br>password.</h2>
        <p class="auth-aside__sub">Your reset link is valid. Set a new password to regain access to your workspace.</p>
        <ul class="auth-aside__points">
          <li><i class="fas fa-circle-check"></i><span>Minimum 8 characters</span></li>
          <li><i class="fas fa-circle-check"></i><span>Must contain a letter and a number</span></li>
        </ul>
      </div>
      <div class="auth-aside__foot">v2.0 &middot; Project Management System</div>
    </aside>

    <main class="auth-main">
      <div class="auth-card">
        <h1>Set new password</h1>
        <p class="auth-card__sub">You are resetting the password for <strong>zongbao</strong>.</p>

        <div class="alert alert--danger" role="alert">
          <i class="fas fa-circle-exclamation"></i>
          <div class="alert__body">
            <div class="alert__title">Passwords do not match</div>
            The confirmation password must be identical to the new password.
          </div>
        </div>

        <form action="#" method="post">
          <div class="field">
            <label for="new_password">New Password <span class="req" aria-hidden="true">*</span></label>
            <div class="input-group">
              <input class="input" type="password" id="new_password" name="new_password"
                     autocomplete="new-password" required autofocus>
              <button class="btn btn--secondary" type="button" title="Show password"><i class="far fa-eye"></i></button>
            </div>
            <span class="hint">At least 8 characters, including a letter and a number.</span>
          </div>
          <div class="field">
            <label for="confirm_password">Confirm New Password <span class="req" aria-hidden="true">*</span></label>
            <input class="input" type="password" id="confirm_password" name="confirm_password"
                   autocomplete="new-password" required>
            <span class="error-text"><i class="fas fa-circle-exclamation"></i> Passwords do not match.</span>
          </div>
          <button class="btn btn--primary btn--lg btn--block" type="submit" style="margin-top:.5rem;">
            <i class="fas fa-key"></i> Update Password
          </button>
        </form>

        <div class="auth-card__foot">
          <a href="login.html"><i class="fas fa-arrow-left"></i> Back to sign in</a>
        </div>
      </div>
    </main>
  </div>"""

    return page("Set New Password", body, bare=True)
