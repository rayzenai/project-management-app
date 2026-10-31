# Git Phase 0 Leftovers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the items the Phase 0 reviews carried forward, and give the owner one command to run a real Forgejo against their local tracker.

**Architecture:** The forge client learns to tell its failures apart and to remove an organization member. A connection's forge target is normalized and unique in the database. Deactivating or deleting a person removes them from every connected forge organization, using each organization's bot token (the tracker never holds a site-admin token). A shell script runs Forgejo from Homebrew with the same settings as `deploy/forgejo/compose.yml` and wires it to the tracker at `APP_URL`.

**Tech Stack:** Laravel 13, Pest, Laravel HTTP client fakes, Forgejo 17.0.1 from Homebrew locally (production pins 15.0.11), bash.

**Spec:** `docs/superpowers/specs/2026-10-29-git-integration-design.md`. The list of leftovers is the "Carried to Phase 1" section of `docs/superpowers/plans/2026-10-30-git-phase-0-foundations.md`.

## Global Constraints

- Follow `CLAUDE.md`: thin controllers, FormRequest authorization through `WorkspaceAccess`, services return `ServiceResult` and never throw on expected failures, web and API twins.
- PHP 8.5, Pint, PHPStan level 7; `composer test` and the four npm checks pass.
- Do not `git commit`, `git add` or push. Do not touch production. The `2026_10_30_*` migrations have run nowhere yet, so edit them in place.
- Forge API: `{base_url}/api/v1/...`, header `Authorization: token {token}`, 10 second timeout.
- Tokens and secrets never appear in a message, flash, exception, log, report or script output.
- The tracker never holds a forge site-admin token. Bot tokens have `read:user,write:organization`.

## Review Focus

1. The forge is down when an office admin deactivates someone. The deactivation still saves, and the message says the forge removal failed. (Task 2)
2. A person on a cross-organization project team is deactivated. They are removed from every connected forge organization, not only their own office's. (Task 2)
3. The same organization is re-enabled with the same forge, written with different case or a trailing slash. The webhook fields are kept and the unique index doesn't trip. (Task 1)
4. A URL that answers 200 with HTML, such as the tracker itself, is entered as the forge. The super-admin sees "doesn't look like a Forgejo forge" and nothing is saved. (Task 1)
5. `local.sh up` is run twice. The second run reuses what exists, with no duplicate-user, organization or source errors. (Task 3)

---

### Task 1: Forge client failures and one connection per forge organization

**Files:**
- Modify: `app/Services/Git/ForgejoClient.php`, `app/Services/Git/ForgejoException.php`, `app/Services/Workspace/EnableGitService.php`, `app/Http/Requests/EnableGitRequest.php`, `app/Models/GitConnection.php`, `database/migrations/2026_10_30_000003_create_git_connections_table.php`
- Test: `tests/Feature/Workspace/GitConnectionTest.php`

**Interfaces:**
- Produces: `GitConnection::normalizeBaseUrl(string $url): string`, which lowercases the scheme and host, keeps the port and path, and drops the trailing slash. Also `ForgejoException::$status`, unchanged. A client that keeps the previous exception.

- [ ] **Step 1: Write the failing tests** in `GitConnectionTest.php`, each through the web route, plus the API route where marked:
  - The client's `/user` answers 404 → 422 "{url} doesn't look like a Forgejo forge." Nothing is saved.
  - `/user` answers 200 with HTML or `{}` (no `login`) → the same message. Nothing is saved.
  - `/user` answers 429 or 400 → 422 "The forge answered {status}. Try again shortly." Nothing is saved.
  - 401 and 403 → "The forge refused that token." A connection error and 500 → "Couldn't reach the forge at {url}." Pin the existing cases that have no test yet.
  - Permissions answer 404 or 403 → "{login} doesn't own {owner} on the forge."; permissions answer 500 → "Couldn't reach the forge at {url}."
  - A failed re-enable keeps the old connection untouched.
  - API surface: a refused token gives a 422 with the same message.
  - A `base_url` with credentials (`https://u:p@git.example`) gets a 422 "The forge address can't contain a user name or password.", on web and API.
  - Re-enabling with `HTTPS://Git.Example/` when `https://git.example` is stored keeps `webhook_id` and `webhook_secret`. Changing the host or the owner clears both.
  - The database refuses a second row with the same normalized `base_url` and `lower(owner)`: insert one directly, then expect `UniqueConstraintViolationException`.
  - The stored `base_url` is normalized.
  - `report()` of a connection failure carries the original `ConnectionException` as `getPrevious()`.
- [ ] **Step 2: Run** `php artisan test --compact --filter=GitConnection`. Expect the new tests to FAIL.
- [ ] **Step 3: Implement.**
  - `ForgejoClient` passes the caught exception as `previous`. `currentUser()` throws `ForgejoException` with status 404 when a 200 has no non-empty `login`.
  - `EnableGitService::failed()` maps: 0 or 5xx → couldn't reach; 401 or 403 → refused; 404 → doesn't look like a forge; anything else → "answered {status}".
  - The service saves `GitConnection::normalizeBaseUrl()`. When the stored normalized `base_url` or `lower(owner)` differs from the new one, it nulls `webhook_id` and `webhook_secret`.
  - `EnableGitRequest`'s one-owner check and the userinfo rule use the same helper.
  - Migration `000003` adds `DB::statement('create unique index git_connections_forge_owner_unique on git_connections (base_url, lower(owner))')`. Postgres and SQLite both accept expression indexes.
- [ ] **Step 4: Run** the filter, then `composer test`. Expect PASS.

### Task 2: Deactivation removes the person from the forge

**Files:**
- Create: `app/Services/Workspace/RemoveFromForgeService.php`
- Modify: `app/Services/Git/ForgejoClient.php`, `app/Http/Controllers/Workspace/MemberController.php`, `app/Http/Controllers/Api/MemberController.php`, `app/Support/Git/GitAccess.php` (delete `withSuspensionReminder`), `docs/forgejo.md` (sections 8, 9 and 10's go-live gate), `CLAUDE.md` (git section, one clause)
- Test: `tests/Feature/Workspace/ForgeOffboardingTest.php`, plus the existing member tests that assert the suspension sentence

**Interfaces:**
- Consumes: `ForgejoClient`, and `ForgejoException::$status` from Task 1.
- Produces:
  - `ForgejoClient::removeOrganizationMember(string $org, string $username): void`. It sends `DELETE /orgs/{org}/members/{username}`; 204 and 404 count as done, and anything else throws `ForgejoException`.
  - `RemoveFromForgeService::execute(?User $user): ServiceResult`. It always succeeds. `message` is the sentence the controllers append, or `''` when there is nothing to do. `data` is `['removed' => list<string>, 'failed' => list<string>]` (forge organization names).

- [ ] **Step 1: Write the failing tests** with `Http::fake`:
  - An office admin deactivates a member whose user has `git_username` `ram.k`, in an organization connected to owner `sdoc`. Expect one `DELETE {base}/api/v1/orgs/sdoc/members/ram.k` carrying that connection's token header, and the flash "Member updated. ram.k was removed from the forge."
  - The same through the API: the JSON message matches.
  - A forge 404 counts as removed.
  - A connection error or 500: the member is still deactivated, and the message is "Member updated. Couldn't remove ram.k from the forge; ask a super-admin to suspend them there today."
  - Two connected organizations: a person on a cross-organization project team is removed from both, and one failure leaves the other's removal standing. The message names the failure.
  - A member without `git_username`: no HTTP call, and the message is "Member updated."
  - An update that leaves `is_active` true: no HTTP call.
  - Delete (web and API): the same removal; the message is "Member and their login removed. ram.k was removed from the forge."
  - A super-admin with no organization deactivating someone: no special case; the same removal.
- [ ] **Step 2: Run** `php artisan test --compact --filter=ForgeOffboarding`. Expect FAIL.
- [ ] **Step 3: Implement.**
  - The service walks every `GitConnection`, since a deactivated or removed person has git access nowhere. It catches `ForgejoException` per connection and `report()`s unexpected throwables without the token.
  - Messages: all removed → "{git_username} was removed from the forge."; any failed → "Couldn't remove {git_username} from the forge; ask a super-admin to suspend them there today."
  - Both member controllers call it after their transaction, and only when the update deactivated the member or on delete, then append the message with one space.
  - Remove `GitAccess::withSuspensionReminder` and update the tests that asserted it.
- [ ] **Step 4: Update the runbook.**
  - §9: removal from the forge organizations is automatic. Suspension is the fallback when the tracker couldn't reach the forge, and the way to also cut personal tokens and SSH keys.
  - A restored member must be re-added to forge teams by hand.
  - Removal from a cross-organization team (while still active) waits for Phase 1's nightly reconcile.
  - §8: the deactivated-member check line.
  - §10: the go-live gate's deactivation condition is met by this, so say so.
  - CLAUDE.md: one clause in the git section.
- [ ] **Step 5: Run** the filter, then `composer test`. Expect PASS.

### Task 3: One-command local forge

**Files:**
- Create: `deploy/forgejo/local.sh` (executable)
- Modify: `docs/forgejo.md` (new section "Try it locally", linked from the top), `README.md` (one line pointing to it)

**Interfaces:**
- Consumes:
  - `php artisan passport:keys`;
  - `php artisan git:oidc-client {redirect_uri}`, which prints `Client ID:` and `Client secret:` lines;
  - the discovery URL `{APP_URL}/.well-known/openid-configuration`;
  - the Organizations page's Enable git form;
  - `deploy/forgejo/compose.yml`, whose settings the local `app.ini` mirrors.
- Produces: `deploy/forgejo/local.sh up|down|reset|status`.

- [ ] **Step 1: Write `local.sh`.**
  - **Locations.** Data lives in `${FORGEJO_LOCAL_DIR:-$HOME/.local/share/sdoc-forgejo}`, outside the repo. The forge is on `http://127.0.0.1:3300`, with SSH on `127.0.0.1:2222`. The tracker is `${TRACKER_URL:-APP_URL from .env}`.
  - **`up`:**
    1. `brew install forgejo` if it is missing, saying so.
    2. Write `app.ini` once: SQLite, `INSTALL_LOCK`, a generated `SECRET_KEY`, and every security setting from `compose.yml`. Mail is off. Allow local webhook hosts.
    3. Start the web server in the background with a pid file and a log, and wait for `/api/healthz`.
    4. On the first run:
       - create `forge-admin` (random password, discarded) and `sdoc-bot`;
       - create the private organization `sdoc` through a one-off `forge-admin` token, add `sdoc-bot` to Owners, then `forge-admin` leaves and the token is deleted;
       - write a `read:user,write:organization` token for `sdoc-bot` to `$DIR/sdoc-bot.token` with mode 600;
       - run `passport:keys` if the keys are missing, without `--force`;
       - run `git:oidc-client http://127.0.0.1:3300/user/oauth2/sdoc-tasks/callback` and capture the ID and secret without printing the secret;
       - check that the discovery URL answers, failing with a clear message ("start the tracker with composer dev, and run php artisan migrate") if it doesn't;
       - `add-oauth` the source `sdoc-tasks` with the runbook's flags (repeated `--scopes`, `--group-claim-name groups`, `--admin-group forge-admins`, `--required-claim-name git_orgs`, `--skip-local-2fa`).
    5. On later runs, reuse everything and update the source's secret only if the client was re-created.
    6. Print the next steps:
       - turn git on for SDOC in the tracker with base URL `http://127.0.0.1:3300`, owner `sdoc`, and the token file's contents (`pbcopy < file`);
       - then the four checks (an SDOC member, an outsider, a super-admin, a wrong token);
       - SSH, and a push with a token.
  - **`down`:** stops the forge. **`status`:** whether it is running, the URL, and whether the source exists. **`reset`:** down, then deletes the data directory after a typed confirmation, and reminds the user to turn git off for SDOC in the tracker.
  - **Never print** a token or secret.
- [ ] **Step 2: Check TLS trust.** When `TRACKER_URL` is https (the owner's is `https://projects.test`, served through a local proxy on 443), confirm that a Go program using the system roots accepts its certificate. Run a 10-line `go run` with Go from `~/.local/share/mise/installs/go/1.27.0`. Document the result, and the fallback (`TRACKER_URL=http://127.0.0.1:8000` with `php artisan serve`) if it doesn't.
- [ ] **Step 3: Prove it.**
  - Serve the tracker on a throwaway Postgres database `tasks_preview` at `http://127.0.0.1:8123`. Use `php -S` with the variables exported, not `artisan serve`, which strips them. Seed `WorkspaceDemoSeeder` and `SdocProjectsSeeder`, and add one SDOC member with a login.
  - Run `TRACKER_URL=http://127.0.0.1:8123 deploy/forgejo/local.sh up` twice; the second run must be clean.
  - Turn git on, then pass the four checks in a browser.
  - Push to a repo over HTTP with a token, and over SSH on 2222.
  - Deactivate the member and see them removed from `sdoc` (Task 2).
- [ ] **Step 4: Write "Try it locally"** in `docs/forgejo.md`: prerequisites (`php artisan migrate`, `composer dev`, an SDOC member with a login, the super-admin in `PM_SUPER_ADMINS`), the commands, the checks and the cleanup.
- [ ] **Step 5: Clean up.** Run `local.sh reset`, drop `tasks_preview`, stop every process, and delete any keys the proof generated. Keep the Homebrew `forgejo`: the owner will use it. Then `composer test` and the npm checks PASS.

### Task 4: Item numbers that fit, and the README roles

**Files:**
- Modify: `app/Models/Task.php` (a new static helper), `app/Services/Workspace/CreateTaskService.php:77`, `app/Services/Workspace/QuickAddTaskService.php:110`, `database/migrations/2026_10_30_000002_give_tasks_a_stable_number.php`, `README.md:120-131`
- Test: `tests/Feature/Workspace/TaskNumberTest.php`

**Interfaces:**
- Produces: `Task::nextItemNumber(int $projectId): ?int`. It returns max + 1 over the project's tasks, trashed included, computed through a bigint cast. It returns `null` when that would exceed 2147483647.

- [ ] **Step 1: Write the failing tests.**
  - With an item number of 2147483647 in a project, creating a task (create and quick add) succeeds and leaves `item_number` empty.
  - The backfill claims only item numbers 1..100000 as task numbers. A legacy 2147483647 is numbered in sequence, and the project's next task gets its number.
- [ ] **Step 2: Run** `php artisan test --compact --filter=TaskNumber`. Expect FAIL.
- [ ] **Step 3: Implement.**
  - Both services call `Task::nextItemNumber`, and their private copies go.
  - The migration's claim range becomes `1..100000`. The constant names the reason: plan items are numbered in the hundreds, so anything larger is legacy noise.
- [ ] **Step 4: Rewrite the README role table** to the model in `CLAUDE.md`'s authorization section: super-admin, office admin, project manager (a project team's leader), member. Keep the key rows' wording. Check line 130's "Team" page wording against the current UI (the People directory).
- [ ] **Step 5: Run** `composer test`. Expect PASS.
