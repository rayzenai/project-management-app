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
  `ShareWorkspaceData` middleware. Shared Inertia props: `statuses`,
  `completeStatus`, `quickAddContext`, `workspaceNotes`, `taskNotes`,
  `appearance`, `themeCatalogue`, `unreadNotifications`, `flash`,
  `isSuperAdmin`, `isOfficeAdmin`, `organization`, `ledTeamIds`. Cross-cutting
  web context goes there. Status metadata includes `is_complete` and `is_closed`.
- **API**: `routes/api.php`, prefix `api/v1`, names `api.*`, Sanctum tokens.
  `POST login` → token; `Api\AuthController::userPayload` is the token-auth
  twin of `ShareWorkspaceData`; it includes organization and office-admin
  context. Cross-cutting API context goes there.
  External systems get tokens via `php artisan workspace:api-token`.
  Reference: `docs/api.md`.

## Authorization — SECURITY CRITICAL

`app/Support/WorkspaceAccess.php` is the single source of truth. Tiers:

1. **Super-admin** — `manage-workspace` Gate (emails in `PM_SUPER_ADMINS` /
   `config('project-management.super_admins')`). Belongs to no organization;
   manages organizations and everything inside them. Only this tier can mark a
   team cross-organization or make a person an office admin.
2. **Office admin** — active member with `is_office_admin`. Full control inside
   their organization: people, projects, managers, rosters and publication.
3. **Project manager** — the one `member_team.role = 'leader'` on a project's
   own team. Edits/archives that project, manages its roster and sees its
   progress; never edits people, chooses managers or creates projects.
4. **Member** — sees their teams, teammates and projects plus public
   projects in their organization; manages own subtasks and notes.

Key methods: `isSuperAdmin`, `memberFor` (read-only),
`officeAdminOrganizationId`, `administers`, `canEditTeam` (choose the manager),
`canManageRosterOf`, `canRemoveFromTeam`, `canJoinTeam`,
`canViewTeamProgress`, `canViewProjectProgress`, `canCreateMember`,
`canManageMember`, `canViewProject`,
`canCreateProject`, `canManageProjectAccess`, `canPublishProject`,
`canChangeProjectKey` (office admin/super-admin), `canManageGit` (super-admin),
`ledTeamIds`. `Project::scopeVisibleTo` is the query twin of `canViewProject`;
`Team` and `Member` also expose `visibleTo`. Per-user `WeakMap` memos flush on
both `RouteMatched` and `RequestHandled`.

Do-not-regress protections: ordinary teams, projects and people never cross
organizations; every roster add goes through `canJoinTeam`; project teams must
share the project's organization; organization changes cannot strand a person
on foreign ordinary teams. `canManageMember` blocks a super-admin's linked
login, reserved super-admin emails and other office admins; managers cannot
reset anyone's password. `StoreMemberRequest`/`UpdateMemberRequest` also block
non-super-admins from assigning a reserved email (`isSuperAdminEmail`). Only a
super-admin may change a person's organization/office-admin flag/bulk teams or
a team's cross-organization flag. Project `manager_id`, `is_public` and
`is_cross_organization` are capability-filtered by `validated()`. Appoint through
`AppointProjectManagerService`: the previous manager stays as a member; the
current manager cannot be removed or demoted, only replaced. `project_team`
is unique on each column; a partial unique index allows one leader per team.
`NotifiesMentions::mentionableIds` filters stored mentions to eligible people;
mention notifications only reach project viewers.

## Domain model

- **Organization** — office/ministry tenant; has many members, teams and
  projects. `DeleteOrganizationService` refuses deletion until all three are
  empty.
- **Project** — belongs to an organization; slug-routed, `archived_at`
  soft-archive, `is_public` within that organization; scopes
  `visibleTo`/`active`/`archived`; hasMany Task, belongsToMany Team (one each).
  `team()`/`ensureTeam()`/`manager()` expose its own team and manager;
  `CreateProjectService` creates both atomically; `StoreProjectRequest`
  requires `manager_id` for interactive creation. `ProjectResource.can_publish`
  exposes the office-admin/super-admin publication capability.
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
  an office admin. `Member::forUser` `firstOrCreate`s (never call in
  authorization); `scopeAssignableFor(Project)` is the source of truth for
  assignees. Creating a person also creates a login and synchronously emails
  its supplied/generated password via `SendLoginDetailsService`; passwords are
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
- **ProjectActivity** — polymorphic audit log, written only by the eight
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
  **WorkspaceNote** — personal stickies.
  **ProjectAssignment/Note/Contact, TaskComment** — task children.

## Notifications

`database` channel only, synchronous. `TaskAssigned`, `TaskStatusChanged`,
`MentionedInComment`, `TaskDeadlineDue`, `AddedToProjectTeam` and
`MadeProjectManager` share
`Concerns\BuildsWorkspaceNotification`; stable payload
`{kind, title, action, body, task, actor, url}` (`action` short-form,
`body` long sentence). Dispatched from observers (assignment created;
status entering done/failed), the comment service (mentions via
`MentionParser`), and the reminders command. Team membership creation or
promotion notifies the linked user, except the actor; team payloads have
`kind: team_added`/`made_manager`, `task: null`, a `project {slug, title}`
and a URL to the project's People tab. `NotificationIndexQuery` groups both
under the `team` filter/count on both surfaces.

## Scheduled / console commands

Daily schedule in `routes/console.php`. Commands: `workspace:api-token`,
`workspace:prune-trashed` (trash TTL from config) and
`workspace:send-deadline-reminders`; both maintenance commands support
`--pretend`. Weekly digest mail, command and subscriber model/table are retired.
`passport:purge` also runs daily. `git:oidc-client {redirect_uri}` creates/reuses
the sole Forgejo client; reruns keep its id and rotate its secret.

`php artisan db:seed --class=SdocProjectsSeeder --force` explicitly seeds SDOC
and eight private projects with their own teams, initially without managers.
It is production-safe and creates missing records. It applies agreed project
keys to existing automatic keys via the alias-preserving rename service,
leaves admin-chosen keys alone, and is not called by `DatabaseSeeder`.

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
  `/oauth/authorize` behind `EnsureCanUseGit` and sessionless, throttled
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
  remove the stored username from every connection using each office's token.
  Forge failures do not block the member change; the response asks for manual
  suspension. Tokens/secrets must never reach resources, old input, errors or logs.
- Runbook/deploy kit: `docs/forgejo.md`, `deploy/forgejo/`; broader design and
  later phases: `docs/superpowers/specs/2026-10-29-git-integration-design.md`.
