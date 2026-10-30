# Git phase 2: repositories, access and code reports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create forge repositories from the tracker, grant read or write access per repository per person (synced to Forgejo as collaborators), protect `main` on created repositories, count each commit's code, comment and blank lines, and report per-person code activity with comparison.

**Architecture:** The tracker stays the source of truth. Grants live in `git_repository_grants`; `RepositoryAccess::sync` makes one repository's Forgejo collaborators match them (plus the project's manager and program manager as admins), triggered by events, every ten minutes and nightly. Commit line counts come from the forge's per-commit diff, classified by a pure `DiffStats` class, filled by a scheduled command. The report is one query (`CodeActivityQuery`) with a scope from `WorkspaceAccess`, served to an Inertia page and the API.

**Tech Stack:** Laravel 13, PHP 8.5, Pest, PHPStan level 7, Inertia + Svelte 5, Forgejo 15 API through `App\Services\Git\ForgejoClient`.

**Spec:** `docs/superpowers/specs/2026-10-30-git-phase-2-access-and-reports-design.md`

## Global Constraints

- Follow `CLAUDE.md` exactly: Route → thin controller → FormRequest `authorize()` → action service returning `ServiceResult` → JsonResource; every feature gets a web and an API controller twin and a route in both route files; every role decision is a `WorkspaceAccess` method; audit rows only through observers and `ProjectActivityRecorder`; new polymorphic subjects in the morph map.
- PHP 8.5, constructor promotion, explicit return types, curly braces always, PHPDoc array shapes, `@property` blocks matching casts. PHPStan level 7 must stay clean (`composer types:check`). Pint clean (`vendor/bin/pint --dirty`).
- Tests are Pest, in `tests/Feature/Workspace/**` (web), `tests/Feature/Workspace/Api/**` (API), `tests/Unit/**`. Every service or `WorkspaceAccess` change gets happy, failure and privilege-escalation tests. Tests run on in-memory SQLite (`phpunit.xml`); never run `php artisan migrate` against the `.env` database.
- Forge fixtures follow Forgejo 15's API structs (see the header comment of `tests/Feature/Workspace/ProjectRepositoryTest.php`); forge calls only through `ForgejoClient`; `Http::fake` in tests; never a real network call.
- Tokens and secrets never in a resource, flash, exception message or log line (log `status`, and `causeMessage($token)` for status 0, as the existing services do).
- Forge jobs go through `App\Support\Background::dispatch`.
- jsonb for any JSON column; no test depends on JSON key order.
- UI: Svelte 5 runes, semantic theme classes only (`bg-surface`, `text-fg-muted`, `border-line`, `.btn`, `.input`, `.chip`, `.popover`, ...), never raw palette colours or `dark:` variants, Lucide icons only, no em-dashes in copy, no `{@html}`.
- Exact values from the spec: permissions `read` and `write` for grants, `admin` for maintainers; repository names `^[a-z0-9]+(?:[._-][a-z0-9]+)*$`, at most 100 characters, equal to the lowercased project key or starting with it followed by `-`, not ending in `.git`, `.wiki`, `.rss`, `.atom`; description at most 255; created repositories `private: true`, `auto_init: false`, `default_branch: "main"`; the `main` rule `{"rule_name":"main","branch_name":"main","enable_push":true,"enable_push_whitelist":true,"push_whitelist_usernames":[],"push_whitelist_teams":["Owners"],"required_approvals":1,"dismiss_stale_approvals":true,"block_on_rejected_reviews":true}`; `git:sync-access` every ten minutes; `git:commit-stats` every five minutes, 300 commits per run, diffs over 5 MB (5242880 bytes) not read; report range at most 366 days, default the last 30, compare at most 4 people.

## Review Focus

1. A project manager or program manager posting a `member_id` of someone outside the project's team, or of another organization, must get a 422 and no grant: `canGrantRepositoryAccessTo` is the only gate (Task 2, Task 5).
2. A deactivated or deleted person must lose every forge collaborator role at once and never be re-added by a later sync (Task 3, Task 4).
3. A sync must never remove a collaborator the tracker does not know (no user has that `git_username`), nor `{owner}-bot` or `forge-admin` (Task 3).
4. The code report must not show a project manager, program manager or member any commit outside their scope, through the people list, the person drill-down or a `project`/`program` filter naming something outside it (Task 10).
5. A lock file, a vendored directory or a huge diff must not count as code, and a huge diff must not be loaded into memory (Task 8, Task 9).

---

### Task 1: Forge client calls for repositories, collaborators, protection and commits

**Files:**
- Modify: `app/Services/Git/ForgejoClient.php`
- Test: `tests/Unit/ForgejoClientTest.php`

**Interfaces:**
- Produces (all throw `ForgejoException` with the HTTP status on failure, status 0 when unreachable, exactly as the existing methods do; path segments `rawurlencode`d as the existing methods do):
  - `createOrganizationRepository(string $org, string $name, ?string $description): array` POST `/orgs/{org}/repos` with `{"name", "description" (empty string when null), "private": true, "auto_init": false, "default_branch": "main"}`; returns the repository JSON.
  - `protectBranch(string $owner, string $name, array $rule): void` POST `/repos/{owner}/{name}/branch_protections`.
  - `updateBranchProtection(string $owner, string $name, string $rule, array $changes): void` PATCH `/repos/{owner}/{name}/branch_protections/{rule}`.
  - `collaborators(string $owner, string $name, int $max = 500): list<string>` the logins, every page (`limit=50`), as `organizationRepositories` pages.
  - `setCollaborator(string $owner, string $name, string $login, string $permission): void` PUT `/repos/{owner}/{name}/collaborators/{login}` with `{"permission": $permission}`.
  - `removeCollaborator(string $owner, string $name, string $login): void` DELETE the same path.
  - `collaboratorPermission(string $owner, string $name, string $login): string` GET `.../collaborators/{login}/permission`, its `permission` field.
  - `commit(string $owner, string $name, string $sha): array` GET `/repos/{owner}/{name}/git/commits/{sha}` with query `stat=true&verification=false&files=false`.
  - `commitDiff(string $owner, string $name, string $sha, int $maxBytes): ?string` GET `/repos/{owner}/{name}/git/commits/{sha}.diff`; `null` when a `Content-Length` header or the body exceeds `$maxBytes`.

- [ ] **Step 1: Write the failing tests** in `tests/Unit/ForgejoClientTest.php`, one per method, each asserting with `Http::assertSent` the method, the exact URL (`https://git.example/api/v1/...`) and the JSON body where there is one, and the parsed return:
  - `it creates a private empty organization repository with main as default branch` (body exactly `["name" => "egp-api", "description" => "", "private" => true, "auto_init" => false, "default_branch" => "main"]` for a null description).
  - `it reports a taken repository name as a 409` (`ForgejoException` with `status` 409).
  - `it lists every collaborator login across pages` (page 1 has 50, page 2 has 1: returns 51 logins).
  - `it sets a collaborator's permission`, `it removes a collaborator`, `it reads a collaborator's permission` (`'write'`).
  - `it reports a collaborator the forge doesn't have as a 422` (PUT answers `422 {"message":"user does not exist [uid: 0, name: ghost]"}`: status 422).
  - `it protects a branch` and `it updates a branch protection` (PATCH to `.../branch_protections/main`).
  - `it reads a commit with its stats and parents` (query string contains `stat=true`, `verification=false`, `files=false`).
  - `it returns a commit's diff` and `it skips a diff larger than the cap` (body of 11 bytes with `$maxBytes = 10`: `null`).

- [ ] **Step 2: Run them to see them fail:** `php artisan test --compact tests/Unit/ForgejoClientTest.php` (FAIL: undefined methods).
- [ ] **Step 3: Implement the methods** with the existing private helpers (`send`, `json`, the paging used by `organizationRepositories`).
- [ ] **Step 4: Run the tests to pass**, then `composer types:check` and `vendor/bin/pint --dirty`.
- [ ] **Step 5: Commit** `git add app/Services/Git/ForgejoClient.php tests/Unit/ForgejoClientTest.php && git commit -m "Forge client: repositories, collaborators, branch protection, commit diffs"`.

---

### Task 2: Grants: table, model and the rules of who may grant whom

**Files:**
- Create: `database/migrations/2026_10_31_150000_create_git_repository_grants_table.php`
- Create: `app/Models/GitRepositoryGrant.php`
- Modify: `app/Models/GitRepository.php` (relation, casts, `@property`), `app/Models/Member.php` (relation), `app/Providers/AppServiceProvider.php` (morph map `git-repository-grant`)
- Modify: `app/Support/WorkspaceAccess.php`
- Create: `app/Queries/RepositoryAccessCandidatesQuery.php`
- Test: `tests/Feature/Workspace/RepositoryAccessRulesTest.php`

**Interfaces:**
- Produces:
  - Table `git_repository_grants`: `id`, `git_repository_id` (FK, cascade on delete), `member_id` (FK, cascade on delete), `permission` (string, 5), `granted_by` (nullable FK to `users`, null on delete), timestamps, unique `(git_repository_id, member_id)`. The same migration adds to `git_repositories`: `protects_main` (boolean, default false) and `access_synced_at` (nullable timestamp).
  - `GitRepositoryGrant` with constants `READ = 'read'`, `WRITE = 'write'`, `PERMISSIONS = [self::READ, self::WRITE]`; relations `repository(): BelongsTo` (to `GitRepository`, foreign key `git_repository_id`), `member(): BelongsTo`, `grantedBy(): BelongsTo` (User); `scopeEffective(Builder $query): void` keeps grants whose member is active and either belongs to the repository's project's organization or is on that project's team.
  - `GitRepository::grants(): HasMany<GitRepositoryGrant>`; casts `protects_main` bool, `access_synced_at` immutable datetime.
  - `Member::repositoryGrants(): HasMany<GitRepositoryGrant>`.
  - `WorkspaceAccess::repositoryMaintainerIds(Project $project): list<int>` member ids: the project's team leader if active, and the project's program's manager if active, in the project's organization, and the program in that organization too (the conditions of `managesProgramOf`). No duplicates.
  - `WorkspaceAccess::canManageRepositoryAccess(?Authenticatable $user, GitRepository $repository): bool` equals `canManageProjectRepositories($user, $repository->project)`.
  - `WorkspaceAccess::canGrantRepositoryAccessTo(?Authenticatable $user, Project $project, Member $member): bool`: `canManageProjectRepositories($user, $project)` and the member is active, has an organization, is not in `repositoryMaintainerIds($project)`, and, when the user `administers($project->organization_id)`, belongs to the project's organization or is on its team; otherwise is on the project's team.
  - `RepositoryAccessCandidatesQuery::get(?Authenticatable $user, GitRepository $repository, string $term): list<array{id: int, name: string, title: ?string}>` the list twin of `canGrantRepositoryAccessTo`: matching `name` or `email` (case-insensitive contains), excluding people who already have a grant on the repository and the maintainers, ordered by name, at most 20; no email in the output.

- [ ] **Step 1: Write the failing tests** in `tests/Feature/Workspace/RepositoryAccessRulesTest.php` (set up an organization `sdoc` with a git connection and a project as `ProjectRepositoryTest` does):
  - `canManageRepositoryAccess` is true for the project's manager, its program's manager, an organizational admin of its organization and a super-admin; false for a team member, an organizational admin of another organization, and everyone once the organization has no connection.
  - `an organizational admin may grant anyone active in the organization or on the team`; `a super-admin may too`.
  - `a project manager may grant only people on the project's team` (someone in the organization but not on the team: false; on the team: true).
  - `a program manager may grant only people on the project's team`.
  - `nobody may grant an inactive person, a person with no organization, or a maintainer`.
  - `nobody may grant someone of another organization who is not on the team`, including an organizational admin.
  - `a cross-organization team's outsider may be granted by the project manager`.
  - `maintainers are the manager and the program's manager` (and the program manager is left out when inactive or the program is in another organization).
  - `effective grants drop people who left the organization or were deactivated` (scope test: three grants, one active in-organization, one deactivated, one moved to another organization and not on the team: only the first).
  - `the candidate list matches canGrantRepositoryAccessTo for each role` (for a PM, an organizational admin: the returned ids equal exactly the ids for which `canGrantRepositoryAccessTo` is true among a fixture of 6 people; no `email` key).
- [ ] **Step 2: Run to fail:** `php artisan test --compact tests/Feature/Workspace/RepositoryAccessRulesTest.php`.
- [ ] **Step 3: Implement** the migration, model, relations, morph map entry, the three `WorkspaceAccess` methods (memos as the class already does for per-request lookups where it helps) and the query.
- [ ] **Step 4: Run to pass**, then `composer types:check`, `vendor/bin/pint --dirty`.
- [ ] **Step 5: Commit** `"Repository grants and who may give access to whom"`.

---

### Task 3: The access sync, its job, the ten-minute command and the nightly step

**Files:**
- Create: `app/Services/Git/RepositoryAccess.php`, `app/Jobs/SyncRepositoryAccess.php`, `app/Console/Commands/SyncGitAccess.php`
- Modify: `app/Services/Git/ForgeMembership.php` (make its untouchable-login rule reusable, e.g. a public static `untouchableLogins(GitConnection $connection): array<string, true>`), `app/Console/Commands/ReconcileGit.php` (an `access` step per repository after its repository step), `routes/console.php`
- Test: `tests/Feature/Workspace/RepositoryAccessSyncTest.php`

**Interfaces:**
- Consumes: Task 1's client methods; Task 2's `GitRepositoryGrant::scopeEffective`, `WorkspaceAccess::repositoryMaintainerIds`.
- Produces:
  - `RepositoryAccess::wanted(GitRepository $repository): array{logins: array<string, string>, waiting: list<int>}` logins (as stored in `users.git_username`) to `admin`, `write` or `read` (maintainers `admin`; effective grants at their permission; the highest wins); `waiting` the member ids with no linked user or no `git_username`.
  - `RepositoryAccess::sync(GitRepository $repository): array{granted: list<string>, removed: list<string>, waiting: list<string>}` (`waiting` are logins the forge answered 422 for, plus the names of `waiting` members): PUT every wanted login; remove every collaborator who is a tracker-known login (case-insensitive) not wanted and not untouchable; when `protects_main`, PATCH the `main` rule with `["enable_push" => true, "enable_push_whitelist" => true, "push_whitelist_usernames" => <maintainer logins that were PUT without error>, "push_whitelist_teams" => ["Owners"]]`, and on a 404 set `protects_main = false` and log a warning; set `access_synced_at = now()`. Each grant or removal logs at `warning` with `owner`, `repository`, `login`, `permission` (never the token). Any `ForgejoException` other than a 422 on a PUT propagates.
  - `RepositoryAccess::importExisting(GitRepository $repository, ?User $actor): int`: for each current collaborator who is a tracker user whose member is active and is in the project's organization or on its team, and is not a maintainer and has no grant yet, create a grant (`collaboratorPermission` `admin`, `owner` or `write` become `write`; `read` stays `read`; `none` skipped); returns how many.
  - `SyncRepositoryAccess` job (`ShouldQueue`, `$tries = 3`, `$backoff = 60`) with `handle(RepositoryAccess $access)`: syncs, and on `ForgejoException` logs a warning with the repository and status and returns (the ten-minute run retries). Static `SyncRepositoryAccess::forProject(Project $project): void` dispatches one job per linked repository of the project through `Background::dispatch`.
  - Command `git:sync-access {--connection=}`: syncs every linked repository (of one connection with the option), one line each `"{full_name}: {n} with access, {w} waiting."` or `"{full_name}: failed (forge answered {status})."`, exit code 1 when any failed. Scheduled in `routes/console.php`: `Schedule::command('git:sync-access')->everyTenMinutes()->withoutOverlapping();` with a one-line comment.
  - `git:reconcile` gains an `access` step for each repository (calls `sync`), reported like its other steps.

- [ ] **Step 1: Write the failing tests** (Http::fake keyed by URL; a project with a manager, a program manager, two granted developers, a forge-only collaborator `contractor` no user has, `sdoc-bot`, and a former developer whose grant was deleted but who is still a collaborator):
  - `the manager and program manager become admins and grants keep their permission` (PUT bodies: manager and program manager `admin`, developers `write` and `read`).
  - `someone the tracker knows who no longer has access is removed`, `someone the tracker doesn't know is never removed` (`contractor`), `the bot and forge-admin are never removed`.
  - `a deactivated person is not wanted` and `someone who never signed in to the forge is waiting` (no PUT for them; listed in `waiting`).
  - `a 422 for a login marks it waiting and the run goes on`; `a 500 on a PUT stops the run` (exception).
  - `the main rule's allow-list is the maintainers who are collaborators` (PATCH body `push_whitelist_usernames` equals the manager and program manager logins; a maintainer who got a 422 is left out); `a deleted main rule turns protects_main off` (PATCH 404).
  - `importExisting turns forge collaborators into grants` (`admin` → `write`, `read` → `read`; skips maintainers, unknown logins, and people of another organization not on the team).
  - `git:sync-access prints one line per repository and fails when one fails`; `git:reconcile runs an access step per repository`.
  - `the job logs and returns when the forge fails` (no exception escapes; a warning logged without the token).
- [ ] **Step 2: Run to fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to pass**, plus `php artisan test --compact tests/Feature/Workspace/GitReconcileTest.php`, `composer types:check`, Pint.
- [ ] **Step 5: Commit** `"Sync each repository's forge collaborators with the tracker"`.

---

### Task 4: What starts a sync: grants, teams, managers, programs, deactivation, linking

**Files:**
- Create: `app/Observers/GitRepositoryGrantObserver.php` (register in `AppServiceProvider` as the others are)
- Modify: `app/Observers/TeamMembershipObserver.php`, `app/Observers/ProgramObserver.php`, `app/Observers/ProjectObserver.php`, `app/Services/Workspace/RemoveFromForgeService.php`, `app/Services/Workspace/LinkRepositoryService.php`
- Test: `tests/Feature/Workspace/RepositoryAccessTriggersTest.php`, and extend `tests/Feature/Workspace/ForgeOffboardingTest.php`

**Interfaces:**
- Consumes: Task 3's `SyncRepositoryAccess`, `RepositoryAccess::importExisting`; `ProjectActivityRecorder` (read how `TeamMembershipObserver` writes project-level activity and do the same).
- Produces:
  - Grant observer: `created`, `updated` (permission changed), `deleted` each write one project-level activity row (subject the grant; `task_id` null; copy "gave {member} {permission} access to {full_name}", "changed {member}'s access to {full_name} to {permission}", "removed {member}'s access to {full_name}") and dispatch `SyncRepositoryAccess` for the grant's repository.
  - `TeamMembershipObserver`: on `created`, on `updated` when `role` changed, and on `deleted`, call `SyncRepositoryAccess::forProject` for the team's project; on `deleted`, first delete (model by model, so the grant observer runs) the member's grants on that project's repositories.
  - `ProgramObserver::updated` when `manager_id` changed: `forProject` for each project in the program. `ProjectObserver::updated` when `program_id` changed: `forProject($project)`.
  - `RemoveFromForgeService::execute`: after the organization removals, for each connection, `removeCollaborator` the user's `git_username` from every repository linked through that connection; a failure adds that repository's `full_name` to `failed` and makes `complete` false, with the same logging rule; the existing message wording stays (the failure message already asks for a manual suspend).
  - `LinkRepositoryService::execute`: after the row is created, `importExisting($repo, $actor)` (a forge failure there is logged and ignored) and dispatch `SyncRepositoryAccess` for it.

- [ ] **Step 1: Write the failing tests** (`Queue::fake()` or the existing `Background` test pattern in `ProjectRepositoryTest`; whichever that file uses):
  - `granting, changing and removing access each record project activity and queue a sync` (three activity rows with the exact copy; three `SyncRepositoryAccess` jobs for the repository).
  - `joining a project's team queues a sync of its repositories`; `becoming its manager queues one`; `leaving the team removes that person's grants on its repositories and queues a sync` (grant rows gone; activity "removed ...").
  - `a new program manager queues syncs for the program's projects`; `moving a project to another program queues a sync`.
  - `deactivating someone removes them as a collaborator from every linked repository` (DELETE `/repos/sdoc/app/collaborators/{login}` sent); `a failed collaborator removal makes the result incomplete` (meta `complete` false; message is the existing failure message).
  - `linking imports the forge's collaborators and queues a sync`.
- [ ] **Step 2: Run to fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to pass**, plus `php artisan test --compact tests/Feature/Workspace` (the whole web suite: observers touch shared paths), types, Pint.
- [ ] **Step 5: Commit** `"Keep forge access in step with teams, managers, programs and deactivation"`.

---

### Task 5: Granting access: endpoints for the web and the API

**Files:**
- Create: `app/Services/Workspace/GrantRepositoryAccessService.php`, `app/Services/Workspace/RevokeRepositoryAccessService.php`, `app/Http/Requests/StoreRepositoryGrantRequest.php`, `app/Http/Requests/UpdateRepositoryGrantRequest.php`, `app/Http/Requests/DestroyRepositoryGrantRequest.php`, `app/Http/Requests/ShowRepositoryAccessRequest.php`, `app/Http/Resources/GitRepositoryGrantResource.php`, `app/Queries/RepositoryAccessQuery.php`, `app/Http/Controllers/Workspace/RepositoryGrantController.php`, `app/Http/Controllers/Api/RepositoryGrantController.php`
- Modify: `routes/web.php`, `routes/api.php`, `docs/api.md`
- Test: `tests/Feature/Workspace/RepositoryGrantTest.php`, `tests/Feature/Workspace/Api/RepositoryGrantTest.php`

**Interfaces:**
- Consumes: Task 2's model and `WorkspaceAccess` methods and candidates query.
- Produces:
  - Routes in both files (web under the `workspace` prefix and names, API under `api/v1`), with `scopeBindings()`:
    `GET projects/{project:slug}/repositories/{repository}/access` (`projects.repositories.access.index`),
    `GET .../access/candidates?q=` (`...access.candidates`),
    `POST .../access` (`...access.store`, body `member_id`, `permission`),
    `PATCH .../access/{grant}` (`...access.update`, body `permission`),
    `DELETE .../access/{grant}` (`...access.destroy`).
  - `RepositoryAccessQuery::get(GitRepository $repository): array{maintainers: list<array{id: int, name: string, title: ?string, role: string, waiting: bool}>, grants: list<array<string, mixed>>}` (`role` is `manager` or `program manager`; `grants` through the resource, ordered by member name).
  - `GitRepositoryGrantResource`: `{id, permission, waiting, member: {id, name, title}, granted_at}` (`waiting`: the member has no linked user or no `git_username`).
  - `GrantRepositoryAccessService::execute(GitRepository $repository, Member $member, string $permission, ?User $actor): ServiceResult` creates or updates the grant (`granted_by` the actor); messages `"Gave {name} {permission} access to {full_name}."` and, when the permission changed on an existing grant, `"{name} now has {permission} access to {full_name}."`; data the grant.
  - `RevokeRepositoryAccessService::execute(GitRepositoryGrant $grant): ServiceResult` message `"Removed {name}'s access to {full_name}."`.
  - Requests: index and candidates authorize `canManageRepositoryAccess`; store validates `member_id` (required, exists) and `permission` (required, in `read,write`), authorizes `canManageRepositoryAccess`, and after validation adds the error `"You can't give {name} access to this repository."` on `member_id` unless `canGrantRepositoryAccessTo`; update the same permission rule and requires `canGrantRepositoryAccessTo` for the grant's member; destroy requires only `canManageRepositoryAccess`.
  - Web controller: `index` and `candidates` return JSON (`{data: ...}`); `store`, `update`, `destroy` redirect through `RedirectsWithServiceResult`. API controller: JSON through `RespondsWithServiceResult` (`store` 201).
  - `docs/api.md`: the five endpoints, with request and response examples.

- [ ] **Step 1: Write the failing tests** (web and API files, the API one with Sanctum tokens as the other API tests do):
  - happy: an organizational admin grants write to someone in the organization not on the team; a project manager grants read to a team member; changing to read; removing; listing shows maintainers with `role` and grants with `waiting`.
  - failure: permission `admin` is rejected (422 on `permission`); an unknown `member_id` 422.
  - escalation: a project manager granting someone in the organization who is not on the team gets 422 with the exact message and no row; a team member (not a manager) gets 403 on every endpoint; an organizational admin of another organization gets 403; a grant id of another repository in the URL is a 404 (scoped binding); a project manager may not raise an organization-admin-made grant for someone off the team (update 422) but may remove it.
  - candidates: the response has no `email` and equals the rule's set (one assertion per role, PM and organizational admin).
- [ ] **Step 2: Run to fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to pass**, types, Pint.
- [ ] **Step 5: Commit** `"Grant, change and remove repository access, on the web and the API"`.

---

### Task 6: Creating a repository from the tracker

**Files:**
- Create: `app/Http/Requests/CreateRepositoryRequest.php`, `app/Services/Workspace/CreateRepositoryService.php`
- Modify: `app/Http/Controllers/Workspace/ProjectRepositoryController.php`, `app/Http/Controllers/Api/ProjectRepositoryController.php`, `routes/web.php`, `routes/api.php`, `docs/api.md`
- Test: `tests/Feature/Workspace/CreateRepositoryTest.php`, `tests/Feature/Workspace/Api/CreateRepositoryTest.php`

**Interfaces:**
- Consumes: Task 1 (`createOrganizationRepository`, `protectBranch`), `LinkRepositoryService` (Task 4 version), Task 3's `SyncRepositoryAccess`.
- Produces:
  - Route `POST projects/{project:slug}/repositories/new` named `projects.repositories.create` in both files; controller method `create(CreateRepositoryRequest $request, Project $project, CreateRepositoryService $service)`.
  - `CreateRepositoryRequest`: authorize `canManageProjectRepositories`; `name` required, string, max 100, the regex and suffix rules from Global Constraints, and must equal `strtolower($project->key)` or start with `strtolower($project->key).'-'` (message `"Start the name with {key}, such as {key}-api."`, lowercased key); `description` nullable string max 255.
  - `CreateRepositoryService::MAIN_RULE` the exact rule array from Global Constraints. `execute(Project $project, string $name, ?string $description, ?User $actor): ServiceResult`:
    1. no connection: failure `"Git isn't on for this organization."` 422;
    2. `createOrganizationRepository`; 409: failure `"A repository named {name} already exists in {owner}. Link it instead."` 422; other forge failures as `LinkRepositoryService::forgeFailed` words them (reuse, do not copy: extract a shared helper if needed);
    3. `protectBranch(owner, name, MAIN_RULE)`; on failure remember it (logged);
    4. `LinkRepositoryService::execute($project, "{owner}/{name}", $actor)`; on failure: failure `"Created {owner}/{name} on the forge, but couldn't link it: {that message}"` 422;
    5. on success set `protects_main = true` when step 3 worked, and return success data the repository with message `"Created and linked {owner}/{name}."`, or `"Created and linked {owner}/{name}, but main couldn't be protected: protect it on the forge."`.
  - `docs/api.md`: the endpoint.

- [ ] **Step 1: Write the failing tests:**
  - `a project manager creates a repository: private, empty, main protected, linked` (POST body to `/orgs/sdoc/repos` as Task 1; POST to `/repos/sdoc/tp-api/branch_protections` with exactly `MAIN_RULE`; a `git_repositories` row with `protects_main` true; flash `"Created and linked sdoc/tp-api."`; a sync job queued).
  - `the name may be the key itself` (`tp`); `names that don't start with the key are refused` (`api`, `tpx`, `tp_api`: 422 with the message); `bad names are refused` (`TP-Api`, `tp--api`, `tp-api.git`, `tp-.x`, a 101-character name).
  - `a taken name says to link it` (409); `a failed protection still links, and says so` (`protects_main` false, the second message); `a failed link after creation says it exists` (the forge's GET for the new repository answers 500).
  - escalation: a team member 403; an organizational admin of another organization 403; no connection 403 (the request's authorize already needs git on).
  - API twin: 201 with the repository resource, and the same refusals.
- [ ] **Step 2: Run to fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to pass**, plus `tests/Feature/Workspace/ProjectRepositoryTest.php`, types, Pint.
- [ ] **Step 5: Commit** `"Create a protected repository from the Code tab, linked on creation"`.

---

### Task 7: The Code tab: new repository and access panels

**Files:**
- Modify: `resources/js/components/project/CodeView.svelte`, `app/Queries/ProjectShowQuery.php` (add `project_key` to the `git` payload if the page lacks it), `resources/js/pages/Guide.svelte` (its Code section)
- Create: `resources/js/components/project/RepositoryAccess.svelte`
- Test: extend `tests/Feature/Workspace/ProjectRepositoryTest.php` only if `ProjectShowQuery` changes (assert the new prop)

**Interfaces:**
- Consumes: Task 5's routes (JSON index and candidates; Inertia `router.post/patch/delete` for changes with `preserveScroll`), Task 6's route.
- Produces:
  - In the Link repository popover: a **New repository** form (name input prefilled with the lowercased key when no linked repository has that name, else `key-`; optional description; a primary **Create and link** button; the server's field errors under the inputs). When the candidate list is empty the form shows at once, under the existing "No repository on the forge is left to link." line; otherwise a **New repository** row at the bottom of the list opens it.
  - For whoever has `git.can_manage`, each repository row gets an **Access** button opening `RepositoryAccess.svelte`: the maintainers (name, title, "Manager" or "Program manager", "Manages the repository"), the grants (name, title, a Read/Write select, a remove button, and "Hasn't signed in to SDOC Git yet" when `waiting`), an **Add someone** search over the candidates endpoint with a Read/Write choice (default Write), and the line "Organizational admins and super-admins reach every repository."
  - Guide, Code section: creating a repository (the name rule, `main` protected: changes go through pull requests, the manager and program manager push directly), access (who may give it, to whom, waiting until first sign-in, removed when leaving the team or deactivated), and that commits count toward a person only when their git email is on their SDOC Git account (Settings, Emails).

- [ ] **Step 1:** Implement; keep the popover's existing behaviour and copy.
- [ ] **Step 2: Verify:** `npm run lint:check && npm run format:check && npm run types:check && npm run build`, and `php artisan test --compact tests/Feature/Workspace/ProjectRepositoryTest.php`.
- [ ] **Step 3: Commit** `"Code tab: create a repository and manage who can reach it"`.

---

### Task 8: Classifying a diff's lines

**Files:**
- Create: `app/Support/Git/DiffStats.php`, `app/Support/Git/CommentSyntax.php`, `app/Support/Git/GeneratedFiles.php`
- Test: `tests/Unit/DiffStatsTest.php`

**Interfaces:**
- Produces:
  - `GeneratedFiles::matches(string $path): bool` the spec's list (lock files by basename; `vendor/`, `node_modules/`, `dist/`, `build/`, `public/build/`, `.next/`, `coverage/` as any path segment; `.min.js`, `.min.css`, `.map` suffixes).
  - `CommentSyntax::forPath(string $path): ?array{line: list<string>, block: list<array{0: string, 1: string}>}` by extension (and `Dockerfile`, `Makefile` by basename): C-like `//` and `/* */` (php, js, mjs, cjs, ts, tsx, jsx, java, kt, kts, swift, go, rs, c, h, cc, cpp, hpp, cs, dart, scala, groovy, gradle, scss, less, json5); css `/* */` only; hash `#` (py, rb, sh, bash, zsh, yaml, yml, toml, r, pl, ps1, conf, env, Dockerfile, Makefile) with Python also treating `"""` and `'''` as blocks; ini `;` and `#`; sql `--` and `/* */`; lua `--` and `--[[ ]]`; hs `--` and `{- -}`; html, htm, xml, svg `<!-- -->`; vue and svelte `//`, `/* */` and `<!-- -->`; `*.blade.php` `{{-- --}}`, `<!-- -->`, `//`, `/* */`. `null` for unknown types (lines count as code).
  - `DiffStats::DOC_EXTENSIONS = ['md', 'markdown', 'rst', 'txt', 'adoc']`.
  - `DiffStats::classify(string $diff): array{files_changed: int, code_added: int, code_removed: int, comment_added: int, comment_removed: int, blank_added: int, blank_removed: int, docs_added: int, docs_removed: int, generated_lines: int}`: files from `diff --git a/... b/...` headers (the new path, or the old one for a deletion; every file counts in `files_changed`); `Binary files ... differ` contributes no lines; generated files add their `+`/`-` lines to `generated_lines` only; docs files to `docs_*`; others to blank (whitespace only), comment (per `CommentSyntax`, block state tracked separately for the old and new side through each hunk: context lines advance both, `-` lines the old, `+` lines the new; state resets at each hunk and file) or code. Ignore `\ No newline at end of file`, `---`/`+++` headers and `index`, mode, rename and similarity lines. Strip a trailing `\r`.

- [ ] **Step 1: Write the failing tests** in `tests/Unit/DiffStatsTest.php`, each a small literal diff with exact expected arrays:
  - a PHP file adding 2 code lines, a `//` line, a 3-line `/** ... */` docblock and a blank line (code 2, comment 4, blank 1);
  - a Python file with `#` comments and a `"""` docstring spanning 3 lines;
  - a block comment opened on a context line continuing into added lines (they count as comment);
  - removed lines count on the removed side only;
  - a Markdown file (docs only); `package-lock.json` and `vendor/foo/bar.php` and `app.min.js` (generated only, `files_changed` still counts them);
  - a binary file (files 1, no lines); a new file (`--- /dev/null`); a deleted file (`+++ /dev/null`); a pure rename (files 1, no lines);
  - HTML `<!-- -->`, Blade `{{-- --}}`, SQL `--`, YAML `#`; an unknown extension (`.xyz`) counts as code;
  - CRLF line endings; `\ No newline at end of file` ignored;
  - two files in one diff sum correctly.
- [ ] **Step 2: Run to fail;** **Step 3: implement;** **Step 4: run to pass**, types, Pint.
- [ ] **Step 5: Commit** `"Classify a diff's lines as code, comments, blank, docs or generated"`.

---

### Task 9: Counting every commit's lines

**Files:**
- Create: `database/migrations/2026_10_31_150100_add_line_counts_to_git_commits_table.php`, `app/Services/Git/CommitStats.php`, `app/Console/Commands/ComputeCommitStats.php`
- Modify: `app/Models/GitCommit.php` (`@property`, casts, `user(): BelongsTo`), `app/Services/Git/GitIngest.php` (set `user_id`), `routes/console.php`
- Test: `tests/Feature/Workspace/CommitStatsTest.php`

**Interfaces:**
- Consumes: Task 1 (`commit`, `commitDiff`), Task 8 (`DiffStats::classify`).
- Produces:
  - Columns on `git_commits`: `user_id` (nullable FK `users`, null on delete), `is_merge` (bool, default false), `files_changed`, `additions`, `deletions`, `code_added`, `code_removed`, `comment_added`, `comment_removed`, `blank_added`, `blank_removed`, `docs_added`, `docs_removed`, `generated_lines` (unsigned integers, default 0 except `files_changed`, `additions`, `deletions` nullable), `stats_status` (nullable string 16), `stats_computed_at` (nullable timestamp); indexes `(user_id, committed_at)` and `(stats_computed_at)`.
  - `GitIngest` sets `user_id` on every commit it upserts: the user whose `git_username` equals `author_username` case-insensitively, else null.
  - `CommitStats::MAX_DIFF_BYTES = 5242880`; `CommitStats::compute(GitCommit $commit): void` per the spec (merge: `is_merge` true, status `merge`; normal: forge totals plus `DiffStats::classify`, status `ok`; `commitDiff` null: totals only, status `too_large`; 404: status `missing`; all set `stats_computed_at`); other `ForgejoException`s propagate.
  - Command `git:commit-stats {--limit=300}`: first fills `user_id` for rows with an `author_username` and no `user_id` (one set-based update per matching user, not per row); then computes stats for commits without `stats_computed_at`, oldest `committed_at` first, up to the limit; a status 0 or 5xx from one connection skips that connection's remaining commits for this run; prints `"Counted {n} commits."` (plus `" {k} left for the next run."` when more remain). Scheduled `everyFiveMinutes()->withoutOverlapping()` with a comment.

- [ ] **Step 1: Write the failing tests:** a normal commit (stats and classified counts stored, status `ok`); a merge (two parents: `is_merge`, no diff requested); an oversize diff (`too_large`, totals stored); a vanished commit (`missing`); a 503 stops that connection's run and leaves `stats_computed_at` null; the limit is honoured and the summary says what is left; ingest sets `user_id` case-insensitively; the command fills `user_id` for older rows.
- [ ] **Step 2: Run to fail;** **Step 3: implement;** **Step 4: run to pass**, plus `tests/Feature/Workspace/GitIngestTest.php`, types, Pint.
- [ ] **Step 5: Commit** `"Count each commit's code, comment and blank lines from its diff"`.

---

### Task 10: The code activity report: scope, query, endpoints

**Files:**
- Modify: `app/Support/WorkspaceAccess.php`, `routes/web.php`, `routes/api.php`, `docs/api.md`
- Create: `app/Queries/CodeActivityQuery.php`, `app/Http/Requests/CodeActivityRequest.php`, `app/Http/Controllers/Workspace/CodeActivityController.php`, `app/Http/Controllers/Api/CodeActivityController.php`
- Test: `tests/Feature/Workspace/CodeActivityTest.php`, `tests/Feature/Workspace/Api/CodeActivityTest.php`

**Interfaces:**
- Consumes: Task 9's columns.
- Produces:
  - `WorkspaceAccess::codeReportScope(?Authenticatable $user): array{all: bool, organization_ids: list<int>, program_ids: list<int>, project_ids: list<int>, user_id: ?int}`: super-admin `all`; organizations the user administers (organizational admin); `managedProgramIds`; the projects of `ledTeamIds`; and the user's own id for their own commits.
  - `CodeActivityQuery::get(?Authenticatable $user, array $filters): array{range: array{from: string, to: string}, people: list<array<string, mixed>>, series: list<array{user_id: int, days: list<array{date: string, code_changed: int, commits: int}>}>, uncounted: int, options: array{projects: list<array{slug: string, title: string}>, programs: list<array{slug: string, name: string}>}}` with `$filters` `{from: CarbonImmutable, to: CarbonImmutable, project: ?Project, program: ?Program, members: list<int>}` (`members` are user ids). Rows per person (`user_id`, `name`, `title`, `commits`, `active_days`, `repositories`, `files_changed`, the eight classified counts, `pull_requests_opened`, `pull_requests_merged`, `tasks`), sorted by `code_added + code_removed` descending; commits only non-merge, reachable, in range, in scope (whole repositories in scope, plus the viewer's own commits anywhere); one row with `user_id` null and `name` "Not matched to anyone" for unmatched commits, only when the viewer has whole repositories in scope; `series` for the people in `members` only, one entry per day in range (zero days included); `uncounted` the in-scope commits in range without `stats_computed_at`; `options` the projects and programs in the viewer's scope that have linked repositories.
  - `CodeActivityQuery::commitsOf(?Authenticatable $user, int $userId, array $filters, int $limit = 50): list<array{sha: string, message: string, repository: string, url: string, committed_at: string, code_added: int, code_removed: int, comment_added: int, comment_removed: int, stats_status: ?string}>` (first line of the message), newest first, in the same scope.
  - `CodeActivityRequest`: `from`, `to` (dates, `to` not before `from`, at most 366 days apart; default the last 30 days ending today), `project` and `program` (slugs; 403 unless inside the viewer's scope), `members` (array of at most 4 integers). Authorizes anyone with git (`gitEnabled` for web, `git_enabled` for the API: reuse `GitAccess`).
  - Routes: web `GET reports/code` (`workspace.reports.code`, Inertia page `Reports/CodeActivity` with the query result plus the applied filters) and `GET reports/code/people/{user}` (JSON `{data: commitsOf(...)}`); API `GET reports/code` and `GET reports/code/people/{user}` (JSON). A person outside the viewer's scope returns an empty list, never a 403 that would confirm they exist.
  - `docs/api.md`: both endpoints.

- [ ] **Step 1: Write the failing tests:**
  - scope: a super-admin sees two organizations' people; an organizational admin only their organization's; a program manager only their programs' projects; a project manager only the projects they lead; a member only their own row; an organizational admin of another organization sees nobody of this one.
  - counting: merges and unreachable commits are left out; the range filter; a project filter outside the scope is 403; the unmatched row is shown to an organizational admin and never to a member; pull requests opened and merged in range; tasks counted through `task_git_links`; `uncounted` counts commits without stats.
  - compare: `members` with 2 ids gives 2 series with one entry per day; 5 ids is a 422; a range of 367 days is a 422.
  - person drill-down: their commits in scope, newest first, first message line only; someone outside scope gives `[]`.
  - API twins of the main cases.
- [ ] **Step 2: Run to fail;** **Step 3: implement;** **Step 4: run to pass**, types, Pint.
- [ ] **Step 5: Commit** `"Code activity report: who committed what, scoped to what each viewer runs"`.

---

### Task 11: The code activity page

**Files:**
- Create: `resources/js/pages/Reports/CodeActivity.svelte`, `resources/js/components/reports/DailyCodeChart.svelte`
- Modify: `resources/js/components/AppShell.svelte` (sidebar link "Code activity", Lucide icon, only when `gitEnabled`), `resources/js/pages/Guide.svelte` (a short "Code activity" part in the Code section)

**Interfaces:**
- Consumes: Task 10's page props and the person JSON route.
- Produces: a page in `AppShell` with the 44px top bar (breadcrumb "Code activity"); filters (7, 30, 90 days presets and from/to dates, a project or program select from `options`) applied through Inertia visits with the query string; a table of people (name and title; commits; active days; code +/−; comments +/−; blank; docs; pull requests opened/merged; tasks) with numbers in Geist Mono, a checkbox per row to compare (at most 4, the rest disabled once 4 are chosen); when 2 to 4 are chosen, side-by-side stat columns and `DailyCodeChart` (an inline SVG multi-line chart of `code_changed` per day, one series per person, distinct series colours from the theme's tokens with a legend, readable in light and dark, axis ticks in Geist Mono); a row click opens a drawer with that person's commits from the person route (repository, message, code and comment counts, date, a link to the forge); a note when `uncounted > 0` ("{n} commits are still being counted."); an empty state when nobody has commits in range. Copy follows CLAUDE.md (no em-dashes, sentence case).

- [ ] **Step 1:** Implement (read the `dataviz` skill's guidance on series colours and line charts before the chart).
- [ ] **Step 2: Verify:** `npm run lint:check && npm run format:check && npm run types:check && npm run build`, and `php artisan test --compact tests/Feature/Workspace/CodeActivityTest.php`.
- [ ] **Step 3: Commit** `"Code activity page: compare people's commits and lines"`.

---

### Task 12: The runbook and the project guide

**Files:**
- Modify: `docs/forgejo.md` (a new section 13 "Repositories, access and code activity": creating, access and its sync, `git:sync-access`, `git:commit-stats`, the `main` rule and the Forgejo behaviour behind the allow-list, the commit email matching, what the reconcile now does; update section 12's reconcile list and the "What runs where" table if needed), `CLAUDE.md` (a "Git (Forgejo), phase 2" section in the style of the phase 1 section: models, rules, services, commands, UI), `README.md` only if it lists commands.

- [ ] **Step 1:** Write them from the code as merged (read the services, not this plan, for exact names).
- [ ] **Step 2:** `npx prettier --check docs/forgejo.md CLAUDE.md` is not configured for docs; just keep the existing style. Run `composer test` once at the end to prove the branch green.
- [ ] **Step 3: Commit** `"Runbook and CLAUDE.md for repositories, access and code activity"`.
