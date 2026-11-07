# Frontend (Svelte 5 + Inertia + Tailwind 4)

Single Inertia entry `resources/js/app.ts`; single root view
`resources/views/app.blade.php`. Svelte 5 runes throughout; Vite 8 builds.

## Pages (`resources/js/pages`)

`MyWork` (the signed-in landing page), `PlanTracker`,
`Auth/{Login,ForgotPassword,SetPassword}`, `Settings/Emails`,
`Notifications/` (two tabs: personal inbox + workspace activity feed,
`?tab=activity`),
`Organizations/Index` (super-admin tenant CRUD + Git connection controls),
`Projects/Index` (program groups, then unprogrammed projects by manager),
`Projects/Show` (list/board/people/code/documents views via `components/project/`),
`Documents/Index` (the filterable workspace document library) and
`Documents/Edit` (full-height embedded ONLYOFFICE editor),
`Tasks/Show`, `Team/Index` (organization-aware People directory),
`Projects/Progress` (workload, Gantt, activity; polls every 15s while visible),
`Programs/Show` (project summary, workload, Gantt and activity across active
projects; polls `progress` every 15s while visible, with admin edit/delete and
New project for admins and its manager), `Reports/CodeActivity` (scoped code
activity, ranking/comparison, unlinked repositories and per-person commits).
Pages wrap themselves in `AppShell` and supply the 44px top bar through its
`bar` snippet (breadcrumb left, actions right); `flush` drops content padding
for full-bleed registers/boards. From `md`, `shell/Sidebar` carries New task,
Search, My work/Projects/People (plus Code activity when enabled), managed
programs, projects and the account button; `[` collapses it. Below `md`,
`PhoneTabs` gives My work, Projects, Updates and Me. `AccountMenu` is a desktop
popover/phone bottom sheet grouped as You, Office, Reports and Admin, with
Appearance and Sign out; `lib/navigation.ts` owns all capability-filtered links.
The main sidebar adds Documents while shared `documentsEnabled` is true.
When shared, `OrganizationSwitcher` is in the sidebar/account sheet and narrows
sidebar projects plus My work, Projects, activity and Code activity; quick add
and single project/program pages stay organization-wide.
`MyWork` groups the caller's tasks, dated subtasks and private to-dos by Today,
Tomorrow, Coming up, Later, You asked others, No date and Snoozed, with optional
Done. Recently joined projects sit above the groups. A quick-added row opens its
folded group, scrolls into view and glows once via `flash.created_key`.
`Guide` (`/workspace/guide`, sidebar link for all signed-in
users) explains the organization/program/project/team model, role routines, ministry title
mapping, dependencies, overdue work and shortcuts. Its Code section appears
when shared `gitEnabled` is true. `GuideQuery` supplies the reader's accessible
forges (own organization first) and forge username; `CommandBlock` makes its
setup/clone/branch/workflow examples copyable. It walks from first forge sign-in
through repository creation/import, every change, runners, secrets, checks and
the protected-branch merge rule. Its Documents section appears when shared
`documentsEnabled` is true and explains collaborative editing, versions and
access. Keep it aligned with changes to how people work.

## Key components (`resources/js/components`)

Primitives: `StatusGlyph` (ring / dashed ring / amber half disc / green check /
red cross), `PriorityBars`, `Avatar` (squircle initials), `ProgressRing`.
Shell + chrome: `AppShell`, `CommandPalette`, `Popover`, `Toasts`, and
`ThemeToggle` (top-bar light/dark switch that saves through preferences and
uses `applyAppearance` for the immediate transition). `Popover` portals to
`body`; nested popovers register with the outer panel so interacting with one
does not close its parent, and presses on `[data-toasts]` close neither.
Quick-add: `QuickAddBar/Form/Overlay` + `lib/quickAdd.svelte.ts`,
`lib/quickAddTokens.ts` (server twin: `App\Support\QuickAddParser`).
`QuickAddForm` mirrors resolved `#project`/`@assignee` tokens, scopes picks to
the effective project and clears incompatible assignments/dependencies when it
changes. `ProjectPicker` opens with Your projects, drills into organizations and
searches every eligible project; where allowed its first row is Just for me, and
an office admin with no project sees General (new). The form starts at shared
`lastProjectId` or private mode when `startPersonal`. Self defaults only when
eligible; choosing someone else replaces that automatic self pick. Roster managers also see organization people marked
"Joins the team"; an organizational admin with no active project gets General
(created on submit). Missing project disables submit; validation and service
errors stay visible. Overlay creates redirect to the project board unless
"Create another" is selected. `AssigneePicker` closes outside on click capture
so an expanded list cannot shift the submit button between press and release;
`QuickAddOverlay` closes only for a press begun and ended on the backdrop.
Task UI: `TaskRow`,
`TaskCode` (mono `KEY-12` id chip from stored project key + permanent task number,
shared formatter `lib/taskCode.ts`; `#12` when the project code is unknown),
`TaskPeek` (+ `lib/peek.svelte.ts`), `CommentThread`, `AssigneePicker/Stack`,
`DateChip`, `StatusChip/Badge`, `CompleteCheckbox`, `AutosaveStatus`. `DateChip`
reads shared status `is_complete`/`is_closed` metadata; closed work shows historical dates.
Project overdue counts/filters, dependency chips/links and progress views use
server-provided `is_overdue`; `is_late` remains available for finished-late work.
Dependencies: `TaskDependencies` in the task page/Peek, `DependsOnPicker` in
quick-add, and `project/DependencyChips` on board cards/list rows.
Notes: `WorkspaceNotesBoard` +
`lib/notesBoard.svelte.ts`, `lib/noteColors.ts`.
Teams: `project/ProjectTeamPanel` manages the project's roster in its People
tab; `team/RosterSearch` performs debounced server-side eligible-person search.
`Team/Index` offers `team/MemberRoleFields` to whoever may manage people:
Program manager of, Project manager of, Member of. Program manager roles stay
locked until handed over; organizational admins may untick a managed project,
leaving it manager-less while keeping the person on its team. New choices name
the current manager.
`team/DesignationSelect` lets structure managers create an organization's
designation (name + rank through the organogram endpoint) and select it without
leaving the person form; the designations panel labels its holder count as people.
`lib/memberRoles.ts` filters options by organization, preserves held roles
outside those lists and sends only changed complete sets. Directory rows show
roles followed by other memberships; `lib/projectLabel.ts` includes the
program name ("General (Revenue)") in project labels, matching `Project::label()`.
`lib/clipboard.ts` provides Clipboard API + legacy fallback for copied logins.
`team/InviteDialog` pastes up to 200 name/email rows, previews the server-built
invite plan, optionally includes active people without logins, assigns
designations and sends set-password links; it never decides status locally.
Notifications: inbox Teams filter covers team additions and project/program manager appointments;
the activity tab includes project-level team changes with no task.
Project views: `components/project/{ListView,BoardView,PeopleView,BoardCard,
TaskTableRow,ProjectFilters,ProjectSummaryStrip,ProjectCreateForm,ProjectEditForm,
ColumnComposer}`. `ProjectCreateForm` is shared by Projects/Index and Programs/Show;
program managers must choose their own program (auto-selected when only one),
with themselves as default manager on the index. A program page fixes program
and organization and defaults to its active manager, including for super-admins.
Its People picker reuses `AssigneePicker` to send initial `member_ids`, excluding
the selected manager. Candidates are the project's organization people; a
super-admin on the index may open the team to other organizations and pick
from all candidates. Submit filters out picks no longer eligible after changing
organization, manager or the cross-organization tick; `member_ids` errors show
by the picker. The cross-organization tick is absent on a program page.
`ProjectEditForm` gates publication with `project.can_publish` and key editing
with `project.can_change_key` (organizational admins and super-admins); old keys remain
searchable aliases. Cross-organization access remains super-admin-only.
Admins also choose the project's optional program within its organization.
Programs: `program/ProgramForm` handles create/edit and choosing a manager from
active organization members. `Projects/Index` creates programs and projects;
groups use each project's `program` reference, while the separate `programs`
list determines which headings link to a program page. Unopenable programs
still group visible projects; empty visible programs appear on the active tab.
Program/project manager pickers and the People organizational-admin toggle carry ministry
role hints. The visible role name is Organizational admin; stored flags and
capability names still use `is_office_admin`/`isOfficeAdmin`. Program
edits/deletion also live on `Programs/Show`. Project roster "Make manager"
uses each member's `can_make_manager`, including program-manager tenant limits.
Command-palette search, dependencies and Gantt labels use the permanent task
number. Plan `item_number` stays editable; task details show it separately only
when it differs from `number`. `Organizations/Index` connects/updates Git using
forge address, owner and a write-only bot token, and can turn the connection off.
Its suggested forge address comes from `project-management.git.default_forge_url`
(`PM_GIT_FORGE_URL`, default `https://git.sdoc.gov.np`).
`BoardView` uses pointer drag/drop: dropping onto a card adds a dependency;
`lib/dependencies.ts` keeps waiting tasks below prerequisites and draws dotted
links, including cross-column links.
Progress: `ProgressReport` combines workload, activity and `team/GanttChart`;
the chart supports persisted zoom/grouping, dependency arrows and task
selection into read-only `team/TaskReportDrawer`. `lib/schedule.ts` plans
finish-to-start dates after prerequisites without changing stored dates.
Code: `project/CodeView` lists linked repositories, searches candidates and
links/unlinks when `git.can_manage`. Its Link repository popover also offers
New repository (lowercase project-key name, optional description), with focus
handling and inline errors. Each repository's Access toggle opens
`project/RepositoryAccess`: read-only manager/program-manager maintainers,
read/write grants, debounced eligible-person search, permission changes and
removal. Missing forge usernames show waiting; ineffective grants show no access
now. Mutations reload flash and the access list, lock controls while saving
and restore refused selections. `TaskCodePanel` in Peek/full task page offers
a copyable branch name and linked PRs, branches and commits. Board cards
show `has_pull_request`. Code data is access-gated by the backend; public-project
visibility alone does not expose the Code tab. `lib/types.ts` holds Program,
Git repository/grant/access, code-activity report, PR/task-code and shared
program/git context shapes. On an empty project view, Team and Repositories use
the full available width and sit side by side from `xl` when both are present.
Repository rows show the fixed `protected_branch` and warn when the forge's
`default_branch` moved away from it, because work merged into the new default
skips the tracker's rule.
Documents: `Documents/Index` lists every document the viewer may open, newest
first, with ownership/access/project/type/search filters, cursor-based Show
more, creation/upload destination and trash. Show more uses the server-applied
filters attached to its cursor and discards a response if an Inertia filter
visit replaced the list while it loaded. `project/DocumentsView` is present
only when the backend supplies a non-null payload and reuses the same
`documents/{DocumentList,DocumentTrash,NewDocument}` components for one project.
Rows show their home and view/edit/frozen state, open Share when allowed, move
an owned personal document one way into a usable project, rename, download and
load version history. `documents/ShareDialog` controls organization-wide
view/edit and named-person shares. New blank files open directly in
`Documents/Edit`; uploads stay on the list. The editor opens read-only or editable
from the server's access level, loads ONLYOFFICE's `api.js`, hands it the signed
config unchanged, reloads on `onRequestRefreshFile`, and offers the current
download when the editor is unreachable. It also exposes Share where permitted.
The session page is kept only in encrypted Inertia history, which sign-out clears.
Reports: `Reports/CodeActivity` offers today/week/last-week/month/custom periods,
project/program/designation filters, ranking by commits/lines/tests/active days
(or unranked name order), and compares up to four people. It shows
uncounted work, a side-by-side comparison and `reports/DailyCodeChart` (code
lines changed per day with daily commit counts in its tooltip). The empty state
also directs people to commit with the email they use to sign in.
`reports/PersonCommitsDrawer` fetches scoped recent commits for a selected
person, preserving the report's range/filters. `reports/UnlinkedRepositories`
shows forge repositories with no project, linking one to an existing project or
creating a new project whose Title/Key suggestions stay editable and in step.
Comments: the full task page passes `mentionCandidates` (`{id, name}` only,
team plus program manager) to `CommentThread`; `team` stays the existing
assignee list. Full task `joinCandidates` and Peek `join_candidates` feed
`AssigneeStack.others`; newcomers are marked "Joins the team" and are never
offered as mentions.

## Task editing and optional features

`Tasks/Show` places details, subtasks, notes and contacts on one page;
`?tab=todos|notes|contacts` deep links scroll to the corresponding section.
Title, description, status note and source URL save on focus-out; progress
saves on change, sending only fields that differ from the last accepted draft.
The page queues another save when needed, warns before unloading unsaved work,
and supports Cmd/Ctrl+S (submits the focused add form, otherwise saves the task).
`TaskPeek` saves title/description on blur and progress on change; subtask
creation/editing also supports blur or Enter. `AutosaveStatus` reports saving,
the saved time, pending edits or errors in both task surfaces.
The task page's `canDelete` and Peek's `can_delete` show deletion only to
whoever runs the project or the task creator. Peek deletes in place and closes;
the full page returns to the project, while deletion from a project view stays
on that view. Both use the undoable trash/restore flow.

`AppearanceConfig` provides theme plus Task notes and Contacts settings; email
preferences live on `Settings/Emails`, where each implemented switch saves by
itself (right-away updates and morning mail; weekly remains stored but hidden).
`SharedProps.features` (`lib/types.ts`) controls optional sections in task
page/Peek, read-only `TaskReportDrawer`, list-row counts and
command-palette results. Both features start off per user; turning them off
keeps their data. Personal sticky notes remain available.

`todos/TodoPanel` is a top-bar popover on desktop and bottom sheet on phones
(`T` toggles it). It fetches Doing, To do and the last week's Done from
`GET /workspace/todos`, serializes mutations, quietly reloads My work/count/flash,
and supports add/start/done/undo/delete/edit/move-to-project. `mywork/TodoRow`
is the same private item in the landing-page list; `mywork/MyWorkRow` and
`RowMenu` handle task/subtask actions and personal assignment fields.

## Theming

`config/themes.php` holds exactly three themes: `system`, `light`, `dark`.
One visual language, two grounds: cool-neutral surfaces, three text tones,
hairline borders, one calm blue accent; green/amber/red reserved for status,
deadlines and priority. Fonts fixed: Geist (UI), Geist Mono (ids/dates/counts),
Mukta (`font-np`, Nepali).

`resources/css/app.css` defines the `--ws-*` custom properties, the Tailwind
`@theme` mapping (`bg-bg`, `bg-surface`, `bg-surface-alt`, `bg-hover`,
`bg-raised`, `text-fg`, `text-fg-muted`, `text-fg-faint`, `border-line`,
`border-line-soft`, `text/bg/border-accent`, `bg-accent-soft`,
`text-success/warn/danger`, `bg-success/warn/danger-soft`) and the component
classes every screen is built from (`.btn`, `.btn-primary`, `.btn-ghost`,
`.btn-danger`, `.btn-icon`, `.input`, `.label`, `.chip*`, `.panel`, `.row`,
`.group-head`, `.col-head`, `.section-title`, `.popover`, `.menu-item`,
`.kbd`, plus the task-register grid `.task-cols`/`.task-head`/`.task-row` —
one shared column template so header and rows align, optional columns from
xl/2xl). `resources/js/lib/applyTheme.ts` (`applyAppearance`) is the single
source of mode.
Charts use semantic `series-1` through `series-4` tokens (`--ws-series-*`),
blue/magenta/purple/teal-blue in fixed order with light/dark values in app.css;
these identify people without implying task status.

Hard rules (see conventions.md): semantic utilities only; no raw palette
colors or `dark:` variants (sticky-note colors in `lib/noteColors.ts` are the
one exception); no inverted black/white buttons; no colored status pills/dots;
no `rounded-xl`/`shadow-*` outside `.popover`; Lucide icons only; no
em-dashes; no mono-uppercase eyebrow labels.

## Separate forge UI

`forgejo/custom/` brands SDOC Git through Forgejo's templates, themes, fonts
and logos: Public Sans + Noto Sans Devanagari, `sdoc-auto|light|dark` themes,
signed-out home with the tracker OIDC button, Tasks navbar link and SDOC footer.
`forgejo/start.sh` replaces the custom public/templates files from the image
on every start. Existing forge accounts keep their stored theme; the runbook's
Branding section covers moving old accounts onto an offered SDOC theme.

`onlyoffice/home/` is the standalone SDOC Docs landing page served by the
editor's nginx at `/`, visually paired with SDOC Git and linking sign-in to the
tracker's Documents page. Its nginx include redirects `/welcome/` back to it;
the editor API and document routes remain unchanged.

## Office and work pages

- `Organogram/Index` (`/workspace/organogram`) uses
  `organogram/{OrganogramOutline,OrgChart,DesignationsPanel}` for outline/chart,
  drag-to-reassign reporting and admin designation management. People use
  designations instead of free-text titles; `Team/Index` edits designation,
  higher authority and attendance exemption as well as roles.
- `attendance/ClockButton` in the shell reads shared `clock`, gets location only
  on a punch, and prompts for a note for outside/unknown punches. `clock` must
  remain distinct from the work pages' `attendance` payload.
- `Attendance/Register` (`/workspace/attendance`) filters a day and team;
  `Attendance/Leave` handles leave and kaaj requests/decisions/direct recording.
  `Attendance/Settings` uses `attendance/settings/{AttendanceSwitch,HoursForm,
  PlacesPanel,NetworksPanel,HolidaysPanel}`: hours on the left, office and
  holidays on the right. Enablement is super-admin-only; office admins configure.
- `Work/{Day,Person,Team}` shows today's activity, a person's range and team
  totals, including attendance while enabled. Person links use
  `/workspace/people/{member}/work`; lists use `/workspace/work` and `/work/team`.
  `Maintenance/Index` is the organization's admin repair list with links to the
  affected people, projects and settings; `office.maintenance_count` badges it.
- Shared frontend shapes: `lib/{structure,attendance,attendanceRegister,work,
  maintenance}.ts`; shared `PersonRef` / `ReportOptions` in `lib/types.ts`.
  `lib/dates.ts` reads days and clocks in office time regardless of the browser;
  `lib/format.ts` owns `formatDistance` and `plural`.
  `ReportVisit` in `lib/reportVisit.svelte.ts` handles filter visits/loading/errors;
  `filters/{OrganizationSelect,ScopeSelects,DayPicker}` and `work/RangePicker`
  share filter UI. Reuse these instead of adding page-local copies.
- `ChecksMark` shows commit/PR/protected-branch test results in Code, task code
  and code activity. `FailedChecks` lists failed check names beneath repository
  rows in `project/CodeView` (`main_checks`) and commits in
  `reports/PersonCommitsDrawer` (`checks`), linking to forge job logs when a
  check URL is available, otherwise showing plain text. It renders only when
  the overall result and individual checks are failed.
  Repository rows show `main_checks` and protection;
  Maintenance lists failing/unprotected/untested protected branches and forge
  defaults moved away from them. The Guide
  covers organogram, attendance, work and protected-main/testing workflows.
