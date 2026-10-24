#!/usr/bin/env python3
"""
Dispatcher — renders every v2.0 screen to docs/design/2.0/<name>.html.

Run from the repo root:
    python3 docs/design/2.0/_build/build.py
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import build as B  # noqa: E402
import screens_part1 as S1  # noqa: E402
import screens_part2 as S2  # noqa: E402
import screens_part3 as S3  # noqa: E402
import screens_part4 as S4  # noqa: E402
import screens_part5 as S5  # noqa: E402

SCREENS = [
    # (output filename, screen function)
    ("index.html",             S1.screen_index),
    ("landing.html",           S1.screen_landing),
    ("login.html",             S1.screen_login),
    ("register.html",          S1.screen_register),
    ("forgot-password.html",   S1.screen_forgot),
    ("reset-password.html",    S1.screen_reset),
    ("dashboard.html",         S2.screen_dashboard),
    ("projects.html",          S2.screen_projects),
    ("project-detail.html",    S2.screen_project_detail),
    ("project-form.html",      S3.screen_project_form),
    ("tasks.html",             S3.screen_tasks),
    ("task-detail.html",       S3.screen_task_detail),
    ("task-form.html",         S4.screen_task_form),
    ("contacts.html",          S4.screen_contacts),
    ("contact-detail.html",    S4.screen_contact_detail),
    ("contact-form.html",      S4.screen_contact_form),
    ("profile.html",           S4.screen_profile),
    ("client-dashboard.html",  S4.screen_client_dashboard),
    ("components.html",        S5.screen_components),
    ("docs-design-notes.html", S5.screen_design_notes),
]


def main():
    print(f"Rendering v2.0 mockup -> {B.OUT}\n")
    for name, fn in SCREENS:
        try:
            B.write(name, fn())
        except Exception as exc:  # noqa: BLE001
            print(f"  FAILED {name}: {type(exc).__name__}: {exc}")
            raise
    print(f"\nDone: {len(SCREENS)} screens.")


if __name__ == "__main__":
    main()
