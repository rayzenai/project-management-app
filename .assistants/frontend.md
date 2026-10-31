# Frontend (Svelte 5 + Inertia + Tailwind 4)

Single Inertia entry `resources/js/app.ts`; single root view
`resources/views/app.blade.php`. Svelte 5 runes throughout; Vite 8 builds.

## Pages (`resources/js/pages`)

`Home`, `MyWorkspace`, `PlanTracker`, `Auth/`, `Notifications/` (two tabs:
personal inbox + workspace activity feed, `?tab=activity`),
`Organizations/Index` (super-admin tenant CRUD + Git connection controls),
`Projects/Index` (program groups, then unprogrammed projects by manager),
`Projects/Show` (list/board/people/code views via `components/project/`),
`Tasks/Show`, `Team/Index` (organization-aware People directory),
`Projects/Progress` (workload, Gantt, activity; polls every 15s while visible),
`Programs/Show` (project summary, workload, Gantt and activity across active
projects; polls `progress` every 15s while visible, with admin edit/delete and
New project for admins and its manager), `Reports/CodeActivity` (scoped code
activity, comparison and per-person commits).
Pages wrap themselves in `AppShell` and supply the 44px top bar through its
`bar` snippet (breadcrumb left, actions right); `flush` drops content padding
for full-bleed registers/boards. The sidebar collapses to an icon rail (`[`
shortcut, persisted in localStorage); Organizations appears only for
super-admins. `managedPrograms` supplies the sidebar's Programs group.
`gitEnabled` also shows the sidebar's Code activity link; filtered report URLs
keep it active. `Guide` (`/workspace/guide`, sidebar link for all signed-in
users) explains the organization/program/project/team model, role routines, ministry title
mapping, dependencies, overdue work and shortcuts. Its Code section appears
when shared `gitEnabled` is true, covering repository creation/access and
matching commits by configuring git to use the tracker's sign-in email (the
forge receives that email automatically at sign-in). Keep it aligned with
changes to how people work.

## Key components (`resources/js/components`)

Primitives: `StatusGlyph` (ring / dashed ring / amber half disc / green check /
red cross), `PriorityBars`, `Avatar` (squircle initials), `ProgressRing`.
Shell + chrome: `AppShell`, `CommandPalette`, `Popover`, `Toasts`, and
`ThemeToggle` (top-bar light/dark switch that saves through preferences and
uses `applyAppearance` for the immediate transition).
Quick-add: `QuickAddBar/Form/Overlay` + `lib/quickAdd.svelte.ts`,
`lib/quickAddTokens.ts` (server twin: `App\Support\QuickAddParser`).
`QuickAddForm` mirrors resolved `#project`/`@assignee` tokens, scopes picks to
the effective project and clears incompatible assignments/dependencies when it
changes. Self defaults only when eligible; choosing someone else replaces that
automatic self pick. Roster managers also see organization people marked
"Joins the team"; an organizational admin with no active project gets General
(created on submit). Missing project disables submit; validation and service
errors stay visible. Overlay creates redirect to the project board unless
"Create another" is selected. `AssigneePicker` closes outside on click capture
so an expanded list cannot shift the submit button between press and release;
`QuickAddOverlay` closes only for a press begun and ended on the backdrop.
Task UI: `TaskRow`, `TaskRegisterHead` (the shared register column header),
`TaskCode` (mono `KEY-12` id chip from stored project key + permanent task number,
shared formatter `lib/taskCode.ts`; `#12` when the project code is unknown),
`TaskPeek` (+ `lib/peek.svelte.ts`), `CommentThread`, `AssigneePicker/Stack`,
`DateChip`, `StatusChip/Badge`, `CompleteCheckbox`, `AutosaveStatus`. `DateChip`
reads shared status `is_complete`/`is_closed` metadata; closed work shows historical dates.
Project overdue counts/filters, dependency chips/links and progress views use
server-provided `is_overdue`; `is_late` remains available for finished-late work.
Dependencies: `TaskDependencies` in the task page/Peek, `DependsOnPicker` in
quick-add, and `project/DependencyChips` on board cards/list rows.
Notes: `NoteSticky`, `NotesStrip`, `WorkspaceNotesBoard` +
`lib/notesBoard.svelte.ts`, `lib/noteColors.ts`.
Teams: `project/ProjectTeamPanel` manages the project's roster in its People
tab; `team/RosterSearch` performs debounced server-side eligible-person search.
`Team/Index` offers `team/MemberRoleFields` to whoever may manage people:
Program manager of, Project manager of, Member of. Held manager roles stay
checked/locked until handed over; new choices name the current manager.
`lib/memberRoles.ts` filters options by organization, preserves held roles
outside those lists and sends only changed complete sets. Directory rows show
roles followed by other memberships; `lib/projectLabel.ts` includes the
program name ("General (Revenue)") in project labels, matching `Project::label()`.
`lib/clipboard.ts` provides Clipboard API + legacy fallback for copied logins.
Notifications: inbox Teams filter covers team additions and project/program manager appointments;
the activity tab includes project-level team changes with no task.
Project views: `components/project/{ListView,BoardView,PeopleView,BoardCard,
TaskTableRow,ProjectFilters,ProjectSummaryStrip,ProjectCreateForm,ProjectEditForm,
ColumnComposer}`. `ProjectCreateForm` is shared by Projects/Index and Programs/Show;
program managers must choose their own program (auto-selected when only one),
with themselves as default manager on the index. A program page fixes program
and organization and defaults to its active manager, including for super-admins.
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
program/git context shapes.
Reports: `Reports/CodeActivity` filters by date, project or program, defaults
to name order with sortable columns and compares up to four people. It shows
uncounted work, a side-by-side comparison and `reports/DailyCodeChart` (code
lines changed per day with daily commit counts in its tooltip). The empty state
also directs people to commit with the email they use to sign in.
`reports/PersonCommitsDrawer` fetches scoped recent commits for a selected
person, preserving the report's range/filters.
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

`AppearanceConfig` provides Settings toggles for Task notes and Contacts along
with theme and email preferences; changes persist with its Save button.
`SharedProps.features` (`lib/types.ts`) controls optional sections in task
page/Peek, read-only `TaskReportDrawer`, `MyWorkspace`, list-row counts and
command-palette results. Both features start off per user; turning them off
keeps their data. Personal sticky notes remain available.

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
