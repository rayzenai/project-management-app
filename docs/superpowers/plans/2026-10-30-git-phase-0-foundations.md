# Git Phase 0: Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every project a key and every task a number that never change, let a super-admin switch git on for an organization, and make the tracker the OIDC sign-in for a Forgejo forge, proven end to end against a real local Forgejo.

**Architecture:** Keys and numbers become stored columns that `Project::code()` and the front end read. A `GitConnection` per organization holds the forge address, forge organization and an encrypted bot token, checked against the forge's API before it is saved. Laravel Passport plus `jeremy379/laravel-openid-connect` turn the tracker into an OIDC provider for one first-party client (Forgejo), whose id_token carries the claims Forgejo needs.

**Tech Stack:** Laravel 13, PHP 8.5, Pest, PHPStan 7, Inertia + Svelte 5, Postgres (prod) / SQLite (tests), Laravel Passport 13.10, jeremy379/laravel-openid-connect 3.3, Forgejo 15.0 LTS (prod) / Homebrew forgejo (local).

**Spec:** `docs/superpowers/specs/2026-10-29-git-integration-design.md` (Phase 0). Webhook creation moves to the Phase 1 plan, which also handles events; nothing is linked before then.

## Global Constraints

- Follow `CLAUDE.md` exactly: Route → thin controller → FormRequest `authorize()` via `WorkspaceAccess` → Service returning `ServiceResult` → JsonResource; every feature has web and API twins (`Api\SubtaskController` is the reference).
- PHP 8.5, Pint clean, PHPStan level 7 clean (`composer test` runs all three plus Pest).
- JSON columns are `jsonb`; tests never depend on JSON key order.
- UI: semantic theme utilities only, Lucide icons only, Svelte 5 runes, no `{@html}`, no em-dashes in copy.
- **Do not `git commit` or push.** The owner commits. Leave changes in the working tree.
- Project key: `^[A-Z]{1,10}$`, unique within an organization across current keys **and** aliases.
- Task number: positive integer, unique per project including soft-deleted tasks, assigned once, never editable through any request.
- Forge API: `{base_url}/api/v1/...`, header `Authorization: token {token}`, 10 second timeout.
- Tokens and secrets: `encrypted` casts, in `$hidden`, never in a resource, flash, exception message or log.
- OIDC claims, exactly: `sub` (user id as string), `email`, `email_verified` (true), `name`, `preferred_username`, `git_orgs` (array of forge organization names), `groups` (`['forge-admins']` for super-admins, else `[]`).
- Who may use git (one rule, used by the claims and the authorize gate): an active member of an organization with a git connection; anyone on the team of a project in such an organization; any super-admin (gets every connected organization).

## Review Focus

1. A project whose title has no ASCII letters (Nepali title, "100", "—") must still get a valid key; expect `P`, or `P` plus a suffix letter when taken.
2. A key that another project in the organization used before (now an alias) must be refused, so an old commit never starts pointing at a different project.
3. Two tasks created at the same moment in one project must not get the same number; expect the unique index plus the project-row lock to serialize them.
4. A person who is deactivated, or whose office has no git, opening `/oauth/authorize` must get a 403, even with a valid session; a super-admin must get through.
5. A token for the wrong forge organization, a token the forge rejects, or a forge that is down must leave nothing saved and say which of the three happened.

Each has a test in the owning task below.

---

### Task 1: Stable project keys

**Files:**
- Create: `database/migrations/2026_10_30_000001_give_projects_a_stable_key.php`
- Create: `app/Support/ProjectKeys.php`, `app/Models/ProjectKeyAlias.php`
- Modify: `app/Models/Project.php` (`code()` at ~line 76, `@property` block, fillable, new relation)
- Modify: `app/Services/Workspace/CreateProjectService.php`, `app/Services/Workspace/UpdateProjectService.php`, `app/Http/Requests/StoreProjectRequest.php`, `app/Http/Requests/UpdateProjectRequest.php`, `app/Http/Resources/ProjectResource.php`, `app/Support/WorkspaceAccess.php`
- Modify: `resources/js/components/project/ProjectEditForm.svelte`, `resources/js/lib/types.ts`
- Modify: `database/seeders/SdocProjectsSeeder.php`, `tests/Feature/Workspace/ProjectCodeTest.php`, `tests/Feature/Workspace/SdocProjectsSeederTest.php`
- Test: `tests/Feature/Workspace/ProjectKeyTest.php`

**Interfaces:**
- Produces:
  - `ProjectKeys::suggest(string $title): string` — ASCII letters only (`Str::ascii`, drop everything but A-Z per word), first letter of up to 4 words, uppercased; `'P'` when nothing is left.
  - `ProjectKeys::isTaken(int $organizationId, string $key, ?int $exceptProjectId = null): bool` — true if another project in the organization has it as its key or as an alias.
  - `ProjectKeys::unique(int $organizationId, string $base, ?int $exceptProjectId = null): string` — `$base` if free, else `$base` + `B`, `C`, ... `Z`, then `$base` + `BA`... (capped at 10 chars).
  - `ProjectKeys::resolve(int $organizationId, string $key): ?Project` — by current key, then by alias; case-insensitive input.
  - `Project::$key` (string), `Project::keyAliases(): HasMany<ProjectKeyAlias>`, `Project::code` returns `$this->key ?? ProjectKeys::suggest($this->title)`.
  - `WorkspaceAccess::canChangeProjectKey(User $user, Project $project): bool` — office admin of the project's organization, or super-admin.
  - Resource fields: `key`, `can_change_key` (alongside the existing `code`).

- [ ] **Step 1: Write the failing tests** in `ProjectKeyTest.php`:
  - `suggest` dataset: `'SMS Gateway' => 'SG'`, `'eGP v2' => 'EV'`, `'Government 100-Day Plan' => 'GDP'`, `'One Two Three Four Five' => 'OTTF'`, `'सडक विभाग' => 'P'`, `'—' => 'P'`.
  - creating `SuTRA` then `Sarkari Portal` in one organization gives `S` then `SB`; the same title in another organization gives `S`.
  - the migration backfills existing projects with `unique(suggest(title))` in id order (pattern: `OneTeamPerProjectMigrationTest`).
  - office admin PATCHes `key => 'egp'` → stored `EGP`, old key appears in `project_key_aliases`, `ProjectKeys::resolve($org, 'ev')` returns the project.
  - a manager's `key` in the same request is silently dropped (project key unchanged, 302 with no error).
  - `key => 'S1'` → validation error on `key`; a key that is another project's alias → validation error `That key is already used in this organization.`
  - changing back to a key in the project's own aliases succeeds and removes that alias.
  - API twin `PATCH /api/v1/projects/{slug}` obeys the same three rules.
- [ ] **Step 2: Run** `php artisan test --compact --filter=ProjectKey` — expect failures (class/column missing).
- [ ] **Step 3: Implement** the migration (nullable `key` string(10) → backfill → not null; unique `(organization_id, key)`; `project_key_aliases`: `project_id` FK cascade, `organization_id` FK cascade, `key` string(10), timestamps, unique `(organization_id, key)`), `ProjectKeys`, the model changes, `CreateProjectService` (key = `unique(org, $attributes['key'] ?? suggest(title))`), `UpdateProjectService` (rename moves old key to an alias inside the transaction), the requests (uppercase before validating; drop `key` in `validated()` when `canChangeProjectKey` is false, the way `is_public` is handled), the resource, and the form field (shown when `can_change_key`; label `Key`; help text `Tasks are written KEY-12 in commits and branches. Old keys keep working after a change.`).
- [ ] **Step 4: Update** `ProjectCodeTest` to the new rules (`G1P` becomes `GDP`; the `P{id}` fallback becomes `P`), and the seeder: `PROJECTS` becomes title => key (`SMS Gateway => SG`, `eGP v2 => EGP`, `GIWMS v2 => GIWMS`, `SuTRA => SUTRA`, `GIOMS => GIOMS`, `Hello Sarkar => HS`, `Nagarik App => NAGARIK`, `Open Source OS with Office Packages => OSOP`); created projects get that key, kept projects whose key differs are renamed through the same alias path. Extend `SdocProjectsSeederTest`: keys set, rerun changes nothing.
- [ ] **Step 5: Run** `php artisan test --compact --filter='ProjectKey|ProjectCode|SdocProjectsSeeder'` — PASS; then `composer test` — PASS.

### Task 2: Stable task numbers

**Files:**
- Create: `database/migrations/2026_10_30_000002_give_tasks_a_stable_number.php`, `resources/js/lib/taskCode.ts`
- Modify: `app/Models/Task.php`, `app/Services/Workspace/CreateTaskService.php`, `app/Http/Resources/TaskResource.php`, `resources/js/lib/types.ts`, `resources/js/components/TaskCode.svelte`, `resources/js/components/project/BoardView.svelte` (`codeOf`), `resources/js/lib/schedule.ts` (~line 150), and every other file under `resources/js` that builds a code as `${code}-${item_number}` (`grep -rn "item_number" resources/js`; leave plan-tracker displays of the item number alone), plus any query that parses `CODE-123` from search input (`grep -rn "item_number" app/Queries app/Services`)
- Test: `tests/Feature/Workspace/TaskNumberTest.php`; update `ProjectCodeTest` payload test

**Interfaces:**
- Consumes: `Project::$code` (Task 1).
- Produces:
  - `Task::$number` (int). Assigned in a `creating` hook when null: `max(number)` over the project's tasks including trashed, plus 1. `number` is not in `$fillable`.
  - `CreateTaskService` locks the project row (`lockForUpdate`) inside its transaction before creating.
  - `TaskResource` exposes `number` in both `reference()` and `toArray()`.
  - `taskCode(projectCode: string | null | undefined, number: number | null | undefined): string` — `SG-12`, or `#12` without a code.

- [ ] **Step 1: Write the failing tests** in `TaskNumberTest.php`:
  - backfill: a project with tasks whose `item_number`s are `[3, 3, null, 7]` (ids ascending) and one trashed task with `item_number` 5 → numbers `[3, 8, 9, 7]` and the trashed task `5` (unique positive item numbers are claimed first, in id order; the rest get max+1 in id order).
  - creating a task after the backfill gives `10`; creating one with `item_number => 50` gives number `10` and keeps `item_number` 50.
  - `PATCH` a task with `number => 1` (web and API) leaves the number unchanged.
  - a deleted task's number is never reused: delete number 10, create → 11.
  - writing a second task with an existing `(project_id, number)` straight through the query builder throws a `UniqueConstraintViolationException` (the index is the last guard against concurrent creates).
  - `TaskResource` payload has `number`.
- [ ] **Step 2: Run** `php artisan test --compact --filter=TaskNumber` — expect failures.
- [ ] **Step 3: Implement** the migration (nullable unsigned int → two-pass backfill per project with `DB::table`, trashed included → not null → unique `(project_id, number)`), the model hook, the service lock, the resource field, `taskCode.ts`, and switch every code-building site to `taskCode(code, task.number)`; add `number` to the `Task` and `TaskRef` types.
- [ ] **Step 4: Run** `php artisan test --compact --filter='TaskNumber|ProjectCode'` then `composer test` and `npm run lint && npm run format && npm run types:check && npm run build` — all PASS.

### Task 3: Switch git on for an organization

**Files:**
- Create: `database/migrations/2026_10_30_000003_create_git_connections_table.php`, `app/Models/GitConnection.php`, `app/Services/Git/ForgejoClient.php`, `app/Services/Git/ForgejoException.php`, `app/Services/Workspace/EnableGitService.php`, `app/Services/Workspace/DisableGitService.php`, `app/Http/Requests/EnableGitRequest.php`, `app/Http/Requests/DisableGitRequest.php`, `app/Http/Controllers/Workspace/OrganizationGitController.php`, `app/Http/Controllers/Api/OrganizationGitController.php`
- Modify: `app/Models/Organization.php`, `app/Support/WorkspaceAccess.php`, the organization JsonResource used by `Organizations/Index`, `routes/web.php`, `routes/api.php`, `resources/js/pages/Organizations/Index.svelte`, `resources/js/lib/types.ts`, `docs/api.md`
- Test: `tests/Feature/Workspace/GitConnectionTest.php`

**Interfaces:**
- Produces:
  - Table `git_connections`: `organization_id` (unique, FK cascade), `base_url`, `owner`, `token` (text), `webhook_secret` (text, nullable), `webhook_id` (nullable unsigned big int), `enabled_by` (FK users, null on delete), timestamps.
  - `GitConnection` (casts `token`/`webhook_secret` encrypted, both hidden), `organization(): BelongsTo`, `url(): string` (`{base_url}/{owner}`). `Organization::gitConnection(): HasOne<GitConnection>`.
  - `ForgejoClient::__construct(string $baseUrl, string $token)`, `ForgejoClient::for(GitConnection $c): self`, `currentUser(): array{id: int, login: string}` (`GET /user`), `organizationPermissions(string $org, string $username): array{is_owner: bool}` (`GET /users/{username}/orgs/{org}/permissions`). Throws `ForgejoException` with `$status` (int, 0 when unreachable).
  - `EnableGitService::execute(Organization $organization, User $actor, array{base_url: string, owner: string, token: string} $input): ServiceResult` — 422 failures with exactly: `Couldn't reach the forge at {base_url}.`, `The forge refused that token.`, `{login} doesn't own {owner} on the forge.`; success creates or updates the connection.
  - `DisableGitService::execute(Organization $organization): ServiceResult`.
  - `WorkspaceAccess::canManageGit(User $user): bool` (super-admin).
  - Routes: `POST|DELETE /workspace/organizations/{organization}/git` (`workspace.organizations.git.store|destroy`), `POST|DELETE /api/v1/organizations/{organization}/git` (`api.organizations.git.store|destroy`), binding the organization the way the existing organization routes do.
  - Organization payload gains `git: { base_url, owner, url } | null`, only for viewers passing `canManageGit`.

- [ ] **Step 1: Write the failing tests** in `GitConnectionTest.php` (`Http::fake` for the forge):
  - super-admin enables with a token whose user owns `sdoc` → row saved, raw DB `token` column is not the plain token, payload `git.url` is `https://git.example.test/sdoc`, payload JSON never contains the token.
  - forge says `is_owner: false` → 422 with `sdoc-bot doesn't own sdoc on the forge.`, nothing saved.
  - forge returns 401 on `/user` → `The forge refused that token.`, nothing saved.
  - forge unreachable (`Http::fake` throwing `ConnectionException`) → `Couldn't reach the forge at https://git.example.test.`, nothing saved.
  - office admin and project manager → 403 on both web and API.
  - disable removes the row; API twin of enable and disable.
- [ ] **Step 2: Run** `php artisan test --compact --filter=GitConnection` — expect failures.
- [ ] **Step 3: Implement** the files above; the Organizations page gets a Git action per organization (fields: `Forge address` placeholder `https://git.sdoc.gov.np`, `Forge organization` prefilled with the organization slug, `Bot token` as a password input; buttons `Turn on git` / `Save` / `Turn off git`; when on, the row shows the forge URL).
- [ ] **Step 4: Run** `php artisan test --compact --filter=GitConnection`, `composer test`, and the four npm checks — all PASS. Document both endpoints in `docs/api.md`.

### Task 4: The tracker as OIDC provider

**Files:**
- Run: `composer require laravel/passport:^13.10 jeremy379/laravel-openid-connect:^3.3`; publish Passport's migrations and the openid config.
- Create: `app/Models/OAuthClient.php`, `app/Http/Middleware/EnsureCanUseGit.php`, `app/Support/Git/GitAccess.php`, `app/Support/Git/GitUsernames.php`, the identity entity/repository classes the openid package asks for (under `app/Support/Git/Oidc/`), `app/Console/Commands/CreateForgeOidcClient.php`, migration `2026_10_30_000004_add_git_username_to_users.php`
- Modify: `app/Providers/AppServiceProvider.php`, `routes/web.php`, `config/openid.php`, `.env.example` (`PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY`), `app/Models/User.php` (`@property` for `git_username` only)
- Test: `tests/Feature/Workspace/OidcProviderTest.php`

**Interfaces:**
- Consumes: `GitConnection`, `Organization::gitConnection()` (Task 3).
- Produces:
  - `GitAccess::organizationsFor(User $user): Collection<int, Organization>` — the Global Constraints rule; only organizations with a connection.
  - `GitUsernames::for(User $user): string` — assigned once and stored in `users.git_username` (unique, nullable, string 40): email local part lowercased, characters outside `[a-z0-9._-]` become `-`, trimmed of leading/trailing `._-`, cut to 40, empty becomes `user`; taken becomes `-2`, `-3`... Never recomputed after it is stored.
  - Claims per Global Constraints; `git_orgs` = the `owner` of each connection in `organizationsFor`.
  - `EnsureCanUseGit` middleware: 403 with `Git isn't switched on for your office.` unless `GitAccess::organizationsFor($user)` is non-empty.
  - Routes: Passport's own routes are not registered (`Passport::ignoreRoutes()`); register `GET oauth/authorize` with `web, auth, EnsureCanUseGit` and `POST oauth/token` with throttling, pointing at Passport's controllers; plus the openid package's discovery (`/.well-known/openid-configuration`) and JWKS routes. Userinfo stays off: every claim travels in the id_token.
  - `OAuthClient extends Laravel\Passport\Client` with `skipsAuthorization(...)` returning true (no consent screen for our own client); registered with `Passport::useClientModel`.
  - `php artisan git:oidc-client {redirect_uri}` — creates (or reuses by name `Forgejo`) a confidential authorization-code client for that redirect URI and prints the client id and secret.
  - Do not add Passport's `HasApiTokens` to `User`; Sanctum's stays (the Passport trait clashes with it). The flow test below proves Passport works without it.

- [ ] **Step 1: Write the failing tests** in `OidcProviderTest.php`:
  - discovery JSON: `issuer` equals `config('app.url')`, has `authorization_endpoint`, `token_endpoint`, `jwks_uri`.
  - full flow for an active SDOC member (connection owner `sdoc`): signed in, `GET /oauth/authorize?client_id=…&redirect_uri=…&response_type=code&scope=openid profile email git&state=xyz` → 302 to the redirect URI with `code` and `state=xyz` (no consent page); `POST /oauth/token` with `grant_type=authorization_code` → 200 with `id_token`; its decoded payload has `sub` = user id string, `email`, `name`, `preferred_username`, `git_orgs` = `['sdoc']`, `groups` = `[]`.
  - member of an organization without a connection → authorize 403 with the exact message; deactivated SDOC member → 403.
  - a person from another organization on an SDOC project's cross-organization team → allowed, `git_orgs` = `['sdoc']`.
  - super-admin → allowed, `groups` = `['forge-admins']`, `git_orgs` lists every connected organization.
  - `GitUsernames`: `Ram.Sharma+git@x.np` → `ram.sharma-git`; a second user with the same local part → `ram.sharma-git-2`; changing the email later keeps the stored username.
  - a redirect URI not registered on the client → no code is issued.
- [ ] **Step 2: Run** `php artisan test --compact --filter=OidcProvider` — expect failures.
- [ ] **Step 3: Implement** (Passport keys: `php artisan passport:keys` locally; production reads `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` from the environment. Tests generate keys in `beforeEach` or a test key pair under `tests/fixtures`).
- [ ] **Step 4: Run** `php artisan test --compact --filter=OidcProvider` then `composer test` — PASS. Add to `CLAUDE.md` (git section: keys, numbers, connection, OIDC, the access rule) and `README.md` (env vars, the command).

### Task 5: Forgejo kit and end-to-end proof

**Files:**
- Create: `deploy/forgejo/compose.yml`, `deploy/forgejo/.env.example`, `docs/forgejo.md`
- Modify: `README.md` (pointer to `docs/forgejo.md`)

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: a compose project Dokploy can run from this repo, and a runbook covering DNS, deploy, bootstrap, OIDC source, backups and upgrades.

- [ ] **Step 1: Write `deploy/forgejo/compose.yml`**: `forgejo` (`codeberg.org/forgejo/forgejo:15`, pin the latest 15.0.x tag) and `db` (`postgres:17-alpine`), named volumes `forgejo-data`, `forgejo-db`, port `2222:2222` for the built-in SSH server, every setting as `FORGEJO__section__KEY` env from the spec's Phase 0 item 4 (`REQUIRE_SIGNIN_VIEW=true`, private repos by default, `ALLOW_ONLY_EXTERNAL_REGISTRATION=true`, `ENABLE_INTERNAL_SIGNIN=false`, auto-registration on with the username taken from `preferred_username`, webhook `ALLOWED_HOST_LIST` from env, `REVERSE_PROXY_TRUSTED_PROXIES` set to Traefik's network, migrations to local networks blocked, mailer from env). Check each key against the Forgejo 15 config cheat sheet and say in the runbook which ones differ by version.
- [ ] **Step 2: Write `docs/forgejo.md`**: NITC DNS record for `git.sdoc.gov.np` (same public address as tasks), the Traefik IP allowlist option, Dokploy compose setup, env vars, bootstrap (`forgejo admin user create` break-glass admin; `sdoc` organization; `sdoc-bot` owner and its token; `forgejo admin auth add-oauth --provider openidConnect --auto-discover-url https://tasks.sdoc.gov.np/.well-known/openid-configuration --scopes "openid profile email git" --group-claim-name groups --admin-group forge-admins --required-claim-name git_orgs ...` with the client from `git:oidc-client`), turning git on for SDOC in the tracker, nightly `pg_dump` plus volume copy, and the upgrade path 15 → 19.
- [ ] **Step 3: Prove it locally.** `brew install forgejo`; run it on `127.0.0.1:3300` with a scratch `app.ini` (SQLite) in the session scratchpad; serve the tracker on a throwaway Postgres database (`tasks_preview`, demo seeder plus `SdocProjectsSeeder`, one SDOC member) at `http://127.0.0.1:8123`; register the OIDC source; create `sdoc` and `sdoc-bot`, turn git on for SDOC through the Organizations page. Expected: the SDOC member clicks "Sign in with SDOC Tasks" and lands in Forgejo signed in with the stored username; an OPMCM-only person gets the 403; the super-admin arrives as site admin; a wrong token on the Organizations page shows the matching error.
- [ ] **Step 4: Clean up** the scratch forge, the preview database and any screenshots; `composer test` and the four npm checks PASS.

---

Production rollout (DNS at NITC, the Dokploy project, Passport keys in the environment, a `tasks-queue` worker, bootstrap, turning git on for SDOC) follows `docs/forgejo.md` and happens only when the owner says so.

## Carried to Phase 1

Done in `2026-10-30-git-phase-0-leftovers.md`:
- removal from every connected forge organization on deactivation or delete;
- re-pointing a connection resets `webhook_id` and `webhook_secret`;
- one forge organization per office, as a database index on the normalized `base_url` and `lower(owner)`;
- `ForgejoClient` keeps and logs the cause of unreachable forges, rejects credentials, a query or a fragment, and tells non-forge and other answers apart, with tests;
- `item_number` arithmetic through bigint, and the backfill claiming only 1..100000;
- the README role table.

Still open:
- **Offboarding beyond organization removal.**
  - Personal access tokens, SSH keys and personal repositories survive removal. Suspension stays manual, because there is no site-admin token.
  - The nightly reconcile should remove forge organization membership for anyone `GitAccess::organizationsFor` no longer admits: taken off a cross-organization team, moved to another organization, or whose organization turned git off.
  - A restored or reactivated member is re-added by hand. The "Restored." message doesn't say so yet.
- **Forge calls on the queue** once `tasks-queue` exists. Removal is synchronous today, with a 10-second timeout per connection.
- **Unique-index race.** Two super-admins connecting the same forge organization at the same moment get the generic error, not the 422.
- **Orphaned webhook.** When a connection is re-pointed, the old forge organization's webhook is left behind (once webhooks exist).
- **`git_username` reuse after delete.** Deleting a member hard-deletes the user and frees the name, while the forge account keeps it, so the next person given that name lands on Forgejo's link page.
- **Rollout checks** (in `docs/forgejo.md`):
  - forge and tracker reach each other through the public names;
  - a token-as-password `git clone` works on 15.0.11 with basic auth off;
  - `DELETE /orgs/{org}/members/{username}` with the bot token behaves the same on 15.0.11;
  - the Traefik IP allowlist, if open question 2 goes that way.
