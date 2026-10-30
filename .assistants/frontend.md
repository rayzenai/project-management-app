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
projects; polls `progress` every 15s while visible, with admin edit/delete).
Pages wrap themselves in `AppShell` and supply the 44px top bar through its
`bar` snippet (breadcrumb left, actions right); `flush` drops content padding
for full-bleed registers/boards. The sidebar collapses to an icon rail (`[`
shortcut, persisted in localStorage); Organizations appears only for
super-admins. `managedPrograms` supplies the sidebar's Programs group.
`Guide` (`/workspace/guide`, sidebar link for all signed-in users) explains
the organization/program/project/team model, role routines, ministry title
mapping, dependencies, overdue work and shortcuts. Its Code section appears
when shared `gitEnabled` is true. Keep it aligned with changes to how people work.

## Key components (`resources/js/components`)

Primitives: `StatusGlyph` (ring / dashed ring / amber half disc / green check /
red cross), `PriorityBars`, `Avatar` (squircle initials), `ProgressRing`.
Shell + chrome: `AppShell`, `CommandPalette`, `Popover`, `Toasts`, and
`ThemeToggle` (top-bar light/dark switch that saves through preferences and
uses `applyAppearance` for the immediate transition).
Quick-add: `QuickAddBar/Form/Overlay` + `lib/quickAdd.svelte.ts`,
`lib/quickAddTokens.ts` (server twin: `App\Support\QuickAddParser`).
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
`lib/clipboard.ts` provides Clipboard API + legacy fallback for copied login
details in the People directory.
Notifications: inbox Teams filter covers team additions and project/program manager appointments;
the activity tab includes project-level team changes with no task.
Project views: `components/project/{ListView,BoardView,PeopleView,BoardCard,
TaskTableRow,ProjectFilters,ProjectSummaryStrip,ProjectEditForm,ColumnComposer}`.
`ProjectEditForm` gates publication with `project.can_publish` and key editing
with `project.can_change_key` (office admins and super-admins); old keys remain
searchable aliases. Cross-organization access remains super-admin-only.
Admins also choose the project's optional program within its organization.
Programs: `program/ProgramForm` handles create/edit and choosing a manager from
active organization members. `Projects/Index` creates programs and projects;
groups use each project's `program` reference, while the separate `programs`
list determines which headings link to a program page. Unopenable programs
still group visible projects; empty visible programs appear on the active tab.
Program/project manager pickers and the People office-admin toggle carry ministry
role hints. Program edits/deletion also live on `Programs/Show`.
Command-palette search, dependencies and Gantt labels use the permanent task
number. Plan `item_number` stays editable; task details show it separately only
when it differs from `number`. `Organizations/Index` connects/updates Git using
forge address, owner and a write-only bot token, and can turn the connection off.
`BoardView` uses pointer drag/drop: dropping onto a card adds a dependency;
`lib/dependencies.ts` keeps waiting tasks below prerequisites and draws dotted
links, including cross-column links.
Progress: `ProgressReport` combines workload, activity and `team/GanttChart`;
the chart supports persisted zoom/grouping, dependency arrows and task
selection into read-only `team/TaskReportDrawer`. `lib/schedule.ts` plans
finish-to-start dates after prerequisites without changing stored dates.
Code: `project/CodeView` lists linked repositories, searches candidates and
links/unlinks when `git.can_manage`. `TaskCodePanel` in Peek/full task page
offers a copyable branch name and linked PRs, branches and commits. Board cards
show `has_pull_request`. Code data is access-gated by the backend; public-project
visibility alone does not expose the Code tab. `lib/types.ts` holds Program,
Git repository/PR/task-code and shared program/git context shapes.
Comments: the full task page passes `mentionCandidates` (`{id, name}` only,
team plus program manager) to `CommentThread`; `team` stays the assignee list.

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

Hard rules (see conventions.md): semantic utilities only; no raw palette
colors or `dark:` variants (sticky-note colors in `lib/noteColors.ts` are the
one exception); no inverted black/white buttons; no colored status pills/dots;
no `rounded-xl`/`shadow-*` outside `.popover`; Lucide icons only; no
em-dashes; no mono-uppercase eyebrow labels.
