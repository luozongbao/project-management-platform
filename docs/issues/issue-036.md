# Issue-036 — Ops: serve the v2.0 design mockup on `http://localhost:8080`

> **Status:** ready for ops pickup.
> **Target release:** v2.0 (dev-only convenience; ships with the design work).
> **Severity:** **n/a** — additive infrastructure, no application impact.
>
> **Requested by the user:**
> > "also write issue file(s) for op to update docker compose file that allow me
> > to browse the mockup, maybe use some other port like 8080 for mockup."
>
> **Depends on:** `docs/issues/issue-035.md` (the v2.0 design spec) and the
> mockup under `docs/design/2.0/`.
>
> **Upstream context:** the existing `web` service blocks `/docs` at the nginx
> layer (`docker/nginx.conf` → `location ~* ^/(?:includes|database|docs|vendor|docker)`),
> so the mockup **cannot** be served by the application container. It needs its
> own static nginx service on a separate port.

---

## Table of contents

1. Objective
2. Constraints
3. Target architecture
4. Changes — `docker-compose.yml`
5. Changes — `docker/mockup-nginx.conf` (new file)
6. Changes — `.env` and `.env.example`
7. Changes — `README.md`
8. Verification
9. Operating the mockup
10. Rollback
11. Security notes
12. Out of scope

---

## 1. Objective

> Provide a **zero-risk, zero-application-impact** way to browse the v2.0
> front-end mockup from a browser on the developer's machine, at
> **`http://localhost:8080/`**, using the existing Docker toolchain.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `docker compose up -d mockup` starts a static server on port 8080 |
| 2 | `http://localhost:8080/` serves the mockup index |
| 3 | All 18 screens + 2 reference sheets are reachable by relative link |
| 4 | `docs/issues/issue-035.md` is reachable from the mockup's "Traceability Matrix" link |
| 5 | The application stack (`app`, `web`, `db`) is **not** started or modified by `docker compose up -d mockup` |
| 6 | `docker compose up -d` (the normal command) is **unchanged** — the mockup does not start |
| 7 | `docker compose down` (no args) does **not** leave a stale container |
| 8 | The service is removable with a single documented command |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| The app's nginx blocks `/docs` | The mockup **must** be a separate container with its own config |
| The mockup is pure static HTML/CSS/JS | No PHP, no DB, no app dependency |
| `docs/design/2.0` contains a `_build/` Python generator | The generator sources must **not** be served as downloadables |
| `docs/issues/` contains internal issue text | Expose **only** `issue-035.md`, not the whole `docs/` tree |
| Port 80 is taken by `APP_PORT` | The mockup uses **8080** |
| The mockup loads Font Awesome 6.0.0 from a CDN | Internet access is required for icons; the layout works without it |
| The developer may already have a service on 8080 | `MOCKUP_PORT` must be overridable |

---

## 3. Target architecture

```
┌──────────────────────────────────────────────────────────────┐
│  host                                                       │
│                                                              │
│  :80   ──►  web  (nginx:alpine)  ──►  app (php-fpm) ──► db   │
│                serves the live application                   │
│                                                              │
│  :8080 ──►  mockup (nginx:alpine, static)                    │
│                serves docs/design/2.0/*.html                 │
│                                                              │
└──────────────────────────────────────────────────────────────┘

docker network: pmp-net   (the mockup joins it but needs no peers)
```

The `mockup` service is **independent**: it declares no `depends_on`, and it
must never be added as a dependency of `app`, `web` or `db`.

---

## 4. Changes — `docker-compose.yml`

Add one service. **Do not modify the existing `app`, `web` or `db` services.**

```yaml
  # ---- v2.0 design mockup (dev only) ----------------------------------
  # Serves docs/design/2.0/*.html as static files.
  # It is INDEPENDENT of the application stack: no depends_on, no DB,
  # no PHP. It is behind a compose profile so a plain `docker compose up -d`
  # does not start it.
  #
  #   docker compose up -d mockup     # then open http://localhost:8080/
  #   docker compose down mockup      # stop and remove it
  #
  # See docs/issues/issue-036.md
  mockup:
    image: nginx:alpine
    container_name: pmp-mockup
    restart: unless-stopped
    profiles:
      - mockup
    ports:
      - "${MOCKUP_PORT:-8080}:80"
    volumes:
      # The mockup's index.html links to ../../issues/issue-035.md.
      # Mounting the two directories at matching depth keeps that relative
      # link valid while exposing nothing else from docs/.
      - ./docs/design/2.0:/usr/share/nginx/html/design/2.0:ro
      - ./docs/issues:/usr/share/nginx/html/issues:ro
      - ./docker/mockup-nginx.conf:/etc/nginx/conf.d/default.conf:ro
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1/health"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 5s
    networks:
      - pmp-net
```

### 4.1 Why the two-volume mount

| Option | Result |
|---|---|
| Mount `./docs/design/2.0` → `/usr/share/nginx/html` | `../../issues/issue-035.md` resolves **outside** the document root → **404**. Broken link. ❌ |
| Mount `./docs` → `/usr/share/nginx/html` | Works, but exposes `docs/requirements.md`, `docs/issues/issue-001…036.md`, `docs/issues/**` publicly. ⚠️ |
| **Mount `./docs/design/2.0` → `/usr/share/nginx/html/design/2.0` and `./docs/issues` → `/usr/share/nginx/html/issues`** | The relative link `../../issues/issue-035.md` from `/design/2.0/index.html` resolves to `/issues/issue-035.md` → **200 OK**. Only the two intended trees are exposed. ✅ **Use this.** |

The document root stays `/usr/share/nginx/html/design/2.0`, so `index.html`
is the landing page and every sibling-relative link (`dashboard.html`,
`v2-tokens.css`, `v2-mockup.js`, …) resolves unchanged.

### 4.2 About `profiles`

- `docker compose up -d mockup` — **starts only the mockup**. Compose enables
  the profile of a service that is named explicitly on the command line, so
  this works even with `profiles: ["mockup"]` present. The app stack is not
  started.
- `docker compose up -d` — **does not start the mockup**. The normal developer
  workflow is unchanged.
- `docker compose --profile mockup up -d` — starts the mockup *together with*
  the full stack. Supported, but rarely wanted.

> Compose **v2.20+** is required for `profiles`. The repo's installed version
> is Compose **v5.5.1**, so this is satisfied. If a developer's machine is
> on an older Compose, either upgrade or drop the `profiles:` block (the
> service still works; it will simply also start with a bare `up -d`).

### 4.3 `docker compose down` behaviour

`docker compose down` removes **all** containers for the project, including
the mockup — no orphan is left behind. `docker compose down mockup` removes
just the mockup. `docker compose stop mockup` stops it but keeps the
container.

---

## 5. Changes — `docker/mockup-nginx.conf` (new file)

Create `docker/mockup-nginx.conf`:

```nginx
# Static nginx configuration for the v2.0 design mockup (issue-036).
#
# Serves docs/design/2.0/*.html on http://localhost:${MOCKUP_PORT:-8080}/.
# It is a documentation viewer only: no PHP, no database, no application
# code, and no access to the rest of the repository.
#
# It is deliberately SEPARATE from docker/nginx.conf (the application):
#   - the application config blocks /docs by design
#   - this config serves exactly two mounted directories
#   - this config never passes anything to a PHP-FPM upstream

server {
    listen 80 default_server;
    server_name _;

    root /usr/share/nginx/html/design/2.0;
    index index.html;

    charset utf-8;

    # ---- Health endpoint (used by the compose healthcheck) ----
    location = /health {
        access_log off;
        add_header Content-Type text/plain;
        return 200 "ok\n";
    }

    # ---- Serve the issue file the mockup links to ----
    # The mockup's index.html references ../../issues/issue-035.md,
    # which resolves to /issues/issue-035.md under this document root.
    location /issues/ {
        root /usr/share/nginx/html;
        types {
            text/markdown md;
        }
        default_type text/markdown;
        add_header Content-Disposition "inline";
        add_header X-Content-Type-Options "nosniff" always;
    }

    # ---- The mockup itself ----
    location / {
        try_files $uri $uri/ =404;
    }

    # ---- Never serve the generator sources ----
    location ~* ^/_build/ {
        return 404;
    }
    location ~* \.(?:py|pyc|pyo|bak|swp|tmp|dist|log)$ {
        return 404;
    }

    # ---- Only the assets the mockup actually uses ----
    location ~* \.(?:css|js|svg|png|jpe?g|gif|webp|ico|woff2?|ttf)$ {
        expires 1h;
        access_log off;
        add_header X-Content-Type-Options "nosniff" always;
    }

    # ---- Baseline security headers ----
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
}
```

Notes for ops:

- `location ~* ^/_build/` — the Python generator lives inside the mounted
  directory. It is blocked explicitly.
- The `.md` type mapping is needed because the default `mime.types` in
  `nginx:alpine` does not always map `.md`, and the browser should render the
  traceability matrix as text rather than download it.
- `try_files ... =404` — the mockup has no SPA fallback. A missing file must
  be a clean 404, not the index page.
- **Do not** add `fastcgi_pass` or `index.php` to this file.

---

## 6. Changes — `.env` and `.env.example`

### 6.1 `.env.example`

Append this block (the file currently ends with the SMTP section):

```dotenv
# ---- v2.0 design mockup (dev only, see docs/issues/issue-036.md) ----
# Host port that maps to the static mockup container. The application uses
# APP_PORT (default 80); the mockup uses a different port so both can run
# at the same time.
#
#   docker compose up -d mockup   ->  http://localhost:${MOCKUP_PORT}/
MOCKUP_PORT=8080
```

### 6.2 `.env`

Add the same key to the local `.env` (it is git-ignored):

```dotenv
MOCKUP_PORT=8080
```

> `.env` is in `.gitignore`, so it is never committed. `.env.example` **is**
> committed and must be updated so the next developer inherits the variable.

---

## 7. Changes — `README.md`

Add a section to the README, next to the existing Docker instructions:

```markdown
### Browsing the v2.0 design mockup

The v2.0 front-end mockup lives in `docs/design/2.0/`. It is a set of
standalone HTML files — no PHP, no database, no application code.

```bash
docker compose up -d mockup
# open http://localhost:8080/
```

| Command | Effect |
|---|---|
| `docker compose up -d mockup` | Start the mockup only (the app stack is untouched) |
| `docker compose stop mockup` | Stop it, keep the container |
| `docker compose down mockup` | Stop and remove it |
| `docker compose --profile mockup up -d` | Start it together with the full stack |

Change the host port with `MOCKUP_PORT` in `.env` (default `8080`).

Without Docker:

```bash
cd docs/design/2.0 && python3 -m http.server 8080
```

See `docs/issues/issue-035.md` for the full design specification and
`docs/issues/issue-036.md` for the ops notes.
```

---

## 8. Verification

Ops must run the following and record the output.

### 8.1 Config validity

```bash
cd /home/zongbao/projects/project-management-platform
docker compose config --services
# expected: app, web, db, mockup

docker compose config > /dev/null && echo "compose config OK"
```

### 8.2 Start and health

```bash
docker compose up -d mockup
docker compose ps mockup
# expect: STATUS = healthy (after ~15s)

curl -fsS http://localhost:8080/health
# expect: ok
```

### 8.3 Every screen resolves

```bash
for f in index landing login register forgot-password reset-password \
         dashboard projects project-detail project-form tasks task-detail \
         task-form contacts contact-detail contact-form profile \
         client-dashboard components docs-design-notes; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8080/$f.html")
  printf '%-22s %s\n' "$f.html" "$code"
done
```

Expected: **every row reports `200`.**

### 8.4 Assets and the issue link

```bash
curl -s -o /dev/null -w 'tokens.css   %{http_code}\n' http://localhost:8080/v2-tokens.css
curl -s -o /dev/null -w 'mockup.js    %{http_code}\n' http://localhost:8080/v2-mockup.js
curl -s -o /dev/null -w 'issue-035    %{http_code}\n' http://localhost:8080/issues/issue-035.md
```

Expected: `200`, `200`, `200`.

### 8.5 The generator must NOT be served

```bash
curl -s -o /dev/null -w '_build/main.py %{http_code}\n' http://localhost:8080/_build/main.py
```

Expected: **404**.

### 8.6 The application stack is unaffected

```bash
docker compose ps app web db
# the mockup command must not have started or restarted these
```

### 8.7 Nothing outside the mockup is exposed

```bash
curl -s -o /dev/null -w 'docs root     %{http_code}\n' http://localhost:8080/requirements.md
curl -s -o /dev/null -w 'issue-034    %{http_code}\n' http://localhost:8080/issues/issue-034.md
curl -s -o /dev/null -w 'app root      %{http_code}\n' http://localhost:8080/index.php
```

Expected: `404`, `404`, `404`.

> If `issue-034.md` returns `200`, the whole `docs/issues` directory is exposed.
> That is acceptable from a functional standpoint (the mockup container is
> localhost-only and holds no secrets), but **note it in the ops log** rather
> than adding a per-file allowlist — a per-file rule is brittle because the
> issue number changes every sprint.

### 8.8 Visual smoke test

Open `http://localhost:8080/` and confirm:

- [ ] The index shows 18 screen cards + 2 reference sheets.
- [ ] Clicking a card opens the screen with the sidebar and topbar.
- [ ] `components.html` renders the colour swatches and badges.
- [ ] The task form's "On Hold" selection reveals the on-hold reason textarea.
- [ ] The client portal's 中文 switch translates the labels.
- [ ] The delay chip is red and the on-hold chip is orange on both
      `task-detail.html` and `client-dashboard.html`.

---

## 9. Operating the mockup

| Task | Command |
|---|---|
| Start | `docker compose up -d mockup` |
| Stop (keep container) | `docker compose stop mockup` |
| Stop + remove | `docker compose down mockup` |
| Restart after a code change | `docker compose restart mockup` |
| Logs | `docker compose logs -f mockup` |
| Shell into it | `docker compose exec mockup sh` |
| Change the port | edit `MOCKUP_PORT` in `.env`, then `docker compose up -d mockup` |
| Regenerate the mockup | `python3 docs/design/2.0/_build/main.py` (the volume is live — just reload the browser) |

> The bind mount is live, so **regenerating the mockup requires no container
> restart** — refresh the browser.

### 9.1 Conflict handling

If port 8080 is already taken:

```bash
MOCKUP_PORT=8081 docker compose up -d mockup
```

or set `MOCKUP_PORT=8081` in `.env` permanently.

---

## 10. Rollback

The change is fully additive. Rollback options, in order of preference:

| Option | Action |
|---|---|
| **A — just stop it** | `docker compose down mockup` (no file changes) |
| **B — remove the service** | Delete the `mockup:` block from `docker-compose.yml`, delete `docker/mockup-nginx.conf`, remove the `MOCKUP_PORT` lines from `.env` / `.env.example`, and remove the README section |
| **C — revert the commit** | `git revert <v2.0-ops-commit>` — the pre-existing `docker-compose.yml` is restored byte-for-byte |

**No data rollback is required.** The mockup service holds no volumes, no
database, and no state. The application's `pmp-db-data` volume is untouched.

Post-rollback verification:

```bash
docker compose config --services   # expect: app, web, db
docker compose up -d               # the app must still start normally
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:${APP_PORT}/login.php
```

---

## 11. Security notes

| Concern | Mitigation |
|---|---|
| The mockup is a static copy of internal UI | It contains **no real data** — every name, email, phone and amount in the mockup is fabricated sample data. Confirm this before sharing the port with anyone. |
| It exposes `docs/issues/*.md` | The container is bound to the host and holds no secrets. Internal issue text is exposed to anyone who can reach the host's port 8080. |
| It must not execute PHP | The config has no `fastcgi_pass`; `.php` files are not reachable because they do not exist in the mounted trees. |
| It must not reach the database | The service has no `depends_on`, no DB env vars, and no network path to `db` in practice. |
| It must not be deployed publicly | The service must **not** be added to the production compose file on Hostinger/cPanel. v2.0 mockup hosting is a **local developer** facility only. |

> **Explicitly:** the mockup service is **not** part of the production
> deployment. `docker-compose.yml` in this repo is the development stack.
> Production runs Apache on Hostinger/cPanel and is unaffected by this issue.

---

## 12. Out of scope

- Publishing the mockup to a public URL or a static-hosting provider.
- Adding the mockup to the production server.
- HTTPS/TLS termination for the mockup port (localhost only).
- Authenticating access to the mockup.
- A watch/rebuild loop — the generator is run manually (§9).
- Rewriting `docker/nginx.conf` — the application config is **not** modified.
- Any change to the `app`, `web` or `db` services.

---

## Cross-references

| Document | Relevance |
|---|---|
| `docs/issues/issue-035.md` | The v2.0 design specification and traceability matrix (served by this service) |
| `docs/design/2.0/index.html` | The mockup gallery — the entry point |
| `docs/design/2.0/docs-design-notes.html` | Implementation notes for the dev |
| `docker/nginx.conf` | The **application** config — must not be modified by this issue |
| `docker-compose.yml` | The file this issue adds one service to |
| `README.md` | The file this issue adds a section to |
