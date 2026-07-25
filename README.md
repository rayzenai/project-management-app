# PMOPM

Project & task tracking workspace for PMOPM (Office of the Prime Minister and
Council of Ministers) — projects → tasks → subtasks, assignments, notes,
contacts, teams, members, activity log, and notifications, with a JSON API for
a companion mobile client.

The workspace itself (models, services, controllers, authorization, the whole
`/workspace/*` + `/api/v1/*` surface) is shipped by the
[`rayzenai/project-management`](https://packagist.org/packages/rayzenai/project-management)
Composer package. This repo is the **host app**: it wires the package into a
Laravel + Inertia + Svelte project and adds the app-specific bits (appearance
API, user preferences, theming).

## Tech stack

- **Backend:** Laravel 13, PHP 8.5, Sanctum (API token auth for the mobile client)
- **Frontend:** Inertia.js + Svelte 5, Tailwind CSS v4, Vite
- **Workspace engine:** `rayzenai/project-management` (Composer package)
- **Testing:** Pest
- **Formatting/linting:** Laravel Pint (PHP), ESLint + Prettier + `svelte-check` (JS/TS/Svelte)

## Requirements

- PHP **8.5**
- Composer 2
- Node **22** + npm
- **PostgreSQL** — the workspace package ships a `pg_trgm` migration for fuzzy
  task search, so Postgres is the supported database (the migration no-ops on
  other drivers, and you lose command-palette search)
- **Redis** — cache, sessions, and the queue
- Docker (optional) — only to run the deployment image locally via `./dock.sh`

## Quick start

```bash
git clone git@github.com:nepal-government/pmopm.git
cd pmopm
composer setup
```

`composer setup` runs the full bootstrap in one shot: `composer install`, copies
`.env.example` to `.env`, generates the app key, runs migrations, and installs +
builds the frontend.

## Manual setup

If you'd rather run the steps yourself (or something in `composer setup` fails):

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create the database, then set DB_* / REDIS_* in .env to match your setup:
createdb pmopm

php artisan migrate

npm install
npm run build     # production build
# or: npm run dev # Vite dev server with HMR
```

Then serve the app with `php artisan serve`, or use `composer dev`, which runs
`php artisan dev` (server + queue listener + Vite, concurrently).

## Configuration

Beyond the standard Laravel `.env` values, the workspace package reads:

| Variable | Purpose |
| --- | --- |
| `PM_SUPER_ADMINS` | Comma-separated emails granted the `manage-workspace` gate (full super-admin access) |
| `PM_SUPER_ADMIN_PASSWORD` | Default password used by the super-admin seeder |
| `PM_TRASH_TTL_DAYS` | Days a soft-deleted workspace record survives before it's pruned (default `30`) |
| `PM_REMINDERS_RUN_AT` | Time of day the deadline-reminder command runs (default `08:00`) |

Package config can be published (optional) with:

```bash
php artisan vendor:publish --tag=project-management-config
```

## Using the app

- `/` redirects to `/workspace`.
- **Web (Inertia)** — `/workspace/*`, session auth, branded login at `/workspace/login`.
- **JSON API** — `/api/v1/*`, Sanctum bearer auth, built for the companion mobile client.

## The project-management package

Everything workspace-shaped (routes, models, services, authorization rules)
lives in the package, not this repo. Before making changes that touch
projects/tasks/teams/etc., read:

- `vendor/rayzenai/project-management/README.md` — full HTTP API reference (web + JSON)
- `vendor/rayzenai/project-management/CLAUDE.md` — architecture and conventions

## Testing & quality

```bash
composer test         # config:clear + php artisan test (Pest)
composer lint          # Pint (fixes)
composer lint:check    # Pint (check only)
npm run lint           # ESLint (fixes)
npm run lint:check     # ESLint (check only)
npm run format         # Prettier (fixes)
npm run format:check   # Prettier (check only)
npm run types:check    # svelte-check
composer ci:check       # everything CI runs: lint/format/types checks + tests
```

## CI

GitHub Actions run on every push/PR:

- `.github/workflows/lint.yml` — Pint + frontend lint/format
- `.github/workflows/tests.yml` — Pest, type checks, and asset build across the PHP matrix

## Docker & deployment

`Dockerfile` is the real deployment artifact, built by Dokploy straight from a git
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

`dock.sh` derives a container env from your `.env`, rewriting loopback hosts to
`host.docker.internal`. The image bakes the source in at build time, so code
changes need a rebuild — for day-to-day dev use `composer dev`.

Deployed as three Dokploy applications off this one image:

| Application | Command | Notes |
| --- | --- | --- |
| `pmopm-api` | (image default) | The only app with a domain; runs migrations via AUTORUN |
| `pmopm-scheduler` | `php artisan schedule:work` | `workspace:prune-trashed` daily + `workspace:send-deadline-reminders` at `PM_REMINDERS_RUN_AT` |
| `pmopm-queue` | `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600` | |

Both workers need `AUTORUN_ENABLED=false` (so they don't race the web app's
migrations) and a disabled Swarm healthcheck (they don't run nginx). See
`~/rayzen/DOKPLOY.md` §4 for the full playbook.
