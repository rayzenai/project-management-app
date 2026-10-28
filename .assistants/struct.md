# Structure

Single-stack Laravel monolith; frontend is compiled by Vite from `resources/js`.

```
app/
  Console/Commands/        # workspace:api-token, workspace:prune-trashed,
                           # workspace:send-deadline-reminders, digest:send-weekly
  Http/
    Controllers/Api/       # /api/v1 controllers (Sanctum) + Concerns/RespondsWithServiceResult
    Controllers/Workspace/ # /workspace Inertia controllers + Concerns/RedirectsWithServiceResult
    Middleware/            # ShareWorkspaceData, EnsureProjectVisible, HandleInertiaRequests
    Requests/              # FormRequests — authorization + validation, shared by both surfaces
    Resources/             # JsonResources ($wrap = null)
  Mail/                    # weekly digest + new-member login-details mailables
  Models/                  # Organization, Project, Task, TaskDependency, Subtask, Team, Member, User, TaskComment,
                           # ProjectAssignment/Note/Contact/Activity/DigestSubscriber,
                           # WorkspaceNote, UserPreference
  Notifications/           # TaskAssigned, TaskStatusChanged, MentionedInComment,
                           # TaskDeadlineDue + Concerns/BuildsWorkspaceNotification
  Observers/               # seven observers — sole writers of the activity log
  Providers/AppServiceProvider.php  # manage-workspace Gate, morph map, observer registry
  Queries/                 # heavy reads (ProjectShow, OrganizationIndex,
                           # TeamIndex/Candidates, ProjectProgress + ProgressReport, ...)
  Services/Workspace/      # action services returning ServiceResult (+ Concerns/NotifiesMentions)
  Support/                 # WorkspaceAccess (ALL role decisions), ServiceResult,
                           # QuickAddParser, MentionParser, ApiResponser
routes/
  web.php                  # /workspace/* Inertia routes (names workspace.*), guest login
  api.php                  # /api/v1/* Sanctum routes (names api.*)
  console.php              # daily schedule
config/
  project-management.php   # status workflow, super-admins, trash TTL, reminders
  government.php           # plan-tracker metadata (categories, deadline types, oath date)
  themes.php               # system/light/dark token sets
database/
  migrations/              # all workspace tables (former package filenames preserved)
                           # including Nepal-time shift, tenancy backfill, task dependencies,
                           # and one-team-per-project / one-leader-per-team constraints
  seeders/                 # WorkspaceDemoSeeder (idempotent demo), WorkspaceSuperadminSeeder
resources/
  js/app.ts                # single Inertia entry
  js/pages/                # Home, MyWorkspace, PlanTracker, Auth/, Notifications/,
                           # Organizations/Index, Projects/{Index,Show,Progress}, Tasks/Show,
                           # Team/Index (People directory)
  js/components/           # shared UI (AppShell, ThemeToggle, StatusGlyph, QuickAdd*, ...)
                           # plus project/ views + ProjectTeamPanel, ProgressReport,
                           # team/{RosterSearch,GanttChart,TaskReportDrawer}, task dependency UI
  js/lib/                  # applyTheme, clipboard, quickAdd, toast, peek, types, utils,
                           # dependencies (board ordering/links), schedule (Gantt planning)
  css/app.css              # --ws-* tokens, Tailwind @theme mapping, component classes
  views/app.blade.php      # single root view
  views/emails/            # HTML + text login-details templates
tests/
  Feature/Workspace/       # web-surface feature tests
  Feature/Workspace/Api/   # API-surface feature tests
  Unit/                    # parser units
docs/api.md                # HTTP API reference + external-integration guide
deploy.sh                  # SDOC Dokploy web/scheduler deploy + health verification
Dockerfile, dock.sh        # production image and local image runner
```
