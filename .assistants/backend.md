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
  `isSuperAdmin`, `ledTeamIds`. Cross-cutting web context goes there.
- **API**: `routes/api.php`, prefix `api/v1`, names `api.*`, Sanctum tokens.
  `POST login` → token; `Api\AuthController::userPayload` is the token-auth
  twin of `ShareWorkspaceData` — cross-cutting API context goes there.
  External systems get tokens via `php artisan workspace:api-token`.
  Reference: `docs/api.md`.

## Authorization — SECURITY CRITICAL

`app/Support/WorkspaceAccess.php` is the single source of truth. Tiers:

1. **Super-admin** — `manage-workspace` Gate (emails in `PM_SUPER_ADMINS` /
   `config('project-management.super_admins')`). Team CRUD, all rosters,
   archive any project, reassign members between teams.
2. **Team leader** — `member_team.role = 'leader'`; scoped power over led teams.
3. **Regular member** — own subtasks and notes only.

Key methods: `isSuperAdmin`, `leadsTeam`, `canManageRosterOf`,
`canArchiveProject`, `canCreateMemberForTeams`, `canManageMember`,
`canViewProject`, `canCreateProject`, `canManageProjectAccess`, `ledTeamIds`
(memoized per request via `WeakMap`; never creates a member as a side effect).

Do-not-regress protections: `canManageMember` blocks managing a super-admin's
linked login (attach-then-password-reset takeover); team rename and member
team-reassignment are super-admin only; team role changes require BOTH
`canManageRosterOf` AND `canManageMember`.

## Domain model

- **Project** — slug-routed, `archived_at` soft-archive, `is_public`; scopes
  `visibleTo`/`active`/`archived`; hasMany Task, belongsToMany Team; derived
  `code` attribute (uppercased initials of the title's words, max 4, fallback
  `P{id}`) exposed on Project/Task resources so tasks render as `CODE-123`.
- **Task** — slug-routed `{project_id}-{slug}`; 100-point-plan fields
  (`item_number`, `category`, `deadline_type`, `responsible_ministry`,
  `title_np`, `description_np`) live in the `metadata` jsonb via `Attribute`
  mutators; `Task::completeStatuses()` derives "finished" from config
  `is_complete` flags; `is_late` is derived, not a status.
- **Subtask** — personal (`user_id`). **Member** — a person, optionally linked
  to a `User`; `Member::forUser` `firstOrCreate`s (never call in
  authorization); `scopeAssignableFor(Project)` is the source of truth for
  assignees. **Team** — auto-slugged, pivot `member_team` with `role`.
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
