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
  Sanctum `AuthenticateSession` + `EnsureCanSignIn` + `TouchLastSeen` +
  `RemembersOrganizationChoice` + `ShareWorkspaceData`
  middleware. Shared Inertia props: `statuses`,
  `completeStatus`, `quickAddContext`, `workspaceNotes`, `taskNotes`,
  `appearance`, `features`, `themeCatalogue`, `unreadNotifications`, `flash`,
  `todoCount`, `isSuperAdmin`, `isOfficeAdmin`, `organization`, `ledTeamIds`,
  `managedProgramIds`, `managedPrograms` (`{slug, name}`), `gitEnabled`,
  `documentsEnabled`, `formsEnabled`,
  `office` (`OfficeLinks` capabilities/count), `clock` (`MyAttendanceQuery`),
  `organizationSwitch` (`OrganizationChoicesQuery::switcher`). Cross-cutting
  web context goes there. Status metadata includes `is_complete` and `is_closed`.
- **API**: `routes/api.php`, prefix `api/v1`, names `api.*`, Sanctum tokens;
  every authenticated route uses `auth:sanctum` + `EnsureCanSignIn` +
  `TouchLastSeen`.
  `POST login` → token; `Api\AuthController::userPayload` is the token-auth
  twin of `ShareWorkspaceData`; it includes organization and organizational-admin
  context plus `features: {task_notes, contacts}`, `managed_program_ids`,
  `git_enabled`, `documents_enabled`, `forms_enabled`, `office` (`OfficeLinks`) and `todo_count`. Cross-cutting API context
  goes there.
  External systems get tokens via `php artisan workspace:api-token`.
  Reference: `docs/api.md`.

`/` and `/workspace` land on web `/workspace/my`. `MyWorkQuery` is the shared
web/API/morning-email read: open assigned tasks, open work the caller created
for others, dated open subtasks and private to-dos, grouped as Today, Tomorrow,
Coming up, Later, You asked others, No date and Snoozed (plus Done on request).
An assignment's `personal_due_at` overrides the task date; snoozed work appears
only in Snoozed; the overdue rule forces work into Today; Tomorrow follows
`WorkingDays`. `joined` lists up to five project teams another person added the
caller to in the last 14 days. The older API-only `/api/v1/workspace/home` and
`/api/v1/workspace/my` endpoints retain their old query classes.

`BikramSambat` converts supported Gregorian dates (2070–2100 BS) for display.
`WorkingDays` is the shared answer for organization workdays/holidays, the next
working day and stable week start; organizations without attendance and people
without an organization fall back to Sunday through Friday.

## Super-admin organization switch

`Support\WorkspaceOrganization` reads/writes `users.workspace_organization_id`
(`null` = all) for super-admins only. The sidebar's
`PUT /workspace/organization` and `RemembersOrganizationChoice` on a page's
`?organization=` pick share it; `OrganizationChoicesQuery::defaultId` uses the
choice when a page offers that organization. People supports explicit `all`.

Web My work, Projects, the activity feed and Code activity pass the
choice into their queries. `Project::inOrganization` is the common project
scope; Code activity narrows a super-admin's all-organizations report to the
chosen organization. Search, quick add, My week and single project/program
pages stay organization-wide. API controllers pass no choice because the app
has no workspace switch.

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

Emails are stored trimmed and lowercase by `User`/`Member`; login, forgotten
password, set-password and API-token entry points lowercase input before lookup.
`TouchLastSeen` updates `users.last_seen_at` at most hourly on authenticated web
and API requests. `PasswordLinkService` is the only password-link mail path: the
`users` broker gives 60-minute reset tokens and `invites` gives seven-day invite
tokens, so the two kinds are not interchangeable. Opening a link only renders a
page; `POST set-password` consumes it, rejects bad/used/expired/deactivated links
with the same timeboxed 422, deletes both token kinds, rotates `remember_token`
and ends other web sessions through `AuthenticateSession` (API tokens remain).
Forgot-password always returns the same answer and sends after the response.

`InvitePlan` rebuilds the preview/send plan server-side (maximum 200), with
privacy-preserving statuses for new, recent, existing, deactivated and forbidden
addresses. `InvitePeopleService` rechecks under locks; `SendInvite` dispatches
after commit through `Background`, rechecks the inviter and sends a set-password
link. A changed/deleted person's old links are forgotten. `UniqueLoginEmail`
keeps login emails unique regardless of letter case.

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
Email preferences (`email_notifications`, `email_morning`, stored
`email_weekly`) use the same preference endpoint; the web exposes the two
implemented deliveries on `/workspace/settings/emails` and leaves weekly hidden.

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
`canManageMember`, `canViewProject`, `canDeleteTask` (project managers,
program managers, organization admins and super-admins; otherwise only the
task creator while they can still view its project),
`canCreateProject`, `canCreateProjectInProgram`, `createsProjectsOnlyInPrograms`,
`canManageProjectAccess`, `canPublishProject`,
`canChangeProjectKey` (organizational admin/super-admin), `canManageGit` (super-admin),
`canViewProjectCode`, `canManageProjectRepositories`, `canManageRepositoryAccess`,
`canSeeUnlinkedRepositories`, `ownsAssignment`, `ownsPersonalTodo`,
`canGrantRepositoryAccessTo` (query twin: `RepositoryAccessCandidatesQuery`),
`repositoryMaintainerIds`, `codeReportScope` (applied by `CodeActivityQuery`),
`ledTeamIds`,
`managedProgramIds`, `managesProgramOf`, `canManagePrograms`, `canViewProgram`,
`canUseProjectDocuments`, `documentAccess` (query twin:
`Document::scopeVisibleTo`), `documentOwnerActive`, `isDocumentFrozen`,
`documentOrganizationId`, `canCreatePersonalDocuments`, `canShareDocument`,
`canShareDocumentWith` (query twin: `DocumentShareCandidatesQuery`),
`isShareEffective`, `canDeleteDocument`, `canMoveDocument`.
Forms add `canUseProjectForms`, `canCreateForm`, `formAccess` (query twin:
`Form::scopeManagedBy`), `preloadFormAccess`, `canEditForm`,
`canSeeFormResponses`, `isFormRespondent` (query twin:
`Form::scopeAnswerableBy`), `canAnswerForm`, `canSeeForm`,
`canAddFormOrganizations`, `canShareFormWith`, `isFormShareEffective`,
`canSendFormTasksTo` and `canOpenFormFile`.
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
program. Optional `member_ids` accepts up to 500 distinct, existing, non-deleted
people; each must be active and pass `canJoinTeam` against an unsaved team with
the new project's organization and allowed cross-organization flag. Only a
super-admin's `is_cross_organization` is honored; others become false. Invalid
membership refuses creation. `CreateProjectService` sets the team's flag,
appoints the manager, then adds the chosen people through `AddTeamMemberService`
in the same transaction; a selected manager keeps their leader role.
`ProjectResource.can_choose_manager` and each roster member's
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
- `canUpdateAssignment` permits role/priority changes to project viewers, but
  star, snooze, personal progress/status note and `personal_due_at` additionally require `ownsAssignment`:
  only the active member linked to the caller owns those fields, never a
  super-admin acting on someone else's assignment.
- `QuickAddTaskRequest.start_general` with no `project_id` is allowed only for
  an organizational admin whose organization has no active project; otherwise
  422 `Pick a project.` on `project_id`. `StartGeneralProjectService` locks the
  organization, rechecks and creates General with the caller as manager through
  `CreateProjectService`. `QuickAddDispatcher` creates General and its task in
  one transaction; failure leaves neither. Parsed `@tokens` and default-to-self
  still resolve only through `scopeAssignableFor`.
- `QuickAddTaskRequest.personal` asks for a private `PersonalTodo` when no
  project is fixed. `QuickAddDispatcher` maps date words to `due_on`, discards
  priority words and refuses naming anyone else with `Pick a project to give it
  to someone.` A resolvable `#project` still makes a task. `fileTitle` is also
  the one path for moving a personal to-do into a project task.
- `ShareWorkspaceData.quickAddContext` adds project `organization_id`, program
  name, `member_ids`, `can_add_people`; `organizationPeople` (`{id, name, designation}`)
  is the viewer's active organization people only when they run a team there or
  may start General. It also supplies the projects' organizations, `startGeneral`,
  `startPersonal` (nothing fileable), and `lastProjectId` (the newer of the
  caller's latest created task/team join). `team` and `currentMemberId` remain.
  Roster capability uses the loaded-project twin to avoid a query per project.

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
  `scopeInOrganization` is the optional web-list narrowing used by the
  super-admin organization switch; null keeps every visible organization.
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
  My work excludes closed work. `is_late` also covers finished-late/failed work
  and remains a separate derived attribute, never a status.
  Permanent `number` comes from `projects.last_task_number` in the creating
  hook; create/quick-add services lock the project row. Unique per project,
  never fillable/request-editable, never reused even after purging trash.
  `created_by` is also set once by the creating hook from the signed-in user,
  never fillable; the migration backfills it from the first task-created
  activity where possible. `DeleteTaskRequest` gates both soft-delete and
  restore through `WorkspaceAccess::canDeleteTask` on web and API. A creator
  may act only while they can still view the project; whoever manages project
  access may act on every task.
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
- **Subtask** — personal (`user_id`). **PersonalTodo** — a private "Just for
  me" row owned by `user_id`, with `due_on`, `started_at` (Doing) and `done_at`.
  It appears only on My work and the top-bar to-do panel, never in activity,
  notifications, reports or email. `PersonalTodosQuery` returns Doing, To do and
  the last week's Done in one query plus the open count. Paired web/API routes
  update/delete/restore/move it; soft-deleted rows keep a seven-day undo window.
  Every request uses `WorkspaceAccess::ownsPersonalTodo` and returns the same 404
  to any non-owner, including a super-admin.
  **Member** — belongs to one organization
  (except a super-admin's own member), optionally links to a `User`, and may be
  an organizational admin. `Member::forUser` `firstOrCreate`s (never call in
  authorization); `managedPrograms()` exposes its managed programs.
  `scopeAssignableFor(Project)` supplies existing assignees; `Assignees` adds
  eligible newcomers for roster managers. The ordinary create-person form
  provisions a login and synchronously sends its supplied/generated password
  through `SendLoginDetailsService`. The separate bulk invite flow can create a
  person/login or link an existing person without one, then sends a password link.
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
  `Support\ActivityText` gives observer descriptions their public words:
  configured status labels (`Task::labelForStatus`), office dates, labelled
  priorities/note types and `none`, never stored keys, timestamps or plan
  numbers. The `2026_10_31_190000` migration rewords reconstructable old rows.
  **WorkspaceNote** — personal stickies.
  **ProjectAssignment/Note/Contact, TaskComment** — task children.

## Documents / ONLYOFFICE

Documents exist only while `config('documents.enabled')` has an editor URL and
a shared JWT secret. The editor image serves the static SDOC Docs front door
from `onlyoffice/home/` at `/`; `/welcome/` redirects there. `Document` belongs
either to a project or, through
`owner_id`, to the login that made a personal document; deleting a login that
owns one is refused. `organization_access` is `view|edit|null`.
`DocumentShare` stores one `view|edit` permission per document/member.
`DocumentVersion` keeps every created, uploaded or edited file as a distinct
object on the `documents` disk. `DocumentTypes` is the one
`docx|xlsx|pptx` list and `DocumentFiles` is the only write path, so versions
are never overwritten or deleted. Production maps that disk to RustFS; local
development uses `storage/app/documents`.

`WorkspaceAccess::documentAccess` returns the highest `edit|view|null` level
from the document's home, organization access and an effective share.
`Document::scopeVisibleTo` is its list-query twin. A project's home admits its
active team, active program manager, organizational admins and super-admins,
but not other public-project viewers. A personal document belongs only to its
active owner until shared; if that owner becomes inactive it is frozen,
read-only for super-admins and hidden from everyone else. Shares stay inside
`documentOrganizationId` (the project's organization or the owner's current
one), and only an active person there is effective. `DocumentResource` exposes
`access`, `frozen`, `can_delete`, `can_share` and `can_move`, plus project
`can_open` and `shares_count` for sharers (effective loaded shares only).

`DocumentLibraryQuery` powers web `/workspace/documents` and API
`/api/v1/workspace/documents`: tabs `recent|personal|shared|projects`,
project/type/title filters, 50-row cursor pagination, project choices and trash.
`DocumentLibraryRequest::OLD_SHOWS` maps old `all|mine|organization` links.
Personal contains the viewer’s own personal documents; Shared with me includes
named shares and office-wide documents reached no other way, excluding frozen
ones. Unfiltered Projects returns `project_cards` instead of document rows;
`projectCards` lists active usable projects with counts and latest edit times,
also at API `GET /api/v1/workspace/documents/projects`. `total` counts visible
documents for the page’s search threshold; list reads eager-load `shares.member`.
The web query follows the super-admin organization switch; the API does not.
`ProjectDocumentsQuery` supplies the project tab. Both list queries call
`WorkspaceAccess::preloadDocumentAccess`, which loads owners, project teams,
viewer memberships and shares in bounded queries before resources ask their
access. All document editor, version, download, rename, trash, restore, move and
sharing routes now live under
`documents/{document}`; old project-document editor links redirect there.
`DocumentRequest` opens a document at its computed level, including frozen
trash for a super-admin, and `DocumentSharingQuery` /
`DocumentShareCandidatesQuery` feed the Share dialog without exposing emails.

`CreateDocumentService` creates personal or project documents. Blank creation
redirects to the editor with one-visit `created_document` flash for title entry.
Web uploads answer plain JSON requests with the document resource (201), while
Inertia requests retain redirects; the UI uploads files sequentially with fetch.
A personal document's active owner may move it one way into any project whose documents
they may use; `MoveDocumentService` keeps its immutable versions and shares,
and closes organization access when the destination is in another organization.
`DocumentSharingService` changes organization access and shares without touching
`updated_at`; `firstOrCreate` makes concurrent identical shares one row. A new
effective share sends `DocumentShared`, also handled by right-away email, at
most once per document/recipient in 24 hours. The four web/API sharing writes
share a per-user `throttle:30,1,document-sharing` limit. Project-document
changes are audited by
`DocumentObserver`/`DocumentShareObserver`/`DocumentVersionObserver`; personal
documents write no project activity.

`DocumentEditorQuery` builds and HS256-signs the complete ONLYOFFICE config:
expiring signed file URL, signed callback URL, shared editor key, viewer id/name
(never email), and disabled AI/plugins/macros/chat. The editor page encrypts
Inertia history; sign-out clears it. `DocumentServerController` is outside the
web middleware and accepts the signed routes only with the editor's
operation-specific JWT. `SaveEditedDocumentService` fetches saves only from the
configured editor host, caps them at 100 MB, deduplicates by SHA-256, preserves
error-status saves with a warning, and advances the editor key when a session ends;
failures tell the editor to retry; saved versions credit the editor's last user
where known.

## Forms

Forms are available while `config('forms.enabled')` (independent of Documents).
Every form belongs to a project and carries an editable JSON `draft`; Publish
copies that draft into an immutable `FormVersion` and points
`published_version_id` at the live schema. `FormSchema::clean` is the only draft
shape kept, with permanent step/block/option ids; `FormAnswers` is the server
truth for conditional visibility, unchecked autosaved drafts and final answer
validation. `resources/js/lib/forms/answers.ts` mirrors it for immediate browser
feedback. Rich text is sanitized TipTap JSON, BS dates use `BsCalendar`, and
place answers are checked against the bundled Nepal hierarchy.

`Form` is soft-deleted and has `draft|open|closed` status, open/close dates,
response limits, `everyone|team|people` respondents and `person|organization`
answering. `FormShare` gives one same-organization active member `edit` or
`responses` access. A `FormResponse` is unique per form and `respondent_key`
(`m:{member}` or `o:{organization}`), keeps the published version, sender
snapshot, answers, stage and optional generated task. `FormDraft` autosaves by
the same key; `FormResponseEvent` records submissions, edits, stage changes,
notes and tasks. Content and answer `FormFile`s live immutably on the documents
disk under `forms/`; `canOpenFormFile` separates live content, respondent-owned
answers and response-manager access. The strict morph map names `form` and
`form-response`.

`formAccess` is the highest of running the project, making the form while still
working on that project, or an effective share; ordinary team membership alone
does not grant builder/response access. Respondents are active people selected
from the project's office (everyone, team or named people), plus whole other
organizations added only by an admin of the form's office. The latter are
counted but their people are not named to the source office. The predicate/query
pairs `formAccess`/`Form::managedBy` and
`isFormRespondent`/`Form::answerableBy` must stay aligned.

`Queries/Forms` owns the Forms library (To fill/Filled/Managing), builder and
fill payloads, project tab, sharing/candidates, respondents/pending counts,
responses, summaries and file references. `Services/Forms` owns every write:
create/save settings/draft, publish/close/duplicate/delete, respondents/sharing,
fill/clear/submit, files, response stages/notes/reminders, task settings and
export. Tests use the same validation path as real submissions but keep no
response. A form may be deleted only before real responses exist. Templates are
ordinary fresh schemas: Blank, Data call, Staff survey, Event/training
registration, Internship/volunteer application, Request and Complaint.

First publish may send `FormOpened`; `RemindFormService` sends `FormReminder` to
unanswered respondents no more than once per 24 hours. Both are database
notifications and right-away-email kinds. Optional task settings create one
system-authored task from each new response, copy only selected answers and
never undo the response if task creation fails. `FormExportService` uses
OpenSpout for filtered XLSX downloads; Open as spreadsheet always creates a
personal Document snapshot, never a project document. Web and API route groups
under `forms/{form}` cover the builder, fill, people/sharing/tasks, response
workflow, exports and files; `/f/{code}` is the signed-in fill link.

## Languages

`Support/Locales` defines English/Nepali and selects saved `User::locale()`
(`user_preferences.locale`), else the `locale` cookie, else English.
`Middleware/SetLocale` runs on web and API, resolving Sanctum before API auth.
`HandleInertiaRequests` shares `locale`/`locales`, including guest pages;
API `AuthController::userPayload` includes `locale`.
`LocaleController` + `UpdateLocaleRequest` serve throttled `POST /locale` for
anyone: cookie plus signed-in preference. Web/API preference PATCH routes also
accept locale; web changes copy it to the cookie. Web login adopts the cookie
only when no saved choice exists, preserving existing choices.
`lang/ne.json` is the English-keyed dictionary for `__()`/`trans_choice()` and
browser `lib/i18n.ts`; Laravel group messages live in `lang/ne/`. `Locales::number`
localizes server placeholders. The languages design spec maps translation rounds;
round 1 translates shell, sign-in, My work, Documents and server messages/errors.

## Notifications

`database` channel only, synchronous. `TaskAssigned`, `TaskStatusChanged`,
`MentionedInComment`, `TaskDeadlineDue`, `AddedToProjectTeam`,
`MadeProjectManager`, `MadeProgramManager`, `DocumentShared`, `FormOpened` and
`FormReminder` share
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
`DocumentShared` carries `document {id, title}` and opens the shared document.

Scheduled email reads these database notifications; the notifications themselves
remain synchronous `database` channel only. `SendEmailUpdates` claims unread,
unhandled assignment/mention/team/manager/document-share rows from the last 24 hours and groups
them per person after a two-minute quiet period or five-minute maximum wait.
Claims use `notifications.email_handled_at`, so read or concurrently claimed
rows are not mailed. `SendMorningEmails` uses `MorningEmailQuery`/`MyWorkQuery`
on each enabled person's working day for late, today and newly assigned work;
unique `email_digests` rows make the daily send idempotent.

`MailFailure` classifies SMTP failures as unreachable (release claims and stop),
deferred at/after RCPT TO (release that person and continue), or refused at/after
RCPT TO (keep handled and warn). `FirstRefusals` treats three refusals among the
run's first three sends as a server-wide refusal: release them and stop. Morning
mail uses the same server/recipient distinction for its day claims. Both commands
catch/report per-person errors without exposing addresses in logs.

## Scheduled / console commands

Schedule in `routes/console.php`. Commands: `workspace:api-token`,
`workspace:prune-trashed` (trash TTL from config) and
`workspace:send-deadline-reminders`; both maintenance commands support
`--pretend`. `workspace:send-email-updates` runs every minute with a five-minute
overlap lock; `workspace:send-morning-emails` runs daily at
`project-management.emails.morning_at` (09:00 by default). Both support
`--pretend`; weekly digest delivery is not implemented.
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
  `ForgejoClient::for` turns a token encrypted under another `APP_KEY` into a
  reported status-0 `ForgejoException`, so every caller treats it as an
  unreachable forge; `GitConnection::readableToken` lets failure logging scrub a
  readable token without throwing again or exposing one it cannot decrypt.
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
  but give nothing. `GitRepository` adds `protects_main`, `access_synced_at` and
  non-fillable `protected_branch`: the forge's default when linked (`main` when
  absent, always `main` for a tracker-created repository). `default_branch`
  keeps following the forge; unlinking and linking again records a new fixed branch.
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
  on `protected_branch` every run. A PUT 422 waits for first forge sign-in;
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
  accompany them, plus repositories created in scoped projects. Except for
  name sorting, rows are ranked by the selected figure with competition ranks;
  unmatched authors remain an unranked final row only for whole-repository
  viewers. Daily series include zero days. Shared `CodeActivityRequest` requires
  `GitAccess`; periods are today, the viewer's working week (default), last week,
  month or a custom range up to 366 days. It filters by project, program or an
  in-scope designation, sorts by commits/lines/tests/active days/name and compares
  up to four user IDs. Unknown/out-of-scope project or program filters are 403.
  Paired controllers serve web `/workspace/reports/code`
  (`Reports/CodeActivity`) and API `/api/v1/workspace/reports/code`, with
  `/people/{user}` returning the latest 50 scoped commits; unknown/out-of-scope
  people return an empty list. Runbook: `docs/forgejo.md` section 13; spec/plan:
  `docs/superpowers/{specs,plans}/2026-10-30-git-phase-2-access-and-reports*.md`.
- `GuideController` renders `GuideQuery`: the forges `GitAccess` admits (the
  reader's own organization first) and their stored forge username, never a
  token. The Guide turns these into its copyable code workflow walkthrough.
- `UnlinkedRepositoriesQuery` finds non-archived forge repositories that no
  project links, newest first. Forge results (including failure) are cached for
  five minutes per connection and forgotten by `GitRepositoryObserver`; the
  sidebar maintenance count reads cache only, while Code activity and Maintenance
  may ask the forge. `find()` always reads the forge before a write. Viewers pass
  `canSeeUnlinkedRepositories`; rows include suggested titles/keys, linkable
  projects and programs. Existing repositories use the normal link service;
  `CreateProjectForRepositoryService` creates a project and links exactly the
  requested repository atomically, rolling both back on link failure.

## Structure, attendance, work and maintenance

These reads live only in `app/Queries/{Structure,Attendance,Work,Maintenance}`;
structure/attendance writes live in `app/Services/{Structure,Attendance}` and
return `ServiceResult`. Both controller surfaces reuse them; never put a query
in these controllers, requests, resources or pages.

- Shared reads: `Structure/PeopleInViewQuery` lists permitted people (optional
  `under`, `attending: true`), `OrganizationChoicesQuery` chooses/defaults offices
  (the offered workspace choice first, then own office, then the first by name;
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
  and networks, forge repositories with no project link, and
  failing/unprotected/untested protected branches plus
  repositories whose forge default moved away from that branch. New checks
  belong in its lists, `countOf`, and the Maintenance page's report type.
  `QUIET_DAYS = 30`, `COUNT_TTL_SECONDS = 300`; full reads refresh the count.

## Forgejo runners, protected main and checks

- `forgejo/runner/` contains four organization runners in Dokploy's `git-runners`
  compose app, one job each; all four share one dind limited to 4 vCPUs, 10 GB
  memory and 46 GB file-backed ext4 Docker storage, with no actions cache.
  Dind, forge data and forge Postgres are mounted on separate logical volumes
  (`runner-jobs`, `forge-data`, `forge-db`), isolating the tracker database disk
  from job images, artifacts and logs. `forgejo/runner/host/sdoc-runner-firewall`
  and its systemd unit key on the compose bridge `sdoc-runners`: jobs retain
  internet access but can reach only ports 80, 443 and 2222 on the VM, enforced
  on both local INPUT and DNAT/FORWARD paths. `forgejo/Dockerfile` enables Actions
  with GitHub as the default action source. Runbook: `docs/forgejo.md` section 14.
- `Services/Git/MainBranch::RULE` describes every branch protection field on
  each repository's fixed `protected_branch` (never its later forge default):
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
- `MainChecksQuery` reads protected-branch checks in one query;
  `GitRepositoryResource` exposes `protected_branch`, `default_branch`,
  `main_checks` and `protects_main`, task/PR/code
  report payloads expose checks, code activity counts passed/failed checks, and
  Maintenance lists failing/unprotected/untested repositories and default-branch drift.
