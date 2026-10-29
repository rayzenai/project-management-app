# Structure

Single-stack Laravel monolith; frontend is compiled by Vite from `resources/js`.

```
app/
  Console/Commands/        # workspace:api-token, workspace:prune-trashed,
                           # workspace:send-deadline-reminders, git:oidc-client
  Http/
    Controllers/Api/       # /api/v1 controllers (Sanctum) + Concerns/RespondsWithServiceResult
    Controllers/Workspace/ # /workspace Inertia controllers + Concerns/RedirectsWithServiceResult
    Middleware/            # ShareWorkspaceData, EnsureProjectVisible, HandleInertiaRequests,
                           # EnsureCanUseGit (OIDC sign-in gate)
    Requests/              # FormRequests — authorization + validation, shared by both surfaces
    Resources/             # JsonResources ($wrap = null)
  Mail/                    # new-member login-details mailable
  Models/                  # Organization, Project, Task, TaskDependency, Subtask, Team, Member, User, TaskComment,
                           # ProjectAssignment/Note/Contact/Activity,
                           # TeamMembership (member_team pivot), WorkspaceNote, UserPreference,
                           # ProjectKeyAlias, GitConnection, OAuthClient (Passport)
  Notifications/           # TaskAssigned, TaskStatusChanged, MentionedInComment,
                           # TaskDeadlineDue, AddedToProjectTeam, MadeProjectManager,
                           # Concerns/BuildsWorkspaceNotification
  Observers/               # eight observers — sole writers of the activity log
  Providers/AppServiceProvider.php  # manage-workspace Gate, morph map, observer registry
  Queries/                 # heavy reads (ProjectShow, OrganizationIndex,
                           # TeamIndex/Candidates, ProjectProgress + ProgressReport, ...)
  Services/Workspace/      # action services returning ServiceResult (+ Concerns/NotifiesMentions)
                           # includes EnableGit, DisableGit, RemoveFromForge services
  Services/Git/            # ForgejoClient + ForgejoException (token-safe HTTP boundary)
  Support/                 # WorkspaceAccess (ALL role decisions), ServiceResult,
                           # QuickAddParser, MentionParser, ApiResponser, ProjectKeys
  Support/Git/             # GitAccess, GitUsernames, Oidc/{IdentityEntity,IdentityRepository}
routes/
  web.php                  # /workspace/* Inertia routes (names workspace.*), guest login
                           # /oauth/authorize + /oauth/token (forge sign-in)
  api.php                  # /api/v1/* Sanctum routes (names api.*)
  console.php              # daily schedule
config/
  project-management.php   # status workflow, super-admins, trash TTL, reminders
  government.php           # plan-tracker metadata (categories, deadline types, oath date)
  themes.php               # system/light/dark token sets
  openid.php               # OIDC scopes/claims, identity repository, discovery + JWKS
database/
  migrations/              # all workspace tables (former package filenames preserved)
                           # including Nepal-time shift, tenancy backfill, task dependencies,
                           # one-team-per-project / one-leader-per-team constraints,
                           # digest retirement, project-level activity backfill,
                           # stable keys/task numbers, git connections and Passport tables
  seeders/                 # WorkspaceDemoSeeder (idempotent demo), WorkspaceSuperadminSeeder
                           # SdocProjectsSeeder (idempotent, production-safe SDOC projects)
resources/
  js/app.ts                # single Inertia entry
  js/pages/                # Home, MyWorkspace, PlanTracker, Guide, Auth/, Notifications/,
                           # Organizations/Index, Projects/{Index,Show,Progress}, Tasks/Show,
                           # Team/Index (People directory)
  js/components/           # shared UI (AppShell, ThemeToggle, StatusGlyph, QuickAdd*, ...)
                           # plus project/ views + ProjectTeamPanel, ProgressReport,
                           # team/{RosterSearch,GanttChart,TaskReportDrawer}, task dependency UI
  js/lib/                  # applyTheme, clipboard, quickAdd, toast, peek, types, utils,
                           # dependencies (board ordering/links), schedule (Gantt planning),
                           # taskCode (stored project key + permanent task number)
  css/app.css              # --ws-* tokens, Tailwind @theme mapping, component classes
  views/app.blade.php      # single root view
  views/emails/            # HTML + text login-details templates
tests/
  Feature/Workspace/       # web-surface feature tests
  Feature/Workspace/Api/   # API-surface feature tests
  Unit/                    # parser units
docs/api.md                # HTTP API reference + external-integration guide
docs/forgejo.md            # forge bootstrap, OIDC, offboarding, backups, local proof
docs/superpowers/          # design specs + implementation plans (Git phase 0 included)
deploy/forgejo/            # Compose + .env.example for forge/Postgres; local.sh laptop runner
deploy.sh                  # SDOC Dokploy web/scheduler deploy + health verification
Dockerfile, dock.sh        # production image and local image runner
```
