# Structure

Single-stack Laravel monolith; frontend is compiled by Vite from `resources/js`.

```
app/
  Console/Commands/        # workspace:api-token, workspace:prune-trashed,
                           # workspace:send-deadline-reminders/send-email-updates/send-morning-emails,
                           # git:oidc-client, git:reconcile,
                           # git:sync-access, git:commit-stats, git:commit-checks
  Http/
    Controllers/GitWebhookController.php # signed, sessionless forge deliveries
    Controllers/DocumentServerController.php # signed, sessionless ONLYOFFICE file/save calls
    Controllers/Api/       # /api/v1 controllers (Sanctum) + Concerns/RespondsWithServiceResult
    Controllers/Workspace/ # /workspace Inertia controllers + Concerns/RedirectsWithServiceResult
    Middleware/            # ShareWorkspaceData, EnsureProjectVisible, HandleInertiaRequests,
                           # EnsureCanSignIn (deactivation gate), EnsureCanUseGit (OIDC git gate),
                           # TouchLastSeen, RemembersOrganizationChoice (super-admin workspace switch)
    Requests/              # FormRequests — authorization + validation, shared by both surfaces
                           # Concerns/ChecksMemberRoles validates person-form role sets
    Resources/             # JsonResources ($wrap = null)
  Mail/                    # login details/password links, grouped updates and morning digest mailables
  Jobs/                    # ProcessGitDelivery, BackfillGitRepository, SyncForgeOwners,
                           # SyncRepositoryAccess, SendInvite
  Models/                  # Organization, Program, Project, Task, TaskDependency, Subtask, Team, Member, User, TaskComment,
                           # ProjectAssignment/Note/Contact/Activity,
                           # TeamMembership (member_team pivot), WorkspaceNote, PersonalTodo,
                           # UserPreference, EmailDigest,
                           # ProjectKeyAlias, GitConnection, OAuthClient (Passport),
                           # GitRepository/Branch/Commit/PullRequest, GitRepositoryGrant,
                           # TaskGitLink, GitWebhookDelivery; Designation, AttendanceSetting/Place/Network/Punch,
                           # Holiday, LeaveRequest, Document/DocumentShare/DocumentVersion
  Notifications/           # TaskAssigned, TaskStatusChanged, MentionedInComment,
                           # TaskDeadlineDue, AddedToProjectTeam, MadeProjectManager, MadeProgramManager,
                           # DocumentShared,
                           # Concerns/BuildsWorkspaceNotification
  Observers/               # activity writers + ProgramObserver (manager notification), document/share/version audit
  Providers/AppServiceProvider.php  # manage-workspace Gate, morph map, observer registry
  Queries/                 # heavy reads (ProjectShow, OrganizationIndex, Guide,
                           # TeamIndex/Candidates, ProjectProgress/ProgramProgress + ProgressReport,
                           # RepositoryCandidates, RepositoryAccess/Candidates, TaskCode, CodeActivity,
                           # MyWork, PersonalTodos, MorningEmail, UnlinkedRepositories, ...)
  Queries/Structure/       # PeopleInView, OrganizationChoices, ReportOptions, PersonRef, Organogram
  Queries/Attendance/      # MyAttendance (shared clock), Register, Summary, Settings, Leave
  Queries/Work/            # WorkActivity shared by WorkDay, PersonWork, TeamWork; WorkAttendance
  Queries/Maintenance/     # MaintenanceQuery (repair lists + cached sidebar count)
  Queries/Documents/       # workspace/project libraries, sharing/candidates, versions + editor config
  Services/Structure/      # designations, reports-to, MoveReportsUp on member move/deletion
  Services/Attendance/     # enable/settings, Punch, places/networks/holidays, leave writes
  Services/Documents/      # create/upload, rename/trash/move/share, keep editor saves as versions
  Services/Workspace/      # action services returning ServiceResult (+ Concerns/NotifiesMentions)
                           # includes program CRUD + its General project, StartGeneralProject,
                           # UpdateMemberRoles, AddTeamMember, EnableGit, DisableGit, RemoveFromForge,
                           # Create/Link/UnlinkRepository, Grant/RevokeRepositoryAccess services
  Services/Git/            # ForgejoClient + ForgejoException (token-safe HTTP boundary)
                           # GitIngest, ForgeShapes, RepositorySync, ForgeMembership, removal breakers,
                           # RepositoryAccess + RepositoryAccessFailed, CommitStats, CommitChecks, MainBranch
  Support/                 # WorkspaceAccess (ALL role decisions), ServiceResult,
                           # Assignees (existing assignees + eligible team joiners),
                           # QuickAddParser, MentionParser, ApiResponser, ProjectKeys, Background,
                           # LoginThrottle (shared web/API credential limit), OrgChart, OfficeLinks,
                           # WorkspaceOrganization (super-admin switch), ActivityText (audit wording),
                           # InvitePlan, BikramSambat, WorkingDays, MailFailure, FirstRefusals
  Support/Attendance/      # AttendanceCalendar, DaySchedule, PunchPlace
  Support/Documents/       # types/templates, immutable file writes/downloads, HS256 editor tokens
  Support/Git/             # GitAccess, GitUsernames, TaskReferences, DiffStats, CommentSyntax,
                           # GeneratedFiles, Checks, Oidc/{IdentityEntity,IdentityRepository}
routes/
  web.php                  # /workspace/* Inertia routes (names workspace.*), guest login;
                           # forgot/invite/set-password; authenticated routes enforce EnsureCanSignIn
                           # /oauth/authorize + /oauth/token (forge sign-in), /git/webhooks/{connection};
                           # signed sessionless /documents/* file and callback routes for ONLYOFFICE
  api.php                  # /api/v1/* Sanctum routes (names api.*); authenticated routes
                           # enforce EnsureCanSignIn
  console.php              # daily reminders/morning mail + minute updates + maintenance;
                           # ten-minute access sync / five-minute commit stats / two-minute checks;
                           # forwards scheduled output to the container log when available
config/
  project-management.php   # status workflow, super-admins, trash TTL, reminders, suggested forge URL
  auth.php                 # distinct reset and invite password brokers
  mail.php                 # SMTP transport including bounded timeout
  government.php           # plan-tracker metadata (categories, deadline types, oath date)
  themes.php               # system/light/dark token sets
  openid.php               # OIDC scopes/claims, identity repository, discovery + JWKS
  documents.php            # ONLYOFFICE enablement/JWT, file limits and documents disk
database/
  migrations/              # all workspace tables (former package filenames preserved)
                           # including Nepal-time shift, tenancy backfill, task dependencies,
                           # one-team-per-project / one-leader-per-team constraints,
                           # digest retirement, project-level activity backfill,
                           # stable keys/task numbers, git connections and Passport tables,
                           # optional task-note/contact preferences (both default off),
                           # Git code/link/delivery tables, programs + projects.program_id,
                           # 2026_10_31_140000 SDOC agreed-key migration (frozen map + aliases),
                           # repository grants/access-sync fields + commit author/line-count fields,
                           # designations/reporting lines, attendance/leave, title retirement, Git checks,
                           # audit rewording, fixed protected branches, users' workspace organization,
                           # immutable task creator attribution, last-seen/email preferences and claims,
                           # separate invite tokens, lowercased emails, private personal to-dos,
                           # project/personal documents, sharing + immutable document versions
  seeders/                 # WorkspaceDemoSeeder (idempotent demo), WorkspaceSuperadminSeeder
                           # SdocProjectsSeeder (fresh databases only per CLAUDE.md; never production)
resources/
  js/app.ts                # single Inertia entry
  js/pages/                # MyWork, PlanTracker, Guide, Auth/{Login,ForgotPassword,SetPassword},
                           # Notifications/, Settings/Emails,
                           # Organizations/Index, Projects/{Index,Show,Progress}, Tasks/Show,
                           # Programs/Show, Team/Index (People directory), Reports/CodeActivity,
                           # Organogram/Index, Attendance/{Register,Leave,Settings},
                           # Work/{Day,Person,Team}, Maintenance/Index, Documents/{Index,Edit}
  js/components/           # shared UI (AppShell, ThemeToggle, AppearanceConfig,
                           # StatusGlyph, QuickAdd*, AutosaveStatus, CommandBlock, ...)
                           # plus project/ views + ProjectTeamPanel, ProgressReport,
                           # team/{RosterSearch,MemberRoleFields,DesignationSelect,GanttChart,TaskReportDrawer},
                           # task dependency UI, program/ProgramForm,
                           # project/{ProjectCreateForm,CodeView,RepositoryAccess}, TaskCodePanel,
                           # reports/{DailyCodeChart,PersonCommitsDrawer,UnlinkedRepositories}, ChecksMark,
                           # shell/{Sidebar,AccountMenu,PhoneTabs,OrganizationSwitcher,Logo},
                           # mywork/, todos/, team/InviteDialog, attendance/ClockButton + settings/,
                           # organogram/, filters/, work/RangePicker, project/DocumentsView,
                           # documents/{DocumentList,DocumentTrash,NewDocument,ShareDialog}
  js/lib/                  # applyTheme, clipboard, quickAdd, toast, peek, types, utils,
                           # dependencies (board ordering/links), schedule (Gantt planning),
                           # taskCode (stored project key + permanent task number),
                           # memberRoles (person-form role sets), projectLabel (title + program),
                           # navigation (main/account links), dates, format, reportVisit.svelte,
                           # structure, attendance, attendanceRegister, work, maintenance
  css/app.css              # --ws-* tokens, Tailwind @theme mapping, component classes
  views/app.blade.php      # single root view
  views/emails/            # HTML + text password-link, update and morning templates
  documents/               # blank docx/xlsx/pptx templates and their licence notice
tests/
  Feature/Workspace/       # web-surface feature tests
  Feature/Workspace/Api/   # API-surface feature tests
  Unit/                    # parsers, calendars, mail-failure classification,
                           # diff classification + ForgejoClient HTTP boundary tests
docs/api.md                # HTTP API reference + external-integration guide
docs/backups.md            # encrypted SDOC VM backup schedule, retention and restore drill
docs/forgejo.md            # forge bootstrap, OIDC, offboarding, backups, local proof,
                           # section 12: webhooks, queue worker, nightly reconcile;
                           # section 13: repositories, access sync, commit stats + code activity;
                           # section 14: runners, protected main and checks
docs/onlyoffice.md         # editor deployment, JWT boundary, RustFS storage and operations
docs/superpowers/          # design specs + implementation plans (Programs, Git phases 0/1/2,
                           # roles on people, structure/attendance/work, Documents)
forgejo/                   # separate Dokploy app: Dockerfile, start.sh, .env.example;
                           # custom/ SDOC Git branding (templates, themes, fonts, logos),
                           # local.sh Homebrew/SQLite laptop runner; runner/ four runners sharing one bounded dind
                           # plus host/ firewall script + systemd unit for runner-job isolation
ops/backup/                # encrypted SDOC VM backup script + systemd service/timer
ops/rustfs/                # production documents bucket/user/policy setup
onlyoffice/                # separate pinned ONLYOFFICE Docs image, entrypoint, fonts and environment
deploy.sh                  # SDOC Dokploy web/scheduler deploy + health verification
Dockerfile, dock.sh        # production image and local image runner
```
