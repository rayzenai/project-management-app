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
php artisan workspace:send-email-updates --pretend   # preview grouped right-away mail
php artisan workspace:send-morning-emails --pretend  # preview the morning digest
php artisan passport:keys                            # local OIDC signing keys
php artisan git:oidc-client https://git.example/user/oauth2/sdoc-tasks/callback
php artisan git:reconcile --connection=1              # nightly forge repair, scoped by office connection
php artisan git:sync-access --connection=1            # sync linked repositories' collaborators
php artisan git:commit-stats --limit=300              # bounded commit-line counting
php artisan git:commit-checks --limit=200             # recent commit test results
forgejo/local.sh up                                   # local forge against this tracker
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
  `Concerns/ChecksMemberRoles`. Programs are handed over; taking away a managed project requires
  `canRemoveManager` and leaves it manager-less (the person stays on the team).
- Assignment scope goes through `Assignees` (existing assignees plus permitted
  same-organization joiners); keep its candidate list and access predicate in step.
- User-facing role copy says Organizational admin; identifiers remain
  `is_office_admin` / `officeAdminOrganizationId`.
- Audit trail is written only by observers via `ProjectActivityRecorder`; its
  user-facing words come from `ActivityText` (status labels via
  `Task::labelForStatus`, office dates and `none`), never stored keys/timestamps.
- New polymorphic subjects must be added to the morph map in
  `AppServiceProvider` (strict morph map: `user`, `task`, `task-comment`, ...).
- Slugs in routes, never numeric ids, for projects/tasks.
- Task codes use stored project `key` + permanent task `number`; `item_number`
  is editable plan metadata. Project selects that expose `code` must include `key`.
- Never hardcode task status strings — derive from config
  (`Task::completeStatuses()`); lateness is derived, not a status.
- The app and PostgreSQL connection are fixed to `Asia/Kathmandu`;
  date/time behavior must not depend on a host or environment timezone.
- Stored and compared emails are trimmed and lowercase. Login, password-link,
  invite and API-token paths normalize input before lookup; do not add a
  case-sensitive identity path.
- Personal assignment fields (star, snooze, progress/status note and own due date)
  require `WorkspaceAccess::ownsAssignment`, in addition to ordinary assignment
  update access. Private `PersonalTodo` rows always require `ownsPersonalTodo`.
- Document visibility and capability checks belong in `WorkspaceAccess`;
  `documentAccess` and `Document::scopeVisibleTo` are predicate/query twins.
  Sharing stays inside `documentOrganizationId`, and a personal document whose
  owner is inactive is frozen rather than reassigned or deleted.

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

Forge deployment is separate: Dokploy project `git` has `git-forgejo` built
from `forgejo/Dockerfile` (context `forgejo/`) and a `git-postgres` database.
`forgejo/.env.example`, `forgejo/start.sh` and `docs/forgejo.md` cover environment,
bootstrap, SDOC branding, OIDC, backups and upgrades. `deploy.sh` does not deploy
the forge; deploy it manually when its image changes. Production OIDC
signing uses `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`; rerunning
`git:oidc-client` keeps the client id but rotates its secret.
Git jobs use `App\Support\Background` (after the response with sync, queued with
a worker); sync-queue jobs dispatched in a transaction wait for commit, and
rollback drops them. Access-sync jobs also use `afterCommit`, read repositories
afresh and have no unique lock. `docs/forgejo.md` section 12 describes adding
`tasks-queue` and switching all services to Redis; `deploy.sh` still deploys
only web/scheduler.

ONLYOFFICE is another separate Dokploy app, built from `onlyoffice/`; its
runbook is `docs/onlyoffice.md`. `onlyoffice/home/` is the SDOC Docs front
door served at `/` by the image's nginx; keep it visually aligned with the
Forgejo home and do not restore the old Dokploy redirect. Documents are enabled
only when the editor URL and a shared JWT secret of at least 32 characters are
configured.
Production uses the `documents` disk backed by RustFS's `tasks-documents`
bucket. `DocumentFiles` is the sole file-write path: every saved version gets
a new object, and the app never overwrites, purges or deletes document files.
Project and personal documents use the same immutable store; moving or sharing
one changes metadata and access only, never its stored versions.
`ops/rustfs/setup-documents.sh` owns the no-delete bucket user and policy.

Section 14 covers four bounded Forgejo runners, protected main and checks.
Runner jobs, forge data and forge Postgres live on separate logical volumes
(`runner-jobs`, `forge-data`, `forge-db`) so a runaway job cannot fill the
tracker's database disk. Runner dind is capped at 46 GB; the host firewall kit
under `forgejo/runner/host/` lets jobs reach the internet but only the forge's
80, 443 and 2222 ports on the VM.
`git:commit-checks` runs every two minutes with a ten-minute overlap lock.
Section 13 covers the ten-minute access sync and five-minute commit stats;
their overlap locks expire after 15 and 30 minutes respectively.
Right-away email runs every minute with a five-minute overlap lock; morning
email runs at `PM_MORNING_EMAIL_AT` (09:00 by default). Both commands own their
claim/retry behavior; web requests never send these notification digests.
The scheduler appends every scheduled command's output to `/proc/1/fd/2` when
it is writable; with `LOG_CHANNEL=stderr`, command output and logs therefore
reach the scheduler container log in production without changing other hosts.

The VM-wide backup kit is `ops/backup/`, with its runbook in `docs/backups.md`.
Its systemd timer takes 12 age-encrypted backups a day to the separately mounted
`/srv/backups` volume: every PostgreSQL database and incremental RustFS data,
plus nightly roles, forge data, Dokploy settings and VM configuration. The age
private key is deliberately kept off the VM; the runbook owns retention and
restore procedure.

## Structure, attendance and work

All reads for these domains belong in `app/Queries/{Structure,Attendance,Work}`;
all structure/attendance writes in `app/Services/{Structure,Attendance}`. Reuse
`PeopleInViewQuery`, `OrganizationChoicesQuery`, `ReportOptionsQuery`, `PersonRef`
and request concerns `ReadsReportScope`, `OpensOrganization`, `ReadsDay`,
`ReadsDayRange` before adding another rule/query. Designations replace titles.
Frontend day/time, distance/plural, filter visits and scope pickers have shared
helpers in `lib/{dates,format,reportVisit.svelte}.ts` and `components/filters/`.
Every new page belongs in `lib/navigation.ts`; keep the Guide aligned with workflows.
`WorkspaceOrganization` is a super-admin's one persisted organization switch:
web list queries receive it, office-page picks update it, and the API has no switch.
