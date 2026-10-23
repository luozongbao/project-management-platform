#!/usr/bin/env python3
"""Remaining v2.0 mockup screens: task form, contacts, profile, client portal."""

from build import (page, write, stat, status_badge, reason_chip, task_node,
                   escape)


# ===========================================================================
# 13. TASK FORM (create / edit)
# ===========================================================================
def screen_task_form():
    body = """        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="dashboard.html">Dashboard</a>
          <span aria-hidden="true">/</span>
          <a href="tasks.html">Tasks</a>
          <span aria-hidden="true">/</span>
          <span>Edit Task</span>
        </nav>

        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-list-check"></i> Edit Task</h1>
            <p class="page-header__sub">MEP rough-in &middot; Harbour Point Renovation</p>
          </div>
          <div class="page-actions">
            <a class="btn btn--secondary" href="task-detail.html">Cancel</a>
            <button class="btn btn--primary" type="button" data-toast="Task saved (demo)" data-toast-kind="success">
              <i class="fas fa-floppy-disk"></i> Save Task
            </button>
          </div>
        </div>

        <div class="alert alert--info">
          <i class="fas fa-circle-info"></i>
          <div class="alert__body">
            <div class="alert__title">Delayed and on-hold tasks must carry a reason</div>
            The reason fields below become required when the status matches, and are shown to the
            client on the shared project portal.
          </div>
        </div>

        <form class="card" action="#" method="post">
          <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Task information</h2></div>
          <div class="card__body">
            <div class="form-grid form-grid--2">
              <div class="field form-section">
                <label for="name">Task Name <span class="req" aria-hidden="true">*</span></label>
                <input class="input" type="text" id="name" name="name" value="MEP rough-in" required>
              </div>
              <div class="field">
                <label for="project_id">Project <span class="req" aria-hidden="true">*</span></label>
                <select class="select" id="project_id" name="project_id" required>
                  <option value="">Select Project</option>
                  <option value="1" selected>Harbour Point Renovation</option>
                  <option value="2">Bangkok Office Fit-out</option>
                  <option value="3">Siam Retail Kiosk</option>
                  <option value="4">Chiang Mai Warehouse</option>
                </select>
              </div>
              <div class="field form-section">
                <label for="description">Description</label>
                <textarea class="textarea" id="description" name="description" rows="4">First-fix and second-fix MEP installation across all levels, including containment, cabling and ductwork.</textarea>
              </div>

              <div class="field">
                <label for="status_display">Current Status</label>
                <input class="input" type="text" id="status_display" value="In Progress" readonly>
                <span class="hint">Read-only &mdash; change the status below.</span>
              </div>
              <div class="field">
                <label for="status">Status <span class="req" aria-hidden="true">*</span></label>
                <select class="select" id="status" name="status" data-status-select>
                  <option value="not_started">Not Started</option>
                  <option value="in_progress" selected>In Progress</option>
                  <option value="completed">Completed</option>
                  <option value="on_hold">On Hold</option>
                </select>
              </div>

              <div class="field form-section">
                <label for="completion_percentage">Completion Percentage</label>
                <div class="range-row">
                  <input type="range" id="completion_slider" min="0" max="100" step="5" value="55"
                         data-range-sync="#completion_percentage" data-range-out="#completion_pct">
                  <input class="input" type="number" id="completion_percentage" name="completion_percentage"
                         min="0" max="100" step="5" value="55" style="width:90px;">
                  <span class="range-value" id="completion_pct">55%</span>
                </div>
              </div>

              <div class="field form-section" data-reason-panel="on_hold" hidden>
                <label for="on_hold_reason">On-hold reason <span class="req" aria-hidden="true">*</span></label>
                <textarea class="textarea" id="on_hold_reason" name="on_hold_reason" rows="3"
                          placeholder="Explain why this task is on hold&hellip;"></textarea>
                <span class="hint">Mandatory while the status is On Hold. Max 2000 characters.</span>
              </div>

              <div class="field form-section" data-reason-panel="delayed" hidden>
                <label for="delay_reason">Delay reason <span class="req" aria-hidden="true">*</span></label>
                <textarea class="textarea" id="delay_reason" name="delay_reason" rows="3"
                          placeholder="Explain why this task is delayed&hellip;"></textarea>
                <span class="hint">Mandatory when the expected completion date has passed. Max 2000 characters.</span>
              </div>

              <div class="field form-section" data-delay-hint hidden>
                <div class="alert alert--warning" style="margin:0;">
                  <i class="fas fa-triangle-exclamation"></i>
                  <div class="alert__body">
                    <div class="alert__title">This task is past its expected completion date</div>
                    Add a delay reason above, or update the expected completion date.
                  </div>
                </div>
              </div>

              <div class="field form-section">
                <label>Total Task Time</label>
                <div class="range-row" style="gap:.5rem;">
                  <input class="input" type="number" id="total_time" name="total_time" value="48" min="0" style="width:110px;">
                  <select class="select" id="total_time_unit" name="total_time_unit" style="width:140px;">
                    <option value="hours" selected>Hours</option>
                    <option value="mandays">Mandays</option>
                  </select>
                </div>
                <label class="checkbox" for="inherit_subtask_hours" style="margin-top:.6rem;">
                  <input type="checkbox" id="inherit_subtask_hours" name="inherit_subtask_hours" checked>
                  <span>Inherit total time from subtasks</span>
                </label>
                <span class="hint">When inherited, the subtask estimates are summed automatically.</span>
              </div>

              <div class="field">
                <label for="responsible_person_id">Responsible Person</label>
                <select class="select" id="responsible_person_id" name="responsible_person_id">
                  <option value="">Select Person</option>
                  <option value="1" selected>Zongbao M.</option>
                  <option value="2">Somchai P.</option>
                  <option value="3">Nok Chantarak</option>
                </select>
              </div>
              <div class="field">
                <label for="contact_person_id">Contact Person</label>
                <select class="select" id="contact_person_id" name="contact_person_id">
                  <option value="">No Contact Person</option>
                  <option value="1" selected>Ploy Sirichai</option>
                  <option value="2">Surasak Wongchai</option>
                  <option value="3">Anan Suwan</option>
                </select>
              </div>

              <div class="field">
                <label for="expected_completion_date">Expected Completion</label>
                <input class="input" type="date" id="expected_completion_date" name="expected_completion_date" value="2026-10-22">
              </div>
              <div class="field">
                <label for="completion_date">Completion Date</label>
                <input class="input" type="datetime-local" id="completion_date" name="completion_date" value="">
              </div>
            </div>
          </div>
          <div class="card__footer">
            <button class="btn btn--primary" type="submit"><i class="fas fa-floppy-disk"></i> Save Task</button>
            <a class="btn btn--secondary" href="task-detail.html">Cancel</a>
          </div>
        </form>

        <div class="card" style="border-color:var(--danger-500); margin-top:var(--sp-5);">
          <div class="card__header" style="background:var(--danger-100);">
            <h2 class="card__title-icon" style="color:var(--danger-700);"><i class="fas fa-triangle-exclamation"></i> Danger zone</h2>
          </div>
          <div class="card__body">
            <p class="text-sm text-muted">
              Deleting removes this task, its 4 subtasks and every time entry logged against
              them. This cannot be undone.
            </p>
            <button class="btn btn--danger btn--block" type="button" style="margin-top:1rem;"
                    data-modal-open="confirm-delete-task">
              <i class="fas fa-trash"></i> Delete Task
            </button>
          </div>
        </div>

        <div class="modal-backdrop" id="confirm-delete-task" hidden>
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdt-title">
            <div class="modal__header">
              <div>
                <h3 id="cdt-title">Confirm task deletion</h3>
                <p>Type the task code exactly as shown to confirm.</p>
              </div>
              <button class="modal__close" type="button" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal__body">
              <div class="alert alert--danger">
                <i class="fas fa-triangle-exclamation"></i>
                <div class="alert__body">
                  <div class="alert__title">TSK-0417</div>
                  This task, its 4 subtasks and all logged time entries will be permanently removed.
                </div>
              </div>
              <div class="field">
                <label for="delete-task-confirmation">Task Code <span class="req" aria-hidden="true">*</span></label>
                <input class="input text-mono" type="text" id="delete-task-confirmation" placeholder="TSK-0417">
              </div>
            </div>
            <div class="modal__footer">
              <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
              <button class="btn btn--danger" type="button" data-toast="Demo only &mdash; task not deleted" data-toast-kind="danger">
                <i class="fas fa-trash"></i> Delete permanently
              </button>
            </div>
          </div>
        </div>"""

    return page("Edit Task", body, active="tasks.html")


# ===========================================================================
# 14. CONTACTS LIST
# ===========================================================================
def screen_contacts():
    people = [
        ("Surasak Wongchai", "Site Manager", "Harbour Point Co., Ltd.", "sw@example.com",
         "+66 81 234 5678", "3 projects", "avatar--gray"),
        ("Ploy Sirichai", "Project Architect", "Arcline Design", "ploy@example.com",
         "+66 82 345 6789", "2 projects", "avatar"),
        ("Anan Suwan", "Contractor Lead", "Siam Steel Works", "anan@example.com",
         "+66 83 456 7890", "1 project", "avatar--warning"),
        ("Malee Charoen", "Procurement Manager", "Rangsit Trading", "malee@example.com",
         "+66 84 567 8901", "2 projects", "avatar--success"),
        ("Kai Lertsakda", "Logistics Coordinator", "Trans-Asia Freight", "",
         "+66 85 678 9012", "1 project", "avatar--danger"),
    ]

    cards = []
    for name, pos, comp, mail, tel, projs, av in people:
        mail_html = (f'<a href="mailto:{mail}"><i class="fas fa-envelope"></i> {mail}</a>'
                     if mail else '<span class="text-muted">&mdash; no email</span>')
        cards.append(f"""        <div class="project-card">
          <div class="project-card__head">
            <span class="avatar avatar--lg {av}"><i class="fas fa-user"></i></span>
            <div style="flex:1;min-width:0;">
              <h3 class="project-card__title"><a href="contact-detail.html">{name}</a></h3>
              <div class="project-card__meta">
                {f'<span><i class="fas fa-briefcase"></i> {pos}</span>' if pos else ''}
                {f'<span><i class="fas fa-building"></i> {comp}</span>' if comp else ''}
              </div>
            </div>
          </div>
          <div class="detail-list">
            <div class="detail-row"><span class="detail-row__k" style="min-width:70px;">Email</span><span class="detail-row__v text-sm">{mail_html}</span></div>
            <div class="detail-row"><span class="detail-row__k" style="min-width:70px;">Phone</span><span class="detail-row__v text-sm"><a href="tel:{tel.replace(' ','')}"><i class="fas fa-phone"></i> {tel}</a></span></div>
          </div>
          <div class="project-card__stats">
            <div>
              <div class="project-card__stat-label">Assigned</div>
              <div class="project-card__stat-value">{projs}</div>
            </div>
          </div>
          <div class="project-card__foot">
            <a class="btn btn--sm btn--primary" href="contact-detail.html"><i class="fas fa-eye"></i> Open</a>
            <a class="btn btn--sm btn--secondary" href="contact-form.html"><i class="fas fa-pen"></i> Edit</a>
            {f'<a class="btn btn--sm btn--secondary btn--icon" href="mailto:{mail}" title="Send email"><i class="fas fa-envelope"></i></a>' if mail else ''}
            {f'<a class="btn btn--sm btn--secondary btn--icon" href="tel:{tel.replace(" ","")}" title="Call"><i class="fas fa-phone"></i></a>'}
          </div>
        </div>""")

    body = f"""        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-address-book"></i> Contacts</h1>
            <p class="page-header__sub">{len(people)} contacts across 7 projects</p>
          </div>
          <div class="page-actions">
            <a class="btn btn--primary" href="contact-form.html"><i class="fas fa-plus"></i> New Contact</a>
          </div>
        </div>

        <div class="stat-grid">
{stat("18", "Total Contacts", "fas fa-address-book", "")}
{stat("15", "With Email", "fas fa-envelope", "stat--info", "")}
{stat("12", "With Phone", "fas fa-phone", "stat--success", "")}
{stat("6", "Projects Covered", "fas fa-folder-open", "stat--on-hold", "")}
        </div>

        <div class="filter-bar" data-filter-form>
          <div class="field">
            <label for="search">Search Contacts</label>
            <input class="input" type="search" id="search" placeholder="Name, company or email&hellip;">
          </div>
          <div class="field">
            <label for="project_filter">Project</label>
            <select class="select" id="project_filter">
              <option value="">All Projects</option>
              <option value="1">Harbour Point Renovation</option>
              <option value="2">Bangkok Office Fit-out</option>
              <option value="3">Siam Retail Kiosk</option>
              <option value="4">Chiang Mai Warehouse</option>
            </select>
          </div>
          <div class="filter-bar__actions">
            <button class="btn btn--primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
            <button class="btn btn--secondary" type="button" data-filter-reset>Reset</button>
          </div>
        </div>

        <div class="project-grid">
{chr(10).join(cards)}
        </div>"""

    return page("Contacts", body, active="contacts.html")


# ===========================================================================
# 15. CONTACT DETAIL
# ===========================================================================
def screen_contact_detail():
    tasks = [
        ("MEP rough-in", "Harbour Point Renovation", "in_progress", "55%"),
        ("Structural steel erection", "Harbour Point Renovation", "in_progress", "30%"),
        ("Update signage mockups", "Siam Retail Kiosk", "completed", "100%"),
    ]
    task_rows = []
    for name, proj, st, pct in tasks:
        task_rows.append(f"""              <tr>
                <td><a href="task-detail.html"><strong>{name}</strong></a></td>
                <td class="text-sm text-muted">{proj}</td>
                <td>{status_badge(st)}</td>
                <td style="min-width:110px;">
                  <div class="progress"><div class="progress__fill" style="width:{pct.rstrip('%')}"></div></div>
                </td>
              </tr>""")

    projects = [
        ("Harbour Point Renovation", "in_progress", "64%"),
        ("Bangkok Office Fit-out", "in_progress", "38%"),
    ]
    proj_rows = []
    for name, st, pct in projects:
        proj_rows.append(f"""              <tr>
                <td><a href="project-detail.html"><strong>{name}</strong></a></td>
                <td>{status_badge(st)}</td>
                <td style="min-width:110px;">
                  <div class="progress"><div class="progress__fill" style="width:{pct.rstrip('%')}"></div></div>
                </td>
              </tr>""")

    body = f"""        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="dashboard.html">Dashboard</a>
          <span aria-hidden="true">/</span>
          <a href="contacts.html">Contacts</a>
          <span aria-hidden="true">/</span>
          <span>Surasak Wongchai</span>
        </nav>

        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-user"></i> Surasak Wongchai</h1>
            <div class="chip-row" style="margin-top:.35rem;">
              <span class="badge badge--outline"><i class="fas fa-briefcase"></i> Site Manager</span>
              <span class="badge badge--outline"><i class="fas fa-building"></i> Harbour Point Co., Ltd.</span>
            </div>
          </div>
          <div class="page-actions">
            <a class="btn btn--secondary" href="mailto:sw@example.com"><i class="fas fa-envelope"></i> Email</a>
            <a class="btn btn--secondary" href="tel:+66812345678"><i class="fas fa-phone"></i> Call</a>
            <a class="btn btn--primary" href="contact-form.html"><i class="fas fa-pen"></i> Edit Contact</a>
          </div>
        </div>

        <div class="grid-sidebar">
          <div>
            <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:0;">
              {stat("2", "Projects", "fas fa-folder", "")}
              {stat("3", "Total Tasks", "fas fa-list-check", "")}
              {stat("1", "Completed", "fas fa-circle-check", "stat--success", "")}
            </div>

            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-list-check"></i> Recent Tasks</h2></div>
              <div class="card__body card__body--flush">
                <div class="table-wrap">
                  <table class="table">
                    <thead><tr><th>Task</th><th>Project</th><th>Status</th><th>Progress</th></tr></thead>
                    <tbody>
{chr(10).join(task_rows)}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-address-card"></i> Contact Information</h2></div>
              <div class="card__body">
                <div class="detail-list">
                  <div class="detail-row"><span class="detail-row__k">Email</span><span class="detail-row__v"><a href="mailto:sw@example.com">sw@example.com</a></span></div>
                  <div class="detail-row"><span class="detail-row__k">Phone</span><span class="detail-row__v"><a href="tel:+66812345678">+66 81 234 5678</a></span></div>
                  <div class="detail-row"><span class="detail-row__k">Mobile</span><span class="detail-row__v"><a href="tel:+66812345678">+66 81 234 5678</a></span></div>
                  <div class="detail-row"><span class="detail-row__k">Address</span><span class="detail-row__v">88/12 Soi Sukhumvit 21, Khlong Toei Nuea, Watthana, Bangkok 10110</span></div>
                  <div class="detail-row"><span class="detail-row__k">Added</span><span class="detail-row__v">Aug 3, 2026, 10:12</span></div>
                  <div class="detail-row"><span class="detail-row__k">Updated</span><span class="detail-row__v">Oct 6, 2026, 15:41</span></div>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-share-nodes"></i> Social Media</h2></div>
              <div class="card__body">
                <div class="detail-list">
                  <div class="detail-row"><span class="detail-row__k">WeChat</span><span class="detail-row__v"><a href="#">surasak_w</a></span></div>
                  <div class="detail-row"><span class="detail-row__k">LINE</span><span class="detail-row__v"><a href="#">surasak_wc</a></span></div>
                  <div class="detail-row"><span class="detail-row__k">Facebook</span><span class="detail-row__v"><a href="https://facebook.com/surasak.w" rel="noopener">facebook.com/surasak.w</a></span></div>
                  <div class="detail-row"><span class="detail-row__k">LinkedIn</span><span class="detail-row__v"><a href="https://linkedin.com/in/surasak-wongchai" rel="noopener">linkedin.com/in/surasak-wongchai</a></span></div>
                </div>
              </div>
            </div>
          </div>

          <div>
            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-sitemap"></i> Projects (2)</h2></div>
              <div class="card__body card__body--flush">
                <div class="table-wrap">
                  <table class="table table--compact">
                    <thead><tr><th>Project</th><th>Status</th><th>%</th></tr></thead>
                    <tbody>
{chr(10).join(proj_rows)}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Overview</h2></div>
              <div class="card__body">
                <p class="text-sm text-muted" style="margin:0;">
                  Site manager responsible for day-to-day site coordination and quality sign-off
                  on all structural and MEP installation work.
                </p>
              </div>
            </div>
          </div>
        </div>"""

    return page("Contact Detail", body, active="contacts.html")


# ===========================================================================
# 16. CONTACT FORM
# ===========================================================================
def screen_contact_form():
    body = """        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="dashboard.html">Dashboard</a>
          <span aria-hidden="true">/</span>
          <a href="contacts.html">Contacts</a>
          <span aria-hidden="true">/</span>
          <span>Edit Contact</span>
        </nav>

        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-address-card"></i> Edit Contact</h1>
            <p class="page-header__sub">Surasak Wongchai</p>
          </div>
          <div class="page-actions">
            <a class="btn btn--secondary" href="contact-detail.html">Cancel</a>
            <button class="btn btn--primary" type="button" data-toast="Contact saved (demo)" data-toast-kind="success">
              <i class="fas fa-floppy-disk"></i> Save Contact
            </button>
          </div>
        </div>

        <form class="card" action="#" method="post">
          <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Contact details</h2></div>
          <div class="card__body">
            <div class="form-grid form-grid--3">
              <div class="field form-section">
                <label for="name">Contact Name <span class="req" aria-hidden="true">*</span></label>
                <input class="input" type="text" id="name" name="name" value="Surasak Wongchai" required>
              </div>
              <div class="field">
                <label for="position">Position / Title</label>
                <input class="input" type="text" id="position" name="position" value="Site Manager">
              </div>
              <div class="field">
                <label for="company">Company</label>
                <input class="input" type="text" id="company" name="company" value="Harbour Point Co., Ltd.">
              </div>
              <div class="field form-section">
                <label for="description">Description / Notes</label>
                <textarea class="textarea" id="description" name="description" rows="4">Site manager responsible for day-to-day site coordination and quality sign-off on all structural and MEP installation work.</textarea>
              </div>
              <div class="field">
                <label for="email">Email Address</label>
                <input class="input" type="email" id="email" name="email" value="sw@example.com">
                <span class="error-text"><i class="fas fa-circle-exclamation"></i> That email is already used by another contact.</span>
              </div>
              <div class="field">
                <label for="phone">Phone Number</label>
                <input class="input" type="tel" id="phone" name="phone" value="+66 81 234 5678">
              </div>
              <div class="field">
                <label for="mobile">Mobile Number</label>
                <input class="input" type="tel" id="mobile" name="mobile" value="+66 81 234 5678">
              </div>
              <div class="field form-section">
                <label for="address">Address</label>
                <textarea class="textarea" id="address" name="address" rows="3">88/12 Soi Sukhumvit 21, Khlong Toei Nuea, Watthana, Bangkok 10110</textarea>
              </div>
              <div class="field">
                <label for="wechat">WeChat ID</label>
                <input class="input" type="text" id="wechat" name="wechat" value="surasak_w">
              </div>
              <div class="field">
                <label for="line_id">LINE ID</label>
                <input class="input" type="text" id="line_id" name="line_id" value="surasak_wc">
              </div>
              <div class="field">
                <label for="facebook">Facebook Profile</label>
                <input class="input" type="url" id="facebook" name="facebook" value="https://facebook.com/surasak.w" placeholder="https://facebook.com/username">
                <span class="error-text"><i class="fas fa-circle-exclamation"></i> Must be a valid URL starting with https://</span>
              </div>
              <div class="field">
                <label for="linkedin">LinkedIn Profile</label>
                <input class="input" type="url" id="linkedin" name="linkedin" value="https://linkedin.com/in/surasak-wongchai" placeholder="https://linkedin.com/in/username">
              </div>
              <div class="field form-section">
                <label>Assign to Projects <span class="req" aria-hidden="true">*</span></label>
                <div class="checkbox-list checkbox-list--grid">
                  <label class="checkbox"><input type="checkbox" name="projects[]" value="1" checked><span>Harbour Point Renovation</span></label>
                  <label class="checkbox"><input type="checkbox" name="projects[]" value="2" checked><span>Bangkok Office Fit-out</span></label>
                  <label class="checkbox"><input type="checkbox" name="projects[]" value="3"><span>Siam Retail Kiosk</span></label>
                  <label class="checkbox"><input type="checkbox" name="projects[]" value="4"><span>Chiang Mai Warehouse</span></label>
                </div>
                <span class="hint">A contact must belong to at least one project.</span>
              </div>
            </div>
          </div>
          <div class="card__footer">
            <button class="btn btn--primary" type="submit"><i class="fas fa-floppy-disk"></i> Save Contact</button>
            <a class="btn btn--secondary" href="contact-detail.html">Cancel</a>
          </div>
        </form>

        <div class="card" style="border-color:var(--danger-500); margin-top:var(--sp-5);">
          <div class="card__header" style="background:var(--danger-100);">
            <h2 class="card__title-icon" style="color:var(--danger-700);"><i class="fas fa-triangle-exclamation"></i> Danger zone</h2>
          </div>
          <div class="card__body">
            <p class="text-sm text-muted">
              Deleting removes Surasak Wongchai from the 2 projects they are assigned to.
              Tasks already assigned to them are kept. This cannot be undone.
            </p>
            <button class="btn btn--danger btn--block" type="button" style="margin-top:1rem;"
                    data-modal-open="confirm-delete-contact">
              <i class="fas fa-trash"></i> Delete Contact
            </button>
          </div>
        </div>

        <div class="modal-backdrop" id="confirm-delete-contact" hidden>
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdc-title">
            <div class="modal__header">
              <div>
                <h3 id="cdc-title">Confirm contact deletion</h3>
                <p>Type the contact name exactly as shown to confirm.</p>
              </div>
              <button class="modal__close" type="button" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal__body">
              <div class="alert alert--danger">
                <i class="fas fa-triangle-exclamation"></i>
                <div class="alert__body">
                  <div class="alert__title">Surasak Wongchai</div>
                  This contact will be unassigned from 2 projects. Tasks already assigned to them are kept.
                </div>
              </div>
              <div class="field">
                <label for="delete-contact-confirmation">Contact Name <span class="req" aria-hidden="true">*</span></label>
                <input class="input" type="text" id="delete-contact-confirmation" placeholder="Surasak Wongchai">
              </div>
            </div>
            <div class="modal__footer">
              <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
              <button class="btn btn--danger" type="button" data-toast="Demo only &mdash; contact not deleted" data-toast-kind="danger">
                <i class="fas fa-trash"></i> Delete permanently
              </button>
            </div>
          </div>
        </div>"""

    return page("Edit Contact", body, active="contacts.html")


# ===========================================================================
# 17. PROFILE
# ===========================================================================
def screen_profile():
    body = """        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-user-edit"></i> My Profile</h1>
            <p class="page-header__sub">Manage your account details and password.</p>
          </div>
          <div class="page-actions">
            <button class="btn btn--primary" type="button" data-toast="Profile saved (demo)" data-toast-kind="success">
              <i class="fas fa-floppy-disk"></i> Save Changes
            </button>
          </div>
        </div>

        <div class="stat-grid">
          <div class="stat">
            <div class="stat__icon"><i class="fas fa-folder-open"></i></div>
            <div><div class="stat__value">7</div><div class="stat__label">Projects</div></div>
          </div>
          <div class="stat">
            <div class="stat__icon"><i class="fas fa-list-check"></i></div>
            <div><div class="stat__value">38</div><div class="stat__label">Tasks</div></div>
          </div>
          <div class="stat stat--success">
            <div class="stat__icon"><i class="fas fa-circle-check"></i></div>
            <div><div class="stat__value">19</div><div class="stat__label">Completed</div></div>
          </div>
          <div class="stat stat--info">
            <div class="stat__icon"><i class="fas fa-address-book"></i></div>
            <div><div class="stat__value">18</div><div class="stat__label">Contacts</div></div>
          </div>
        </div>

        <div class="grid-2">
          <form class="card" action="#" method="post">
            <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-id-card"></i> Profile information</h2></div>
            <div class="card__body">
              <div class="form-grid">
                <div class="field">
                  <label for="name">Full Name</label>
                  <input class="input" type="text" id="name" name="name" value="Zongbao M.">
                </div>
                <div class="field">
                  <label for="username">Username</label>
                  <input class="input" type="text" id="username" name="username" value="zongbao">
                </div>
                <div class="field">
                  <label for="email">Email Address</label>
                  <input class="input" type="email" id="email" name="email" value="zongbao@example.com">
                </div>
              </div>
            </div>
            <div class="card__footer">
              <button class="btn btn--primary" type="submit"><i class="fas fa-floppy-disk"></i> Update Profile</button>
            </div>
          </form>

          <form class="card" action="#" method="post">
            <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-lock"></i> Change Password</h2></div>
            <div class="card__body">
              <div class="form-grid">
                <div class="field">
                  <label for="current_password">Current Password</label>
                  <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password">
                </div>
                <div class="field">
                  <label for="new_password">New Password</label>
                  <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password">
                  <span class="hint">At least 8 characters, including a letter and a number.</span>
                </div>
                <div class="field">
                  <label for="confirm_password">Confirm New Password</label>
                  <input class="input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
                  <span class="error-text"><i class="fas fa-circle-exclamation"></i> Passwords do not match.</span>
                </div>
              </div>
            </div>
            <div class="card__footer">
              <button class="btn btn--primary" type="submit"><i class="fas fa-key"></i> Change Password</button>
            </div>
          </form>
        </div>"""

    return page("My Profile", body, active="profile.html")


# ===========================================================================
# 18. CLIENT PORTAL
# ===========================================================================
def screen_client_dashboard():
    body = """  <div class="client-shell">
    <header class="client-header">
      <div class="client-header__inner">
        <div class="client-header__brand">
          <span class="client-header__brand-icon"><i class="fas fa-project-diagram"></i></span>
          <span>Project Management System</span>
        </div>
        <div class="client-header__project">
          <div class="client-header__project-name">Harbour Point Renovation</div>
          <div class="client-header__project-meta">Client view &middot; updated Oct 20, 2026, 09:14</div>
        </div>
        <div class="lang-switch" data-lang-switch role="group" aria-label="Language">
          <button type="button" data-lang-set="en" class="is-active">ENG</button>
          <button type="button" data-lang-set="zh">中文</button>
        </div>
      </div>
    </header>

    <main class="client-content">
      <div class="client-hero">
        <div class="client-hero__label" data-i18n="Overall Completion|总体进度">Overall Completion</div>
        <div class="client-hero__value">64.0%</div>
        <div class="client-hero__bar"><div class="client-hero__bar-fill" style="width:64%"></div></div>
        <div class="client-hero__stats">
          <div>
            <div class="client-hero__stat-label" data-i18n="Tasks|任务">Tasks</div>
            <div class="client-hero__stat-value">18</div>
          </div>
          <div>
            <div class="client-hero__stat-label" data-i18n="Completed|已完成">Completed</div>
            <div class="client-hero__stat-value">12</div>
          </div>
          <div>
            <div class="client-hero__stat-label" data-i18n="In Progress|进行中">In Progress</div>
            <div class="client-hero__stat-value">4</div>
          </div>
          <div>
            <div class="client-hero__stat-label" data-i18n="Delayed|已延期">Delayed</div>
            <div class="client-hero__stat-value">3</div>
          </div>
          <div>
            <div class="client-hero__stat-label" data-i18n="On Hold|已暂停">On Hold</div>
            <div class="client-hero__stat-value">2</div>
          </div>
        </div>
      </div>

      <div class="client-alert-strip" style="margin-bottom:1.25rem;">
        <span class="badge badge--danger"><i class="fas fa-clock-rotate-left"></i> 3 delayed</span>
        <span class="badge badge--warning"><i class="fas fa-pause"></i> 2 on hold</span>
      </div>

      <div class="card" style="margin-bottom:1.25rem;">
        <div class="card__header">
          <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Project information</h2>
        </div>
        <div class="card__body">
          <div class="client-info-grid">
            <div class="client-info-item">
              <div class="client-info-item__k" data-i18n="Status|状态">Status</div>
              <div class="client-info-item__v"><span class="status status--in_progress">In Progress</span></div>
            </div>
            <div class="client-info-item">
              <div class="client-info-item__k" data-i18n="Budget|预算">Budget</div>
              <div class="client-info-item__v">THB 4,850,000.00</div>
            </div>
            <div class="client-info-item">
              <div class="client-info-item__k" data-i18n="Started|开始日期">Started</div>
              <div class="client-info-item__v">Aug 1, 2026</div>
            </div>
            <div class="client-info-item">
              <div class="client-info-item__k" data-i18n="Expected|预计完成">Expected</div>
              <div class="client-info-item__v">Nov 30, 2026</div>
            </div>
            <div class="client-info-item">
              <div class="client-info-item__k" data-i18n="Completed|已完成">Completed</div>
              <div class="client-info-item__v">&mdash;</div>
            </div>
            <div class="client-info-item">
              <div class="client-info-item__k" data-i18n="Manager|项目经理">Manager</div>
              <div class="client-info-item__v">Zongbao M.</div>
            </div>
          </div>
          <div style="margin-top:1.25rem;">
            <div class="client-info-item__k" style="margin-bottom:.35rem;" data-i18n="Scope|项目范围">Scope</div>
            <p class="text-sm" style="margin:0;">Structural, MEP, finishes and fit-out of levels 1&ndash;4 plus rooftop works.</p>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__header">
          <h2 class="card__title-icon"><i class="fas fa-list-check"></i> Tasks</h2>
          <div class="card__actions">
            <span class="badge badge--neutral">18 tasks</span>
          </div>
        </div>
        <div class="card__body">
          <ul class="task-tree">
            <li class="task-node task-node--completed">
              <div class="task-node__head">
                <span class="task-node__name task-node__name--done">Site survey &amp; structural assessment</span>
                <span class="status status--completed">Completed</span>
                <span class="status status--delayed"><i class="fas fa-clock-rotate-left"></i> Delayed</span>
              </div>
              <div class="task-node__meta">
                <span><i class="fas fa-user"></i> Zongbao M.</span>
                <span><i class="fas fa-calendar"></i> Due Sep 12, 2026</span>
                <span><i class="fas fa-calendar-check"></i> Completed Oct 2, 2026</span>
                <span><i class="fas fa-clock"></i> 48 hours</span>
              </div>
              <div class="task-node__progress">
                <div class="progress progress--success"><div class="progress__fill" style="width:100%"></div></div>
                <span class="task-node__pct">100%</span>
              </div>
              <div class="task-node__reasons">
                <div class="reason-chip reason-chip--delay">
                  <i class="fas fa-clock-rotate-left"></i>
                  <span><strong>Delay:</strong> Heavy monsoon rainfall in early September flooded the lower levels and blocked the survey for 12 days.</span>
                </div>
              </div>
              <ul class="task-tree" style="margin-top:.6rem;">
                <li class="task-node task-node--completed">
                  <div class="task-node__head">
                    <span class="task-node__name task-node__name--done">Structural survey report</span>
                    <span class="status status--completed">Completed</span>
                  </div>
                  <div class="task-node__meta">
                    <span><i class="fas fa-user"></i> Zongbao M.</span>
                    <span><i class="fas fa-calendar"></i> Due Oct 02, 2026</span>
                  </div>
                  <div class="task-node__progress">
                    <div class="progress progress--success"><div class="progress__fill" style="width:100%"></div></div>
                    <span class="task-node__pct">100%</span>
                  </div>
                </li>
                <li class="task-node task-node--completed">
                  <div class="task-node__head">
                    <span class="task-node__name task-node__name--done">Load test certification</span>
                    <span class="status status--completed">Completed</span>
                  </div>
                  <div class="task-node__meta">
                    <span><i class="fas fa-user"></i> Anan Suwan</span>
                  </div>
                  <div class="task-node__progress">
                    <div class="progress progress--success"><div class="progress__fill" style="width:100%"></div></div>
                    <span class="task-node__pct">100%</span>
                  </div>
                </li>
              </ul>
            </li>

            <li class="task-node task-node--on-hold">
              <div class="task-node__head">
                <span class="task-node__name">Permit renewal</span>
                <span class="status status--on_hold">On Hold</span>
                <span class="status status--delayed"><i class="fas fa-clock-rotate-left"></i> Delayed</span>
              </div>
              <div class="task-node__meta">
                <span><i class="fas fa-user"></i> Zongbao M.</span>
                <span><i class="fas fa-calendar"></i> Due Oct 05, 2026</span>
              </div>
              <div class="task-node__progress">
                <div class="progress progress--warning"><div class="progress__fill" style="width:0%"></div></div>
                <span class="task-node__pct">0%</span>
              </div>
              <div class="task-node__reasons">
                <div class="reason-chip reason-chip--on-hold">
                  <i class="fas fa-pause"></i>
                  <span><strong>On hold:</strong> Building permit renewal awaiting municipal authority scheduling.</span>
                </div>
                <div class="reason-chip reason-chip--delay">
                  <i class="fas fa-clock-rotate-left"></i>
                  <span><strong>Delay:</strong> Permit office processing backlog extended the expected renewal date by 3 weeks.</span>
                </div>
              </div>
            </li>

            <li class="task-node">
              <div class="task-node__head">
                <span class="task-node__name">MEP rough-in</span>
                <span class="status status--in_progress">In Progress</span>
              </div>
              <div class="task-node__meta">
                <span><i class="fas fa-user"></i> Nok Chantarak</span>
                <span><i class="fas fa-calendar"></i> Due Oct 22, 2026</span>
                <span><i class="fas fa-clock"></i> 120 hours</span>
              </div>
              <div class="task-node__progress">
                <div class="progress"><div class="progress__fill" style="width:55%"></div></div>
                <span class="task-node__pct">55%</span>
              </div>
            </li>

            <li class="task-node">
              <div class="task-node__head">
                <span class="task-node__name">Facade glazing install</span>
                <span class="status status--not_started">Not Started</span>
              </div>
              <div class="task-node__meta">
                <span><i class="fas fa-user"></i> Somchai P.</span>
                <span><i class="fas fa-calendar"></i> Due Nov 08, 2026</span>
                <span class="not-estimated"><i class="fas fa-clock"></i> Not estimated</span>
              </div>
              <div class="task-node__progress">
                <div class="progress"><div class="progress__fill" style="width:0%"></div></div>
                <span class="task-node__pct">0%</span>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </main>

    <footer class="footer">
      &copy; 2026 Project Management System. All rights reserved.
    </footer>
  </div>"""

    return page("Client Portal", body, bare=True)
