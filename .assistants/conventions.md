# Conventions

Full binding rules live in `CLAUDE.md` (and `~/rayzen/CLAUDE.md`). Summary map:

## Commands

```bash
composer test              # config:clear + pint check + phpstan (level 7) + Pest
composer types:check       # PHPStan over app/ config/ database/ routes/
vendor/bin/pint --dirty
npm run lint && npm run format && npm run types:check && npm run build
php artisan test --compact tests/Feature/Workspace   # scoped test runs
php artisan db:seed --class=WorkspaceDemoSeeder      # demo data (non-prod)
php artisan db:seed --class=SdocProjectsSeeder       # fresh databases only; never production
php artisan passport:keys                            # local OIDC signing keys
php artisan git:oidc-client https://git.example/user/oauth2/sdoc-tasks/callback
php artisan git:reconcile --connection=1              # nightly forge repair, scoped by office connection
deploy/forgejo/local.sh up                            # local forge against this tracker
./deploy.sh                                          # SDOC web + scheduler (VPN required)
```

`laravel/pao` rewrites pint/phpstan/pest output to JSON; on a PHP fatal the
JSON is empty — rerun `php -d display_errors=stderr vendor/bin/pest > file 2>&1`.

## PHP

- PHP 8.5, constructor promotion, explicit return types, curly braces always,
  PHPDoc array shapes. Models carry `@property` blocks matching casts.
- Dates are `CarbonImmutable` app-wide; accept `CarbonInterface` in signatures.
- Services never throw on expected failure — return `ServiceResult::failure()`.
- All role/permission checks route through `App\Support\WorkspaceAccess`; never
  re-implement inline. Changes to it require happy + failure +
  privilege-escalation tests.
- Organizations are the tenant boundary. Nothing crosses them except membership
  in a team explicitly marked cross-organization by a super-admin.
- Sensitive capability-filtered fields (`organization_id`, organizational-admin/team
  flags, project publication) must be read through the FormRequest's
  overridden `validated()`, not `safe()`.
- Person-form role sets are complete wanted sets; omitted fields stay unchanged.
  Apply through `UpdateMemberRolesService`, with request checks in
  `Concerns/ChecksMemberRoles`. Manager roles are handed over, never cleared.
- Assignment scope goes through `Assignees` (existing assignees plus permitted
  same-organization joiners); keep its candidate list and access predicate in step.
- User-facing role copy says Organizational admin; identifiers remain
  `is_office_admin` / `officeAdminOrganizationId`.
- Audit trail written only by observers via `ProjectActivityRecorder`.
- New polymorphic subjects must be added to the morph map in
  `AppServiceProvider` (strict morph map: `user`, `task`, `task-comment`, ...).
- Slugs in routes, never numeric ids, for projects/tasks.
- Task codes use stored project `key` + permanent task `number`; `item_number`
  is editable plan metadata. Project selects that expose `code` must include `key`.
- Never hardcode task status strings — derive from config
  (`Task::completeStatuses()`); lateness is derived, not a status.
- The app and PostgreSQL connection are fixed to `Asia/Kathmandu`;
  date/time behavior must not depend on a host or environment timezone.

## Frontend

- Svelte 5 runes; `SvelteSet`/`SvelteMap` for reactive collections; no `{@html}`.
- Semantic theme utilities only (`bg-surface`, `text-fg-muted`, ...): never raw
  palette colors, `dark:` variants (exception: `lib/noteColors.ts` stickies),
  colored status pills, `rounded-xl`/`shadow-*` outside `.popover`, unicode
  glyph icons (Lucide only), em-dashes in copy, or mono-uppercase eyebrows.

## Adding a feature (the pattern)

Write once: FormRequest (authorize + validate) → action Service (ServiceResult)
→ JsonResource. Then add a thin method to **both** controllers
(`Workspace\...` and `Api\...`) and a route in **both** route files.
`Api\SubtaskController` is the canonical reference.

## Production

`tasks.sdoc.gov.np` runs the Docker image in SDOC Dokploy as `tasks-web` plus
`tasks-scheduler`; there is no queue worker (`QUEUE_CONNECTION=sync`). Deploys
are manual because the panel is private: push `main`, then run `./deploy.sh`
over the SDOC VPN. The script waits for build, rollout and `/up` health.

Forge deployment is separate: `deploy/forgejo/compose.yml` and
`docs/forgejo.md` cover bootstrap, OIDC, backups and upgrades. Production OIDC
signing uses `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`; rerunning
`git:oidc-client` keeps the client id but rotates its secret.
Git jobs use `App\Support\Background` (after the response with sync, queued with
a worker). `docs/forgejo.md` section 12 describes adding `tasks-queue` and
switching all services to Redis; `deploy.sh` still deploys only web/scheduler.
