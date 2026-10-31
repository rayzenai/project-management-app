# Backend (Laravel 13)

## Request flow

```
Route → thin Controller → FormRequest::authorize() (via WorkspaceAccess)
      → action Service (returns App\Support\ServiceResult, never throws on expected failure)
      → JsonResource ($wrap = null, ISO-8601 dates, whenLoaded)
```

`ServiceResult` → response via traits: web uses
`Workspace\Concerns\RedirectsWithServiceResult` (Inertia redirect +
`workspace_flash`, errors via `withErrors`); API uses
`Api\Concerns\RespondsWithServiceResult` (`{message, data}` / `{message,
errors}` at `result->code`). Cheap ownership checks may `abort_unless` inline
(subtasks are personal).

## Two delivery surfaces

- **Web**: `routes/web.php`, prefix `workspace`, names `workspace.*`, `auth` +
  `EnsureCanSignIn` + `ShareWorkspaceData` middleware. Shared Inertia props: `statuses`,
  `completeStatus`, `quickAddContext`, `workspaceNotes`, `taskNotes`,
  `appearance`, `features`, `themeCatalogue`, `unreadNotifications`, `flash`,
  `isSuperAdmin`, `isOfficeAdmin`, `organization`, `ledTeamIds`,
  `managedProgramIds`, `managedPrograms` (`{slug, name}`), `gitEnabled`,
  `office` (`OfficeLinks` capabilities/count), `clock` (`MyAttendanceQuery`). Cross-cutting
  web context goes there. Status metadata includes `is_complete` and `is_closed`.
- **API**: `routes/api.php`, prefix `api/v1`, names `api.*`, Sanctum tokens;
  every authenticated route uses `auth:sanctum` + `EnsureCanSignIn`.
  `POST login` → token; `Api\AuthController::userPayload` is the token-auth
  twin of `ShareWorkspaceData`; it includes organization and organizational-admin
  context plus `features: {task_notes, contacts}`, `managed_program_ids`,
  `git_enabled` and `office` (`OfficeLinks`). Cross-cutting API context
  goes there.
  External systems get tokens via `php artisan workspace:api-token`.
  Reference: `docs/api.md`.

## Authentication

`WorkspaceAccess::canSignIn` is the one rule for signing in and remaining
signed in: a linked member must be active, while a configured super-admin or a
login with no member may still sign in. Web and API credential exchanges refuse
a deactivated person after validating the right password, without creating a
session/token. `EnsureCanSignIn` then covers every authenticated workspace/API
route and `/oauth/authorize`: it invalidates a deactivated web session with an
Inertia full-page visit to login, or answers an API token with 401. Both use the
same stable deactivation message.

`Support\LoginThrottle` gives web and API login one shared key per normalized
email and IP address. Five wrong passwords across either surface exhaust the
minute-long limit; a successful login clears it. Web returns the normal
validation error flow, while API throttling uses 429.

## Optional task features

`User::features()` reads `UserPreference.task_notes_enabled` and
`contacts_enabled` (boolean casts, database defaults false); missing preferences
also mean both off. Shared `UpdatePreferenceRequest` validates these optional
booleans for `PATCH /workspace/preferences` and `PATCH /api/v1/user/preferences`.
Both preference controllers preserve omitted settings; API GET/PATCH returns
both stored flag names alongside appearance and notification preferences.
`ShareWorkspaceData` returns an empty `taskNotes` feed while task notes are off.
These are per-user display preferences: existing data, task payloads, search
queries and authorized note/contact endpoints remain available.

## Authorization — SECURITY CRITICAL

`app/Support/WorkspaceAccess.php` is the single source of truth. Tiers:

1. **Super-admin** — `manage-workspace` Gate (emails in `PM_SUPER_ADMINS` /
   `config('project-management.super_admins')`). May belong to an organization through their own member;
   manages organizations and everything inside them. Only this tier can mark a
   team cross-organization or make a person an organizational admin.
2. **Organizational admin** — active member with `is_office_admin`. Full control
   inside their organization: people, projects, programs, managers, rosters and
   publication.
3. **Program manager** — active `programs.manager_id` in their own organization.
   Runs each project in that program (edit/archive, roster, progress, code and
   repositories) without joining its team. Creates projects in their programs
   and chooses/replaces their managers, from the project's organization even
   on a cross-organization team. Never removes a manager, creates programs,
   publishes/rekeys/moves projects or edits people.
4. **Project manager** — an active member with `member_team.role = 'leader'`
   on a project's own team. Edits/archives that project, manages its roster and
   sees its progress; never edits people, chooses managers or creates projects.
5. **Member** — sees their teams, teammates and projects plus public
   projects in their organization; manages own subtasks and notes.

Key methods: `isSuperAdmin`, `memberFor` (read-only), `canSignIn`,
`officeAdminOrganizationId`, `administers`, `canEditTeam` (organization admins),
`canAppointProjectManager` / project twin `canChooseManagerOf`,
`canAppointAsProjectManager` (eligible replacement),
`canManageRosterOf` / `canManageRosterOfProject` (loaded-project twin, no query
per project; keep in step), `canRemoveFromTeam`, `canJoinTeam`,
`canAssignOutsideTeam`, `canStartGeneralProject`, `canSetRolesIn`,
`canViewTeamProgress`, `canViewProjectProgress`, `canCreateMember`,
`canManageMember`, `canViewProject`,
`canCreateProject`, `canCreateProjectInProgram`, `createsProjectsOnlyInPrograms`,
`canManageProjectAccess`, `canPublishProject`,
`canChangeProjectKey` (organizational admin/super-admin), `canManageGit` (super-admin),
`canViewProjectCode`, `canManageProjectRepositories`, `canManageRepositoryAccess`,
`canGrantRepositoryAccessTo` (query twin: `RepositoryAccessCandidatesQuery`),
`repositoryMaintainerIds`, `codeReportScope` (applied by `CodeActivityQuery`),
`ledTeamIds`,
`managedProgramIds`, `managesProgramOf`, `canManagePrograms`, `canViewProgram`.
`Project::scopeVisibleTo` and `Program::scopeVisibleTo` are query twins of their
view predicates; `Team` and `Member` also expose `visibleTo`. Project/team/member
scopes reach program projects through `Project::inPrograms`, checking both the
program's and project's organization. Per-user `WeakMap` memos flush on
both `RouteMatched` and `RequestHandled`.
`ledTeamIds` is empty for inactive members: deactivated project managers keep
their pivot row but lose manager powers.

Do-not-regress protections: ordinary teams, projects and people never cross
organizations; every roster add goes through `canJoinTeam`; project teams must
share the project's organization; organization changes cannot strand a person
on foreign ordinary teams. `canManageMember` blocks a super-admin's linked
login, reserved super-admin emails and other organizational admins; managers cannot
reset anyone's password. `StoreMemberRequest`/`UpdateMemberRequest` also block
non-super-admins from assigning a reserved email (`isSuperAdminEmail`). Only a
super-admin may change a person's organization/organizational-admin flag or a
team's cross-organization flag. Organizational admins may set their people's
program, project-manager and team roles through the person form (see below).
Project `manager_id`, `is_public` and
`is_cross_organization` are capability-filtered by `validated()`. Appoint through
`AppointProjectManagerService`: the previous manager stays as a member; only whoever passes
`canRemoveManager` (= `canEditTeam`) may remove/demote the current manager.
A project may have no manager; program/project managers can only replace one. `project_team`
is unique on each column; a partial unique index allows one leader per team.
`NotifiesMentions::mentionableIds` filters stored mentions to eligible people;
mention notifications only reach project viewers. `TaskShowQuery` offers
`mentionCandidates` (team plus program manager) as `{id, name}` only; `team` remains the
existing assignee list; off-team `joinCandidates` are never mentionable.
Program management grants no team leadership or people-editing
rights. `UpdateMemberRequest` blocks organization changes while managing programs.
Only organization admins may change an existing project's `program_id`; other
editors' values are dropped by `validated()`, and kept values must belong to the
project's organization.
`StoreProjectRequest` requires program-only creators to pick one of their own
programs (`Choose one of your programs.`), derives its organization and drops
`key`/`is_public`; a super-admin may also derive the organization from a posted
program. `ProjectResource.can_choose_manager` and each roster member's
`can_make_manager` expose the appointment capabilities.

## People roles and task assignment

- `StoreMemberRequest` / `UpdateMemberRequest` share `Concerns/ChecksMemberRoles`:
  `managed_program_ids`, `managed_project_ids`, `team_ids` are complete wanted
  sets; omitted fields stay unchanged. Changes require administration of each
  affected organization and must fit the person's organization after any move.
  `UpdateMemberRolesService` hands over programs and appoints projects through
  `AppointProjectManagerService`; taking away a managed project requires
  `canRemoveManager` and leaves it manager-less with the person still on its
  team. It never clears a program manager, removes someone from a team they
  still manage or blindly syncs memberships. Inactive people cannot gain manager
  roles. Both member controllers roll the whole save back on a refused role.
  `MemberResource::ROLE_RELATIONS` loads all four role-id lists (including
  `led_team_ids`); `TeamIndexQuery` supplies `canSetRoles` and `roleOptions`
  (programs and active projects with current managers, bounded query count).
- `Support/Assignees::joiners` is the shared assignment rule for quick add and
  `StoreAssignmentRequest` / `AssignMemberService`: existing
  `Member::scopeAssignableFor` people, plus active people of the project's own
  organization for whoever manages its roster (`canAssignOutsideTeam`).
  `AddTeamMemberService` joins newcomers before assigning in one transaction;
  the same service powers the People tab, preserving membership notifications
  and activity. No foreign newcomer, even on cross-organization teams.
  `Assignees::joinCandidates` is the list twin; task show uses `joinCandidates`,
  preview uses `join_candidates`, each `{id, name, designation}` only.
- `QuickAddTaskRequest.start_general` with no `project_id` is allowed only for
  an organizational admin whose organization has no active project; otherwise
  422 `Pick a project.` on `project_id`. `StartGeneralProjectService` locks the
  organization, rechecks and creates General with the caller as manager through
  `CreateProjectService`. `QuickAddDispatcher` creates General and its task in
  one transaction; failure leaves neither. Parsed `@tokens` and default-to-self
  still resolve only through `scopeAssignableFor`.
- `ShareWorkspaceData.quickAddContext` adds project `organization_id`, program
  name, `member_ids`, `can_add_people`; `organizationPeople` (`{id, name, designation}`)
  is the viewer's active organization people only when they run a team there or
  may start General. `startGeneral` signals that option; `team` and
  `currentMemberId` remain. Roster capability uses the loaded-project twin to
  avoid a query per project.

## Domain model

- **Organization** — office/ministry tenant; has many members, teams and
  projects. `DeleteOrganizationService` refuses deletion until all three are
  empty.
- **Program** — slug-routed named collection in one organization, one manager
  chosen from its active members (nullable only after pruning that member). Optional
  `projects.program_id` puts each project in at most one program. Paired
  `ProgramController`s use shared Store/Update requests, Create/Update/Delete
  services and `ProgramResource`; only organizational admins/super-admins manage
  them. `CreateProgramService` atomically creates its private General project,
  managed by the program manager; failure or a missing General manager rolls
  back both. Names are unique within the organization ignoring case. Deletion detaches projects
  individually so `ProjectObserver` logs moves; projects stay. Web show and API
  `programs/{program:slug}/progress` use `ProgramProgressQuery` + `ProgressReport`
  across active projects; API `GET programs` lists visible programs.
  `ProgramResource.can_create_project` and `ProgramProgressQuery.managerCandidates`
  support project creation from the program page as well as admin editing.
- **Project** — belongs to an organization; slug-routed, `archived_at`
  soft-archive, `is_public` within that organization; scopes
  `visibleTo`/`active`/`archived`; hasMany Task, belongsToMany Team (one each).
  `team()`/`ensureTeam()`/`manager()` expose its own team and manager;
  `CreateProjectService` creates both atomically; `StoreProjectRequest`
  requires `manager_id` for interactive creation. `label()` names it with its
  program ("General (Revenue)"), mirrored by `lib/projectLabel.ts`.
  `ProjectResource.can_publish` exposes the organizational-admin/super-admin
  publication capability.
  Stored `key` (1 to 10 uppercase ASCII letters) is also its `code`;
  `ProjectKeys` suggests a unique key from the title (fallback `P`). Current keys
  and `ProjectKeyAlias` entries reserve keys within the organization. Renaming
  via `UpdateProjectService` retains the old key as an alias. Requests filter
  key changes through `canChangeProjectKey`; resources expose `can_change_key`.
  Saved projects selected for `code` must load `key`; no title-based fallback.
- **Task** — slug-routed `{project_id}-{slug}`; 100-point-plan fields
  (`item_number`, `category`, `deadline_type`, `responsible_ministry`,
  `title_np`, `description_np`) live in the `metadata` jsonb via `Attribute`
  mutators; `Task::completeStatuses()` derives "finished" from config
  `is_complete` flags; `closedStatuses()`/`isClosed()` use `is_closed` plus
  complete statuses (currently done and failed). `notClosed()` selects open
  work; `overdue()`/`is_overdue` mean not closed and deadline before today.
  Queries, reminders and task/reference/progress payloads share this rule;
  Home excludes closed work. `is_late` also covers finished-late/failed work
  and remains a separate derived attribute, never a status.
  Permanent `number` comes from `projects.last_task_number` in the creating
  hook; create/quick-add services lock the project row. Unique per project,
  never fillable/request-editable, never reused even after purging trash.
  `item_number` remains editable plan metadata (1..2147483647);
  `nextItemNumber()` includes trash and returns null at the limit.
  The backfill preserves unique legacy item numbers in 1..100000, then assigns
  the rest sequentially. Resources/references/progress expose `number`;
  `TaskSearchQuery` matches bare numbers or case-insensitive `KEY-12` using
  current keys or aliases, within visible active projects.
- **TaskDependency** — finish-to-start link (`task_id` waits for
  `depends_on_task_id`); `Task::dependencies()`/`dependents()` expose the graph.
  `StoreTaskDependencyRequest` rejects self-links, duplicates, other projects
  and cycles (`TaskDependency::reaches`). Add/remove services and paired
  controllers serve `POST/DELETE tasks/{task}/dependencies[/{dependsOn}]`.
  `TaskResource` emits references when loaded; `QuickAddTaskService` accepts
  initial dependency ids, ignoring tasks outside the chosen project.
- **Subtask** — personal (`user_id`). **Member** — belongs to one organization
  (except a super-admin's own member), optionally links to a `User`, and may be
  an organizational admin. `Member::forUser` `firstOrCreate`s (never call in
  authorization); `managedPrograms()` exposes its managed programs.
  `scopeAssignableFor(Project)` supplies existing assignees; `Assignees` adds
  eligible newcomers for roster managers. Creating a person also creates a login
  and synchronously emails its supplied/generated password via `SendLoginDetailsService`; passwords are
  never queued.
- **Team** — made with its project and named after it (`Team::project()`);
  belongs to the same organization, is auto-slugged, may be
  `is_cross_organization`, and uses pivot `member_team.role` through
  `TeamMembership` on both Member/Team relations. Candidate search
  is limited to the home organization unless cross-organization. No standalone
  team CRUD routes; `TeamIndexQuery` serves the People directory, while roster
  endpoints remain under `teams/{team}`.
- **ProjectProgressQuery + ProgressReport** — manager/admin report at
  `projects/{project:slug}/progress` on both surfaces, authorized by
  `canViewProjectProgress`. Includes eligible people and former teammates still
  assigned work, up to 500 project tasks (including dependency ids), workload
  and recent activity. The web polls `progress` every 15s while visible; old
  web team-progress links redirect here. No team-progress API remains.
- **ProjectActivity** — polymorphic audit log, written only by
  observers via `ProjectActivityRecorder`. Each new row carries `project_id`;
  `task_id` is null for project-level entries. `TeamMembershipObserver` records
  roster additions/removals and manager handovers through `recordForProject`,
  using the team as subject. The migration backfills existing projects and
  converts `changes` to jsonb on PostgreSQL. Surfaced through
  `App\Queries\ActivityFeedQuery` on the activity
  tab of the notifications screen (`/workspace/notifications?tab=activity`);
  visibility scopes `project_id` to projects `visibleTo` the viewer; progress
  and dashboard feeds also include team changes. Never filter the
  feed on `->public()` (observers write `is_public = false`).
  `ProjectObserver` also records program moves; Git observers record repository
  links, repository grants and PR activity. Forge-originated entries have no
  actor; PR activity belongs only to tasks of the repository's own project.
  Git activity also requires code access, including in dashboard, task and activity feeds.
  **WorkspaceNote** — personal stickies.
  **ProjectAssignment/Note/Contact, TaskComment** — task children.

## Notifications

`database` channel only, synchronous. `TaskAssigned`, `TaskStatusChanged`,
`MentionedInComment`, `TaskDeadlineDue`, `AddedToProjectTeam`,
`MadeProjectManager` and `MadeProgramManager` share
`Concerns\BuildsWorkspaceNotification`; stable payload
`{kind, title, action, body, task, actor, url}` (`action` short-form,
`body` long sentence). Dispatched from observers (assignment created;
status entering done/failed), the comment service (mentions via
`MentionParser`), and the reminders command. Team membership creation or
promotion notifies the linked user, except the actor; team payloads have
`kind: team_added`/`made_manager`, `task: null`, a `project {slug, title}`
and a URL to the project's People tab. `NotificationIndexQuery` groups both
under the `team` filter/count on both surfaces. `ProgramObserver` sends
`made_program_manager` for appointments/handovers except to the actor, with
`task: null`, `program {slug, name}` and the program URL; also in the Teams filter.

## Scheduled / console commands

Schedule in `routes/console.php`. Commands: `workspace:api-token`,
`workspace:prune-trashed` (trash TTL from config) and
`workspace:send-deadline-reminders`; both maintenance commands support
`--pretend`. Weekly digest mail, command and subscriber model/table are retired.
`passport:purge` also runs daily. `git:oidc-client {redirect_uri}` creates/reuses
the sole Forgejo client; reruns keep its id and rotate its secret.
`git:reconcile [--connection=] [--allow-removals]` runs daily at 01:30 without
overlapping; see the phase 1 map below for repair and removal safeguards.
`git:sync-access [--connection=]` runs every ten minutes with a 15-minute
overlap lock; `git:commit-stats [--limit=300]` every five minutes with a
30-minute lock. `git:commit-checks [--limit=200]` runs every two minutes with
a ten-minute lock (see checks below). When `/proc/1/fd/2` is writable, the schedule appends every
event's output there so command output and `LOG_CHANNEL=stderr` logs reach the
scheduler container log. See phase 2 below.

`SdocProjectsSeeder` seeds SDOC and eight private projects with their own teams,
initially without managers; not called by `DatabaseSeeder`. Per `CLAUDE.md`, use
it only on fresh databases, never production. Existing production projects get
agreed keys from `2026_10_31_140000_give_sdoc_projects_their_agreed_keys.php`:
its own frozen SDOC/title/key map, alias-preserving `UpdateProjectService`,
automatic keys only, conflicts logged and skipped, no records created.

Application and PostgreSQL session timezones are fixed to `Asia/Kathmandu`.
The one-time timestamp migration converts existing UTC wall-clock values.

## Git / Forgejo (phase 0)

- `Organization::gitConnection()` is one encrypted-token connection per office;
  `GitConnection` hides token/webhook secret. Paired `OrganizationGitController`s
  serve `POST/DELETE organizations/{organization}/git` via shared requests and
  `EnableGitService`/`DisableGitService`. Enable verifies the token user's forge
  organization ownership with `Services/Git/ForgejoClient` before saving. HTTPS
  is required except on loopback; one forge organization connects to one office.
  `OrganizationResource.git` exposes only base URL, owner and URL to super-admins.
- OIDC: Passport + `jeremy379/laravel-openid-connect` (replaces Passport's provider;
  Passport auto-discovery/routes disabled). `routes/web.php` registers authenticated
  `/oauth/authorize` behind `EnsureCanSignIn` then `EnsureCanUseGit`, and sessionless, throttled
  `/oauth/token`; `config/openid.php` enables discovery/JWKS, disables userinfo,
  fixes issuer to `APP_URL`. `OAuthClient` skips consent. Access/refresh lifetimes:
  one hour/one day. `User` retains Sanctum's `HasApiTokens`.
- `Support/Git/GitAccess::organizationsFor` gates sign-in and `git_orgs`: connected
  offices only; super-admins get all, active members their own plus offices of
  projects whose teams they belong to. Inactive/missing members get none.
  `Oidc/IdentityEntity` emits identity claims and, for the `git` scope, `git_orgs`
  and `groups` (`forge-admins` for super-admins); absent access omits `git_orgs`.
  `GitUsernames` assigns `users.git_username` once, respects Forgejo's alphabet,
  reserved names/endings and shared user/organization namespace, and never renames
  it after an email change. Login uses `Inertia::location` for OIDC redirects.
- Member controllers call `RemoveFromForgeService` after deactivation/deletion:
  remove the stored username from every connected organization, then from
  collaborators of every repository each organization owns, linked or not,
  using each connection's token. Lists collaborators first, compares logins
  ignoring case and reports/logs only real repository removals. Unreachable
  forges short-circuit that connection. Failures never block the member change;
  `meta.complete` is false, `failed` names organizations/repositories (`owner/*`
  when listing failed), and the message asks for manual suspension.
  Tokens/secrets must never reach resources, old input, errors or logs.
- Runbook/deploy kit: `docs/forgejo.md`, `forgejo/` (separate Dokploy application,
  SDOC Git branding in `custom/`, Homebrew local runner); broader design and
  later phases: `docs/superpowers/specs/2026-10-29-git-integration-design.md`.

## Git / Forgejo (phase 1: code)

- Models: `GitRepository` links to one project, unique per connection/forge id;
  `GitBranch`, `GitCommit` (retained with `unreachable_at`), `GitPullRequest`
  (`open|merged|closed`, `forge_updated_at` rejects older snapshots). `TaskGitLink`
  is a morph pivot with repository cascade. The strict morph map includes
  `git-repository`, `git-repository-grant`, `git-branch`, `git-commit`, `git-pull-request`.
- Paired `ProjectRepositoryController`s, shared link/search/unlink requests and
  `LinkRepositoryService`/`UnlinkRepositoryService` manage links; candidates come
  from `RepositoryCandidatesQuery`. `canViewProjectCode` requires git and team
  membership, program management, organizational administration or super-admin access;
  public-project visibility alone is insufficient. Query twin:
  `Project::scopeCodeVisibleTo`. Changes need `canManageProjectRepositories`.
  `TaskCodeQuery::inVisibleRepositories` filters task code and board PR flags by
  the source repository's project access, even for references across projects.
- `Services/Git/GitIngest` is the single ingest/linking path for webhooks,
  backfill and reconcile; `ForgeShapes` maps forge JSON. `Support/Git/TaskReferences`
  parses uppercase text references, any-case branch references, skipping code
  and URLs. Keys/aliases resolve inside the repository's organization; unknown
  keys and trashed tasks link nothing. Git events never change task status.
- `GitWebhookController`: sessionless, throttled `POST /git/webhooks/{connection}`;
  raw-body HMAC-SHA256, 2 MB cap, UUID deduplication via `GitWebhookDelivery`.
  `ProcessGitDelivery` applies each delivery once; unprocessed redeliveries retry.
  `BackfillGitRepository` uses `RepositorySync::catchUp`. `Support/Background`
  dispatches after the response on the sync queue (only after any surrounding
  transaction commits; rollback drops the job), or to a configured worker.
- `ReconcileGit` repairs hook settings/secret, reconciles repository snapshots,
  marks unreachable commits, prunes branches, unlinks gone repositories, syncs
  forge membership and prunes deliveries after 14 days. `RepositorySync::unlinkGone`
  requires the bot still to own the forge organization; more than max(3, 25%)
  unlinks are held back. `ForgeMembership` keeps Owners aligned with active office
  admins and removes tracker-known logins no longer admitted by `GitAccess`,
  preserving `forge-admin`, the bot and unknown logins. More than max(5, 25%)
  login removals per step are held back. Breakers exit non-zero;
  `--allow-removals` lifts both volume limits for one run, never the ownership check.
  Changes log at warning. `SyncForgeOwners` runs on organizational-admin flag changes.
- Repointing a connection unlinks its repositories; disabling git and unlinking
  hand the forge its own issue tracker back. Forge collaborators stay as they
  are; unlink feedback says access persists until changed there or linked again.
  Offboarding still removes deactivated people from unlinked repositories.
  `GoToTaskController` handles
  `/workspace/go/{org}/{index}` (including aliases); inaccessible tasks return
  404 and sign-in uses a full page visit. Setup/worker runbook: `docs/forgejo.md`
  section 12; plan: `docs/superpowers/plans/2026-10-30-git-phase-1-code.md`.

## Git / Forgejo (phase 2: access and reports)

- `GitRepositoryGrant` stores unique repository/member `read|write` access and
  `granted_by`. `scopeEffective` / `isEffective` require an active, undeleted
  member in the project's organization or on its team; other grants stay stored
  but give nothing. `GitRepository` adds `protects_main` and `access_synced_at`.
- Paired `RepositoryGrantController`s serve scoped
  `projects/{project:slug}/repositories/{repository}/access` (list/add),
  `/access/candidates`, and `/access/{grant}` (PATCH/DELETE), using shared
  Show/Store/Update/Destroy requests, Grant/Revoke services and
  `GitRepositoryGrantResource`. `RepositoryAccessQuery` lists maintainers and
  grants by name, without emails, with `waiting` (no forge username) and grant
  `effective`; the list asks the effective scope once. Candidate search returns
  at most 20 `{id, name, designation}` matches, excluding maintainers/existing grants.
- `canManageRepositoryAccess` follows `canManageProjectRepositories`.
  `canGrantRepositoryAccessTo` permits organizational admins/super-admins to
  grant active organization people or teammates; project/program managers only
  teammates. Never grant maintainers, deleted/inactive people or an
  organization-less member. Unknown/foreign off-team IDs get the same generic
  422 without naming outsiders. Managers may remove an admin's off-team grant
  but cannot change its permission. `repositoryMaintainerIds` supplies the
  active project manager and active program manager (same organization as
  both program and project), deduplicated; their forge access is `admin`.
- `CreateRepositoryRequest` + `CreateRepositoryService` serve paired
  `POST .../repositories/new`: private repository with a README, default `main`, name
  equal to the lowercased project key or prefixed by `key-`, forge-safe, at
  most 100 characters. `MainBranch::RULE` blocks pushes (including admins),
  requires one approval and every check to pass (see checks below). Protection
  failure still links with a warning;
  link failure says the forge repository was created. `LinkRepositoryService`
  imports existing collaborators via `RepositoryAccess::importExisting` in the
  link transaction, regardless of caller: eligible active organization people
  and teammates, excluding maintainers; `admin|owner|write` becomes `write`.
  Failed import rolls back the link. Pre-phase-2 links had no access import.
- `Services/Git/RepositoryAccess::sync` removes unwanted tracker-known
  collaborators first (a failed removal stops grants), PUTs maintainers and
  effective grants at the higher permission, then calls `MainBranch::ensure`
  on the default branch every run. A PUT 422 waits for first forge sign-in;
  other refusals allow remaining PUTs then fail; unreachable fails immediately.
  A refused rule sets `protects_main` false and fails the run after remaining
  work. Bots, `forge-admin` and unknown logins are untouched. Only
  successful sync updates `access_synced_at`; changes/failures log at warning.
- `SyncRepositoryAccess` has no unique lock, uses `afterCommit`, reloads the
  repository, skips unlinked rows and logs/catches forge failures. Triggered
  through `Background` by grant changes, team joins/role changes/leaves,
  program/project manager scope changes, member organization moves and linking.
  Leaving a team deletes its repository grants individually in the same
  transaction. `GitRepositoryGrantObserver` also writes code-gated activity.
  `git:sync-access` retries every ten minutes (non-zero if any repository fails,
  unreachable skips the connection's remaining calls); nightly reconcile adds
  a per-repository access step.
- `GitCommit` stores matched `user_id` (case-insensitive forge username),
  `is_merge`, forge totals, files changed, code/comment/blank/docs added/removed,
  generated lines and `stats_status` / `stats_computed_at`.
  `Services/Git/CommitStats::compute` marks merges without a diff, classifies
  streamed diffs up to 5 MB through `Support/Git/DiffStats`, and records
  `ok|merge|too_large|missing`. `CommentSyntax` provides heuristic per-file
  comment rules; state is separate per hunk side. `GeneratedFiles` excludes
  dependencies, lockfiles and build output from code counts. `ComputeCommitStats`
  attempts oldest pending commits first, failed attempts counting toward the
  limit, and fills older unmatched `user_id`s. Forge sign-in sets the account's
  primary email to the tracker login email, so developers normally match commits
  by configuring git to use that same email. Status 0/401/403/429 stops that
  connection; other failures skip a commit, five consecutive 5xx stop it.
- `CodeActivityQuery` applies `WorkspaceAccess::codeReportScope`: whole
  repositories of the organizations/programs/projects the viewer runs, plus
  their own commits anywhere. Non-merge, reachable commits count by local
  `committed_at`; PRs opened/merged, linked tasks, line totals and active days
  accompany them. People default to name order, unmatched authors to one final
  row only for whole-repository viewers. Daily series include zero days.
  Shared `CodeActivityRequest` requires `GitAccess`, defaults to 30 days,
  caps at 366 days and four compared user IDs; unknown/out-of-scope project or
  program filters are 403. Paired controllers serve web `/workspace/reports/code`
  (`Reports/CodeActivity`) and API `/api/v1/workspace/reports/code`, with
  `/people/{user}` returning the latest 50 scoped commits; unknown/out-of-scope
  people return an empty list. Runbook: `docs/forgejo.md` section 13; spec/plan:
  `docs/superpowers/{specs,plans}/2026-10-30-git-phase-2-access-and-reports*.md`.

## Structure, attendance, work and maintenance

These reads live only in `app/Queries/{Structure,Attendance,Work,Maintenance}`;
structure/attendance writes live in `app/Services/{Structure,Attendance}` and
return `ServiceResult`. Both controller surfaces reuse them; never put a query
in these controllers, requests, resources or pages.

- Shared reads: `Structure/PeopleInViewQuery` lists permitted people (optional
  `under`, `attending: true`), `OrganizationChoicesQuery` chooses/defaults offices
  (own office first, otherwise first offered by name for a super-admin;
  `withAttendance` limits choices),
  `ReportOptionsQuery` supplies report filters, `PersonRef::of` emits
  `{id, name, designation}` from a loaded relation, never querying.
- Shared requests: `Concerns/ReadsReportScope` authorizes organization/`under`
  (unknown/malformed/forbidden `under` all 403), `OpensOrganization` opens a
  single office, `ReadsDay` reads `date`, `ReadsDayRange` reads `from`/`to` with
  overridable default/range cap (also reused by `CodeActivityRequest`).
- Structure: `Designation` belongs to an organization, `rank` sorts senior first;
  `members.designation_id` replaces free-text `title` (migration preserves old
  titles as designations). `reports_to_id` is one higher authority in the same
  organization. `SetReportsToService` locks the organization and refuses self,
  inactive/foreign bosses and loops (`Support/OrgChart::wouldLoop`).
  `SetDesignationService` accepts an organization's own designation.
  `Concerns/SetsMemberStructure` filters the person form's `designation_id`,
  `reports_to_id`, `attendance_exempt` through `canManageStructure`; omitted
  values stay unchanged. Refused reporting changes roll back the person save.
- `MoveReportsUpService` (MemberObserver) moves reports up on deletion or an
  organization move; deactivation keeps lines. `OrgChart` memoizes recursive
  ancestor/descendant CTEs; `WorkspaceAccess::flush` clears them per request and
  `Tests/TestCase` per test. Lines give attendance/work visibility, no project
  powers. `oversees` means an admin of the person's organization or an active
  same-organization ancestor, never oneself; `overseenMemberIds` is its list
  twin. `canViewPersonOf` adds self. `canViewOrganogram` admits active office
  people; `OrganogramQuery` never exposes emails. Structure writes need
  `canManageStructure` (= `administers`).
- Attendance is off until a super-admin enables it (`EnableAttendanceService`,
  `canEnableAttendance`); organization admins configure hours, workdays,
  seasons, grace, office places/networks and holidays (`canManageAttendance`).
  Models: `AttendanceSetting`, `AttendancePlace`, `AttendanceNetwork`, `Holiday`,
  `AttendancePunch`, `LeaveRequest`. `Member::scopeAttending` excludes exemptions;
  settings `enabled` and organizations `withAttendanceOn` share the enabled rule.
- `Support/Attendance/AttendanceCalendar` is the workday/hours/lateness rule.
  `PunchService` locks the member and records server-time punches in
  `AttendancePunch::nextKinds` order: in, lunch start, lunch end, out. Never
  edit/delete punches. Source is web/app/device. `PunchPlace::judge` records
  office network, near office, outside or unknown; location accuracy can extend
  the radius only to twice its size. Outside/unknown need a note. Location is
  captured only at a punch. Networks disallow ranges wider than IPv4 /8 or
  IPv6 /16; trusted proxy addresses rely on Traefik replacing forwarded input.
- `MyAttendanceQuery` is the shared `clock` payload (not `attendance`, used by
  work pages). `AttendanceRegisterQuery` shows a day; `AttendanceSummaryQuery`
  counts only from attendance enablement/person creation through today. Expected
  attending people on workdays exclude approved leave/kaaj unless they clock in.
  `LeaveQuery` reads requests; Request/Decide/Record/CancelLeave services write
  them. Kaaj is duty, not absence. `canPunch` gates personal requests;
  `canDecideLeave` / `canRecordLeaveFor` require `oversees`, never self-approval.
- `Work/WorkActivityQuery` shares tasks started/closed by activity actor
  (`Task::startedStatuses`/`closedStatuses`), reachable non-merge commits by
  `user_id` and PRs by author `git_username`. Hidden projects/repositories are
  counted but never named. `WorkDayQuery`, `PersonWorkQuery`, `TeamWorkQuery`
  compose it with `WorkAttendanceQuery` (null when attendance is off). Lists use
  `PeopleInViewQuery` and name order. Routes: web `/workspace/work`,
  `/workspace/people/{member}/work`, `/workspace/work/team`; API equivalents
  include organization paths for list reports (see `routes/api.php`, `docs/api.md`).
- `Support/OfficeLinks` supplies sidebar/API office capabilities and cached
  maintenance count; each page authorizes again. `MaintenanceQuery` needs
  `canViewMaintenance` (= `administers`), checks manager-less/empty/quiet active
  projects, unassigned/inactive-only tasks, unmanaged programs, disconnected
  people/missing designations/logins/inactive bosses, missing attendance places
  and networks, and failing/unprotected/untested default branches. New checks
  belong in its lists, `countOf`, and the Maintenance page's report type.
  `QUIET_DAYS = 30`, `COUNT_TTL_SECONDS = 300`; full reads refresh the count.

## Forgejo runners, protected main and checks

- `forgejo/runner/` contains four organization runners in Dokploy's `git-runners`
  compose app, one job each; all four share one dind limited to 4 vCPUs, 10 GB
  memory and 30 GB file-backed ext4 Docker storage, with no actions cache. `forgejo/Dockerfile` enables Actions
  with GitHub as the default action source. Runbook: `docs/forgejo.md` section 14.
- `Services/Git/MainBranch::RULE` describes every branch protection field:
  no pushes including repository admins, one approval and all status checks
  (`*`) passed; no tests means no merge. Forge site admins can bypass it.
  `ensure` reads first, writes only on drift, creates a missing rule (rereads a
  concurrent-create refusal) and skips a branch that does not yet exist.
  Create and every repository access sync use this one rule.
- `Support/Git/Checks` maps commit statuses to `passed|failed|running|none`, only
  success passes; pending runs, error/failure/warning fail. `shape` returns null
  until checked. Relative run paths become absolute; URLs survive only on the
  forge's own host. `Services/Git/CommitChecks::refresh` stores checks on the
  commit and same-repository PR heads; `GitIngest::pullRequest` copies them on
  head changes. `catchUpPullRequests` repairs missed open-PR copies.
- Workflow success/failure webhooks trigger refresh. `git:commit-checks
  [--limit=200]` runs every two minutes (`withoutOverlapping(10)`), asks reachable
  commits learned in the last two days with unknown/running checks, and retries
  `none` for ten minutes only for commits made within a day. Minimum interval
  90s; 0/401/403/429 stop a connection. `ReconcileGit` replaces hooks missing
  events or with recent bad signatures (new hook/secret then delete old), since
  Forgejo's hook edit does not write the secret/workflow events.
- `MainChecksQuery` reads default-branch checks in one query;
  `GitRepositoryResource` exposes `main_checks` and `protects_main`, task/PR/code
  report payloads expose checks, code activity counts passed/failed checks, and
  Maintenance lists failing/unprotected/untested repositories.
