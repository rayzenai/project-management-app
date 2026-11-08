# Testing

Pest 4 (+ pest-plugin-laravel). CI gate is `composer test`
(config:clear + pint check + PHPStan level 7 + Pest).

## Layout

- `tests/Feature/Workspace/**` — web-surface feature tests (visibility,
  permissions, quick-add, notifications, soft-delete/restore, reminders,
  seeder, plan tracker, appearance, login-detail/password/invite/update/morning email, organizations,
  project progress, task dependencies, multi-tenant isolation, stable task codes,
  programs, Git connections/code/webhooks/reconcile, OIDC sign-in and forge offboarding).
- `tests/Feature/Workspace/Api/**` — API-surface twins (`AuthApiTest`,
  `ProjectApiTest`, `TaskApiTest`, `TaskCommentApiTest`, `MemberApiTest`,
  `TeamRosterApiTest`, `MemberRolesApiTest`, `NotificationApiTest`, `RestoreApiTest`).
- `tests/Feature/` root — cross-cutting (WorkspaceAccessTest, theme tokens,
  user preferences, api-token command, seeders).
- `tests/Unit/` — parsers (MentionParser, TaskReferences), Bikram Sambat,
  mail-failure classification, DiffStats classification and ForgejoClient HTTP tests.
- `ProjectProgressTest` covers both surfaces, access gates and legacy web
  redirects; `TaskDependencyTest` covers web/API links, cycle/scope validation,
  audit logging, quick-add and graph props. `OneTeamPerProjectMigrationTest`
  covers folding existing teams into project-owned teams with one leader.
  `ProjectCreateAccessTest`/`ProjectUpdateAccessTest` and `TenancyTest` cover
  manager appointment, roster protections and reserved super-admin emails.
  `ProjectCreatePeopleTest` covers initial team selection on web/API, preserving
  a selected manager's leader role, refusing foreign/inactive/unknown people
  without creating a project or team, super-admin cross-organization creation,
  ignored organizational-admin flags and program-manager membership boundaries.
- `OverdueRuleTest` checks the model scope/attribute and project/My work counts;
  `DeadlineReminderTest` also excludes failed (closed) work. `TeamActivityTest`
  covers roster/manager audit entries, team notifications, actor suppression,
  project-feed visibility on web/API and the publication capability.
  `TaskDeleteTest` covers immutable creator attribution, creator/project-runner
  delete and restore access, public-project and teammate refusals, loss of
  creator visibility, web/API parity, task-page/Peek capability flags,
  undo redirects and migration backfill from creation activity.
  `GuidePageTest` checks signed-in access, guest redirects and the reader's
  accessible forge list/username without leaking tokens;
  `SdocProjectsSeederTest` checks project/team creation, safe reruns and
  isolation from another organization's same-named project, plus agreed-key
  updates with aliases and preservation of admin-chosen keys.
  `SdocKeysMigrationTest` separately covers the deployed key migration: aliases,
  chosen-key preservation, conflicts, absent SDOC and independence from seeders.
- `ProjectKeyTest` covers suggestion/backfill, tenant uniqueness, reserved aliases
  and web/API key-change permissions; `ProjectCodeTest` covers resource/query
  code loading. `TaskNumberTest` covers backfill, counter non-reuse after purge,
  number immutability on web/API, quick-add, code/alias search and legacy bounds.
- `GitConnectionTest` covers web/API super-admin gates, forge ownership checks,
  connection failures, URL validation/normalization, office uniqueness and secret
  redaction. `ForgeOffboardingTest` covers deactivation/deletion across all
  connections, every forge repository (linked or not), case-insensitive actual
  collaborator removals, unreachable short-circuiting, partial failures, token
  masking, warning logs and completion feedback.
- `OidcProviderTest` covers discovery/JWKS, code-to-token claims, access/tenant
  gates (including cross-organization teams), scope filtering, redirect/consent
  handling, SPA login redirect, deactivated-session sign-out before the git gate,
  sessionless throttled token route, persistent
  Forgejo-safe usernames, purge schedule and client command, with temporary
  signing keys generated in the test.
- `LoginTest` + `Api/AuthApiTest` cover the shared five-attempt email/IP throttle
  across both credential surfaces, reset after a successful login, deactivated
  login refusal, the super-admin exception, and termination of existing web
  sessions/API tokens after deactivation.
- `ForgotPasswordTest` + `Api/PasswordApiTest` cover generic reset answers,
  separate reset/invite brokers, expiry/one-use/kind/deactivation refusals,
  timing, throttles, password rules, session invalidation and API-token survival.
  `EmailCaseTest`, `ApiTokenCommandTest` and migration tests cover lowercased
  storage/lookups, case-collision warnings and case-insensitive super-admins.
  `LastSeenTest` covers hourly touches and invite resend classification.
- `InvitePeopleTest` + `Api/InvitePeopleApiTest` cover preview/send plan rebuilds,
  200-person limits, throttles, tenant/manager privacy, existing/no-login people,
  designations, recent resend suppression, after-commit jobs and send-time rechecks.
- `EmailUpdatesTest` and `MorningEmailTest` cover preferences, grouping/quiet/max
  waits, claims and idempotency, My work/working-day selection, pretend mode and
  unreachable/deferred/refused SMTP behavior including first-three refusals.
  `FirstRefusalsTest` and `MailFailureTest` isolate the failure classifiers.
- `OptionalFeaturesTest` covers default-off notes/contacts, web/API preference
  updates, preservation of other settings, boolean validation, shared task-note
  feed gating and API user feature context. `TaskNotesInWorkspaceFeedTest`
  explicitly enables task notes before testing feed contents.
- Programs: `ProgramModelTest`, `ProgramAccessTest`, `ProgramManagementTest`,
  `ProjectProgramTest`, `ProgramProgressTest`, `ProgramWorkspaceTest` and
  `ProgramEditPageTest` cover model/scope behavior, tenant and inactive-manager
  gates, CRUD, project moves/deletion audit, progress, grouping/shared context,
  mention-candidate privacy and admin editing. `Api/ProgramApiTest` covers the
  parallel list/CRUD surface; progress and project assignment also test API calls.
- `ProgramManagerBuildsTest` covers web/API creation within managed programs,
  manager replacement (including teamless projects), filtered admin-only fields,
  same-organization appointments even on cross-organization teams, inactive
  managers, unchanged member powers and super-admin creation from a program page.
  `ProgramGeneralProjectTest` covers atomic program/General creation, rollback
  on failure or a missing manager, task creation, program labels and page controls.
- `MemberRolesTest` + `Api/MemberRolesApiTest` cover person create/update role
  sets, manager handovers and membership retention, whole-save rollback on
  refusal, inactive people, tenant and cross-organization boundaries, forbidden
  fields/callers, unchanged omitted roles, resource/options shapes, archived
  exclusions, program-qualified labels and bounded People-page query counts.
- `QuickAddEverywhereTest` covers General creation and rollback on web/API,
  start authorization and project validation, assignments that join a team,
  off-team/member/inactive/foreign refusals, task/preview candidate privacy,
  shared context, bounded query counts and the roster predicate twins.
  `AssignmentMemberTest` also checks blank roles do not add empty parentheses
  to assignment activity descriptions.
  `ActivityWordingTest` covers status/priority/note labels, office-formatted
  dates, task lifecycle wording and migration of reconstructable old rows.
- `MyWorkTest` + `Api/MyWorkApiTest` cover every due/snooze/asked-others/Done
  group, personal dates, closed-work exclusion, organization switching, recent
  team joins and query bounds. `WorkingDaysTest` covers fallback/configured work
  weeks and stable week starts; `Unit/BikramSambatTest` covers calendar bounds.
- `AssignmentUpdateAccessTest` proves only an assignment's owner changes star,
  snooze and personal due date, even when another viewer may change role/priority.
  `PersonalTodoTest` + API twin cover private quick add, date parsing, assignment
  refusals, move-to-task atomicity and owner-only 404s. `TodoListTest` + API twin
  cover Doing/To do/recent Done ordering, serialized actions, delete/restore and
  pruning.
- Code: `ProjectRepositoryTest` + `Api/ProjectRepositoryTest` cover link/search/
  unlink gates, ownership, concurrency, secret redaction and backfill.
  `ProjectCodeViewTest` covers project/task payloads, PR flags and repository
  visibility across projects; `GitActivityVisibilityTest` covers scoped audit.
  `GitIngestTest` covers references/aliases, link replacement, stale snapshots
  and preservation of task status; `Unit/TaskReferencesTest` covers parsing.
- `GitWebhookTest` covers signature/size/UUID checks, replay/retry safety,
  queue failure handling and push/PR/branch/repository events. `GitReconcileTest`
  covers hook repair, unreachable commits, gone repositories, ownership and
  removal breakers, owner/member sync, logging and delivery retention.
  `GoToTaskTest` covers forge links, tenant isolation, aliases, inaccessible-task
  404s and guest sign-in. `Unit/ForgejoClientTest` covers pagination and forge APIs.
- Access: `RepositoryAccessRulesTest` covers grant/effective-scope predicate
  twins, inactive managers, maintainers, tenant boundaries and candidate limits.
  `RepositoryAccessSyncTest` covers removal-before-grant ordering, bots/unknown
  logins, waiting/refused users, main-rule drift repair and creation, import, job freshness,
  ten-minute schedule/lock expiry and reconcile. `RepositoryAccessTriggersTest`
  covers observer activity/jobs, code-gated feeds, atomic team leave/grant
  removal, program/member moves, link import rollback and after-response sync.
  `BackgroundTest` checks transaction and nested-savepoint rollbacks drop jobs.
- `RepositoryGrantTest` + `Api/RepositoryGrantTest` cover list/candidates and
  grant CRUD, read/write validation, scoped bindings, concurrent grants,
  waiting/effective payloads, off-team permission-change refusals and outsider
  privacy. `CreateRepositoryTest` + its API twin cover key-prefixed name rules,
  private creation/main protection/linking, warnings/partial failures and gates.
- `CommitStatsTest` covers totals/classification, merge/large/missing handling,
  username attribution/backfill, attempt limits, per-connection failures and
  five-minute schedule/lock expiry. `Unit/DiffStatsTest` covers comment syntax,
  independent hunk-side state, generated/docs/binary files, quoted paths and
  linear scans of long lines. `Unit/ForgejoClientTest` also covers repository
  creation, collaborators, protection and bounded streamed diffs.
- `CodeActivityTest` + `Api/CodeActivityTest` cover report role/tenant scopes,
  ranking/ties/name order/unmatched authors, own commits, period/range/project/
  program/designation filters, repository-created counts, merge/unreachable
  exclusions, PR/task/check counts, zero-filled comparisons, private person drill-down
  and bounded query counts. The query-count test fakes `SyncRepositoryAccess`
  so after-response access-sync queries cannot affect its measurements.
- `UnlinkedRepositoriesTest` + API twin cover forge-list caching/failure, cached
  sidebar counts, stale linked-row removal, title/key suggestions, link and
  create-project writes, rename/concurrency races, rollback and tenant gates.

- Structure: `Structure/{Foundation,ReportingLines,ReportsTo,Designation,
  MemberDesignation,Organogram,PersonFormStructure,OfficeLinks}Test` cover CTE
  walks/loops, scope twins, role/tenant gates, move/deletion behavior, designation
  CRUD/pickers, filtered person fields, whole-save rollback and shared office
  links. `TitlesToDesignationsMigrationTest` covers title conversion;
  `Api/Structure/StructureApiTest` exercises the parallel surface.
- Attendance: `Attendance/{Settings,Punch,PunchPlace,PunchFactory}Test` cover
  enablement/configuration, ordered immutable punches, server time, place/radius/
  network rules, required notes and tenant boundaries. `RegisterTest`,
  `SummaryTest`, `LeaveTest` cover expected/present/late totals, enablement/join
  date bounds, exemptions, leave/kaaj decisions, no self-approval and scope.
  API twins live in `Api/Attendance/{Settings,Punch,Register,Leave}ApiTest`.
- Work: `Work/WorkFixture.php` shares fixtures;
  `Work/{WorkReports,WorkAttendance}Test` cover actor attribution, deduplication,
  hidden-name privacy, reachable/non-merge code, PR attribution, range/scope/
  oversight gates, bounded queries and attendance integration.
  `Api/Work/WorkApiTest` verifies API parity. `Tests/TestCase` flushes
  `WorkspaceAccess`/OrgChart memos between tests (SQLite reuses IDs).
- `MaintenanceTest` checks each repair list, admin/tenant scope, no-manager
  projects, designation/organogram issues, code-check/protection/default-drift
  issues and count caching. `MemberRolesTest`
  and its API twin also cover permitted project-manager removal with membership
  retained and forbidden program-manager removal.
- `CommitChecksTest` covers status normalization and safe relative/absolute run
  URLs, webhook/command refresh, PR head copying/catch-up, protected-branch checks,
  retry/connection limits and schedule. `GitWebhookTest`/`GitReconcileTest`
  additionally cover workflow events, bad-signature hook replacement and secret
  rotation; access-sync tests cover full main-rule enforcement without redundant
  writes. `CreateRepositoryTest` checks README creation before protection.
- `OrganizationSwitchTest` covers super-admin-only persistence, sidebar/shared
  state, page-pick synchronization, deleted organizations, People/office-page
  defaults, web list narrowing (My work, Projects, activity) and API
  independence. `CodeActivityTest` covers its switched-organization report scope.
- Repository link/create/access-sync tests cover fixing `protected_branch` at
  link time, forcing tracker-created repositories to `main`, keeping the rule
  there after forge default drift and exposing both branches in resources/UI.
- `DocumentsTest` retains project-home and editor coverage: disabled/configured
  access, team/program/admin and tenant gates, blank/uploaded files, validation,
  rename/trash/restore/download/version history, project activity, signed/JWT
  file/callback routes, editor-config expiry/history clearing, saved-file host/
  hash handling, error saves, stale sessions and retry responses.
  `DocumentsAccessTest` is the `documentAccess`/`Document::scopeVisibleTo`
  matrix: personal ownership, organization access, effective shares, highest
  level, organization moves, frozen owners, project sharing powers and the
  personal-document/login deletion constraint. `DocumentsLibraryTest` covers
  read-only/editable opens, frozen trash, old-link redirects, filters/order/
  cursor pagination, project/personal create/upload/trash, organization switch,
  API parity, one-way moves and bounded query counts for both library and
  project-tab lists. `DocumentsSharingTest` covers organization and
  named-person sharing, privacy-preserving outsider errors/candidates, frozen/
  trashed refusals, activity, notifications/right-away email, concurrent-share
  idempotence, the 24-hour notification cooldown, the shared web/API throttle
  and surface parity.
  `DocumentsTabsTest` covers Recent/Personal/Shared semantics, project cards and
  ordering, old tab aliases, filtered rows, effective share counts, project-open
  capability, API parity, organization narrowing, frozen shared exclusions,
  one-visit creation flash and JSON-upload versus Inertia-redirect responses.
  These tests fake every editor HTTP call; none reaches ONLYOFFICE or RustFS.
- `LanguageTest` covers default/saved/cookie locale precedence, guest switching,
  validation, translated errors/actions/sign-in refusals, web/API preference
  parity, and login adoption of a cookie only when no saved choice exists.

## Running

```bash
composer test                                        # full gate
php artisan test --compact tests/Feature/Workspace   # one directory
php artisan test --compact --filter=ProjectProgress  # one test/group
```

Output is JSON-rewritten by `laravel/pao`; empty JSON means a PHP fatal —
rerun `php -d display_errors=stderr vendor/bin/pest > file 2>&1`.
`phpunit.xml` uses in-memory SQLite, array cache/session/mail and sync queue,
with a 512 MB memory limit.

## Expectations

- Any change to `WorkspaceAccess` or an action service needs happy-path +
  failure-path + privilege-escalation tests.
- Tenant changes must prove both isolation and the deliberate
  cross-organization-team exception across super-admin, organizational-admin, leader
  and member roles (`TenancyTest` is the broad regression suite).
- New features touch both surfaces, so test both (web dir + Api dir).
- Frontend gate: `npm run lint && npm run format && npm run types:check &&
  npm run build`, plus `npm run i18n:check` (also in lint CI; no JS test suite).
  The translation check scans `resources/js` and `app` for literal keys, rejects
  interpolated keys, and verifies Nepali entries and `:placeholders`; unused
  dictionary entries warn only.
