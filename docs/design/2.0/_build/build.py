#!/usr/bin/env python3
"""
v2.0 mockup generator — renders every screen of the Project Management
Platform v2.0 redesign into self-contained static HTML under
docs/design/2.0/.

Why a generator instead of hand-written HTML:
  * The app shell (sidebar, topbar, footer) is identical across 12
    internal screens. Duplicating it by hand guarantees drift.
  * The mockup must stay in lockstep with the design system tokens.

The generated .html files are committed to the repo — the generator is a
convenience for future revisions, not a build step the app depends on.

Usage:
    python3 docs/design/2.0/_build/main.py

This module is the shared helper library (shell + reusable fragments).
main.py is the executable dispatcher that renders every screen.
"""

import os
from html import escape

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.dirname(HERE)

APP_NAME = "Project Management System"
APP_VERSION = "v2.0"
BRAND_ICON = '<i class="fas fa-project-diagram"></i>'


# ---------------------------------------------------------------------------
# Shared shell
# ---------------------------------------------------------------------------

SIDEBAR_NAV = [
    ("dashboard.html", "Dashboard", "fas fa-tachometer-alt", None),
    ("projects.html", "Projects", "fas fa-folder-open", None),
    ("tasks.html", "Tasks", "fas fa-tasks", "3"),
    ("contacts.html", "Contacts", "fas fa-address-book", None),
]

SIDEBAR_SECTION = [
    ("profile.html", "My Profile", "fas fa-user-edit"),
    ("index.html", "Design Index", "fas fa-palette"),
]


def sidebar(active=None):
    """Left navigation rail."""
    links = []
    for href, label, icon, count in SIDEBAR_NAV:
        is_active = ' is-active' if href == active else ''
        badge = ""
        if count:
            badge = f'<span class="badge-count">{count}</span>'
        links.append(
            f'        <a class="sidebar__link{is_active}" href="{href}">'
            f'<i class="{icon}"></i><span>{escape(label)}</span>{badge}</a>'
        )

    section = []
    for href, label, icon in SIDEBAR_SECTION:
        is_active = ' is-active' if href == active else ''
        section.append(
            f'        <a class="sidebar__link{is_active}" href="{href}">'
            f'<i class="{icon}"></i><span>{escape(label)}</span></a>'
        )

    nav = "\n".join(links)
    acct = "\n".join(section)

    return f"""    <aside class="sidebar">
      <div class="sidebar__brand">
        <span class="sidebar__brand-icon">{BRAND_ICON}</span>
        <span class="sidebar__brand-text">{escape(APP_NAME)}<small>v2.0 redesign</small></span>
      </div>
      <nav class="sidebar__nav" aria-label="Main navigation">
{nav}
        <div class="sidebar__section-label">Account</div>
{acct}
      </nav>
      <div class="sidebar__footer">
        <span class="sidebar__avatar">ZM</span>
        <span class="sidebar__user">
          <span class="sidebar__user-name">Zongbao M.</span>
          <span class="sidebar__user-role">Owner</span>
        </span>
      </div>
    </aside>"""


def topbar(extra=""):
    """Top bar with search, notifications, About panel and user menu."""
    return f"""      <header class="topbar">
        <button class="topbar__toggle" type="button" data-sidebar-toggle aria-label="Toggle navigation">
          <i class="fas fa-bars"></i>
        </button>
        <div class="topbar__search">
          <i class="fas fa-magnifying-glass"></i>
          <input type="search" placeholder="Search projects, tasks, contacts&hellip;" aria-label="Global search">
        </div>
        <div class="topbar__actions">
{extra}          <button class="topbar__icon-btn" type="button" title="Notifications" data-toast="3 notifications" data-toast-kind="warning">
            <i class="far fa-bell"></i><span class="dot" aria-hidden="true"></span>
          </button>
          <details class="dropdown">
            <summary class="topbar__icon-btn" title="About" style="list-style:none;">
              <i class="far fa-circle-question"></i>
            </summary>
            <div class="dropdown__menu" role="menu">
              <div style="padding:.6rem .75rem;border-bottom:1px solid var(--gray-100);">
                <div class="text-sm fw-semibold">Version {APP_VERSION}</div>
                <div class="text-xs text-muted">Built by: Me</div>
                <div class="text-xs text-muted">Vibe coding with AI agent</div>
              </div>
              <div class="dropdown__divider"></div>
              <a class="dropdown__item" href="index.html"><i class="fas fa-rocket"></i> What&rsquo;s new in v2.0</a>
            </div>
          </details>
          <details class="dropdown">
            <summary class="topbar__icon-btn" style="list-style:none;">
              <span class="avatar">ZM</span>
            </summary>
            <div class="dropdown__menu" role="menu">
              <a class="dropdown__item" href="profile.html"><i class="fas fa-user-edit"></i> Profile</a>
              <a class="dropdown__item" href="login.html"><i class="fas fa-right-from-bracket"></i> Logout</a>
            </div>
          </details>
        </div>
      </header>"""


def footer():
    return f"""      <footer class="footer">
        &copy; 2026 {escape(APP_NAME)}. All rights reserved.
      </footer>"""


def page(title, body, *, active=None, bare=False, extra_head="", extra_scripts="",
         body_class=""):
    """Wrap page content in the shared app shell."""
    if bare:
        return f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{escape(title)} &mdash; {escape(APP_NAME)}</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="v2-tokens.css">
{extra_head}</head>
<body class="{body_class}">
{body}
  <script src="v2-mockup.js"></script>
{extra_scripts}</body>
</html>
"""
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{escape(title)} &mdash; {escape(APP_NAME)}</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="v2-tokens.css">
{extra_head}</head>
<body>
  <div class="app-shell">
{sidebar(active)}
    <div class="app-main">
{topbar()}
      <main class="content">
{body}
      </main>
{footer()}
    </div>
  </div>
  <script src="v2-mockup.js"></script>
{extra_scripts}</body>
</html>
"""


def write(name, html):
    path = os.path.join(OUT, name)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(html)
    print(f"  wrote {name}  ({len(html):,} bytes)")


# ---------------------------------------------------------------------------
# Reusable fragments
# ---------------------------------------------------------------------------

def stat(value, label, icon, kind="", meta=""):
    meta_html = f'<div class="stat__meta">{meta}</div>' if meta else ""
    return f"""        <div class="stat {kind}">
          <div class="stat__icon"><i class="{icon}"></i></div>
          <div>
            <div class="stat__value">{value}</div>
            <div class="stat__label">{label}</div>
            {meta_html}
          </div>
        </div>"""


STATUS_LABELS = {
    "not_started": "Not Started",
    "in_progress": "In Progress",
    "completed": "Completed",
    "on_hold": "On Hold",
    "delayed": "Delayed",
}


def status_badge(status, label=None, *, reason_missing=None):
    """Render a task status badge.

    reason_missing: None | "on_hold" | "delay" | "both". When set, a
    superscript warning marker is appended, mirroring production
    .status-reason-required (assets/css/style.css) which marks a
    delayed / on-hold task that still has no reason recorded.
    """
    text = label or STATUS_LABELS.get(status, status)
    marker = ""
    if reason_missing:
        if reason_missing == "on_hold":
            marker = ('<i class="fas fa-exclamation-circle reason-missing" '
                      'title="On-hold reason missing" '
                      'aria-label="On-hold reason missing"></i>')
        elif reason_missing == "delay":
            marker = ('<i class="fas fa-exclamation-circle reason-missing" '
                      'title="Delay reason missing" '
                      'aria-label="Delay reason missing"></i>')
        elif reason_missing == "both":
            marker = ('<i class="fas fa-exclamation-circle reason-missing" '
                      'title="Reason missing" '
                      'aria-label="Reason missing"></i>')
    return f'<span class="status status--{status}">{text}{marker}</span>'


def reason_chip(kind, icon, label, text, *, title=False):
    """Reason chip. Set title=True where the chip is rendered inside a table
    cell and may be visually truncated, so the full text stays reachable on
    hover and for assistive tech."""
    title_attr = f' title="{escape(label + ": " + text)}"' if title else ""
    return (f'<div class="reason-chip reason-chip--{kind}"{title_attr}>'
            f'<i class="{icon}"></i><span><strong>{label}:</strong> {text}</span></div>')


def reason_missing_inline(text):
    """Inline 'reason missing' callout. Parity with production
    .status-reason-required used inside the task-detail Status Reason
    card when a delayed / on-hold task has no reason recorded yet."""
    return (f'<span class="reason-missing reason-missing--inline">'
            f'<i class="fas fa-exclamation-circle"></i> {text}</span>')


def task_node(name, status, pct, *, delayed=False, on_hold=False,
              reasons="", meta="", done=False, expandable=False, tree_id=None):
    cls = "task-node"
    if delayed:
        cls += " task-node--delayed"
    if on_hold:
        cls += " task-node--on-hold"
    if done:
        cls += " task-node--completed"
    name_cls = "task-node__name" + (" task-node__name--done" if done else "")

    head = ""
    if expandable:
        head = (f'<button class="btn btn--ghost btn--icon" type="button" '
                f'data-tree-toggle="{tree_id}" aria-expanded="true" '
                f'aria-label="Toggle subtasks"><i class="fas fa-chevron-down"></i></button>')

    return f"""              <li class="{cls}">
                <div class="task-node__head">
                  {head}
                  <span class="{name_cls}">{name}</span>
                  {status_badge(status)}
                </div>
                {meta}
                <div class="task-node__progress">
                  <div class="progress"><div class="progress__fill" style="width:{pct}%"></div></div>
                  <span class="task-node__pct">{pct}%</span>
                </div>
                {reasons}
              </li>"""
