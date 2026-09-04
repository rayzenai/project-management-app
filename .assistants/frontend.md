# Frontend (Svelte 5 + Inertia + Tailwind 4)

Single Inertia entry `resources/js/app.ts`; single root view
`resources/views/app.blade.php`. Svelte 5 runes throughout; Vite 8 builds.

## Pages (`resources/js/pages`)

`Home`, `MyWorkspace`, `PlanTracker`, `Auth/`, `Notifications/` (two tabs:
personal inbox + workspace activity feed, `?tab=activity`), `Projects/Index`,
`Projects/Show` (list/board/people views via `components/project/`),
`Tasks/Show`, `Team/Index`. Pages wrap themselves in `AppShell` and supply the
44px top bar through its `bar` snippet (breadcrumb left, actions right);
`flush` drops content padding for full-bleed registers/boards. The sidebar
collapses to an icon rail (`[` shortcut, persisted in localStorage).

## Key components (`resources/js/components`)

Primitives: `StatusGlyph` (ring / dashed ring / amber half disc / green check /
red cross), `PriorityBars`, `Avatar` (squircle initials), `ProgressRing`.
Shell + chrome: `AppShell`, `CommandPalette`, `Popover`, `Toasts`.
Quick-add: `QuickAddBar/Form/Overlay` + `lib/quickAdd.svelte.ts`,
`lib/quickAddTokens.ts` (server twin: `App\Support\QuickAddParser`).
Task UI: `TaskRow`, `TaskRegisterHead` (the shared register column header),
`TaskCode` (mono `CODE-123` id chip from project code + item number),
`TaskPeek` (+ `lib/peek.svelte.ts`), `CommentThread`, `AssigneePicker/Stack`,
`DateChip`, `StatusChip/Badge`, `CompleteCheckbox`.
Notes: `NoteSticky`, `NotesStrip`, `WorkspaceNotesBoard` +
`lib/notesBoard.svelte.ts`, `lib/noteColors.ts`.
Project views: `components/project/{ListView,BoardView,PeopleView,BoardCard,
TaskTableRow,ProjectFilters,ProjectSummaryStrip,ProjectEditForm,ColumnComposer}`.

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
