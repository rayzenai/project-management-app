# CLAUDE.md — PMOPM

Guidance for Claude Code working in this repo. `~/rayzen/CLAUDE.md` (PHP 8.5,
Pint, Pest, CI, jsonb, Redis rules) still applies; this file is the app-specific
layer.

## What this is

A standalone Laravel 13 + Inertia + Svelte 5 project/task tracking workspace
(projects → tasks → subtasks, assignments, notes, contacts, teams, members,
activity log, in-app notifications, weekly digest, 100-point plan tracker) with a
Sanctum JSON API. It used to be a thin host for the `rayzenai/project-management`
package; that package is **retired** and all of its code now lives here under
`App\`. Do not reintroduce the dependency.

## Commands

```bash
composer test                                   # config:clear + pint check + phpstan + Pest
php artisan test --compact tests/Feature/Workspace
php artisan test --compact --filter=TeamPermissions
composer types:check                            # PHPStan level 7 over app/ config/ database/ routes/
vendor/bin/pint --dirty
npm run lint && npm run format && npm run types:check && npm run build
php artisan db:seed --class=WorkspaceDemoSeeder  # full demo workspace (idempotent, non-prod only)
php artisan workspace:api-token bot@example.com --name=crm-sync   # token for an external system
php artisan workspace:prune-trashed --pretend
php artisan workspace:send-deadline-reminders --pretend
php artisan digest:send-weekly --pretend
```

Output of pint/phpstan/pest is rewritten to JSON by `laravel/pao`; when a PHP
fatal occurs the JSON is empty — rerun with `php -d display_errors=stderr
vendor/bin/pest > file 2>&1` and read the file.

## Where things live

- `app/Providers/AppServiceProvider.php` — the `manage-workspace` Gate (from
  `config('project-management.super_admins')`), the strict morph map (`user`,
  `task`, `task-comment`, `project-note`, `project-contact`, `subtask`,
  `project-assignment`, `team`, `member`), and the six observers.
- `routes/web.php` — `/workspace/*` (Inertia, `auth` + `ShareWorkspaceData`),
  guest login routes, `/` redirect. `routes/api.php` — `/api/v1/*` (Sanctum).
  `routes/console.php` — the daily schedule.
- `app/Http/Controllers/{Workspace,Api}` — thin controller pairs.
  `app/Http/Requests` — FormRequests (authorization + validation, shared by both
  surfaces). `app/Http/Resources` — JsonResources. `app/Services/Workspace` —
  action services returning `App\Support\ServiceResult`. `app/Queries` — heavy
  read queries. `app/Support/WorkspaceAccess.php` — every role decision.
- `resources/js/pages` (Inertia pages), `resources/js/components`,
  `resources/js/lib`, `resources/css/app.css` (the semantic theme layer). One
  Inertia entry: `resources/js/app.ts`; one root view: `resources/views/app.blade.php`.
- `config/project-management.php` — status workflow, super-admins, trash TTL,
  reminders. `config/government.php` — plan tracker metadata (categories,
  deadline types, oath date). `config/themes.php` — the system/light/dark token sets.
- `database/migrations` — all workspace tables (the former package migrations
  keep their original filenames, so existing databases need no re-migration).
- `docs/api.md` — the HTTP API reference + external-integration guide.

## Core architectural pattern (follow it exactly)

```
Route → Controller (thin) → FormRequest::authorize() (authorization)
      → action Service (returns ServiceResult, never throws on expected failure)
      → JsonResource (output shape)
```

- **Controllers are thin.** They type-hint the FormRequest + the action service,
  call `$service->execute(...)`, and hand the `ServiceResult` to a trait. Cheap
  ownership checks may use `abort_unless(...)` inline (subtasks are personal).
- **Authorization lives in FormRequest `authorize()`**, routed through
  `WorkspaceAccess`. The same FormRequest is reused by both controllers.
- **Action services** return a `ServiceResult` (`success(data, message, meta)` /
  `failure(message, code, data)` / `fromException(e)`). They never throw on
  expected-failure paths; `try/catch` only truly exceptional conditions, `report`,
  and return `fromException`.
- **`ServiceResult` → response** via traits: web
  `Workspace\Concerns\RedirectsWithServiceResult` (Inertia redirect with
  `workspace_flash`, errors via `withErrors`); API
  `Api\Concerns\RespondsWithServiceResult` (`{message, data}` / `{message, errors}`
  at `result->code`).
- **Output** through JsonResources (`$wrap = null`, ISO-8601 dates, `whenLoaded`).

When adding a feature: write the Service + FormRequest + Resource once, then add a
thin method to **both** controllers and a route in **both** route files.
`Api\SubtaskController` is the canonical reference.

## Authorization model — SECURITY CRITICAL

`app/Support/WorkspaceAccess.php` is the single source of truth. Three tiers:

1. **Super-admin** — the `manage-workspace` Gate (emails in `PM_SUPER_ADMINS`).
   Creates/deletes/renames teams, manages every member, archives any project,
   reassigns members between teams.
2. **Team leader** — `member_team.role = 'leader'`. Scoped power over led teams.
3. **Regular member** — assignable; manages only their own subtasks and notes.

Key methods: `isSuperAdmin`, `leadsTeam`, `canManageRosterOf`,
`canArchiveProject`, `canCreateMemberForTeams` (a leader cannot create an
unattached member), `canManageMember` (attributes only, never team affiliation),
`canViewProject`, `canCreateProject`, `canManageProjectAccess`, `ledTeamIds`
(memoized per request via a `WeakMap`; never creates a member as a side effect).

Privilege-escalation protections — do not regress:

- `canManageMember` blocks managing a super-admin's linked login (blocks the
  attach-then-password-reset takeover).
- Team rename + member team-reassignment are super-admin only.
- Team role changes require BOTH `canManageRosterOf` AND `canManageMember`
  (`UpdateTeamMemberRoleRequest::authorize()`).

Every role decision goes through `WorkspaceAccess`. Never re-implement inline.

## Domain model (`app/Models`)

- **Project** — slug-routed; `archived_at` soft-archive; `is_public`; scopes
  `visibleTo`, `active`, `archived`; `hasMany Task`, `belongsToMany Team`.
- **Task** — slug-routed (`{project_id}-{slug}`); plan fields (`item_number`,
  `category`, `deadline_type`, `responsible_ministry`, `title_np`,
  `description_np`) stored in the `metadata` jsonb column via `Attribute`
  mutators; `Task::completeStatuses()` derives "finished" from the config
  `is_complete` flags — never hardcode status strings. Lateness is derived
  (`is_late`), not a status.
- **Subtask** — personal (`user_id`). **Member** — a person; optionally linked to
  a `User` (`Member::forUser` `firstOrCreate`s — never call it in authorization).
  `scopeAssignableFor(Project)` is the single source of truth for assignees.
- **Team** — auto-slugged; pivot `member_team` with `role`. **ProjectActivity** —
  polymorphic audit log written only by observers. **WorkspaceNote** — personal
  stickies. **ProjectAssignment / ProjectNote / ProjectContact / TaskComment** —
  task children. **ProjectDigestSubscriber** — weekly digest recipients.
- Dates are `CarbonImmutable` app-wide (`Date::use`); type-hint
  `CarbonInterface` when accepting model dates.

## In-app notifications (`app/Notifications`)

`database` channel only, synchronous. `TaskAssigned`, `TaskStatusChanged`,
`MentionedInComment`, `TaskDeadlineDue` share `Concerns\BuildsWorkspaceNotification`
and emit the stable payload `{kind, title, body, task, actor, url}`. Dispatched
from observers (assignment created → `TaskAssigned`; task status entering
done/failed → `TaskStatusChanged`), the comment service (mentions) and the
reminders command.

## Two delivery surfaces

- **Web** (`routes/web.php`, prefix `workspace`, names `workspace.*`):
  `ShareWorkspaceData` shares `statuses`, `completeStatus`, `quickAddContext`,
  `workspaceNotes`, `taskNotes`, `appearance`, `themeCatalogue`,
  `unreadNotifications`, `flash`, `isSuperAdmin`, `ledTeamIds`. Add
  cross-cutting web context there.
- **API** (`routes/api.php`, prefix `api/v1`, names `api.*`): `POST login`
  → token; `Api\AuthController::userPayload` is the token-auth twin of
  `ShareWorkspaceData`. External systems get tokens via `workspace:api-token`.
  Add cross-cutting API context to `userPayload`.

## Semantic theming layer (web UI)

`config/themes.php` holds exactly three themes: `system` (follows the OS),
`light` and `dark`. One visual language, two grounds: cool-neutral surfaces,
three text tones, hairline borders, one calm blue accent, and green / amber /
red reserved for status, deadlines and priority. Fonts are fixed: Geist (UI),
Geist Mono (ids, dates, counts) and Mukta (Nepali, via `font-np`).

`resources/css/app.css` defines the `--ws-*` custom properties, the Tailwind
`@theme` block mapping color utilities to them (`bg-bg`, `bg-surface`,
`bg-surface-alt`, `bg-hover`, `bg-raised`, `text-fg`, `text-fg-muted`,
`text-fg-faint`, `border-line`, `border-line-soft`, `text/bg/border-accent`,
`bg-accent-soft`, `text-success/warn/danger`, `bg-success/warn/danger-soft`) and
the component classes every screen is built from (`.btn`, `.btn-primary`,
`.btn-ghost`, `.btn-danger`, `.btn-icon`, `.input`, `.label`, `.chip*`,
`.panel`, `.row`, `.group-head`, `.col-head`, `.section-title`, `.popover`,
`.menu-item`, `.kbd`). `resources/js/lib/applyTheme.ts` (`applyAppearance`) is
the single source of mode.

Primitives: `StatusGlyph` (the status icon: ring / dashed ring / amber half
disc / green check / red cross), `PriorityBars`, `Avatar` (squircle initials),
`ProgressRing`. Pages wrap themselves in `AppShell` and provide the 44px top
bar through its `bar` snippet (breadcrumb left, actions right); `flush` drops
the content padding for full-bleed registers and boards.

Rules: semantic utilities only, never raw palette colours (`neutral-*`,
`amber-*`) or `dark:` variants (the stickies in `lib/noteColors.ts` are the one
exception), never black/white inverted buttons, never coloured status pills or
dots, no `rounded-xl`/`shadow-*` outside `.popover`, no em-dashes in copy, no
unicode glyph icons (Lucide only), no mono-uppercase eyebrow labels.

## Conventions

- PHP 8.5; constructor promotion; explicit return types; curly braces always;
  PHPDoc array shapes. Models carry `@property` blocks matching their casts —
  PHPStan level 7 runs over `app/` in CI.
- Svelte 5 runes; reactive collections use `SvelteSet`/`SvelteMap`; no `{@html}`.
- Slugs in routes, never numeric ids for projects/tasks.
- Audit trail only via observers + `ProjectActivityRecorder`.
- New polymorphic subjects go in the morph map in `AppServiceProvider`.
- Tests: `tests/Feature/Workspace/**` (web), `tests/Feature/Workspace/Api/**`
  (API), `tests/Unit`. Any change to `WorkspaceAccess` or a service gets happy +
  failure + privilege-escalation tests.
