# ROUTLAW → Docker, Isolated from aiwebscapes + mokimi — Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task (2-stage review per task).

**Goal:** Containerize the ROUTLAW PHP 8.3 freight/carrier platform with Docker (Apache mod_php, MariaDB) in its own Compose project so it runs cleanly on this host *and* still deploys to Hostinger unchanged — with **zero** bleed-over to/from the existing aiwebscapes stack AND the mokimi Docker project.

**Architecture:** Own directory + own Compose project name ⇒ Docker auto-creates a private bridge network and prefixed containers/volumes (isolation is free, no shared `external:` resources). Use the official `php:8.3-apache` image. Routlaw's docroot is `public/` (unlike mokimi's repo root) — `COPY . /var/www/html/` then set `APACHE_DOCUMENT_ROOT=/var/www/html/public`. MariaDB matches Hostinger's engine. Dev `.env` at repo root supplies `RL_*` env so `config/autoload.php`'s loader resolves it. Production stays git → hPanel upload → hPanel env vars. `vendor/` is gitignored for Routlaw (unlike mokimi) → Dockerfile MUST run `composer install` in-image.

**Tech Stack:** Docker + Compose v2, `php:8.3-apache` (Debian bookworm), `mariadb:11`, Composer (build stage), the existing Routlaw codebase at `C:\xampp\htdocs\Routlaw`. Hostinger/LiteSpeed + Cloudflare + Apple iCloud SMTP remain the public-deploy path.

**Sources (official, recent):**
- Docker Build Best Practices — `https://docs.docker.com/build/building/best-practices/`
- Docker Compose Production — `https://docs.docker.com/compose/how-tos/production/`
- Dockerfile reference — `https://docs.docker.com/reference/dockerfile/`
- Docker PHP Official Image README — `https://github.com/docker-library/docs/tree/master/php`
- Docker Compose Startup Order — `https://docs.docker.com/compose/how-tos/startup-order/`
- Verified aiwebscapes + mokimi isolation facts + port map (see `references/compose-project-isolation.md` in `docker-compose-dev-stacks` skill)
- ROUTLAW build conventions — `references/routlaw.md` in `php-dev-stack` skill (verified this session)

---

## Current context / assumptions (verified, not guessed)

- Routlaw lives at `C:\xampp\htdocs\Routlaw`; runs on XAMPP today. `vendor/` is **gitignored** (unlike mokimi) → must build deps in-image. composer.json requires **PHP >=8.3** + `phpmailer/phpmailer ^6.9`; dev: `phpunit ^11.5`, `phpstan ^1.11`.
- Config reads env via `config/autoload.php`: `RL_DB_HOST`, `RL_DB_USER`, `RL_DB_PASS`, `RL_DB_NAME='routlaw'`, `RL_DB_PORT='3306'`. Uses `Routlaw\Db\Connection::connect()` (mysqli, utf8mb4). Uses `sodium` extension (`sodium_bin2hex`, `random_bytes` in DocumentService + TokenStore).
- aiwebscapes occupies ports: `3307` (db), `5678` (n8n/ollama-relay), `6333` (qdrant), `8000` (ai-memory), `11434` (ollama, internal). **mokimi occupies `8080`** (Docker app). Routlaw must avoid these. Use **`8081`** for HTTP.
- Existing Routlaw XAMPP vhost binds `:8080` (conflicts with mokimi Docker) — Docker uses `:8081` to avoid collision.
- Host has no host PHP/Composer we rely on — build/run everything in containers (per `php-docker-tested-build` skill, Windows MSYS).

## Proposed approach

1. Add four files to the repo: `Dockerfile`, `.dockerignore`, `compose.yaml`, `compose.production.yaml`. None touch XAMPP, the live aiwebscapes/mokimi stack, or Hostinger deploy.
2. `php:8.3-apache` base, `COPY . /var/www/html/`, install `mysqli` + `sodium` + `intl`/`zip` for Composer, enable `opcache`, set `DOCUMENT_ROOT=/var/www/html/public`.
3. Composer `install` in-image (vendor gitignored) — never committed.
4. `mariadb:11` service with healthcheck; `app` uses `depends_on: db: condition: service_healthy`. `restart: unless-stopped` on both.
5. `.env` (gitignored) at repo root supplies `RL_DB_HOST=db`, `RL_DB_USER`, `RL_DB_PASS`, `RL_DB_NAME=routlaw`. `config/autoload.php` finds it.
6. Pin the `php`/`mariadb` base images **by digest** (resolve at apply time), per Docker best-practice; tags alone float.
7. Cloudflare/Hostinger untouched: those are DNS/edge concerns; Docker is local dev only.

## Step-by-step plan

### Task 1: Add `.dockerignore` (prevent vendor/.git/.env from bloating build ctx)
**Source:** Docker Build Best Practices — "Exclude with .dockerignore" — `https://docs.docker.com/build/building/best-practices/#exclude-with-dockerignore`
> "To exclude files not relevant to the build, without restructuring your source repository, use a `.dockerignore` file."

**Objective:** Keep build context lean and avoid shipping host secrets into the image.
**Files:** Create `C:\xampp\htdocs\Routlaw\.dockerignore`
**Step 1:** Write `.dockerignore`:
```
.git
.github
.hermes
vendor
config/secrets.local.php
.env
.env.local
node_modules
.phpunit.result.cache
.phpunit.cache
__pycache__
composer.phar
*.cache
```
**Step 2:** Sanity check it excludes `config/secrets.local.php` and `.env` (they must NOT be `COPY`d into the image; the image gets `.env` at runtime via compose `env_file` or a non-secret compose `environment`).
**Step 3:** Commit: `git add .dockerignore && git commit -m "chore: add .dockerignore for Docker build"`

### Task 2: Author `Dockerfile` (official `php:8.3-apache`, pinned digest)
**Sources:**
- Docker PHP Official Image README — `https://github.com/docker-library/docs/tree/master/php` — apache variant: `COPY src/ /var/www/html/`
- Docker Build Best Practices — "Choose the right base image" — "Whenever possible, use current official images as the basis for your images."
- Docker Build Best Practices — "Pin base image versions" — pin by digest for reproducibility
- Dockerfile reference — "WORKDIR" — "For clarity and reliability, you should always use absolute paths for your WORKDIR"
- PHP Official Image README — "It is strongly recommended to use the production config for images used in production environments" + "In many production environments, it is also recommended to (build and) enable the PHP core OPcache extension for performance"

**Objective:** Reproducible PHP 8.3 Apache image with required exts (`mysqli`, `sodium`) + opcache, Routlaw at `public/` docroot.
**Files:** Create `C:\xampp\htdocs\Routlaw\Dockerfile`
**Step 1:** Resolve the digest first (run, record the sha256):
```bash
docker pull php:8.3-apache-bookworm && docker inspect --format='{{index .RepoDigests 0}}' php:8.3-apache-bookworm
```
**Step 2:** Write `Dockerfile` (replace `<PHP_DIGEST>` with the resolved sha256):
```dockerfile
# syntax=docker/dockerfile:1
# ROUTLAW — PHP 8.3 Apache image, pinned to a digest for reproducibility.
# Docroot is public/ (not repo root).
FROM php:8.3-apache-bookworm@sha256:<PHP_DIGEST>

# Build-time tooling for Composer.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip curl \
      libicu-dev libzip-dev \
      libsodium-dev \
  && rm -rf /var/lib/apt/lists/*

# Extensions: mysqli (DB), sodium (DocumentService/TokenStore), intl/zip for Composer, opcache for prod.
RUN docker-php-source extract \
  && docker-php-ext-install -j"$(nproc)" \
      mysqli sodium intl zip opcache \
  && docker-php-source delete

# Apache: Routlaw's docroot is public/.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
  && a2enmod rewrite

# Composer (build helper).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# vendor/ is gitignored; build dependencies inside the image.
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 80
```
**Step 3:** Confirm `a2enmod rewrite` is present (honor `.htaccess` if it exists).
**Step 4:** Commit: `git add Dockerfile && git commit -m "feat: add Dockerfile (php:8.3-apache, pinned, public/ docroot)"`

### Task 3: Author `compose.yaml` (own project, isolated, MariaDB, restart policy)
**Sources:**
- Docker Compose Production — "Modify your Compose file for production" — "Specifying a restart policy like restart: always to avoid downtime"
- Docker Compose Startup Order — "Control startup order" — "Compose waits only until a container is 'running', not 'ready' → use condition: service_healthy with a healthcheck"
- Docker Compose Startup Order — "service_healthy specifies that a dependency is expected to be 'healthy'... before starting a dependent service"
- Docker Compose reference — "depends_on" — `https://docs.docker.com/compose/how-tos/startup-order/`

**Objective:** Stand up Routlaw + MariaDB as a self-contained Compose project, no aiwebscapes/mokimi links, free host port 8081.
**Files:** Create `C:\xampp\htdocs\Routlaw\compose.yaml`
**Step 1:** Resolve MariaDB digest:
```bash
docker pull mariadb:11 && docker inspect --format='{{index .RepoDigests 0}}' mariadb:11
```
**Step 2:** Write `compose.yaml` (replace `<MARIADB_DIGEST>`):
```yaml
services:
  app:
    build: .
    ports:
      - "8081:80"            # host 8081 free (mokimi uses 8080; aiwebscapes uses 3307/5678/6333/8000/11434)
    environment:
      RL_DB_HOST: db
      RL_DB_USER: routlaw
      RL_DB_PASS: routlaw_dev
      RL_DB_NAME: routlaw
      RL_DB_PORT: "3306"
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped
    networks:
      - routlaw_net

  db:
    image: mariadb:11@sha256:<MARIADB_DIGEST>
    environment:
      MARIADB_DATABASE: routlaw
      MARIADB_USER: routlaw
      MARIADB_PASSWORD: routlaw_dev
      MARIADB_ROOT_PASSWORD: routlaw_root_dev
    volumes:
      - routlaw_db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 60s
    restart: unless-stopped
    networks:
      - routlaw_net

volumes:
  routlaw_db_data:

networks:
  routlaw_net:
    driver: bridge
```
**Step 3:** Verify **no** `external:` keys reference `aiwebscapes-*` or `mokimi-*` networks/volumes.
**Step 4:** Commit: `git add compose.yaml && git commit -m "feat: add compose.yaml (isolated routlaw stack, :8081)"`

### Task 4: Generate `.env` (gitignored) for local dev
**Source:** Docker Compose Production — "Modify your Compose file for production" — "Setting environment variables differently, such as reducing the verbosity of logging, or to specify settings for external services"

**Objective:** Supply runtime env at repo root so `autoload.php` resolves it.
**Files:** Create `C:\xampp\htdocs\Routlaw\.env` (already in `.gitignore`? confirm; add if missing)
**Step 1:** Confirm `.env` is in `.gitignore` (it currently lists `.env` + `.env.local` — confirmed).
**Step 2:** Write `.env`:
```
RL_DB_HOST=db
RL_DB_USER=routlaw
RL_DB_PASS=routlaw_dev
RL_DB_NAME=routlaw
RL_DB_PORT=3306
```
**Step 3:** Do NOT commit the file itself (gitignored). Commit only if `.gitignore` needed updating.

### Task 5: Build + bring up, verify isolation + app boot
**Source:** Docker Build Best Practices — "Rebuild your images often" — "To keep your images up-to-date and secure, rebuild your images regularly with updated dependencies."

**Objective:** Prove the stack runs and is isolated from aiwebscapes + mokimi.
**Files:** none (runtime verification)
**Step 1:** `docker compose up -d --build` (use a script wrapper if the long-lived-process heuristic balks; poll health).
**Step 2:** Wait for `db` healthy, then `docker compose ps` shows both `Up`.
**Step 3:** Verify **isolation**: `docker network inspect aiwebscapes-platform_default` must NOT list `routlaw_*` containers. `docker network inspect mokimi_mokimi_net` must NOT list `routlaw_*` containers. `docker network ls` shows a distinct `routlaw_routlaw_net`.
**Step 4:** Smoke test from host: `curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8081/` → expect `200`.
**Step 5:** Confirm no port collision: `docker ps --format '{{.Names}}\t{{.Ports}}'` — routlaw on `8081` only.
**Step 6:** Commit: stage explicit paths only; likely nothing new to commit (runtime state).

### Task 6: Import XAMPP Routlaw DB data into Docker MariaDB
**Source:** Docker Compose Production — "Deploying changes" — "When you make changes to your app code, remember to rebuild your image and recreate the app's containers."

**Objective:** Bring existing Routlaw content (tenants, proposals, memory, etc.) into the container DB.
**Files:** none (data import)
**Step 1:** `mysqldump` the XAMPP `routlaw` DB, pipe into the Docker MariaDB `db` container:
```bash
"C:/xampp/mysql/bin/mysqldump.exe" -h 127.0.0.1 -u root --single-transaction --routines --no-tablespaces routlaw 2>/dev/null \
  | docker exec -i routlaw-db-1 mariadb -u root -prootlaw_root_dev routlaw 2>&1
```
**Step 2:** Verify row counts in Docker:
```bash
docker exec -i routlaw-db-1 mariadb -u root -prootlaw_root_dev routlaw -e "SHOW TABLES; SELECT COUNT(*) AS tables_count FROM information_schema.tables WHERE table_schema='routlaw';"
```
**Step 3:** Verify app connects via config (`RL_DB_HOST=db`):
```bash
docker compose exec -T app php -r '
require "config/autoload.php";
$m = Routlaw\Db\Connection::connect($_ENV["RL_DB_NAME"]);
echo "connected, charset=".$m->character_set_name()."\n";
'
```
**Step 4:** No commit (data import only).

### Task 7: Production override file (optional, follow Docker prod guidance)
**Source:** Docker Compose Production — "Modify your Compose file for production" — "consider defining an additional Compose file, for example compose.production.yaml, with production-specific configuration details... applied over the original compose.yaml"

**Objective:** Provide `compose.production.yaml` applying prod-only changes — for a future self-hosted Docker deploy. Hostinger deploy itself needs NONE of this.
**Files:** Create `C:\xampp\htdocs\Routlaw\compose.production.yaml`
**Step 1:** Write override:
```yaml
services:
  app:
    restart: always
    environment:
      RL_DB_HOST: ${RL_DB_HOST}
      RL_DB_USER: ${RL_DB_USER}
      RL_DB_PASS: ${RL_DB_PASS}
      RL_DB_NAME: ${RL_DB_NAME}
      RL_DB_PORT: ${RL_DB_PORT:-3306}
  db:
    restart: always
    environment:
      MARIADB_ROOT_PASSWORD: ${MARIADB_ROOT_PASSWORD}
```
**Step 2:** Document that Hostinger deploy ignores this file (it runs LiteSpeed, not Docker). Apply only if self-hosting Routlaw in Docker later: `docker compose -f compose.yaml -f compose.production.yaml up -d`.
**Step 3:** Commit: `git add compose.production.yaml && git commit -m "feat: add compose.production.yaml (Docker self-host override)"`

### Task 8: Hermes project binding (so chats don't bleed into aiwebscapes/mokimi)
**Source:** Hermes Desktop Projects skill — `hermes-desktop-projects` — "Create a real, stable Project (survives session clearing)"

**Objective:** Bind Routlaw work to its own Hermes Project, not aiwebscapes or mokimi.
**Files:** CLI (`hermes` on PATH)
**Step 1:** `hermes project create "Routlaw" "C:\xampp\htdocs\Routlaw" --use`
**Step 2:** Confirm `projects.db` `active_id` now points at the new project, and `discovered_repos`/sessions for routlaw are separate from `aiwebscapes-platform` and `mokimi`.
**Step 3:** No commit (Hermes state, not repo).

## Files likely to change
- New: `Dockerfile`, `.dockerignore`, `compose.yaml`, `compose.production.yaml`, `.env` (gitignored)

## Tests / validation
- `docker compose ps` → both `Up`, `db` `healthy`.
- Isolation: `docker network inspect aiwebscapes-platform_default` + `docker network inspect mokimi_mokimi_net` contain NO `routlaw_*`; `docker network inspect routlaw_routlaw_net` contains only routlaw containers.
- `curl http://localhost:8081/` → `200`.
- `docker exec` into `app`: `php -r 'require "config/autoload.php"; $m=Routlaw\Db\Connection::connect($_ENV["RL_DB_NAME"]); echo $m->character_set_name();'` → `utf8mb4`.
- `php -m | grep -E 'mysqli|sodium|opcache'` all present.
- No aiwebscapes/mokimi port reused; host `8081` only.

## Risks, tradeoffs, open questions
- **Docker dev ≠ Hostinger prod SAPI:** Hostinger=LSAPI, Docker=mod_php. Already covered by Routlaw's config pattern (`.user.ini` for LSAPI + `mod_php.c` block for Apache + PHP-level `ini_set` defense-in-depth).
- **Cloudflare:** never touches Docker (DNS/edge). If you later expose the Docker container publicly, you'd point Cloudflare at it — out of scope here.
- **Secret hygiene:** `.env` and `config/secrets.local.php` MUST stay gitignored and out of the image build context (`.dockerignore`).
- **Vendor build:** `composer install` runs in-image because `vendor/` is gitignored. If `composer.lock` drifts, rebuild.
- **Digest pinning:** resolves at apply time; record the sha256 in the file. Re-pin on `docker build --pull` cadence (Docker best-practice: rebuild often).
- **Tenant scoping:** the imported DB carries real tenant data — the container is dev-only, network-isolated, never exposed. No cross-tenant leakage risk from Docker itself (app enforces `company_id` scoping per FR-042).
- **Windows MSYS note:** keep everything on `C:`; use `$(pwd -W)` for any `-v` mounts; no `.sh` in build so `.gitattributes` CRLF not a concern here.

## Why this satisfies "no bleed-over" (either direction, including mokimi)
1. Own dir ⇒ own Compose project name ⇒ own `routlaw_routlaw_net` bridge + `routlaw_*` containers/volumes. **Zero** `external:` links to aiwebscapes OR mokimi.
2. Distinct host port (`8081`) — no collision with aiwebscapes (`3307/5678/6333/8000/11434`) or mokimi (`8080`).
3. Separate `.env`/secrets — never imports platform creds.
4. Hermes chat bound to its own Project (Task 8) — chat context doesn't attach to aiwebscapes or mokimi.
5. aiwebscapes + mokimi containers cannot reach routlaw's network and vice versa unless explicitly bridged (we don't).
