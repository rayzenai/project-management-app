# Structure

Single-stack Laravel monolith; frontend is compiled by Vite from `resources/js`.

```
app/
  Console/Commands/        # workspace:api-token, workspace:prune-trashed,
                           # workspace:send-deadline-reminders, git:oidc-client, git:reconcile,
                           # git:sync-access, git:commit-stats
  Http/
    Controllers/GitWebhookController.php # signed, sessionless forge deliveries
    Controllers/Api/       # /api/v1 controllers (Sanctum) + Concerns/RespondsWithServiceResult
    Controllers/Workspace/ # /workspace Inertia controllers + Concerns/RedirectsWithServiceResult
    Middleware/            # ShareWorkspaceData, EnsureProjectVisible, HandleInertiaRequests,
                           # EnsureCanSignIn (deactivation gate), EnsureCanUseGit (OIDC git gate)
    Requests/              # FormRequests — authorization + validation, shared by both surfaces
                           # Concerns/ChecksMemberRoles validates person-form role sets
    Resources/             # JsonResources ($wrap = null)
  Mail/                    # new-member login-details mailable
  Jobs/                    # ProcessGitDelivery, BackfillGitRepository, SyncForgeOwners, SyncRepositoryAccess
  Models/                  # Organization, Program, Project, Task, TaskDependency, Subtask, Team, Member, User, TaskComment,
                           # ProjectAssignment/Note/Contact/Activity,
                           # TeamMembership (member_team pivot), WorkspaceNote, UserPreference,
                           # ProjectKeyAlias, GitConnection, OAuthClient (Passport),
                           # GitRepository/Branch/Commit/PullRequest, GitRepositoryGrant,
                           # TaskGitLink, GitWebhookDelivery
  Notifications/           # TaskAssigned, TaskStatusChanged, MentionedInComment,
                           # TaskDeadlineDue, AddedToProjectTeam, MadeProjectManager, MadeProgramManager,
                           # Concerns/BuildsWorkspaceNotification
  Observers/               # activity writers + ProgramObserver (manager notification)
  Providers/AppServiceProvider.php  # manage-workspace Gate, morph map, observer registry
  Queries/                 # heavy reads (ProjectShow, OrganizationIndex,
                           # TeamIndex/Candidates, ProjectProgress/ProgramProgress + ProgressReport,
                           # RepositoryCandidates, RepositoryAccess/Candidates, TaskCode, CodeActivity, ...)
  Services/Workspace/      # action services returning ServiceResult (+ Concerns/NotifiesMentions)
                           # includes program CRUD + its General project, StartGeneralProject,
                           # UpdateMemberRoles, AddTeamMember, EnableGit, DisableGit, RemoveFromForge,
                           # Create/Link/UnlinkRepository, Grant/RevokeRepositoryAccess services
  Services/Git/            # ForgejoClient + ForgejoException (token-safe HTTP boundary)
                           # GitIngest, ForgeShapes, RepositorySync, ForgeMembership, removal breakers,
                           # RepositoryAccess + RepositoryAccessFailed, CommitStats
  Support/                 # WorkspaceAccess (ALL role decisions), ServiceResult,
                           # Assignees (existing assignees + eligible team joiners),
                           # QuickAddParser, MentionParser, ApiResponser, ProjectKeys, Background,
                           # LoginThrottle (shared web/API credential limit)
  Support/Git/             # GitAccess, GitUsernames, TaskReferences, DiffStats, CommentSyntax,
                           # GeneratedFiles, Oidc/{IdentityEntity,IdentityRepository}
routes/
  web.php                  # /workspace/* Inertia routes (names workspace.*), guest login;
                           # authenticated routes enforce EnsureCanSignIn
                           # /oauth/authorize + /oauth/token (forge sign-in), /git/webhooks/{connection}
  api.php                  # /api/v1/* Sanctum routes (names api.*); authenticated routes
                           # enforce EnsureCanSignIn
  console.php              # daily maintenance + ten-minute access sync / five-minute commit stats;
                           # forwards scheduled output to the container log when available
config/
  project-management.php   # status workflow, super-admins, trash TTL, reminders, suggested forge URL
  government.php           # plan-tracker metadata (categories, deadline types, oath date)
  themes.php               # system/light/dark token sets
  openid.php               # OIDC scopes/claims, identity repository, discovery + JWKS
database/
  migrations/              # all workspace tables (former package filenames preserved)
                           # including Nepal-time shift, tenancy backfill, task dependencies,
                           # one-team-per-project / one-leader-per-team constraints,
                           # digest retirement, project-level activity backfill,
                           # stable keys/task numbers, git connections and Passport tables,
                           # optional task-note/contact preferences (both default off),
                           # Git code/link/delivery tables, programs + projects.program_id,
                           # 2026_10_31_140000 SDOC agreed-key migration (frozen map + aliases),
                           # repository grants/access-sync fields + commit author/line-count fields
  seeders/                 # WorkspaceDemoSeeder (idempotent demo), WorkspaceSuperadminSeeder
                           # SdocProjectsSeeder (fresh databases only per CLAUDE.md; never production)
resources/
  js/app.ts                # single Inertia entry
  js/pages/                # Home, MyWorkspace, PlanTracker, Guide, Auth/, Notifications/,
                           # Organizations/Index, Projects/{Index,Show,Progress}, Tasks/Show,
                           # Programs/Show, Team/Index (People directory), Reports/CodeActivity
  js/components/           # shared UI (AppShell, ThemeToggle, AppearanceConfig,
                           # StatusGlyph, QuickAdd*, AutosaveStatus, ...)
                           # plus project/ views + ProjectTeamPanel, ProgressReport,
                           # team/{RosterSearch,MemberRoleFields,GanttChart,TaskReportDrawer},
                           # task dependency UI, program/ProgramForm,
                           # project/{ProjectCreateForm,CodeView,RepositoryAccess}, TaskCodePanel,
                           # reports/{DailyCodeChart,PersonCommitsDrawer}
  js/lib/                  # applyTheme, clipboard, quickAdd, toast, peek, types, utils,
                           # dependencies (board ordering/links), schedule (Gantt planning),
                           # taskCode (stored project key + permanent task number),
                           # memberRoles (person-form role sets), projectLabel (title + program)
  css/app.css              # --ws-* tokens, Tailwind @theme mapping, component classes
  views/app.blade.php      # single root view
  views/emails/            # HTML + text login-details templates
tests/
  Feature/Workspace/       # web-surface feature tests
  Feature/Workspace/Api/   # API-surface feature tests
  Unit/                    # parsers, diff classification + ForgejoClient HTTP boundary tests
docs/api.md                # HTTP API reference + external-integration guide
docs/forgejo.md            # forge bootstrap, OIDC, offboarding, backups, local proof,
                           # section 12: webhooks, queue worker, nightly reconcile;
                           # section 13: repositories, access sync, commit stats + code activity
docs/superpowers/          # design specs + implementation plans (Programs, Git phases 0/1/2,
                           # roles on people)
forgejo/                   # separate Dokploy app: Dockerfile, start.sh, .env.example;
                           # custom/ SDOC Git branding (templates, themes, fonts, logos),
                           # local.sh Homebrew/SQLite laptop runner
deploy.sh                  # SDOC Dokploy web/scheduler deploy + health verification
Dockerfile, dock.sh        # production image and local image runner
```
