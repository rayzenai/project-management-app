# Git Phase 1: Projects Link Repos, Tasks Show Code — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A manager links forge repositories to a project. Commits, branches and pull requests that mention `KEY-12` then appear on that task. `KEY-12` written on the forge links back to the task. A nightly reconcile fills gaps and keeps forge membership in step with the tracker.

**Architecture:** Turning git on creates one organization webhook per connection. A signed intake route stores each delivery once and queues a job. Webhooks, backfill and reconcile all feed one ingest service (`GitIngest`), which upserts branches, commits and pull requests and links them to tasks through `TaskReferences` (the key parser). Every path therefore links the same way. Link and unlink are action services with web and API twins. The UI adds a Code tab on the project, a Code section in the task Peek, and a pull-request mark on board cards.

**Tech Stack:** Laravel 13, queues (`ShouldQueue` jobs; production runs `sync` until the `tasks-queue` worker exists), Pest with `Http::fake`/`Queue::fake`, Svelte 5, Lucide icons, Forgejo 15 API (`/api/v1`).

**Spec:** `docs/superpowers/specs/2026-10-29-git-integration-design.md`, section "Phase 1: projects link repos, tasks show code". "Deactivation" there is already built; see the end of `docs/superpowers/plans/2026-10-30-git-phase-0-foundations.md` for what Phase 0 left open.

## Global Constraints

- Follow `CLAUDE.md` exactly: Route → thin controller → FormRequest `authorize()` via `WorkspaceAccess` → Service returning `ServiceResult` → JsonResource. Every feature has web and API twins. Every role decision goes through `WorkspaceAccess`.
- The audit trail goes only through observers plus `ProjectActivityRecorder`. New polymorphic subjects go in the morph map in `AppServiceProvider`.
- PHP 8.5, Pint, PHPStan level 7 (`composer test`). JSON columns are `jsonb`, and tests never depend on JSON key order. Use Svelte 5 runes, semantic theme classes only, Lucide icons only, no `{@html}`, no em-dashes in copy.
- Forge API: `{base_url}/api/v1/...`, header `Authorization: token {token}`, 10 second timeout, every call through `App\Services\Git\ForgejoClient`. Tokens, webhook secrets and payload signatures never appear in a message, flash, exception, log, resource or report.
- The bot token's scopes become `read:user,write:organization,write:repository`. Write access to repositories is needed for the external-tracker link-back and to read commits and pull requests. The tracker still never holds a site-admin token.
- Keys: `^[A-Z]{1,10}$`, resolved within the repository's organization through current keys and `project_key_aliases`. Unknown keys are ignored. In branch names keys match case-insensitively; in commit messages and pull-request titles and bodies they match in uppercase only, skipping inline code, fenced code and URLs. A reference's number is the task's `number`. Trashed tasks are not linked.
- A merge never changes a task's status. Nothing in this phase changes task status.
- The Code tab and the task's Code section appear only when the project's organization has a git connection. Viewing them needs `canViewProjectCode` (the project's team, its organization's office admins, super-admins; not other viewers of a public project, per the spec); linking and unlinking needs `canManageProjectAccess` (manager, office admin, super-admin).
- One forge repository is linked to at most one project: unique `(git_connection_id, forge_id)`.
- Do not `git commit` or push. The `2026_10_31_*` migrations are new and may be edited in place until they ship.

## Review Focus

1. A webhook with a missing or wrong signature, or a replayed delivery UUID, changes nothing. A bad signature gets a 403; a replay gets a 200 and is not processed twice. (Task 3)
2. A commit message naming `TP-1`, when `TP` is a key in a different organization, links nothing. An alias resolves; a trashed task is not linked. (Task 1)
3. Commits that vanished after a force-push are marked unreachable by the reconcile, never deleted, and become reachable again if they reappear. (Task 6)
4. A repository already linked to any project is excluded from the candidates and refused by link. A repository of another forge organization is refused. (Task 4)
5. `/workspace/go/{org}/{index}` gives a 404, not a 403, to someone who can't view the task, so it can't confirm that a task exists. The Code tab and section are absent for organizations without git. (Tasks 4 and 5)

---

### Task 1: Code data model, reference parser, ingest

**Files:**
- Create:
  - `database/migrations/2026_10_31_000001_create_git_code_tables.php`, with:
    - `git_repositories`: `git_connection_id` FK cascade, `project_id` FK cascade, `forge_id` unsigned bigint, `owner`, `name`, `full_name`, `html_url`, `clone_url`, `ssh_url`, `default_branch`, `last_pushed_at` nullable, `reconciled_at` nullable, timestamps, unique `(git_connection_id, forge_id)`;
    - `git_branches`: `git_repository_id` FK cascade, `name`, `head_sha` nullable, timestamps, unique `(git_repository_id, name)`;
    - `git_commits`: `git_repository_id` FK cascade, `sha` char 40, `message` text, `author_name`, `author_username` nullable, `url`, `committed_at`, `unreachable_at` nullable, timestamps, unique `(git_repository_id, sha)`;
    - `git_pull_requests`: `git_repository_id` FK cascade, `number` unsigned int, `title`, `body` text nullable, `state` string (`open`, `closed` or `merged`), `url`, `head_branch`, `author_username` nullable, `opened_at`, `merged_at` nullable, `closed_at` nullable, timestamps, unique `(git_repository_id, number)`;
    - `task_git_links`: `task_id` FK cascade, `linkable_type`, `linkable_id`, `created_at`, unique `(task_id, linkable_type, linkable_id)`, index `(linkable_type, linkable_id)`.
  - Models `app/Models/GitRepository.php`, `GitBranch.php`, `GitCommit.php`, `GitPullRequest.php`, `TaskGitLink.php` (the pivot model).
  - `app/Support/Git/TaskReferences.php`, `app/Services/Git/GitIngest.php`.
  - Observers `app/Observers/GitRepositoryObserver.php`, `TaskGitLinkObserver.php`, `GitPullRequestObserver.php`.
- Modify:
  - `app/Providers/AppServiceProvider.php`: add morph-map entries `git-repository`, `git-branch`, `git-commit`, `git-pull-request` and register the three observers;
  - `app/Models/Task.php`: `gitLinks()`, plus `pullRequests()`, `branches()`, `commits()` as `morphedByMany` through `task_git_links`;
  - `app/Models/Project.php`: `repositories()` hasMany;
  - `app/Models/GitConnection.php`: `repositories()` hasMany.
- Test: `tests/Unit/TaskReferencesTest.php`, `tests/Feature/Workspace/GitIngestTest.php`

**Interfaces:**
- Produces:
  - `TaskReferences::inBranch(string $name): list<string>` returns uppercase `KEY-N` strings, unique and in order. It matches case-insensitively with `(?<![A-Za-z0-9])([A-Za-z]{1,10})-([1-9][0-9]{0,8})(?![0-9])`.
  - `TaskReferences::inText(string $text): list<string>` matches the same pattern in uppercase only, after removing fenced blocks (```...```), inline code (`` `...` ``) and URLs (`\bhttps?://\S+`).
  - `GitIngest::tasksFor(GitRepository $repo, list<string> $refs): Collection<int, Task>` resolves within `$repo->gitConnection->organization_id`.
  - `GitIngest::commit(GitRepository $repo, array{sha: string, message: string, author_name: string, author_username: ?string, url: string, committed_at: string} $c): GitCommit` upserts the commit and adds links from the message. Links are only ever added.
  - `GitIngest::branch(GitRepository $repo, string $name, ?string $headSha): GitBranch` upserts the branch and syncs its links from its name.
  - `GitIngest::deleteBranch(GitRepository $repo, string $name): void`.
  - `GitIngest::pullRequest(GitRepository $repo, array{number: int, title: string, body: ?string, state: string, url: string, head_branch: string, author_username: ?string, opened_at: string, merged_at: ?string, closed_at: ?string} $pr): GitPullRequest` upserts the pull request and syncs its links from title, body (uppercase rule) and head branch (branch rule).
  - The `GitRepository` accessors `gitConnection` (not `connection`, which clashes with Eloquent's own property), `project`, `branches`, `commits`, `pullRequests`.

- [ ] **Step 1: Write the failing tests.**
  - **Parser:**
    - `inBranch('tp-12-fix-login')` returns `['TP-12']`;
    - `inBranch('feature/TP-3_and_tp-4')` returns `['TP-3','TP-4']`;
    - `inText('Fix TP-12 and TP-13')` returns both;
    - `inText('fix tp-12')` returns `[]`;
    - `inText('see `TP-9` and ```\nTP-8\n```')` returns `[]`;
    - `inText('https://x.test/TP-7 then TP-6')` returns `['TP-6']`;
    - `inText('ABTP-5')` returns `['ABTP-5']` (`ABTP` is a valid key), never `TP-5` from inside the longer word;
    - `inText('TP-0 TP-05')` returns `[]`.
  - **Ingest:**
    - a commit "TP-1 fix" links to task number 1 of the project keyed TP in the repository's organization;
    - an alias key links;
    - the same key in another organization links nothing;
    - a trashed task is not linked;
    - re-ingesting the same commit is idempotent;
    - a pull request whose title loses a key drops that link, and one that gains a key gets it;
    - a branch `tp-1-x` links;
    - `deleteBranch` removes the branch and its links.
  - **Activity:**
    - linking a repository records a project activity "Linked repository sdoc/app", and unlinking records "Unlinked repository sdoc/app";
    - a pull request first linked to a task records the task activity "Pull request #3 opened: Title";
    - the state changing to merged records "Pull request #3 merged" on each linked task;
    - commits and branches record nothing.
- [ ] **Step 2: Run** `php artisan test --compact --filter='TaskReferences|GitIngest'`. Expect FAIL.
- [ ] **Step 3: Implement** the migration, models (with `@property` blocks), parser, ingest and observers. `tasksFor` uses one query per key: the project via `key` or an alias in the organization, then `Task::where('project_id')->where('number')`.
- [ ] **Step 4: Run** the filter, then `composer test`. Expect PASS.

### Task 2: Forge client and the organization webhook

**Files:**
- Modify: `app/Services/Git/ForgejoClient.php`, `app/Services/Workspace/EnableGitService.php`, `app/Services/Workspace/DisableGitService.php`, `routes/web.php` (a named route `git.webhook` → `POST /git/webhooks/{connection}`, controller stubbed in Task 3; register the name now so the URL can be built)
- Test: `tests/Feature/Workspace/GitConnectionTest.php`, `tests/Unit/ForgejoClientTest.php`

**Interfaces:**
- Produces, on `ForgejoClient`. Every method throws `ForgejoException` with the status on failure, and lists page through `page`/`limit=50`:
  - `createOrganizationHook(string $org, string $url, string $secret): int`, which posts `{type:'forgejo', active:true, branch_filter:'*', events:['push','create','delete','pull_request','repository'], config:{url, content_type:'json', secret}}`;
  - `organizationHookExists(string $org, int $id): bool`, where 404 means false;
  - `deleteOrganizationHook(string $org, int $id): void`, where 404 is fine;
  - `organizationRepositories(string $org, int $max = 500): list<array<string,mixed>>`;
  - `repository(string $owner, string $name): array<string,mixed>`;
  - `branches(string $owner, string $name, int $max = 200): list<array<string,mixed>>`;
  - `commits(string $owner, string $name, string $ref, ?CarbonInterface $since, int $max = 500): list<array<string,mixed>>`, which sends `stat=false&files=false&verification=false`;
  - `pullRequests(string $owner, string $name, int $max = 200): list<array<string,mixed>>`, which sends `state=all&sort=recentupdate`;
  - `setExternalTracker(string $owner, string $name, string $format): void`, which patches `{has_issues:true, external_tracker:{external_tracker_url:<APP_URL>, external_tracker_format:$format, external_tracker_style:'alphanumeric'}}`;
  - `organizationMembers(string $org): list<string>` (logins);
  - `ownersTeamId(string $org): int`;
  - `teamMembers(int $team): list<string>`;
  - `addTeamMember(int $team, string $user): void`;
  - `removeTeamMember(int $team, string $user): void`, where 404 is fine.
- Behaviour:
  - Enable: after the ownership check, create the hook with a new `Str::random(40)` secret and store `webhook_id` and `webhook_secret`. Re-enabling the same target keeps a hook that still exists and recreates a missing one. Re-pointing deletes the old hook (best effort) and creates the new one. If creating the hook fails, nothing is saved and the message is "Couldn't create the forge webhook: the forge answered {status}." (or "Couldn't reach the forge at {url}." for status 0).
  - Disable: delete the hook (best effort, logged on failure), then delete the connection.

- [ ] **Step 1: Write the failing tests.**
  - The hook payload, including that the URL is `route('git.webhook', $connection)` and the events are exactly the five.
  - Enable stores an id and an encrypted secret.
  - A 403 on hook creation saves nothing and shows the message.
  - Re-enable with the hook present makes no POST.
  - Re-enable with the hook missing (404) POSTs once.
  - Re-point deletes the old hook.
  - Disable deletes the hook, and still disables when the forge is down.
  - Each new client method on its happy path and its 404 path, with paging that stops at `$max`.
- [ ] **Step 2: Run** `php artisan test --compact --filter='GitConnection|ForgejoClient'`. Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter, then `composer test`. Expect PASS.

### Task 3: Webhook intake and processing

**Files:**
- Create:
  - `database/migrations/2026_10_31_000002_create_git_webhook_deliveries_table.php`: `git_connection_id` FK cascade, `delivery` uuid unique, `event` string, `payload` jsonb, `received_at`, `processed_at` nullable, `error` text nullable;
  - `app/Models/GitWebhookDelivery.php`;
  - `app/Http/Controllers/GitWebhookController.php`;
  - `app/Jobs/ProcessGitDelivery.php`.
- Modify: `routes/web.php`. The `git.webhook` route uses `withoutMiddleware('web')` and `throttle:600,1`, like `oauth/token`.
- Test: `tests/Feature/Workspace/GitWebhookTest.php`

**Interfaces:**
- Consumes: `GitIngest` (Task 1), and `GitConnection::$webhook_secret` (Task 2).
- **Intake rules:**
  - an unknown connection → 404;
  - a body over 2 MB → 413;
  - `X-Forgejo-Signature` (falling back to `X-Gitea-Signature`) must equal `hash_hmac('sha256', raw body, secret)` under `hash_equals`, else 403;
  - a missing `X-Forgejo-Delivery` → 400;
  - an already-stored delivery → 200 `{"message":"Already received."}`;
  - otherwise store the delivery, dispatch `ProcessGitDelivery`, and answer 202 `{"message":"Received."}`.
  - Events outside the five are stored with `processed_at` set and are not dispatched.
- **`ProcessGitDelivery`** finds the `GitRepository` by connection and `payload.repository.id`. A repository that isn't linked is marked processed and dropped. Per event:
  - `push`: each commit through `GitIngest::commit`, the branch head through `GitIngest::branch` (for `refs/heads/*` only), and `last_pushed_at`.
  - `create` and `delete` with `ref_type=branch`: `branch` and `deleteBranch`.
  - `pull_request`: `GitIngest::pullRequest`. State is `merged` when `pull_request.merged`, otherwise `closed` or `open`.
  - `repository`:
    - `deleted`: delete the `GitRepository`;
    - `renamed`: update `name`, `full_name` and the URLs;
    - `transferred` to another owner: delete it.
  - Failures set `error` and rethrow, so the queue retries. Tries 3, backoff 60.

- [ ] **Step 1: Write the failing tests**, with fixture payloads in the Forgejo shape:
  - every intake rule above;
  - a signed push for a linked repository creates the commits and links `TP-1`;
  - the same delivery twice processes once;
  - a push to an unlinked repository stores the delivery but creates nothing;
  - pull request opened, then edited, then closed with `merged: true`, ends with state `merged`, one link and the activity rows from Task 1;
  - branch create and delete;
  - repository renamed and deleted;
  - an unsupported event is stored as processed;
  - the response never echoes the signature.
- [ ] **Step 2: Run** `php artisan test --compact --filter=GitWebhook`. Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter, then `composer test`. Expect PASS.

### Task 4: Link and unlink, backfill, link-back, go route

**Files:**
- Create:
  - `app/Services/Workspace/LinkRepositoryService.php`, `app/Services/Workspace/UnlinkRepositoryService.php`;
  - `app/Jobs/BackfillGitRepository.php`, `app/Services/Git/RepositorySync.php`;
  - `app/Http/Requests/LinkRepositoryRequest.php`, `app/Http/Requests/UnlinkRepositoryRequest.php`;
  - `app/Http/Resources/GitRepositoryResource.php`;
  - `app/Queries/RepositoryCandidatesQuery.php`;
  - `app/Http/Controllers/Workspace/ProjectRepositoryController.php`, `app/Http/Controllers/Api/ProjectRepositoryController.php`;
  - `app/Http/Controllers/Workspace/GoToTaskController.php`.
- Modify: `app/Support/WorkspaceAccess.php`, `routes/web.php`, `routes/api.php`
- Test: `tests/Feature/Workspace/ProjectRepositoryTest.php`, `tests/Feature/Workspace/Api/ProjectRepositoryTest.php`, `tests/Feature/Workspace/GoToTaskTest.php`

**Interfaces:**
- Consumes: the `ForgejoClient` methods (Task 2) and `GitIngest` (Task 1).
- Produces:
  - `WorkspaceAccess::canViewProjectCode(?Authenticatable, Project): bool` (the organization has a connection, and the user is on the project's team, administers its organization, or is a super-admin);
  - `WorkspaceAccess::canManageProjectRepositories(?Authenticatable, Project): bool` (`canManageProjectAccess` and the organization has a connection);
  - `RepositorySync::catchUp(GitRepository $repo, ?CarbonInterface $since): void`, used by backfill now and by reconcile in Task 6. It ingests pull requests updated since `$since`, every branch, and the default branch's commits since `$since`, all capped. `$since` null means 90 days.
  - Routes, web under `workspace.`:
    - `GET /workspace/projects/{project:slug}/repositories/candidates?q=` (JSON);
    - `POST /workspace/projects/{project:slug}/repositories` (`full_name`);
    - `DELETE /workspace/projects/{project:slug}/repositories/{repository}` (scoped);
    - `GET /workspace/go/{org}/{index}`.
  - API under `api.workspace.`: `GET /api/v1/workspace/projects/{project}/repositories`, `GET .../candidates`, `POST`, `DELETE`.
- **Behaviour:**
  - **Candidates:** the connection's organization repositories minus those linked anywhere for this connection, filtered case-insensitively by `q`, at most 50.
  - **Link:**
    1. Fetch the repository; it must belong to the connection's owner, else 422 "That repository isn't in the {owner} organization on the forge.".
    2. A repository already linked gets a 422 "That repository is already linked to {project title}.".
    3. Create the row and set the external tracker with format `{APP_URL}/workspace/go/{user}/{index}`. If that fails the link stays, the message adds " The forge link back couldn't be set; the nightly reconcile will retry.", and the failure is logged without the token.
    4. Dispatch `BackfillGitRepository`. The message is "Linked {full_name}.".
  - **Unlink:** delete the row (cascades) and leave the forge alone. The message is "Unlinked {full_name}. The repository is untouched on the forge.".
  - **Go route:**
    1. Find the connection whose `lower(owner)` equals `lower(org)`.
    2. Parse `index` as `KEY-N` and resolve the key or alias within that organization, then the task by number.
    3. Without `canViewProject` → 404; unknown → 404; otherwise redirect to `workspace.tasks.show`. It needs `auth`; a guest goes to login and comes back.

- [ ] **Step 1: Write the failing tests:**
  - the authorization matrix: a member can't link (403), a manager, office admin and super-admin can, another organization's office admin can't, and an organization without git gets 403 on every route;
  - candidates exclude linked repositories;
  - every refusal and its message;
  - link dispatches the backfill (`Queue::fake`);
  - running the backfill job with `Http::fake` creates pull requests, branches and commits, and links them;
  - a link-back failure still links;
  - unlink cascades and makes no forge call;
  - the go route: redirect, 404 for an outsider, 404 for an unknown key, alias works, case-insensitive owner;
  - API twins: the same matrix with `Sanctum::actingAs`, and resources never include tokens.
- [ ] **Step 2: Run** `php artisan test --compact --filter='ProjectRepository|GoToTask'`. Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter, then `composer test`. Expect PASS.

### Task 5: Code tab, task Code section, board mark, Guide

**Files:**
- Create: `resources/js/components/project/CodeView.svelte`, `resources/js/components/TaskCode.svelte` (rename it if the name is already taken; it is: `TaskCode.svelte` shows a task code, so use `TaskCodePanel.svelte`), `app/Queries/TaskCodeQuery.php`
- Modify:
  - `app/Queries/ProjectShowQuery.php`: add a `git` prop (null without `canViewProjectCode`; otherwise `{repositories: GitRepositoryResource[], can_manage: bool}`, each repository with up to 10 open pull requests), and `has_pull_request` on each task via `withExists`;
  - `app/Queries/TaskPreviewQuery.php`: add `code` from `TaskCodeQuery`, null without `canViewProjectCode`;
  - `resources/js/pages/Projects/Show.svelte`: add a `code` tab after People, shown only when `git` is not null, and the tab query parameter guard;
  - `resources/js/components/TaskPeek.svelte`, `resources/js/components/project/BoardCard.svelte`, `resources/js/lib/types.ts`, `resources/js/pages/Guide.svelte`;
  - the API twin for task code: add `code` to the API task show response, through the same `TaskCodeQuery`.
- Test: `tests/Feature/Workspace/ProjectCodeViewTest.php`

**Interfaces:**
- Consumes: Task 4's resources and routes, and Task 1's relations.
- Produces: `TaskCodeQuery::data(Task $task): array{branch_name: string, pull_requests: list<array>, branches: list<array>, commits: list<array>}`.
  - `branch_name` is `strtolower(code).'-'.Str::slug(Str::limit(title, 40, ''))`, for example `tp-12-fix-login`.
  - It returns at most 10 pull requests (open first, then most recent), the branches, and the latest 10 commits with their first line, short sha, url, author, time and `unreachable`.

- **UI:**
  - **The Code tab** lists each repository with its name linking to the forge, a copy button for HTTPS and SSH clone addresses, the default branch, last push and open pull requests. When `can_manage`, it shows "Link repository", a popover search over the candidates endpoint, and "Unlink" with a confirm. Empty state: "No repositories linked yet." For managers it adds "Link one to see its commits and pull requests on tasks."
  - **The task Peek "Code" section** (only when `code` is not null): pull requests with a state glyph (open, merged, closed), branches, commits, and "Copy branch name".
  - **Board card:** a small Lucide `GitPullRequest` icon when `has_pull_request`.
  - **Guide:** a "Code" section on how to link a repository, how to name branches and write commits (`TP-12`), and that a merge never marks a task done.
  - Follow the theme rules in `CLAUDE.md`: Lucide `GitBranch`, `GitPullRequest`, `GitCommitHorizontal`, `GitMerge`, `Copy`, `Link`, `Unlink`; semantic classes only; no em-dashes.

- [ ] **Step 1: Write the failing feature tests.**
  - The `git` prop is null without a connection, and present with repositories and `can_manage` for a manager but false for a member.
  - The task preview has `code` with the expected `branch_name`, pull requests, branches and commits, and null without a connection.
  - `has_pull_request` is true on a linked task.
  - The API task show carries `code`.
- [ ] **Step 2: Run** `php artisan test --compact --filter=ProjectCodeView`. Expect FAIL.
- [ ] **Step 3: Implement the queries, then the Svelte pieces.**
- [ ] **Step 4: Run** `composer test` and `npm run lint && npm run format && npm run types:check && npm run build`. Expect PASS.
- [ ] **Step 5: Look at it in a browser.**
  - Seed a connection, a linked repository with a pull request, branches and commits in a throwaway database (`tasks_preview`, never `projects`).
  - Serve it with `php -S`, `PHP_CLI_SERVER_WORKERS=4` and the database variables exported.
  - Screenshot the Code tab, the Peek section and a board card with the mark, in light and dark.
  - Fix anything that breaks the theme rules, then drop the database.

### Task 6: Nightly reconcile, forge owners, membership

**Files:**
- Create: `app/Console/Commands/ReconcileGit.php` (`git:reconcile {--connection=}`), `app/Services/Git/ForgeMembership.php`
- Modify:
  - `routes/console.php`: `git:reconcile` daily at 01:30, `withoutOverlapping`;
  - `app/Http/Controllers/Workspace/MemberController.php` and `Api/MemberController.php`: when `is_office_admin` changes, run `ForgeMembership::syncOwners` for that organization after the transaction.
- Test: `tests/Feature/Workspace/GitReconcileTest.php`

**Interfaces:**
- Consumes: `RepositorySync::catchUp` (Task 4), the client methods (Task 2) and `GitAccess::organizationsFor`.
- Produces:
  - `ForgeMembership::syncOwners(GitConnection $c): void`. Every active office admin of the organization who has a `git_username` is in the Owners team. Owners-team members who are tracker users but not office admins are removed. The bot (`{owner}-bot`), `forge-admin`, and logins that match no tracker user are never touched.
  - `ForgeMembership::removeStrangers(GitConnection $c): void`. Organization members who are tracker users that `GitAccess::organizationsFor` no longer admits for this organization are removed. The same accounts are never touched.

- **Reconcile per connection:**
  1. Recreate a missing webhook (new secret).
  2. For each linked repository:
     - refresh its metadata, and delete it when the forge returns 404;
     - re-set the external tracker;
     - run `RepositorySync::catchUp(repo, reconciled_at - 1 day)`;
     - mark unreachable: within the window, a stored commit not seen on any branch's history (each branch's `commits` since the window start) gets `unreachable_at = now`, and a stored unreachable commit seen again gets null;
     - set `reconciled_at`.
  3. `syncOwners`, then `removeStrangers`.
  4. Delete deliveries older than 14 days.

  One connection's failure is logged (without the token) and the next one continues. The command exits non-zero if any connection failed, and prints one line per connection.

- [ ] **Step 1: Write the failing tests**, with `Http::fake`:
  - a missing hook is recreated;
  - a missed pull request and commit are filled in;
  - a force-push makes a commit unreachable and its reappearance makes it reachable again;
  - a forge 404 for a repository unlinks it;
  - owners: a new office admin is added, a demoted one is removed, and the bot and `forge-admin` are untouched;
  - strangers: someone taken off the cross-organization team is removed from the other office's forge organization;
  - one failing connection doesn't stop the others;
  - old deliveries are pruned;
  - the schedule contains `git:reconcile`;
  - the office-admin flag change triggers `syncOwners` on both surfaces.
- [ ] **Step 2: Run** `php artisan test --compact --filter=GitReconcile`. Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter, then `composer test`. Expect PASS.

### Task 7: Operations, docs, and the end-to-end proof

**Files:**
- Modify: `docs/forgejo.md`, `deploy/forgejo/local.sh`, `docs/api.md`, `CLAUDE.md` (the git section gains a "phase 1" part, and the morph map list), `README.md`, `docs/superpowers/plans/2026-10-30-git-phase-0-foundations.md` (the "Carried to Phase 1" list marks what Phase 1 did)

- [ ] **Step 1: Docs.**
  - **The runbook:**
    - the bot token scopes become `read:user,write:organization,write:repository`, with a "replace the token" step for an existing install through the Organizations dialog;
    - the webhook is created by the tracker; `FORGEJO_WEBHOOK_ALLOWED_HOSTS` must admit the tracker's host;
    - the `tasks-queue` worker is required for Phase 1;
    - `git:reconcile` runs nightly, and how to run it by hand;
    - the §8 checks for linking, push, pull request, link-back and reconcile.
  - **`docs/api.md`:** every new endpoint and payload, checked against the resources.
  - **`CLAUDE.md` and README:** brought in step.
- [ ] **Step 2: `local.sh`.**
  - New bot tokens get the three scopes. An existing token file whose forge row lacks `write:repository` is replaced.
  - Webhooks from the forge to the local tracker must work. The local tracker's host resolves to loopback, which `ALLOWED_HOST_LIST = loopback` admits. Verify this rather than assuming it.
- [ ] **Step 3: Prove it end to end on the real local forge**, using the throwaway tracker pattern from Phase 0 (`tasks_preview` with `php -S`, `PHP_CLI_SERVER_WORKERS=4`, `TRACKER_URL=http://127.0.0.1:8123` and `DB_DATABASE` exported; a scratch `FORGEJO_LOCAL_DIR`; never `projects` or `~/.local/share/sdoc-forgejo`). Run a queue worker against `tasks_preview` (`php artisan queue:work --queue=default` with the same environment) or set `QUEUE_CONNECTION=sync` for the served tracker; say which. Show each of these:
  1. Turning git on creates the webhook on the forge.
  2. An office admin creates a repository on the forge, then links it from the Code tab; the backfill pulls in an existing commit that mentions `TP-1`.
  3. A push of `TP-1 second change` appears in the task's Code section within seconds.
  4. A branch `tp-1-thing` and a pull request appear, and the board card shows the mark.
  5. Merging the pull request shows it as merged and the task's status is unchanged.
  6. `TP-1` written in a forge issue or commit links back to the task through `/workspace/go/...`.
  7. A webhook missed while the tracker was down is filled in by `php artisan git:reconcile`.
- [ ] **Step 4: Clean up** (the scratch forge, `tasks_preview`, processes, generated keys). Then `composer test` and the npm checks PASS.
