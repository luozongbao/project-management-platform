#!/usr/bin/env python3
"""Internal application screens for the v2.0 mockup."""

from build import (page, write, stat, status_badge, reason_chip, task_node,
                   escape)


# ===========================================================================
# 7. DASHBOARD
# ===========================================================================
def screen_dashboard():
    stats = "\n".join([
        stat("62.4%", "Overall Completion", "fas fa-chart-pie", "",
             "Weighted across all projects"),
        stat("7", "Total Projects", "fas fa-folder-open", "",
             "3 completed"),
        stat("19", "Pending Tasks", "fas fa-list-check", "",
             "38 total"),
        stat("3", "Delayed Tasks", "fas fa-clock-rotate-left", "stat--delayed",
             "Past expected completion"),
        stat("2", "On Hold Tasks", "fas fa-pause", "stat--on-hold",
             "Reason recorded"),
        stat("5", "Upcoming Deadlines", "fas fa-calendar-day", "stat--info",
             "Next 14 days"),
    ])

    recent_projects = [
        ("Harbour Point Renovation", "in_progress", "64%", "12 / 18 tasks", "Nov 30, 2026"),
        ("Bangkok Office Fit-out", "in_progress", "38%", "5 / 14 tasks", "Dec 18, 2026"),
        ("Siam Retail Kiosk", "completed", "100%", "9 / 9 tasks", "Oct 2, 2026"),
    ]
    rp_rows = []
    for name, st, pct, tcount, due in recent_projects:
        rp_rows.append(f"""              <tr>
                <td><a href="project-detail.html"><strong>{name}</strong></a></td>
                <td>{status_badge(st)}</td>
                <td style="min-width:130px;">
                  <div class="progress"><div class="progress__fill" style="width:{pct.rstrip('%')}"></div></div>
                </td>
                <td class="text-sm text-muted nowrap">{tcount}</td>
                <td class="text-sm nowrap">{due}</td>
              </tr>""")

    deadlines = [
        ("Site handover drawings", "Harbour Point Renovation", "Oct 24, 2026", "danger", "2 days overdue"),
        ("Electrical sign-off", "Bangkok Office Fit-out", "Oct 26, 2026", "warning", "due soon"),
        ("Supplier delivery", "Harbour Point Renovation", "Oct 29, 2026", "warning", "due soon"),
        ("Structural inspection", "Chiang Mai Warehouse", "Nov 04, 2026", "info", "on track"),
        ("Final handover", "Siam Retail Kiosk", "Nov 12, 2026", "info", "on track"),
    ]
    dl_rows = []
    for name, proj, due, kind, note in deadlines:
        color = {"danger": "text-danger", "warning": "text-warning", "info": "text-muted"}[kind]
        dl_rows.append(f"""              <tr>
                <td><strong>{name}</strong></td>
                <td class="text-sm text-muted">{proj}</td>
                <td class="text-sm nowrap {color}">{due}</td>
                <td class="text-xs text-muted nowrap">{note}</td>
              </tr>""")

    recent_tasks = [
        ("Electrical wiring inspection", "Harbour Point Renovation", "in_progress", "45%",
         "Nok Chantarak"),
        ("Update signage mockups", "Siam Retail Kiosk", "completed", "100%", "Zongbao M."),
        ("Deep-clean lobby floor", "Bangkok Office Fit-out", "on_hold", "0%",
         "Somchai P."),
    ]
    rt_rows = []
    for name, proj, st, pct, who in recent_tasks:
        extra = ""
        if st == "on_hold":
            extra = reason_chip("on-hold", "fas fa-pause",
                                 "On hold", "Client postponed signage sign-off to the next budget cycle.")
        rt_rows.append(f"""              <tr>
                <td><a href="task-detail.html"><strong>{name}</strong></a></td>
                <td class="text-sm text-muted">{proj}</td>
                <td>{status_badge(st)}</td>
                <td style="min-width:110px;">
                  <div class="progress"><div class="progress__fill" style="width:{pct.rstrip('%')}"></div></div>
                </td>
                <td class="text-sm nowrap">{who}</td>
              </tr>""")

    body = f"""        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-gauge-high"></i> Dashboard</h1>
            <p class="page-header__sub">Overview of every project, task and deadline &mdash; updated live.</p>
          </div>
          <div class="page-actions">
            <a class="btn btn--secondary" href="projects.html"><i class="fas fa-folder-open"></i> Projects</a>
            <a class="btn btn--primary" href="project-form.html"><i class="fas fa-plus"></i> New Project</a>
          </div>
        </div>

        <div class="alert alert--warning" role="alert">
          <i class="fas fa-triangle-exclamation"></i>
          <div class="alert__body">
            <div class="alert__title">3 delayed tasks have no reason recorded</div>
            Delayed and on-hold tasks must carry a reason. Review and add the missing reasons so your
            client view stays accurate.
            <div style="margin-top:.6rem;">
              <a class="btn btn--sm btn--warning" href="tasks.html">Review delayed tasks</a>
            </div>
          </div>
        </div>

        <div class="stat-grid">
{stats}
        </div>

        <div class="grid-sidebar--wide" style="display:grid;grid-template-columns:1fr 400px;gap:1.25rem;align-items:start;">
          <div>
            <div class="card">
              <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-folder-open"></i> Recent Projects</h2>
                <div class="card__actions">
                  <a class="btn btn--sm btn--ghost" href="projects.html">View all <i class="fas fa-arrow-right"></i></a>
                </div>
              </div>
              <div class="card__body card__body--flush">
                <div class="table-wrap">
                  <table class="table">
                    <thead>
                      <tr><th>Project</th><th>Status</th><th>Progress</th><th>Tasks</th><th>Due</th></tr>
                    </thead>
                    <tbody>
{chr(10).join(rp_rows)}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-clock-rotate-left"></i> Recent Tasks</h2>
                <div class="card__actions">
                  <a class="btn btn--sm btn--ghost" href="tasks.html">View all <i class="fas fa-arrow-right"></i></a>
                </div>
              </div>
              <div class="card__body card__body--flush">
                <div class="table-wrap">
                  <table class="table">
                    <thead>
                      <tr><th>Task</th><th>Project</th><th>Status</th><th>Progress</th><th>Owner</th></tr>
                    </thead>
                    <tbody>
{chr(10).join(rt_rows)}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card__header">
              <h2 class="card__title-icon"><i class="fas fa-calendar-day"></i> Upcoming Deadlines</h2>
            </div>
            <div class="card__body card__body--flush">
              <div class="table-wrap">
                <table class="table table--compact">
                  <tbody>
{chr(10).join(dl_rows)}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>"""

    return page("Dashboard", body, active="dashboard.html")


# ===========================================================================
# 8. PROJECTS
# ===========================================================================
def screen_projects():
    projects = [
        {
            "name": "Harbour Point Renovation",
            "status": "in_progress", "due": "Nov 30, 2026", "tasks": "12 / 18 tasks",
            "pending": "6 pending", "pct": 64,
            "desc": "Full interior renovation of a 4-storey heritage building.",
        },
        {
            "name": "Bangkok Office Fit-out",
            "status": "in_progress", "due": "Dec 18, 2026", "tasks": "5 / 14 tasks",
            "pending": "9 pending", "pct": 38,
            "desc": "Cat-B fit-out for a 240 sqm office on the 12th floor.",
        },
        {
            "name": "Siam Retail Kiosk",
            "status": "completed", "due": "Oct 2, 2026", "tasks": "9 / 9 tasks",
            "pending": "0 pending", "pct": 100,
            "desc": "Freestanding retail kiosk at Siam Paragon.",
        },
        {
            "name": "Chiang Mai Warehouse",
            "status": "on_hold", "due": "Jan 15, 2027", "tasks": "3 / 11 tasks",
            "pending": "8 pending", "pct": 24,
            "desc": "New distribution warehouse with cold storage.",
        },
    ]

    cards = []
    for p in projects:
        warn = ""
        if p["status"] == "on_hold":
            warn = reason_chip("on-hold", "fas fa-pause", "On hold",
                               "Landlord lease amendment pending.")
        cards.append(f"""        <div class="project-card">
          <div class="project-card__head">
            <div style="flex:1;min-width:0;">
              <h3 class="project-card__title"><a href="project-detail.html">{p["name"]}</a></h3>
              <div class="project-card__meta">
                <span><i class="fas fa-calendar"></i> Due {p["due"]}</span>
                <span><i class="fas fa-list-check"></i> {p["tasks"]}</span>
              </div>
            </div>
            {status_badge(p["status"])}
          </div>
          <p class="text-sm text-muted" style="margin:0;">{p["desc"]}</p>
          {warn}
          <div class="progress"><div class="progress__fill" style="width:{p["pct"]}%"></div></div>
          <div class="project-card__stats">
            <div>
              <div class="project-card__stat-label">Progress</div>
              <div class="project-card__stat-value">{p["pct"]}%</div>
            </div>
            <div>
              <div class="project-card__stat-label">Pending</div>
              <div class="project-card__stat-value">{p["pending"]}</div>
            </div>
          </div>
          <div class="project-card__foot">
            <a class="btn btn--sm btn--primary" href="project-detail.html"><i class="fas fa-eye"></i> Open</a>
            <a class="btn btn--sm btn--secondary" href="project-form.html"><i class="fas fa-pen"></i> Edit</a>
            <button class="btn btn--sm btn--ghost btn--icon" type="button" title="Delete project"
                    data-modal-open="delete-project"><i class="fas fa-trash"></i></button>
          </div>
        </div>""")

    body = f"""        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-folder-open"></i> Projects</h1>
            <p class="page-header__sub">{len(projects)} projects &middot; {sum(1 for p in projects if p["status"] == "completed")} completed</p>
          </div>
          <div class="page-actions">
            <a class="btn btn--primary" href="project-form.html"><i class="fas fa-plus"></i> New Project</a>
          </div>
        </div>

        <div class="filter-bar" data-filter-form>
          <div class="field">
            <label for="search">Search</label>
            <input class="input" type="search" id="search" placeholder="Project name or description&hellip;">
          </div>
          <div class="field">
            <label for="status_filter">Status</label>
            <select class="select" id="status_filter">
              <option value="all">All Status</option>
              <option value="not_started">Not Started</option>
              <option value="in_progress">In Progress</option>
              <option value="completed">Completed</option>
              <option value="on_hold">On Hold</option>
            </select>
          </div>
          <div class="filter-bar__actions">
            <button class="btn btn--primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
            <button class="btn btn--secondary" type="button" data-filter-reset>Reset</button>
          </div>
        </div>

        <div class="project-grid">
{chr(10).join(cards)}
        </div>

        <div class="modal-backdrop" id="delete-project" hidden>
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="del-title">
            <div class="modal__header">
              <div>
                <h3 id="del-title">Delete project</h3>
                <p>This permanently removes the project, its tasks, subtasks and project assignments.</p>
              </div>
              <button class="modal__close" type="button" data-modal-close aria-label="Close">
                <i class="fas fa-xmark"></i>
              </button>
            </div>
            <div class="modal__body">
              <div class="alert alert--danger">
                <i class="fas fa-triangle-exclamation"></i>
                <div class="alert__body">
                  <div class="alert__title">This action cannot be undone</div>
                  Type the project code <strong>HRP-2026-014</strong> to confirm.
                </div>
              </div>
              <div class="field">
                <label for="delete-project-confirmation">Project Code <span class="req" aria-hidden="true">*</span></label>
                <input class="input text-mono" type="text" id="delete-project-confirmation"
                       name="delete-project-confirmation" placeholder="HRP-2026-014" required>
              </div>
            </div>
            <div class="modal__footer">
              <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
              <button class="btn btn--danger" type="button" data-toast="Demo only &mdash; project not deleted" data-toast-kind="danger">
                <i class="fas fa-trash"></i> Delete Project
              </button>
            </div>
          </div>
        </div>"""

    return page("Projects", body, active="projects.html")


# ===========================================================================
# 9. PROJECT DETAIL
# ===========================================================================
def screen_project_detail():
    tasks = [
        ("Site survey &amp; structural assessment", "completed", "100%", "Zongbao M.", ""),
        ("Demolition &amp; site clearance", "completed", "100%", "Somchai P.", ""),
        ("MEP rough-in", "in_progress", "55%", "Nok Chantarak", ""),
        ("Structural steel erection", "in_progress", "30%", "Anan S.", ""),
        ("Permit renewal", "on_hold", "0%", "Zongbao M.",
         reason_chip("on-hold", "fas fa-pause", "On hold",
                     "Building permit renewal awaiting municipal authority scheduling.")),
        ("Facade glazing install", "not_started", "0%", "Somchai P.", ""),
    ]
    task_rows = []
    for name, st, pct, who, extra in tasks:
        task_rows.append(f"""              <tr>
                <td><a href="task-detail.html"><strong>{name}</strong></a></td>
                <td>{status_badge(st)}</td>
                <td style="min-width:120px;">
                  <div class="progress"><div class="progress__fill" style="width:{pct.rstrip('%')}"></div></div>
                </td>
                <td class="text-sm nowrap">{who}</td>
                <td class="text-sm nowrap">{pct}</td>
              </tr>""")

    contacts = [
        ("Surasak Wongchai", "Site Manager", "Harbour Point Co., Ltd.", "sw@example.com", "+66 81 234 5678"),
        ("Ploy Sirichai", "Project Architect", "Arcline Design", "ploy@example.com", "+66 82 345 6789"),
        ("Anan Suwan", "Contractor Lead", "Siam Steel Works", "anan@example.com", "+66 83 456 7890"),
    ]
    contact_rows = []
    for name, pos, comp, mail, tel in contacts:
        contact_rows.append(f"""              <tr>
                <td><a href="contact-detail.html"><strong>{name}</strong></a></td>
                <td class="text-sm text-muted">{pos}</td>
                <td class="text-sm text-muted">{comp}</td>
                <td><a href="mailto:{mail}"><i class="fas fa-envelope"></i> {mail}</a></td>
                <td class="text-sm nowrap"><a href="tel:{tel.replace(' ','')}"><i class="fas fa-phone"></i> {tel}</a></td>
              </tr>""")

    body = f"""        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="dashboard.html">Dashboard</a>
          <span aria-hidden="true">/</span>
          <a href="projects.html">Projects</a>
          <span aria-hidden="true">/</span>
          <span>Harbour Point Renovation</span>
        </nav>

        <div class="page-header">
          <div class="page-header__text">
            <h1><i class="fas fa-folder-open"></i> Harbour Point Renovation</h1>
            <p class="page-header__sub">Full interior renovation of a 4-storey heritage building.</p>
            <div class="chip-row" style="margin-top:.5rem;">
              {status_badge("in_progress")}
              <span class="badge badge--outline">Code HRP-2026-014</span>
              <span class="badge badge--outline"><i class="fas fa-calendar"></i> Due Nov 30, 2026</span>
            </div>
          </div>
          <div class="page-actions">
            <a class="btn btn--secondary" href="project-form.html"><i class="fas fa-pen"></i> Edit Project</a>
            <a class="btn btn--primary" href="task-form.html"><i class="fas fa-plus"></i> Add Task</a>
          </div>
        </div>

        <div class="grid-sidebar">
          <div>
            <div class="card">
              <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-share-nodes"></i> Client share code</h2>
                <div class="card__actions">
                  <button class="btn btn--sm btn--secondary" type="button" data-copy="HRP-2026-014">
                    <i class="far fa-copy"></i> Copy code
                  </button>
                </div>
              </div>
              <div class="card__body">
                <div class="share-box">
                  <code id="shareCode">HRP-2026-014</code>
                  <button class="btn btn--sm btn--secondary" type="button" data-copy="https://project.lorwongam.com/?code=HRP-2026-014">
                    <i class="far fa-copy"></i> Copy link
                  </button>
                </div>
                <p class="text-xs text-muted" style="margin:.75rem 0 0;">
                  <i class="fas fa-globe"></i>
                  Client view: <a href="client-dashboard.html" class="text-mono">https://project.lorwongam.com/?code=HRP-2026-014</a>
                </p>
                <div class="alert alert--warning" style="margin-top:1rem;margin-bottom:0;">
                  <i class="fas fa-triangle-exclamation"></i>
                  <div class="alert__body">
                    <div class="alert__title">Rotating the code invalidates the current link</div>
                    Anyone holding the old code will lose access immediately.
                  </div>
                </div>
                <button class="btn btn--secondary btn--sm" type="button"
                        data-toast="Demo only &mdash; code not rotated" data-toast-kind="warning"
                        style="margin-top:.75rem;">
                  <i class="fas fa-rotate"></i> Rotate share code
                </button>
              </div>
            </div>

            <div class="card">
              <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-list-check"></i> Project Tasks</h2>
                <div class="card__actions">
                  <a class="btn btn--sm btn--ghost" href="tasks.html">View all <i class="fas fa-arrow-right"></i></a>
                </div>
              </div>
              <div class="card__body card__body--flush">
                <div class="table-wrap">
                  <table class="table">
                    <thead>
                      <tr><th>Task</th><th>Status</th><th>Progress</th><th>Owner</th><th>%</th></tr>
                    </thead>
                    <tbody>
{chr(10).join(task_rows)}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-address-book"></i> Project Contacts</h2>
                <div class="card__actions">
                  <a class="btn btn--sm btn--ghost" href="contact-form.html">Add contact <i class="fas fa-plus"></i></a>
                </div>
              </div>
              <div class="card__body card__body--flush">
                <div class="table-wrap">
                  <table class="table">
                    <thead>
                      <tr><th>Name</th><th>Position</th><th>Company</th><th>Email</th><th>Phone</th></tr>
                    </thead>
                    <tbody>
{chr(10).join(contact_rows)}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div>
            <div class="stat-grid" style="grid-template-columns:repeat(2,1fr);margin-bottom:0;">
              {stat("64.0%", "Avg Completion", "fas fa-chart-pie", "")}
              {stat("18", "Total Tasks", "fas fa-list-check", "")}
              {stat("6", "Uncompleted", "fas fa-hourglass-half", "stat--on-hold", "")}
              {stat("3", "Contacts", "fas fa-address-book", "")}
            </div>

            <div class="card">
              <div class="card__header"><h2 class="card__title-icon"><i class="fas fa-sitemap"></i> Project details</h2></div>
              <div class="card__body">
                <div class="detail-list">
                  <div class="detail-row"><span class="detail-row__k">Status</span><span class="detail-row__v">{status_badge("in_progress")}</span></div>
                  <div class="detail-row"><span class="detail-row__k">Scope</span><span class="detail-row__v">Structural, MEP, finishes and fit-out of levels 1&ndash;4 plus rooftop works.</span></div>
                  <div class="detail-row"><span class="detail-row__k">Budget</span><span class="detail-row__v">THB 4,850,000.00</span></div>
                  <div class="detail-row"><span class="detail-row__k">Start date</span><span class="detail-row__v">Aug 1, 2026</span></div>
                  <div class="detail-row"><span class="detail-row__k">Expected completion</span><span class="detail-row__v">Nov 30, 2026</span></div>
                  <div class="detail-row"><span class="detail-row__k">Completion date</span><span class="detail-row__v text-muted">&mdash; Not set</span></div>
                </div>
              </div>
            </div>
          </div>
        </div>"""

    return page("Project Detail", body, active="projects.html")
