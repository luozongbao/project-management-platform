#!/usr/bin/env python3
"""Form screens + remaining v2.0 mockup screens."""

from build import (page, write, stat, status_badge, reason_chip, task_node,
                   escape, reason_missing_inline)


# ===========================================================================
# 10. PROJECT FORM (create / edit)
# ===========================================================================
def screen_project_form():
    body = """        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="dashboard.html">Dashboard</a>
          <span aria-hidden="true">/</span>
          <a href="projects.html">Projects</a>
          <span aria-hidden="true">/</span>
          <span>Edit Project</span>
        </nav>

        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-pen-to-square"></i> Edit Project</h1>
            <p class="page-header__sub">Harbour Point Renovation &middot; Code HRP-2026-014</p>
          </div>
          <div class="page-actions">
            <a class="btn btn--secondary" href="project-detail.html">Cancel</a>
            <button class="btn btn--primary" type="button" data-toast="Project saved (demo)" data-toast-kind="success">
              <i class="fas fa-floppy-disk"></i> Save Changes
            </button>
          </div>
        </div>

        <div class="grid-sidebar">
          <form class="card" action="#" method="post">
            <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Project information</h2></div>
            <div class="card__body">
              <div class="form-grid form-grid--2">
                <div class="field form-section">
                  <label for="name">Project Name <span class="req" aria-hidden="true">*</span></label>
                  <input class="input" type="text" id="name" name="name" value="Harbour Point Renovation" required>
                </div>
                <div class="field">
                  <label for="status">Status <span class="req" aria-hidden="true">*</span></label>
                  <select class="select" id="status" name="status" required>
                    <option value="not_started">Not Started</option>
                    <option value="in_progress" selected>In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="on_hold">On Hold</option>
                  </select>
                </div>
                <div class="field form-section">
                  <label for="description">Description</label>
                  <textarea class="textarea" id="description" name="description" rows="4">Full interior renovation of a 4-storey heritage building.</textarea>
                </div>
                <div class="field">
                  <label for="scope">Scope of Work</label>
                  <textarea class="textarea" id="scope" name="scope" rows="5">Structural, MEP, finishes and fit-out of levels 1&ndash;4 plus rooftop works.</textarea>
                </div>
                <div class="field">
                  <label for="budget_amount">Budget Amount</label>
                  <input class="input" type="number" step="0.01" min="0" id="budget_amount" name="budget_amount" value="4850000.00">
                  <span class="hint">Leave empty for no budget.</span>
                </div>
                <div class="field">
                  <label for="currency">Currency</label>
                  <select class="select" id="currency" name="currency">
                    <option value="">— No budget —</option>
                    <option value="THB" selected>THB — Thai Baht</option>
                    <option value="USD">USD — US Dollar</option>
                    <option value="CNY">CNY — Chinese Yuan</option>
                    <option value="JPY">JPY — Japanese Yen</option>
                    <option value="SGD">SGD — Singapore Dollar</option>
                    <option value="EUR">EUR — Euro</option>
                  </select>
                </div>
                <div class="field">
                  <label for="start_date">Start Date</label>
                  <input class="input" type="date" id="start_date" name="start_date" value="2026-08-01">
                </div>
                <div class="field">
                  <label for="expected_completion_date">Expected Completion Date</label>
                  <input class="input" type="date" id="expected_completion_date" name="expected_completion_date" value="2026-11-30">
                </div>
                <div class="field">
                  <label for="completion_date">Completion Date</label>
                  <input class="input" type="date" id="completion_date" name="completion_date" value="">
                  <span class="hint">Set when the project is actually finished.</span>
                </div>
              </div>
            </div>
            <div class="card__footer">
              <button class="btn btn--primary" type="submit"><i class="fas fa-floppy-disk"></i> Save Changes</button>
              <a class="btn btn--secondary" href="project-detail.html">Cancel</a>
            </div>
          </form>

          <div>
            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-share-nodes"></i> Client share</h2></div>
              <div class="card__body">
                <div class="share-box">
                  <code>HRP-2026-014</code>
                  <button class="btn btn--sm btn--secondary" type="button" data-copy="HRP-2026-014"><i class="far fa-copy"></i></button>
                </div>
                <p class="text-xs text-muted" style="margin:.75rem 0 1rem;">
                  Last rotated: Aug 1, 2026, 09:14
                </p>
                <button class="btn btn--secondary btn--sm btn--block" type="button"
                        data-toast="Demo only &mdash; code not rotated" data-toast-kind="warning">
                  <i class="fas fa-rotate"></i> Rotate share code
                </button>
              </div>
            </div>

            <div class="card" style="border-color:var(--danger-500);">
              <div class="card__header" style="background:var(--danger-100);">
                <h2 class="card__title-icon" style="color:var(--danger-700);"><i class="fas fa-triangle-exclamation"></i> Danger zone</h2>
              </div>
              <div class="card__body">
                <p class="text-sm text-muted">
                  Deleting removes the project, all 18 tasks, their subtasks and every project
                  assignment for 3 contacts. This cannot be undone.
                </p>
                <div class="field" style="margin-top:1rem;">
                  <label for="delete-project-confirmation">Type the project code to confirm <span class="req" aria-hidden="true">*</span></label>
                  <input class="input text-mono" type="text" id="delete-project-confirmation"
                         name="delete-project-confirmation" placeholder="HRP-2026-014">
                  <span class="hint">Must exactly match <strong>HRP-2026-014</strong>.</span>
                </div>
                <button class="btn btn--danger btn--block" type="button" style="margin-top:1rem;"
                        data-modal-open="confirm-delete-project">
                  <i class="fas fa-trash"></i> Delete Project
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-backdrop" id="confirm-delete-project" hidden>
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdp-title">
            <div class="modal__header">
              <div>
                <h3 id="cdp-title">Confirm project deletion</h3>
                <p>Type the project code exactly as shown to confirm.</p>
              </div>
              <button class="modal__close" type="button" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal__body">
              <div class="alert alert--danger">
                <i class="fas fa-triangle-exclamation"></i>
                <div class="alert__body">
                  <div class="alert__title">HRP-2026-014</div>
                  All 18 tasks, their subtasks and 3 contact assignments will be permanently removed.
                </div>
              </div>
              <div class="field">
                <label for="delete-project-confirmation-2">Project Code <span class="req" aria-hidden="true">*</span></label>
                <input class="input text-mono" type="text" id="delete-project-confirmation-2" placeholder="HRP-2026-014">
              </div>
            </div>
            <div class="modal__footer">
              <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
              <button class="btn btn--danger" type="button" data-toast="Demo only &mdash; project not deleted" data-toast-kind="danger">
                <i class="fas fa-trash"></i> Delete permanently
              </button>
            </div>
          </div>
        </div>"""

    return page("Edit Project", body, active="projects.html")


# ===========================================================================
# 11. TASKS LIST
# ===========================================================================
def screen_tasks():
    rows = [
        {
            "name": "Site survey &amp; structural assessment", "proj": "Harbour Point Renovation",
            "st": "completed", "pct": "100%", "resp": "Zongbao M.", "contact": "Surasak Wongchai",
            "due": "Sep 12, 2026", "completed": "Sep 10, 2026", "subs": "3 subtasks", "flag": "",
        },
        {
            "name": "MEP rough-in", "proj": "Harbour Point Renovation",
            "st": "in_progress", "pct": "55%", "resp": "Nok Chantarak", "contact": "Ploy Sirichai",
            "due": "Oct 22, 2026", "completed": "—", "subs": "4 subtasks", "flag": "",
        },
        {
            # v1.8.0: on hold AND delayed — production renders BOTH the
            # on-hold chip and the delay chip, plus the missing-reason
            # marker when a reason has not been recorded yet.
            "name": "Permit renewal", "proj": "Harbour Point Renovation",
            "st": "on_hold", "pct": "0%", "resp": "Zongbao M.", "contact": "Anan Suwan",
            "due": "Oct 05, 2026", "completed": "—", "subs": "0 subtasks",
            "flag": reason_chip("on-hold", "fas fa-pause", "On hold",
                                "Awaiting municipal authority scheduling."),
            "extra_status": "delayed",
            "delay_flag": reason_chip("delay", "fas fa-clock-rotate-left", "Delayed",
                                       "Permit window closed before the authority "
                                       "replied; submission pushed to the next cycle."),
            "reason_missing": "on_hold",
        },
        {
            "name": "Facade glazing install", "proj": "Harbour Point Renovation",
            "st": "not_started", "pct": "0%", "resp": "Somchai P.", "contact": "Surasak Wongchai",
            "due": "Nov 08, 2026", "completed": "—", "subs": "2 subtasks", "flag": "",
        },
        {
            "name": "Deep-clean lobby floor", "proj": "Bangkok Office Fit-out",
            "st": "on_hold", "pct": "0%", "resp": "Somchai P.", "contact": "—",
            "due": "Oct 10, 2026", "completed": "—", "subs": "0 subtasks",
            "flag": reason_chip("on-hold", "fas fa-pause", "On hold",
                                "Client postponed signage sign-off to next budget cycle."),
            "extra_status": "delayed",
            "delay_flag": reason_chip("delay", "fas fa-clock-rotate-left", "Delayed",
                                       "Site access was restricted by the building "
                                       "management for two weeks."),
            "reason_missing": "on_hold",
        },
        {
            "name": "Electrical wiring inspection", "proj": "Harbour Point Renovation",
            "st": "in_progress", "pct": "45%", "resp": "Nok Chantarak", "contact": "Anan Suwan",
            "due": "Oct 18, 2026", "completed": "—", "subs": "1 subtask", "flag": "",
        },
        {
            # v1.8.0: delayed with NO reason recorded — the row must carry
            # the missing-reason marker so the backfill gap is visible.
            "name": "Deep pile foundation", "proj": "Harbour Point Renovation",
            "st": "in_progress", "pct": "30%", "resp": "Somchai P.", "contact": "—",
            "due": "Sep 30, 2026", "completed": "—", "subs": "2 subtasks", "flag": "",
            "extra_status": "delayed",
            "delay_flag": "",
            "reason_missing": "delay",
        },
    ]

    body_rows = []
    for r in rows:
        is_delayed = r.get("extra_status") == "delayed"
        overdue = "text-danger fw-semibold" if is_delayed else "text-muted"
        delay_badge = status_badge("delayed") if is_delayed else ""
        delay_chip = r.get("delay_flag", "")
        body_rows.append(f"""              <tr>
                <td>
                  <div class="chip-row" style="flex-wrap:wrap;">
                    <a href="task-detail.html"><strong>{r["name"]}</strong></a>
                    {delay_badge}
                    {r["flag"]}
                  </div>
                  {delay_chip}
                </td>
                <td class="text-sm text-muted nowrap">{r["proj"]}</td>
                <td>{status_badge(r["st"], reason_missing=r.get("reason_missing"))}</td>
                <td class="text-sm nowrap">{r["resp"]}</td>
                <td class="text-sm nowrap">{r["contact"]}</td>
                <td class="text-sm nowrap {overdue}">{r["due"]}</td>
                <td class="text-sm nowrap text-muted">{r["completed"]}</td>
                <td class="text-sm nowrap text-muted">{r["subs"]}</td>
                <td>
                  <div class="row-actions">
                    <a class="btn btn--sm btn--secondary btn--icon" href="task-form.html" title="Edit"><i class="fas fa-pen"></i></a>
                    <a class="btn btn--sm btn--ghost btn--icon" href="task-detail.html" title="Open"><i class="fas fa-arrow-right"></i></a>
                  </div>
                </td>
              </tr>""")

    body = f"""        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-tasks"></i> Tasks</h1>
            <p class="page-header__sub">38 tasks across 7 projects &middot; 3 delayed &middot; 2 on hold</p>
          </div>
          <div class="page-actions">
            <a class="btn btn--primary" href="task-form.html"><i class="fas fa-plus"></i> New Task</a>
          </div>
        </div>

        <div class="alert alert--warning" role="alert">
          <i class="fas fa-triangle-exclamation"></i>
          <div class="alert__body">
            <div class="alert__title">3 delayed tasks are missing a delay reason</div>
            Delayed tasks must record why they slipped. Add the missing reasons so the client portal stays accurate.
            <div style="margin-top:.6rem;">
              <a class="btn btn--sm btn--warning" href="task-form.html">Add reasons</a>
            </div>
          </div>
        </div>

        <div class="stat-grid">
{stat("38", "Total Tasks", "fas fa-list-check", "")}
{stat("19", "Completed", "fas fa-circle-check", "stat--success", "")}
{stat("16", "Pending", "fas fa-hourglass-half", "stat--on-hold", "")}
{stat("52.6%", "Avg Completion", "fas fa-chart-pie", "stat--info", "")}
        </div>

        <div class="filter-bar" data-filter-form>
          <div class="field">
            <label for="search">Search Tasks</label>
            <input class="input" type="search" id="search" placeholder="Task name or description&hellip;">
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
          <div class="field">
            <label for="status_filter">Status</label>
            <select class="select" id="status_filter">
              <option value="all">All Status</option>
              <option value="not_started">Not Started</option>
              <option value="in_progress">In Progress</option>
              <option value="completed">Completed</option>
              <option value="on_hold">On Hold</option>
              <option value="delayed">Delayed</option>
            </select>
          </div>
          <div class="filter-bar__actions">
            <button class="btn btn--primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
            <button class="btn btn--secondary" type="button" data-filter-reset>Reset</button>
          </div>
        </div>

        <div class="card">
          <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-list"></i> Task list</h2>
            <div class="card__actions">
              <span class="badge badge--neutral">Showing 6 of 38</span>
              <button class="btn btn--sm btn--ghost" type="button" data-toast="Export queued (demo)"><i class="fas fa-file-export"></i> Export</button>
            </div>
          </div>
          <div class="card__body card__body--flush">
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Task</th><th>Project</th><th>Status</th><th>Responsible</th>
                    <th>Contact</th><th>Due</th><th>Completed</th><th>Subtasks</th><th></th>
                  </tr>
                </thead>
                <tbody>
{chr(10).join(body_rows)}
                </tbody>
              </table>
            </div>
          </div>
        </div>"""

    return page("Tasks", body, active="tasks.html")


# ===========================================================================
# 12. TASK DETAIL
# ===========================================================================
def screen_task_detail():
    subtasks = [
        ("Structural survey report", "completed", "100%", "Zongbao M.", True),
        ("Load test certification", "completed", "100%", "Anan Suwan", False),
        ("Floor plan revision", "in_progress", "60%", "Nok Chantarak", False),
    ]
    sub_rows = []
    for name, st, pct, who, done in subtasks:
        cls = ' style="opacity:.75;"' if done else ""
        sub_rows.append(f"""              <li class="task-node{' task-node--completed' if done else ''}">
                <div class="task-node__head">
                  <span class="task-node__name{' task-node__name--done' if done else ''}">{name}</span>
                  {status_badge(st)}
                </div>
                <div class="task-node__meta">
                  <span><i class="fas fa-user"></i> {who}</span>
                  <span><i class="fas fa-calendar"></i> Due Oct 02, 2026</span>
                </div>
                <div class="task-node__progress">
                  <div class="progress"><div class="progress__fill" style="width:{pct.rstrip('%')}"></div></div>
                  <span class="task-node__pct">{pct}</span>
                </div>
              </li>""")

    sub_rows_html = "\n".join(sub_rows)
    body = f"""        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="dashboard.html">Dashboard</a>
          <span aria-hidden="true">/</span>
          <a href="tasks.html">Tasks</a>
          <span aria-hidden="true">/</span>
          <a href="project-detail.html">Harbour Point Renovation</a>
          <span aria-hidden="true">/</span>
          <span>Site survey &amp; structural assessment</span>
        </nav>

        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-list-check"></i> Site survey &amp; structural assessment</h1>
            <div class="chip-row" style="margin-top:.35rem;">
              <span class="status status--completed">Completed</span>
              <span class="status status--delayed"><i class="fas fa-clock-rotate-left"></i> Delayed</span>
              <span class="badge badge--outline"><i class="fas fa-sitemap"></i> 3 subtasks</span>
              <span class="badge badge--outline"><i class="fas fa-user"></i> Zongbao M.</span>
            </div>
          </div>
          <div class="page-actions">
            <button class="btn btn--success" type="button" data-toast="Demo only &mdash; task already completed" data-toast-kind="success">
              <i class="fas fa-circle-check"></i> Complete Task
            </button>
            <button class="btn btn--warning" type="button" data-modal-open="hold-task">
              <i class="fas fa-pause"></i> Put on Hold
            </button>
            <a class="btn btn--secondary" href="task-form.html"><i class="fas fa-pen"></i> Edit Task</a>
            <a class="btn btn--primary" href="task-form.html"><i class="fas fa-plus"></i> Add Subtask</a>
          </div>
        </div>

        <div class="grid-sidebar">
          <div>
            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-list-check"></i> Task Overview</h2></div>
              <div class="card__body">
                <p class="text-sm">Comprehensive structural survey of all four levels, including load testing,
                foundation assessment and a revised floor plan for levels 2 and 3.</p>
                <div class="detail-list" style="margin-top:1rem;">
                  <div class="detail-row"><span class="detail-row__k">Project</span><span class="detail-row__v"><a href="project-detail.html">Harbour Point Renovation</a></span></div>
                  <div class="detail-row"><span class="detail-row__k">Status</span><span class="detail-row__v"><span class="status status--completed">Completed</span> <span class="status status--delayed"><i class="fas fa-clock-rotate-left"></i> Delayed</span></span></div>
                  <div class="detail-row"><span class="detail-row__k">Responsible person</span><span class="detail-row__v">Zongbao M.</span></div>
                  <div class="detail-row"><span class="detail-row__k">Contact person</span><span class="detail-row__v">Surasak Wongchai</span></div>
                  <div class="detail-row"><span class="detail-row__k">Total task time</span><span class="detail-row__v">48 hours (inherited from subtasks)</span></div>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-diagram-project"></i> Subtasks</h2>
                <div class="card__actions">
                  <a class="btn btn--sm btn--primary" href="task-form.html"><i class="fas fa-plus"></i> Add Subtask</a>
                </div>
              </div>
              <div class="card__body">
                <ul class="task-tree">
{sub_rows_html}
                </ul>
              </div>
            </div>

            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-info-circle"></i> Task Details</h2></div>
              <div class="card__body">
                <div class="detail-list">
                  <div class="detail-row"><span class="detail-row__k">Created</span><span class="detail-row__v">Sep 2, 2026, 14:22</span></div>
                  <div class="detail-row"><span class="detail-row__k">Expected Completion</span><span class="detail-row__v">Sep 12, 2026</span></div>
                  <div class="detail-row"><span class="detail-row__k">Completed</span><span class="detail-row__v">Oct 2, 2026, 16:05</span></div>
                  <div class="detail-row"><span class="detail-row__k">Completion</span><span class="detail-row__v" style="min-width:160px;">
                    <div class="progress progress--success"><div class="progress__fill" style="width:100%"></div></div>
                  </span></div>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Status Reason</h2>
                <div class="card__actions">
                  <button class="btn btn--sm btn--secondary" type="button" data-toast="Reason saved (demo)" data-toast-kind="success">
                    <i class="fas fa-floppy-disk"></i> Save reason
                  </button>
                </div>
              </div>
              <div class="card__body">
                <!-- v1.8.0 parity: the card renders ONE block per applicable
                     condition (on hold / delayed). Each block shows either the
                     saved reason plus an inline editor, or the
                     "missing" callout plus an open add-reason editor. -->
                <div class="field" style="margin-bottom:1rem;">
                  <label>On-hold reason:</label>
                  {reason_missing_inline("Missing &mdash; fill in to explain the hold to the client.")}
                  <details class="reason-inline-edit" open>
                    <summary>Add reason</summary>
                    <textarea class="textarea" id="detail_on_hold_reason"
                              data-update-reason data-field="on_hold_reason"
                              rows="3" maxlength="2000"
                              placeholder="Why is this task on hold?"></textarea>
                    <button class="btn btn--sm btn--primary" type="button"
                            data-save-reason data-field="on_hold_reason">
                      <i class="fas fa-floppy-disk"></i> Save
                    </button>
                    <span data-save-status aria-live="polite"></span>
                  </details>
                </div>

                <div class="field">
                  <label>Delay reason:</label>
                  <div class="reason-chip reason-chip--delay reason-chip--block" style="margin-bottom:var(--sp-2);">
                    <i class="fas fa-clock-rotate-left"></i>
                    <span><strong>Delayed:</strong> Heavy monsoon rainfall in early September flooded the lower levels and blocked the survey for 12 days. Re-survey rescheduled from Sep 4 to Sep 12.</span>
                  </div>
                  <details class="reason-inline-edit">
                    <summary>Edit</summary>
                    <textarea class="textarea" id="detail_delay_reason"
                              data-update-reason data-field="delay_reason"
                              rows="3" maxlength="2000">Heavy monsoon rainfall in early September flooded the lower levels and blocked the survey for 12 days. Re-survey rescheduled from Sep 4 to Sep 12.</textarea>
                    <button class="btn btn--sm btn--primary" type="button"
                            data-save-reason data-field="delay_reason">
                      <i class="fas fa-floppy-disk"></i> Save
                    </button>
                    <span data-save-status aria-live="polite"></span>
                  </details>
                  <span class="hint">Max 2000 characters. Visible to the client on the shared portal.</span>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-clock-rotate-left"></i> Recent Activity</h2></div>
              <div class="card__body">
                <ul class="timeline">
                  <li>
                    <span class="timeline__icon"><i class="fas fa-check"></i></span>
                    <div class="timeline__body">
                      <div><strong>Zongbao M.</strong> marked the task completed</div>
                      <div class="timeline__meta">Oct 2, 2026, 16:05</div>
                    </div>
                  </li>
                  <li>
                    <span class="timeline__icon"><i class="fas fa-pen"></i></span>
                    <div class="timeline__body">
                      <div><strong>Nok Chantarak</strong> added a delay reason</div>
                      <div class="timeline__meta">Sep 13, 2026, 09:31</div>
                    </div>
                  </li>
                  <li>
                    <span class="timeline__icon"><i class="fas fa-flag"></i></span>
                    <div class="timeline__body">
                      <div><strong>System</strong> flagged the task as delayed (past expected completion)</div>
                      <div class="timeline__meta">Sep 13, 2026, 00:00</div>
                    </div>
                  </li>
                  <li>
                    <span class="timeline__icon"><i class="fas fa-user-plus"></i></span>
                    <div class="timeline__body">
                      <div><strong>Zongbao M.</strong> created the task</div>
                      <div class="timeline__meta">Sep 2, 2026, 14:22</div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <div>
            <div class="stat-grid" style="grid-template-columns:1fr 1fr;margin-bottom:0;">
              {stat("100%", "Completion", "fas fa-chart-pie", "stat--success", "")}
              {stat("3", "Subtasks", "fas fa-diagram-project", "")}
            </div>
            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-clock"></i> Time</h2></div>
              <div class="card__body">
                <div class="detail-list">
                  <div class="detail-row"><span class="detail-row__k">Total time</span><span class="detail-row__v">48 hours</span></div>
                  <div class="detail-row"><span class="detail-row__k">Source</span><span class="detail-row__v">Inherited from subtasks</span></div>
                  <div class="detail-row"><span class="detail-row__k">Unit</span><span class="detail-row__v">Hours</span></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-backdrop" id="hold-task" hidden>
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="hold-title">
            <div class="modal__header">
              <div>
                <h3 id="hold-title">Put task on hold</h3>
                <p>A reason is mandatory and is visible to the client on the shared portal.</p>
              </div>
              <button class="modal__close" type="button" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal__body">
              <div class="field">
                <label for="hold-reason">On-hold reason <span class="req" aria-hidden="true">*</span></label>
                <textarea class="textarea" id="hold-reason" rows="4" placeholder="Explain why this task is on hold&hellip;" required></textarea>
                <span class="hint">Max 2000 characters.</span>
              </div>
            </div>
            <div class="modal__footer">
              <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
              <button class="btn btn--warning" type="button" data-toast="Demo only &mdash; task not held" data-toast-kind="warning">
                <i class="fas fa-pause"></i> Put on Hold
              </button>
            </div>
          </div>
        </div>"""

    return page("Task Detail", body, active="tasks.html")
