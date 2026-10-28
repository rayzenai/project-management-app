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
  web context goes there.
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
   their organization: teams, people, leaders, projects and publication.
3. **Team leader** — `member_team.role = 'leader'`. Searches/adds/removes the
   roster of led teams and sees those teams' progress; never edits people or
   chooses leaders.
4. **Member** — sees their teams, teammates and team projects plus public
   projects in their organization; manages own subtasks and notes.

Key methods: `isSuperAdmin`, `memberFor` (read-only),
`officeAdminOrganizationId`, `administers`, `canCreateTeam`, `canEditTeam`,
`canManageRosterOf`, `canRemoveFromTeam`, `canJoinTeam`,
`canViewTeamProgress`, `canCreateMember`, `canManageMember`, `canViewProject`,
`canCreateProject`, `canManageProjectAccess`, `canPublishProject`,
`ledTeamIds`. `Project::scopeVisibleTo` is the query twin of `canViewProject`;
`Team` and `Member` also expose `visibleTo`. Per-user `WeakMap` memos flush on
both `RouteMatched` and `RequestHandled`.

Do-not-regress protections: ordinary teams, projects and people never cross
organizations; every roster add goes through `canJoinTeam`; project teams must
share the project's organization; organization changes cannot strand a person
on foreign ordinary teams. `canManageMember` blocks a super-admin's linked
login and other office admins; leaders cannot reset anyone's password. Only a
super-admin may change a person's organization/office-admin flag/bulk teams or
a team's cross-organization flag. Mention notifications only reach project
viewers.

## Domain model

- **Organization** — office/ministry tenant; has many members, teams and
  projects. `DeleteOrganizationService` refuses deletion until all three are
  empty.
- **Project** — belongs to an organization; slug-routed, `archived_at`
  soft-archive, `is_public` within that organization; scopes
  `visibleTo`/`active`/`archived`; hasMany Task, belongsToMany Team; derived
  `code` attribute (uppercased initials of the title's words, max 4, fallback
  `P{id}`) exposed on Project/Task resources so tasks render as `CODE-123`.
- **Task** — slug-routed `{project_id}-{slug}`; 100-point-plan fields
  (`item_number`, `category`, `deadline_type`, `responsible_ministry`,
  `title_np`, `description_np`) live in the `metadata` jsonb via `Attribute`
  mutators; `Task::completeStatuses()` derives "finished" from config
  `is_complete` flags; `is_late` is derived, not a status.
- **Subtask** — personal (`user_id`). **Member** — belongs to one organization
  (except a super-admin's own member), optionally links to a `User`, and may be
  an office admin. `Member::forUser` `firstOrCreate`s (never call in
  authorization); `scopeAssignableFor(Project)` is the source of truth for
  assignees. Creating a person also creates a login and synchronously emails
  its supplied/generated password via `SendLoginDetailsService`; passwords are
  never queued.
- **Team** — belongs to a home organization, is auto-slugged, may be
  `is_cross_organization`, and uses pivot `member_team.role`. Candidate search
  is limited to the home organization unless cross-organization.
- **TeamProgressQuery** — leader/admin view of member workload, up to 500 active
  project tasks for a Gantt chart, and recent activity; the web page polls its
  `progress` prop every 15 seconds while visible.
- **ProjectActivity** — polymorphic audit log, written only by the six
  observers. Surfaced through `App\Queries\ActivityFeedQuery` on the activity
  tab of the notifications screen (`/workspace/notifications?tab=activity`);
  visibility rides on the task's project via `visibleTo` — never filter the
  feed on `->public()` (observers write `is_public = false`).
  **WorkspaceNote** — personal stickies.
  **ProjectAssignment/Note/Contact, TaskComment** — task children.
  **ProjectDigestSubscriber** — weekly digest recipients.

## Notifications

`database` channel only, synchronous. `TaskAssigned`, `TaskStatusChanged`,
`MentionedInComment`, `TaskDeadlineDue` share
`Concerns\BuildsWorkspaceNotification`; stable payload
`{kind, title, action, body, task, actor, url}` (`action` short-form,
`body` long sentence). Dispatched from observers (assignment created;
status entering done/failed), the comment service (mentions via
`MentionParser`), and the reminders command.

## Scheduled / console commands

Daily schedule in `routes/console.php`. Commands: `workspace:api-token`,
`workspace:prune-trashed` (trash TTL from config), 
`workspace:send-deadline-reminders`, `digest:send-weekly` — all support
`--pretend` except api-token.

Application and PostgreSQL session timezones are fixed to `Asia/Kathmandu`.
The one-time timestamp migration converts existing UTC wall-clock values.
