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

Config files worth knowing:

- `config/project-management.php` — task status workflow, super-admins, trash TTL, reminder cadence.
- `config/government.php` — the 100-point plan metadata (categories, deadline types, oath date) that drives the plan tracker and task category labels.
- `config/themes.php` — the theme + font catalogue for web and mobile appearance settings.

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
| `digest:send-weekly [--pretend]` | Weekly project digest email to subscribers |

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

Deployed as three Dokploy applications off this one image (`./deploy.sh`
triggers them):

| Application | Command | Notes |
| --- | --- | --- |
| `pmopm-api` | (image default) | The only app with a domain; runs migrations via AUTORUN |
| `pmopm-scheduler` | `php artisan schedule:work` | `workspace:prune-trashed` daily + `workspace:send-deadline-reminders` at `PM_REMINDERS_RUN_AT` |
| `pmopm-queue` | `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600` | |

Both workers need `AUTORUN_ENABLED=false` (so they don't race the web app's
migrations) and a disabled Swarm healthcheck (they don't run nginx). See
`~/rayzen/DOKPLOY.md` §4 for the full playbook.
