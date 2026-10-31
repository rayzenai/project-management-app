# CLAUDE.md — PMOPM

Guidance for Claude Code working in this repo. `~/rayzen/CLAUDE.md` (PHP 8.5,
Pint, Pest, CI, jsonb, Redis rules) still applies; this file is the app-specific
layer.

## What this is

A standalone Laravel 13 + Inertia + Svelte 5 project/task tracking workspace
(projects → tasks → subtasks, assignments, notes, contacts, teams, members,
activity log, in-app notifications, 100-point plan tracker) with a
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
php artisan db:seed --class=SdocProjectsSeeder  # SDOC + its projects, fresh databases only (production got SDOC's keys from the 2026_10_31_140000 migration; never run it there)
php artisan workspace:api-token bot@example.com --name=crm-sync   # token for an external system
php artisan workspace:prune-trashed --pretend
php artisan workspace:send-deadline-reminders --pretend
php artisan passport:keys                         # local OIDC signing keys (storage/oauth-*.key)
php artisan git:oidc-client https://git.example/user/oauth2/sdoc-tasks/callback   # the forge's OIDC client
php artisan git:reconcile [--connection=1]        # the nightly forge repair, by hand
php artisan git:sync-access [--connection=1]      # write every linked repository's collaborators to the forge
php artisan git:commit-stats [--limit=300]        # count the lines of commits not counted yet
```

Output of pint/phpstan/pest is rewritten to JSON by `laravel/pao`; when a PHP
fatal occurs the JSON is empty — rerun with `php -d display_errors=stderr
vendor/bin/pest > file 2>&1` and read the file.

## Where things live

- `app/Providers/AppServiceProvider.php` — the `manage-workspace` Gate (from
  `config('project-management.super_admins')`), the strict morph map (`user`,
  `project`, `task`, `task-comment`, `task-dependency`, `project-note`,
  `project-contact`, `subtask`, `project-assignment`, `team`, `member`,
  `git-repository`, `git-repository-grant`, `git-branch`, `git-commit`,
  `git-pull-request`), and the
  observers (`ProgramObserver`, `ProjectObserver`, `TaskObserver`, ...).
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

`app/Support/WorkspaceAccess.php` is the single source of truth. The app is
multi-tenant: an **organization** (an office or ministry) is the tenant
boundary. Every project has exactly one team of its own (made with it,
never on its own), and that team's one leader is the project's **manager**.
Office roles (senior dev, QA, ...) are not modeled: a person's free-text
`title` carries them. Five tiers:

1. **Super-admin**: the `manage-workspace` Gate (emails in `PM_SUPER_ADMINS`).
   Belongs to no organization. Manages organizations and everything in them;
   the only one who can open a project's team to other organizations or make
   a person an organizational admin (`is_office_admin`).
2. **Organizational admin**: a member with `is_office_admin` (and `is_active`). Full
   control inside their own organization: creates its people and projects,
   chooses each project's manager, sees every project, can publish projects
   (public means "everyone in this organization"), creates its programs and
   chooses their managers.
3. **Program manager**: `programs.manager_id` (while `is_active`, programs of
   their own organization only; a ministry's Joint Secretary). On every
   project in the program, exactly what its project manager can do (see,
   edit, archive, progress, roster, code and repositories), without sitting
   on its team. Creates projects in their programs and chooses or replaces
   their managers (never removes one). Never creates programs, never
   publishes or rekeys a project, never opens its team to other
   organizations, never moves it between programs, never edits people.
4. **Project manager**: `member_team.role = 'leader'` on a project's team (a
   partial unique index allows one per team). Edits and archives their
   projects, manages their teams' rosters by searching people
   (`TeamCandidatesQuery`: their organization, or anyone for a
   cross-organization team) and sees their projects' progress. Never edits
   people, never chooses managers, never creates projects.
5. **Member**: sees their projects and teammates (plus their organization's
   public projects); manages own subtasks and notes.

Key methods: `isSuperAdmin`, `memberFor` (never creates a member),
`canSignIn` (signing in and staying signed in: never while the member is
deactivated; a super-admin and a login with no member yet always may; both
logins refuse with `EnsureCanSignIn::DEACTIVATED`, and `EnsureCanSignIn`, on
every authenticated web and API route, signs out a person deactivated while
signed in: the web session ends, an API token answers 401),
`officeAdminOrganizationId`, `administers`, `canEditTeam` (whoever
administers the team's organization), `canAppointProjectManager` (choose
the manager: `canEditTeam` or the project's program manager; its project
twin `canChooseManagerOf`), `canAppointAsProjectManager` (who may be
chosen: `canJoinTeam`, and for a program manager the team's organization
only),
`canManageRosterOf` (project twin `canManageRosterOfProject`, answered
from the loaded project without a query; keep them in step),
`canRemoveFromTeam`, `canJoinTeam`,
`canAssignOutsideTeam` (give a task to someone not on the team, who joins
it first: whoever passes `canManageRosterOf`, for an active person of the
project's own organization who passes `canJoinTeam`; list twin
`Assignees::joinCandidates`), `canStartGeneralProject` (an organizational
admin whose organization has no active project),
`canViewTeamProgress`, `canViewProjectProgress`,
`canCreateMember`, `canManageMember`, `canSetRolesIn` (the person form's
role fields: whoever administers the organization), `canViewProject` (twin of
`Project::scopeVisibleTo`), `canCreateProject`, `canCreateProjectInProgram`
(its organization's admins and its manager), `createsProjectsOnlyInPrograms`
(a program manager who administers nothing), `canManageProjectAccess`,
`canPublishProject`, `canViewProjectCode`, `canManageProjectRepositories`,
`canManageRepositoryAccess` (give, change and remove a repository's grants:
whoever passes `canManageProjectRepositories`),
`canGrantRepositoryAccessTo` (who may be given access: never a maintainer,
an inactive or deleted person or a super-admin's own member; an
organizational admin gives it to their organization's people and the team,
a manager or program manager only to the team; twin of
`RepositoryAccessCandidatesQuery`, keep them in step),
`repositoryMaintainerIds` (the active manager and the program's active
manager, the latter as `managesProgramOf` counts them), `codeReportScope`
(whose commits the code report shows: `{all, organization_ids, program_ids,
project_ids, user_id}`, empty but the viewer's own commits for an inactive
member), `ledTeamIds` (empty for a deactivated member: a deactivated manager
has no manager powers, like inactive organizational admins and program
managers), `managedProgramIds`, `managesProgramOf`, `canManagePrograms`,
`canViewProgram` (twin of `Program::scopeVisibleTo`). Visibility scopes:
`Project`, `Team`, `Member` and `Program::scopeVisibleTo` (the first three
reach a program manager's projects through `Project::inPrograms`). Memos
are per user instance and flushed on every `RouteMatched`/`RequestHandled`.

Privilege-escalation protections — do not regress:

- Nothing crosses organizations except through a cross-organization project
  team. `canJoinTeam` gates every roster add and every new manager; a
  project's team shares its organization; a person cannot change
  organization while on its teams.
- `project_team` is unique on both columns and `member_team` holds one
  `leader` per team: never attach a second team to a project or a second
  leader to a team; appoint through `AppointProjectManagerService`. The
  manager cannot be removed or demoted, only replaced.
- `canManageMember` blocks a super-admin's linked login and other organizational
  admins; managers manage no people at all (so a cross-organization manager
  can never reset an outsider's password).
- Organization and organizational-admin flag on a member are super-admin
  only (`UpdateMemberRequest::authorize()` 403s otherwise). The person
  form's role fields (`managed_program_ids`, `managed_project_ids`,
  `team_ids`, each the complete wanted set, absent = unchanged) are open to
  whoever passes `canManageMember` (create: `canCreateMember`), but every
  program, project or team added or taken away must be in an organization
  the user `administers` and fit the person after any move
  (`Concerns\ChecksMemberRoles`); they are applied only by
  `UpdateMemberRolesService`, which appoints through
  `AppointProjectManagerService`, never takes a manager role away, never
  takes anyone off a team they manage, and never `sync`s. A refused role
  undoes the whole save (the member controllers throw inside their
  transaction). `manager_id`, `is_cross_organization` and `is_public` on a project are
  silently dropped for anyone not allowed to set them. Read them through the FormRequest's `validated()`, not
  `safe()`, which bypasses those overrides.
- Mentions only notify people who can view the task's project. The comment
  box offers `TaskShowQuery`'s `mentionCandidates` (the team plus the
  program's manager, `{id, name}` only: no email or login of someone the
  teammate may not otherwise see); `team` stays the assignees, and
  `joinCandidates` (people who would join the team) are never mentionable.
- Who may be assigned has one rule, `App\Support\Assignees::joiners`,
  shared by quick add (`QuickAddTaskService`) and the assignment route
  (`StoreAssignmentRequest`, `AssignMemberService`): anyone
  `Member::scopeAssignableFor` allows, on the same path as always, plus,
  for whoever runs the team, people passing `canAssignOutsideTeam`, who
  join the team as members through `AddTeamMemberService` (the People
  tab's add) before the assignment, in one transaction. So an assignee can
  always see the task; a member never adds anyone to a team this way; no
  one of another organization joins this way, not even a
  cross-organization team's outsiders. The quick-add `@token` and the
  default-to-self still use `scopeAssignableFor` only.
- `start_general` on quick add (`QuickAddTaskRequest`, no `project_id`) is
  refused (422 `Pick a project.` on `project_id`) unless
  `canStartGeneralProject`; `StartGeneralProjectService` re-checks under a
  lock on the organization row, makes "General" through
  `CreateProjectService` with the caller as manager (rolled back if it has
  none), and `QuickAddDispatcher` files the task in the same transaction.
- A program manager's program powers never make them a team leader (they
  lead a team only when chosen as its manager, as on create by default),
  reach only projects of programs in their own organization
  (`managedProgramIds` and `managesProgramOf` check both the program's and
  the project's organization; a person cannot change organization while
  managing a program), and give no power over people (`canManageMember`,
  `canCreateMember`, `canEditTeam` unchanged).
- A program manager creates projects only in their programs
  (`StoreProjectRequest`: `program_id` required and passing
  `canCreateProjectInProgram`, else 422 `Choose one of your programs.`; the
  organization is the program's; `key`, `is_public` dropped) and appoints
  managers only through `AppointProjectManagerService`, only on their
  program's projects (`canAppointProjectManager`: the project update's
  `manager_id`, the team role route), only people of the project's
  organization even on a cross-organization team
  (`canAppointAsProjectManager`), and never removes a manager
  (`canRemoveFromTeam` keeps `canEditTeam`).

Every role decision goes through `WorkspaceAccess`. Never re-implement inline.

## Domain model (`app/Models`)

- **Organization** — the tenant; `hasMany` members, teams, projects. Deleted
  only when empty (`DeleteOrganizationService`).
- **Project** — slug-routed; belongs to an organization; `archived_at`
  soft-archive; `is_public` (within its organization); scopes `visibleTo`,
  `active`, `archived`; `hasMany Task`, `belongsToMany Team`.
- **Program** — a named collection of projects in one organization with one
  program manager (`manager_id`, a member). `projects.program_id` is nullable:
  a project is in at most one program, and deleting a program keeps its projects.
  Creating a program creates its "General" project, managed by its manager;
  an organizational admin's quick add starts an organization-level "General"
  (no program) when the organization has no project. Messages name a project
  with its program (`Project::label()`, "General (Revenue)"; the UI's twin is
  `resources/js/lib/projectLabel.ts`).
- **Task** — slug-routed (`{project_id}-{slug}`); plan fields (`item_number`,
  `category`, `deadline_type`, `responsible_ministry`, `title_np`,
  `description_np`) stored in the `metadata` jsonb column via `Attribute`
  mutators; `Task::completeStatuses()` derives "finished" from the config
  `is_complete` flags and `Task::closedStatuses()` from `is_closed` (done and
  failed: work has stopped) — never hardcode status strings. Lateness is
  derived (`is_late`), not a status. **Overdue has one rule everywhere**:
  not closed and past its deadline (`is_overdue`, `scopeOverdue`,
  `notClosed`); every count, filter, chip and reminder uses it, and closed
  tasks never sit on anyone's to-do list.
- **TaskDependency** — finish-to-start (`task_id` waits for
  `depends_on_task_id`), same project only, no loops
  (`TaskDependency::reaches`); `Task::dependencies()` / `dependents()`;
  logged by `TaskDependencyObserver`. The Gantt draws them as arrows and
  `resources/js/lib/schedule.ts` moves waiting work after what it waits for.
  On the board (`resources/js/lib/dependencies.ts`) a task always sits below
  what it waits for in its column, dotted lines join them, and dropping a
  card onto another links them.
- **Subtask** — personal (`user_id`). **Member** — a person in one organization
  (none only for a super-admin's own member), `is_office_admin`; optionally linked to
  a `User` (`Member::forUser` `firstOrCreate`s — never call it in authorization).
  `scopeAssignableFor(Project)` is who is assignable as things stand;
  `Assignees::joiners` is the whole rule (it adds whom whoever runs the
  team may bring onto it, see the escalation notes).
- **Team** — a project's own (`Project::team()`, `ensureTeam()`,
  `manager()`; `Team::project()`), named after it; auto-slugged; belongs to
  its organization; `is_cross_organization`; pivot `member_team` with `role`.
  Managed from the project's People tab (`ProjectTeamPanel.svelte`); the
  Team page is the People directory. A project's progress page
  (`ProjectProgressQuery` on `ProgressReport`, for whoever passes
  `canViewProjectProgress`; old team progress links redirect there) is a
  report polled every 15s: `components/ProgressReport.svelte`,
  `components/team/GanttChart.svelte` selects, and
  `TaskReportDrawer.svelte` shows the task read-only (never the editable Peek). **ProjectActivity** —
  polymorphic audit log written only by observers; every row names its
  `project_id`, and `task_id` is null for project-level entries (team
  changes, written by `TeamMembershipObserver` via the `TeamMembership`
  pivot model; program moves, by `ProjectObserver`). Feeds scope activity
  by `project_id`. **WorkspaceNote** — personal
  stickies. **ProjectAssignment / ProjectNote / ProjectContact / TaskComment** —
  task children.
- Dates are `CarbonImmutable` app-wide (`Date::use`); type-hint
  `CarbonInterface` when accepting model dates.

## In-app notifications (`app/Notifications`)

`database` channel only, synchronous. `TaskAssigned`, `TaskStatusChanged`,
`MentionedInComment`, `TaskDeadlineDue`, `AddedToProjectTeam` and
`MadeProjectManager` and `MadeProgramManager` share `Concerns\BuildsWorkspaceNotification`
and emit the stable payload `{kind, title, action, body, task, actor, url}`
(`action` is the short form with the task title left out, for surfaces that lead
with the title; `body` keeps the long sentence). Dispatched
from observers (assignment created → `TaskAssigned`; task status entering
done/failed → `TaskStatusChanged`; joining a project's team or becoming its
manager → the team notifications, never to the person who did it), the
comment service (mentions) and the reminders command. The team ones carry
`task: null` and a `project {slug, title}`; `MadeProgramManager` (a new program
manager, from `ProgramObserver`) carries a `program {slug, name}` instead.

The sidebar's **Guide** (`pages/Guide.svelte`, `/workspace/guide`) explains
to users the model (Organization, Program, Project, Team, Tasks), each role's
routine (organizational admin, program manager, project manager, member) and how a
ministry's titles map onto those roles ("In a ministry"): keep it in step
when a feature changes how people work. The same mapping shows as hints by
the organizational admin switch and the program and project manager pickers.

## Two delivery surfaces

- **Web** (`routes/web.php`, prefix `workspace`, names `workspace.*`):
  `ShareWorkspaceData` shares `statuses`, `completeStatus`, `quickAddContext`
  (`projects` with `member_ids`, `organization_id` and `can_add_people` =
  `canManageRosterOfProject`, no query per project; `organizationPeople` `{id, name, title}`, the active
  people of the viewer's organization for whoever runs a team in it or may
  start its General, else `[]`; `startGeneral`; `team`; `currentMemberId`),
  `workspaceNotes`, `taskNotes`, `appearance`, `themeCatalogue`,
  `unreadNotifications`, `flash`, `isSuperAdmin`, `ledTeamIds`,
  `managedProgramIds`, `managedPrograms` (`{slug, name}`, the sidebar's
  Programs group). Add cross-cutting web context there.
- **API** (`routes/api.php`, prefix `api/v1`, names `api.*`): `POST login`
  → token; `Api\AuthController::userPayload` is the token-auth twin of
  `ShareWorkspaceData` (`is_super_admin`, `is_office_admin`, `led_team_ids`,
  `managed_program_ids`, `features`, `git_enabled`, ...). External systems
  get tokens via `workspace:api-token`.
  Add cross-cutting API context to `userPayload`.

## Git (Forgejo), phase 0

Spec: `docs/superpowers/specs/2026-10-29-git-integration-design.md`.

- **Project keys** (`Project::$key`, `App\Support\ProjectKeys`): 1 to 10
  capital letters, unique in the organization across current keys **and**
  `project_key_aliases` (a rename leaves the old key as an alias, so an old
  commit never points at another project). Suggested from the title (`P`
  when it has no ASCII letters); only `canChangeProjectKey` (organizational admin,
  super-admin) may change it. `Project::code` is the key.
- **Task numbers** (`Task::$number`): assigned once in the `creating` hook
  from `projects.last_task_number`, a counter that only goes up, so a number
  is never reused, even after the trash is purged; unique
  `(project_id, number)`; never fillable, never editable through a request.
  The create services lock the project row; the hook's increment locks it
  for any other creator. A task's code is `KEY-12`
  (`resources/js/lib/taskCode.ts`); search finds it by current or old key.
- **Git connection** (`GitConnection`, `Organization::gitConnection()`): one
  per organization (forge base URL, forge organization `owner`, encrypted
  hidden bot token, whose forge user `{owner}-bot` is a Forgejo bot user
  because the forge requires 2FA of every person and exempts only bots;
  `docs/forgejo.md`). An organization "has git" when it has one. Super-admin
  only (`canManageGit`), checked against the forge by `EnableGitService` before
  saving. `base_url` is https (plain http only for a forge on 127.0.0.1,
  localhost or ::1), and a forge organization connects to one office only.
  Tokens and secrets never go in a resource, flash, exception or log.
  Deactivating or deleting someone with a `git_username` removes them from
  every connected forge organization with each connection's own token
  (`RemoveFromForgeService`); a forge failure never blocks the change and the
  message asks for a manual suspend.
- **OIDC provider** for the forge: Passport + `jeremy379/laravel-openid-connect`
  (its provider replaces Passport's, which is in composer `dont-discover`;
  Passport's own routes are off). Routes in `routes/web.php`:
  `GET oauth/authorize` (`web`, `auth`, `EnsureCanUseGit`) and `POST
  oauth/token` (no session or CSRF, throttled); the package adds
  `/.well-known/openid-configuration` (issuer = `APP_URL`) and `/oauth/jwks`.
  One client, `Forgejo` (`git:oidc-client`), skips consent
  (`OAuthClient::skipsAuthorization`). Userinfo is off: every claim is in the
  id_token (`App\Support\Git\Oidc\IdentityEntity`): `sub` (user id),
  `email`, `email_verified`, `name`, `preferred_username` (`GitUsernames`,
  stored once in `users.git_username`, never recomputed, always a name
  Forgejo accepts: no separator runs, letter or digit at both ends, its
  reserved names and endings treated as taken, as are every connection's
  `owner`, its `{owner}-bot` and `forge-admin` (one namespace for forge
  users and organizations), `user-{id}` fallback), and
  for the `git` scope `git_orgs` (forge organization names; left out when
  empty, so the forge's required-claim check refuses) and `groups`
  (`forge-admins` for super-admins). Access tokens live an hour, refresh
  tokens a day; `passport:purge` runs daily. `User` keeps Sanctum's
  `HasApiTokens`; never add Passport's. Keys: `passport:keys` locally,
  `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` in production.
- **Who may use git** (`App\Support\Git\GitAccess::organizationsFor`, the
  one rule for the sign-in gate and `git_orgs`): only organizations with a
  connection; a super-admin gets all of them; an active member gets their own
  organization plus the organization of every project whose team they are on
  (a cross-organization team's outsiders included); a deactivated or missing
  member gets none, and none means a 403 at `/oauth/authorize`.
  A connected forge must sign people in only through this tracker, so forge usernames are the tracker's; removal on deactivation walks every connected forge organization by that name.

## Git (Forgejo), phase 1

Plan: `docs/superpowers/plans/2026-10-30-git-phase-1-code.md`. Runbook:
`docs/forgejo.md` (section 12: webhook, queue, reconcile).

- **Models**: `GitRepository` (linked to one project; unique
  `(git_connection_id, forge_id)`, so a forge repository links to at most one
  project), `GitBranch`, `GitCommit` (`unreachable_at`, never deleted),
  `GitPullRequest` (`open|merged|closed`, `forge_updated_at` guards against
  older snapshots), linked to tasks through `TaskGitLink` (`task_git_links`,
  a `MorphPivot` with its own `git_repository_id` cascade). Morph map:
  `git-repository`, `git-branch`, `git-commit`, `git-pull-request`.
  `GitWebhookDelivery` keeps each delivery once by its UUID (14 days).
- **One linking path**: `App\Services\Git\GitIngest` upserts branches,
  commits and pull requests and links them to tasks through
  `App\Support\Git\TaskReferences` (the key parser; uppercase only in text,
  any case in branch names; code, fences and URLs skipped). Keys resolve
  within the repository's organization (current keys and aliases); unknown
  keys and trashed tasks link nothing. Webhooks (`ProcessGitDelivery`),
  backfill (`BackfillGitRepository`, `RepositorySync::catchUp`) and the
  reconcile all go through it; `ForgeShapes` maps forge JSON once. Nothing in
  phase 1 changes a task's status, a merge included.
- **Access**: viewing code needs `canViewProjectCode`: git on, and the
  project's team (any role, while the member is active), its program's
  manager, its organization's organizational admins or a super-admin; not other
  viewers of a public project. Its query twin is
  `Project::scopeCodeVisibleTo`; keep them in step. Linking and unlinking
  needs `canManageProjectRepositories` (manager, program manager, organizational
  admin, super-admin, git on). A task shows code only from repositories whose
  project's code the viewer may see (`TaskCodeQuery::inVisibleRepositories`,
  on `codeVisibleTo`, also on the board's `has_pull_request`), and a pull
  request writes task activity only on tasks of its repository's own
  project, with no user (the forge did it). `gitEnabled` (web) /
  `git_enabled` (API `userPayload`) say whether `GitAccess` admits the user
  anywhere; the Guide's Code section shows only then.
  `/workspace/go/{org}/{index}` (the forge's link back) 404s, never 403s, a
  task the caller can't view; signing in on the way there is a full page
  visit.
- **Webhook**: `POST /git/webhooks/{connection}` (`git.webhook`, outside the
  `web` group, throttled), one organization hook per connection made by
  `EnableGitService`. HMAC-SHA256 over the raw body with the connection's
  `webhook_secret`, else 403; over 2 MB 413; a replayed delivery 200 and not
  processed twice. Jobs go through `App\Support\Background::dispatch` (after
  the response on the sync queue; the `tasks-queue` worker otherwise).
- **Reconcile**: `git:reconcile [--connection=] [--allow-removals]`, daily
  at 01:30. Per connection: recreate the hook, or write its settings and
  stored secret back (every run: the forge hides secrets),
  `RepositorySync::reconcile` each repository (by forge id; mark
  unreachable; prune branches; a 404 or transfer only reports it gone),
  then `RepositorySync::unlinkGone` (all or none: only while the bot still
  owns the forge organization under that name, and at most max(3, 25%) of
  the linked repositories, else `UnlinksHeldBack`), `ForgeMembership`
  (Owners = active organizational admins; remove people `GitAccess` no longer
  admits), prune deliveries. Never touches `forge-admin`, `{owner}-bot` or
  logins the tracker doesn't know. Circuit breaker: a step that would remove
  more than max(5, 25%) of the tracker-known logins removes nobody
  (`TooManyForgeRemovals`) and the command exits non-zero;
  `--allow-removals` lifts both breakers (not the ownership check) for one
  run. Membership changes and unlinks log at `warning` (production's level).
  Organizational-admin flag changes run `SyncForgeOwners` at once. Re-pointing a
  connection (another forge or owner) unlinks its repositories; turning git
  off points each back at the forge's own issue tracker first.
- **UI**: the project's Code tab (`project/CodeView.svelte`), the task's Code
  section (`TaskCodePanel.svelte`, Peek and full page), the board card's
  pull-request mark; absent without git.

## Git (Forgejo), phase 2

Spec: `docs/superpowers/specs/2026-10-30-git-phase-2-access-and-reports-design.md`.
Runbook: `docs/forgejo.md` section 13. API: `docs/api.md`.

- **Models**: `GitRepositoryGrant` (`git_repository_grants`: `read|write`,
  unique `(git_repository_id, member_id)`, `granted_by`; morph `git-repository-grant`;
  `scopeEffective` = the member is active and in the project's organization
  or on its team, the rest stays stored and gives nothing; `isEffective`
  asks it of one grant, and the resource's `effective` says it, the access
  list asking the scope once). `GitRepository`
  gains `protects_main` (set only for repositories the tracker created and
  protected) and `access_synced_at`. `GitCommit` gains `user_id` (the user
  whose lowercased `git_username` equals `author_username`), `is_merge`,
  `files_changed`, `additions`, `deletions`, the classified
  `code|comment|blank|docs` `_added|_removed`, `generated_lines`,
  `stats_status` (`ok|merge|too_large|missing`) and `stats_computed_at`.
- **Create**: `CreateRepositoryService` (`POST .../repositories/new`, the web
  Code tab's New repository): private, empty, `main` default; name = the
  lowercased project key or that plus `-...`, forge-safe, at most 100
  characters; protects `main` with `CreateRepositoryService::MAIN_RULE`
  (push allow-list plus the Owners team, one approval), then links through
  `LinkRepositoryService`. A refused rule still links (message says to
  protect it); a failed link after creation says it exists.
- **Link imports access**: `LinkRepositoryService` saves the row and
  `RepositoryAccess::importExisting` (the forge's current collaborators an
  organizational admin could grant, whoever links: an active person of the
  project's organization or on its team, not a maintainer, as
  `RepositoryAccessCandidatesQuery` lists them for an organizational admin;
  `admin`/`owner`/`write`
  become `write`, `read` stays) in one transaction; if the forge cannot
  answer, nothing is linked ("Couldn't read ... so it wasn't linked").
  Repositories linked before phase 2 were never imported: their first sync
  takes off tracker-known collaborators without a grant.
- **Sync**: `App\Services\Git\RepositoryAccess::sync` writes a repository's
  collaborators: maintainers `admin`, effective grants at their permission
  (the higher when both). Order: removals of tracker-known logins no longer
  wanted (a failure stops the run), then every PUT (a 422 = no forge user yet,
  *waiting*; another refusal for one login does not stop the rest and fails
  the run afterwards; unreachable stops at once), then, if `protects_main`,
  the PATCH of `main`'s allow-list (last: the forge drops allow-listed users
  who are not write collaborators at that moment; a 404 clears
  `protects_main`). `{owner}-bot`, `forge-admin` and logins with no tracker
  user are never touched (`ForgeMembership::untouchableLogins`). Logs at
  `warning`, no token.
- **Triggers**: `SyncRepositoryAccess` (a job: no unique lock, `afterCommit`,
  reads its repository afresh, skips an unlinked one; it catches every
  `ForgejoException`, logs it and returns, so the ten-minute run is the retry)
  from `GitRepositoryGrantObserver` (also writes the activity row),
  `TeamMembershipObserver` (add, role change, leave; leaving first deletes that
  member's grants on the project's repositories, one by one, in the same
  transaction), `ProjectObserver` (program change), `ProgramObserver` (new
  manager), `MemberObserver` (organization change), `LinkRepositoryService`;
  plus `git:sync-access` every ten minutes (`withoutOverlapping(15)`) and the
  reconcile's per-repository `access` step. `Background::dispatch` on the sync
  queue now waits for the surrounding transaction to commit (a rollback drops
  the job); never dispatch forge work any other way.
- **Offboarding**: `RemoveFromForgeService` removes a deactivated or deleted
  person from every connected forge organization, then lists every
  repository of each of those forge organizations (linked or not) and its
  collaborators, and removes them only where they are one (login in any
  case), per connection with its own token; an unreachable forge
  short-circuits the connection's remaining calls, only real removals log
  and are listed, and a forge failure never blocks the change
  (`meta.complete` false, the failed repositories, or `{owner}/*` when an
  organization's repositories can't be listed, in `failed`; the message asks
  for a manual suspend). Unlinking, turning git off and re-pointing a
  connection leave forge collaborators as they are (the unlink message says
  so).
- **Access rules** (do not regress): grant, change and remove need
  `canManageRepositoryAccess`; `StoreRepositoryGrantRequest` and
  `UpdateRepositoryGrantRequest` check `canGrantRepositoryAccessTo` and answer
  an unknown member, or one outside the project's organization and team, with
  the same generic 422 on `member_id` (never naming outsiders); a manager
  cannot change an organizational admin's grant to someone off the team, only
  remove it. A maintainer's access is never a grant. A cross-organization
  team's outsiders can hold grants only while on the team.
- **Commit stats**: `git:commit-stats` (every five minutes,
  `withoutOverlapping(30)`, 300 commits per run, oldest first; `--limit`)
  via `CommitStats::compute`: more than one parent is a merge (marked, not
  counted); else the forge's totals plus the diff
  (`ForgejoClient::commitDiff`, streamed, over 5 MB is `too_large`) classified
  by `App\Support\Git\DiffStats` (`CommentSyntax` per file type; `GeneratedFiles`
  skipped; docs by extension; block-comment state per hunk side); a 404 is
  `missing`. Every attempt counts toward `--limit`; 0/401/403/429 stop that
  connection for the run, other 4xx and 5xx skip only that commit, five
  consecutive 5xx stop the connection. It also fills `user_id` on commits
  ingested before their author had a `git_username`.
- **Code activity report**: `CodeActivityQuery` over
  `WorkspaceAccess::codeReportScope` (whole repositories of what the viewer
  runs, plus anyone's own commits), non-merge and reachable commits by
  `committed_at`; `CodeActivityRequest` (anyone `GitAccess` admits; range at
  most 366 days, default 30; up to 4 compared people; a project or program
  outside the scope, or unknown, is 403). People are ordered by name, never by
  a figure; commits matched to no user are one "Not matched to anyone" row,
  only for viewers of whole repositories. Web `/workspace/reports/code` (page
  `Reports/CodeActivity`) and `/workspace/reports/code/people/{user}`; API
  `/api/v1/workspace/reports/code[/people/{user}]`.
- **UI**: the Code tab's New repository and Access (grants and maintainers),
  the sidebar's Code activity (shown with git). Keep the Guide in step:
  developers commit with the email they sign in with (sign-in gives the forge
  that email; nothing to add on the forge).

## Structure, attendance and work reports

Spec: `docs/superpowers/specs/2026-10-31-structure-attendance-work-design.md`.
API: `docs/api.md` (Structure, Attendance, Leave and kaaj, Work reports).

The owner's rule for these parts: every read is one query class under
`app/Queries/{Structure,Attendance,Work}/`, every write one service under
`app/Services/{Structure,Attendance}/`; the web and API controllers only
call them, so a new client (the Flutter app, a fingerprint machine) needs
no new query. Never write a query in a controller, request, resource or
page for these parts, and never write a rule twice: each one below has a
single home, so reuse it.

Shared building blocks (reach for these before writing anything new):

- `app/Queries/Structure/`: `PeopleInViewQuery` (whose rows a viewer's
  list shows; `attending:` for attendance; `find` for a report's `under`),
  `OrganizationChoicesQuery` (which organization a page opens on and which
  a super-admin may switch between; `withAttendance:` for attendance
  pages), `ReportOptionsQuery` (a list report's `options`: organizations
  and teams), `PersonRef` (how every report names a person).
- `app/Http/Requests/Concerns/`: `ReadsReportScope` (organization and
  `under` for a list report; the report supplies `maySeeOrganization`),
  `ReadsDay` (`?date=`), `ReadsDayRange` (`?from=`/`?to=`, overridable
  `defaultFrom` and `maxRangeDays`; the code activity report uses it too).
- Model scopes: `Member::scopeAttending` (who attendance expects),
  `AttendanceSetting::scopeEnabled`, `Organization::scopeWithAttendanceOn`.
- Frontend: `resources/js/lib/dates.ts` (days and clock times read as the
  office's, whatever the browser's timezone), `lib/format.ts`
  (`formatDistance`, `plural`), `ReportOptions` and `PersonRef` in
  `lib/types.ts`, `components/filters/` (`OrganizationSelect`,
  `ScopeSelects`), `components/organogram/` (`OrganogramOutline`,
  `OrgChart`, `DesignationsPanel`) and `components/attendance/settings/`.

- **Structure**: `designations` (per organization; `rank` orders them,
  smaller is more senior), `members.designation_id`,
  `members.reports_to_id` (one higher authority in the same organization),
  set only through `SetReportsToService` (refuses another organization, an
  inactive boss and loops: `OrgChart::wouldLoop`) by whoever passes
  `canManageStructure` (= `administers`); the person form's two fields are
  silently dropped for anyone else. `MoveReportsUpService` (from
  `MemberObserver`) moves someone's reports up to their own higher
  authority when they are deleted or change organization; deactivation
  keeps the lines. `App\Support\OrgChart` walks the lines
  (`descendantIds`, `ancestorIds`: recursive CTEs, memoized per request and
  flushed by `WorkspaceAccess::flush`, which `Tests\TestCase::setUp` also
  calls). Lines give no power over projects; they decide who sees whose
  attendance and work: `oversees` (super-admin, an admin of the person's
  organization, an active member of the same organization above them;
  never oneself), its list twin `overseenMemberIds` (keep them in step),
  `canViewPersonOf` (oneself or `oversees`), `canViewTeamWork`. Every list of
  people a report shows comes from `PeopleInViewQuery`. The organogram
  (`OrganogramQuery`, `/workspace/organogram`) is for the organization's
  active people (`canViewOrganogram`), never with emails; a super-admin
  opens on the first organization by name, like every such page.
- **Attendance**: off until a super-admin turns it on per organization
  (`EnableAttendanceService`, `canEnableAttendance`); its admins set
  workdays, hours, seasons (winter hours), grace, office places, office
  networks (nothing wider than /8 or /16) and holidays
  (`canManageAttendance`), and exempt people from the expected count on the
  person form (`attendance_exempt`, one of `SetsMemberStructure`'s fields). `AttendanceCalendar` is the one answer to "is
  this a working day, what are its hours, when is late". `PunchService`
  takes a day's punches in order (`AttendancePunch::nextKinds`: in, lunch
  start, lunch end, out), at the server's time, under a lock on the member,
  with the request's address and the reported location; `PunchPlace` judges
  where it came from (office network, near office within the radius plus
  the reading's accuracy up to twice the radius, outside, unknown; outside
  and unknown need a note). Punches are never edited or deleted; `source`
  is web, app (the API) or device (a machine, later). Location is recorded
  only at a punch. Leave and kaaj (`leave_requests`): asked by the person
  (`canPunch`), decided by whoever `oversees` them (`canDecideLeave`), or
  recorded by them directly (`canRecordLeaveFor`); kaaj is duty, never
  absence. Reads: `MyAttendanceQuery` (the top bar's `ClockButton`, shared
  as `clock`, never `attendance`, which the work pages use for their own),
  `AttendanceRegisterQuery` (a day: expected = listed attending people on
  a day that expects work, minus approved leave and kaaj unless they
  clocked in), `AttendanceSummaryQuery` (per attending person over a range,
  from the later of the day attendance was turned on and the day they were
  added), `LeaveQuery`,
  `AttendanceSettingsQuery`. The proxy (Traefik) replaces any client-sent
  `X-Forwarded-For`, so with `trustProxies('*')` the recorded address is
  the one the proxy saw; keep it so, and check from a phone on mobile data
  what the settings page shows as "Your address now" before trusting a
  network range.
- **Work**: `WorkActivityQuery` is the one read of what people did over a
  range: tasks closed and started by the activity log's actor (entering a
  closed or `is_started` status, `Task::startedStatuses`), non-merge
  reachable commits by `git_commits.user_id`, pull requests merged by
  `git_username`; titles only from projects the viewer may view, repository
  names only where they may view the code, the rest counted, never named.
  `WorkDayQuery` (today's work), `PersonWorkQuery` (a person's range) and
  `TeamWorkQuery` (a row per person) build on it, and on
  `WorkAttendanceQuery` for attendance (null while it is off). People are
  listed by name, never ranked by a figure.
- **Sidebar**: `OfficeLinks` (shared as `office`, on the API's `/user`)
  says which office pages the user may open; the pages check again.

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
