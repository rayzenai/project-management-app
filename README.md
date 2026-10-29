# PMOPM

Project & task tracking workspace for PMOPM (Office of the Prime Minister and
Council of Ministers) — projects → tasks → subtasks, assignments, notes,
contacts, teams, members, activity log, notifications, and the 100-point plan
tracker — with a JSON API for the companion mobile client and for any other
system that needs to read or manage tasks.

This is a **standalone application**. Everything (models, services, controllers,
authorization, Svelte UI, migrations) lives in this repo; it no longer depends on
the retired `rayzenai/project-management` Composer package.

## Tech stack

- **Backend:** Laravel 13, PHP 8.5, Sanctum (API token auth)
- **Frontend:** Inertia.js + Svelte 5, Tailwind CSS v4, Vite
- **Testing:** Pest (`tests/Feature/Workspace/**` covers the domain, `tests/Feature/Workspace/Api/**` the JSON API)
- **Quality:** Laravel Pint, PHPStan/Larastan (level 7), ESLint + Prettier + `svelte-check`

## Requirements

- PHP **8.5**
- Composer 2
- Node **22** + npm
- **PostgreSQL** — task search uses a `pg_trgm` migration; the migration no-ops
  on other drivers (the test suite runs on SQLite) but you lose fuzzy search
- **Redis** — cache, sessions, and the queue
- Docker (optional) — only to run the deployment image locally via `./dock.sh`

## Quick start

```bash
git clone git@github.com:rayzenai/project-management-app.git pmopm
cd pmopm
composer setup
```

`composer setup` runs `composer install`, copies `.env.example` to `.env`,
generates the app key, runs migrations, and installs + builds the frontend.

Manual equivalent:

```bash
composer install
cp .env.example .env
php artisan key:generate
createdb pmopm            # then set DB_* / REDIS_* in .env
php artisan migrate
php artisan db:seed       # creates the PM_SUPER_ADMINS logins
php artisan db:seed --class=SdocProjectsSeeder   # SDOC and its projects (safe to rerun)
npm install
npm run build             # or: npm run dev
```

Serve with `php artisan serve`, or `composer dev` (server + queue listener +
Vite, concurrently).

## Configuration

| Variable | Purpose |
| --- | --- |
| `PM_SUPER_ADMINS` | Comma-separated emails granted the `manage-workspace` gate (full super-admin access) |
| `PM_SUPER_ADMIN_PASSWORD` | Default password used by `WorkspaceSuperadminSeeder` |
| `PM_TRASH_TTL_DAYS` | Days a soft-deleted record survives before `workspace:prune-trashed` removes it (default `30`) |
| `PM_REMINDERS_RUN_AT` | Time of day `workspace:send-deadline-reminders` runs (default `08:00`) |
| `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` | PEM contents of the key pair that signs the forge's OIDC id_tokens (production; newlines may be written as `\n`). Leave empty locally and run `php artisan passport:keys`, which writes `storage/oauth-*.key` |

Config files worth knowing:

- `config/project-management.php` — task status workflow, super-admins, trash TTL, reminder cadence.
- `config/government.php` — the 100-point plan metadata (categories, deadline types, oath date) that drives the plan tracker and task category labels.
- `config/themes.php` — the system / light / dark token sets for web and mobile appearance settings.

## Using the app

- `/` redirects to `/workspace`.
- **Web (Inertia)** — `/workspace/*`, session auth, branded login at `/workspace/login`.
- **JSON API** — `/api/v1/*`, Sanctum bearer auth. Full reference in [`docs/api.md`](docs/api.md).

### Letting another system manage tasks

Mint a token for a service account and call the JSON API with it — no login flow
needed:

```bash
php artisan workspace:api-token bot@example.com --name=crm-sync [--expires-days=90] [--revoke]
```

See [`docs/api.md` → Integrating another system](docs/api.md#integrating-another-system).

### Console commands

| Command | Purpose |
| --- | --- |
| `workspace:api-token` | Issue / revoke an API token for an external system |
| `workspace:prune-trashed [--pretend]` | Force-delete soft-deleted rows past the trash TTL (scheduled daily) |
| `workspace:send-deadline-reminders [--pretend]` | Heads-up / due-today / overdue notifications (scheduled daily at `PM_REMINDERS_RUN_AT`) |
| `git:oidc-client {redirect_uri}` | Create the forge's OIDC client (`Forgejo`) for that callback URL and print its client id and secret. Run again to move it to a new URL: same id, new secret (the old one stops working) |
| `git:reconcile [--connection=] [--allow-removals]` | The nightly forge repair (scheduled daily at 01:30): puts the webhook back, catches linked repositories up, unlinks those the forge no longer has, keeps forge Owners and members in step. Exits non-zero when a step fails or it holds back a mass removal or unlink; `--allow-removals` lifts those breakers for one run, after checking ([`docs/forgejo.md`](docs/forgejo.md#when-the-reconcile-holds-removals-back)) |

### Signing in to the forge (OIDC)

The tracker is the OpenID Connect provider for the Forgejo forge. Point the
forge's OAuth2 source at `APP_URL/.well-known/openid-configuration` with the
client id and secret from `git:oidc-client`, and the scopes `openid`, `profile`,
`email` and `git`, each with its own `--scopes` (as in
[`docs/forgejo.md`](docs/forgejo.md), bootstrap step 5).
The id_token carries `git_orgs` (the forge organizations the person may enter)
and `groups` (`forge-admins` for super-admins). Only active people whose
office has git switched on, active people on the team of such an office's
project, and super-admins get past `/oauth/authorize`; everyone else gets a
403.

The forge itself (`git.sdoc.gov.np`: the Dokploy compose kit in
`deploy/forgejo/`, DNS, bootstrap, the OIDC source, backups and upgrades) is
in [`docs/forgejo.md`](docs/forgejo.md).

### Code from the forge (git phase 1)

Where an office has git on, a project's manager or office admin links forge
repositories from the project's **Code** tab. Commits, branches and pull
requests that name a task code (`SG-12`) then show in that task's Code
section and as a mark on its board card, and `SG-12` on the forge links back
to the task (`/workspace/go/{forge org}/SG-12`). Nothing on the forge changes
a task's status. The forge reports through one signed organization webhook
(`POST /git/webhooks/{id}`), processed on the queue; `git:reconcile` fills in
whatever it missed every night. Operations (the bot token's scopes, the
webhook, the queue worker, the reconcile and its circuit breaker) are in
[`docs/forgejo.md`](docs/forgejo.md#12-webhook-and-nightly-reconcile), the
endpoints and payloads in [`docs/api.md`](docs/api.md).

To try the forge against your local tracker, run `deploy/forgejo/local.sh up`
([Try it locally](docs/forgejo.md#try-it-locally)).

## Organizations and roles

The workspace is multi-tenant. Each office or ministry is an **organization**;
its teams, projects and people are invisible to every other organization.

| Role | Who | Can |
| --- | --- | --- |
| Super-admin | emails in `PM_SUPER_ADMINS`, in no organization | manage organizations and everything in them; make a team cross-organization; make someone an office admin; change any project's key |
| Office admin | a person marked office admin | create projects and people in their organization, choose each project's manager, see every project of it, publish projects to the whole organization; set and change its projects' keys (the old key keeps working: it stays an alias, so task codes already written still find their tasks) |
| Project manager | the `leader` of a project's own team (one per project) | edit and archive their projects, add people to the project's team by searching (their organization, or anyone for a cross-organization team), see the project's live progress page and Gantt chart; never edits people or chooses managers |
| Member | everyone else | see their projects, teammates and their organization's public projects; manage their own subtasks and notes |

Every project has exactly one team of its own, made with the project, and that
team's leader is the project's manager. Office roles such as senior dev or QA
are not modeled: a person's free-text title carries them.

New organizations: super-admin opens **Organizations**, creates one, then adds
its office admin from **People** (Add person, choose the organization, tick Office
admin). The migration that introduced organizations put existing data into one
organization named after `APP_NAME`.

## Architecture

Request flow, mirrored across the web and API surfaces:

```
Route → Controller (thin) → FormRequest::authorize() → Service (returns ServiceResult) → JsonResource
```

Read `CLAUDE.md` for the full guide (authorization model, domain model, theming
layer, conventions).

## Testing & quality

```bash
composer test          # config:clear + pint check + phpstan + Pest
php artisan test       # Pest only
composer lint          # Pint (fixes)
composer types:check   # PHPStan
npm run lint           # ESLint (fixes)
npm run format         # Prettier (fixes)
npm run types:check    # svelte-check
composer ci:check      # everything CI runs
```

## CI

GitHub Actions run on every push/PR:

- `.github/workflows/lint.yml` — Pint + frontend lint/format
- `.github/workflows/tests.yml` — Pest, PHPStan, and the asset build

## Docker & deployment

`Dockerfile` is the deployment artifact, built by Dokploy straight from a git
clone. Three stages: Composer vendor → asset build (PHP + Node 22, because the
wayfinder Vite plugin shells out to `php artisan wayfinder:generate` during
`vite build`) → `serversideup/php:8.5-fpm-nginx` serving on port **8080**.
`AUTORUN_ENABLED=true` makes the container run `migrate --force`, `storage:link`,
and `optimize` at boot.

Run that exact image locally against your own Postgres and Redis:

```bash
./dock.sh build          # build the image and start it on http://localhost:8101
./dock.sh logs           # follow container logs
./dock.sh artisan migrate:status
./dock.sh shell
./dock.sh down
```

The original PMOPM instance ran as three Dokploy applications off this one image
on a separate server (`202.45.146.44`): `pmopm-api` (image default, runs
migrations via AUTORUN), `pmopm-scheduler` and `pmopm-queue`. Its GitHub push
webhooks still exist. Workers need `AUTORUN_ENABLED=false` (so they don't race
the web app's migrations) and a disabled Swarm healthcheck (they don't run
nginx). See `~/rayzen/DOKPLOY.md` §4 for the full playbook.

### Production: SDOC instance (`https://tasks.sdoc.gov.np`)

Production runs on the SDOC VM's own Dokploy (private
`10.5.23.138`, VPN only; dashboard via `~/rayzen/sdoc/open_dokploy.sh`), project
`tasks` / `production`. It replaced the Kaneo trial (since deleted) on 27 Oct 2026.

| Service | Notes |
| --- | --- |
| `tasks-web` (`esMcfCXzNzINE0Z8bATg1`) | This Dockerfile, `main` over HTTPS (public repo), port 8080, domain `tasks.sdoc.gov.np` with Let's Encrypt |
| `tasks-scheduler` (`gXpBWLno7vIpZgTwq4p9z`) | `php artisan schedule:work`, `AUTORUN_ENABLED=false`, healthcheck NONE |
| `tasks-postgres` | PostgreSQL 18.6, database/user `tasks` |
| `tasks-redis` | Redis 8.2, password, 128 MB `volatile-lru`, AOF |

No queue worker yet, so `QUEUE_CONNECTION=sync`: git phase 1's webhook
processing and backfills then run after each response. Add `tasks-queue`
before turning git on ([`docs/forgejo.md`](docs/forgejo.md#queue-worker)). Mail uses
SDOC's SMTP account (`no-reply@sdoc.gov.np`). The production env is in Dokploy
and in the gitignored, owner-only `.env.sdoc`, which also holds the seeded
super-admin password. The app always runs on Nepal time (`Asia/Kathmandu`,
fixed in `config/app.php` and the pgsql connection), so the reminders fire at
`PM_REMINDERS_RUN_AT` Nepal time.

GitHub webhooks cannot reach the panel, so deploys are manual. Both scripts need
the SDOC VPN and read the VM's credentials from the sibling `../sdoc` checkout
(`ssh.txt`, `dokploy-access.txt`):

```bash
git push origin main     # Dokploy builds origin/main, not this checkout
./deploy.sh              # web (migrates at boot), then scheduler; waits for build, rollout and /up
./deploy.sh web          # one service
./dump_tasks.sh          # production DB → dumps/tasks-<timestamp>.dump (gitignored)
./dump_tasks.sh --restore-latest   # replace the local .env database with the last dump
```
