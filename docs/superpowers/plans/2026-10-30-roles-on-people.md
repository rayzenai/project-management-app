# Roles on People Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename "office admin" to "Organizational admin", give SDOC its agreed project keys by migration, let program managers create projects in their program and replace their projects' managers, give every new program a "General" project, and let admins set a person's roles on the person form.

**Architecture:** Every permission still goes through `App\Support\WorkspaceAccess`. Two new predicates, `canCreateProjectInProgram` and `canAppointProjectManager`, carry the program manager's new powers without widening `canEditTeam`. Person-form roles are a view over the existing relations (program `manager_id`, team leader, team member), applied by one service that reuses `AppointProjectManagerService` and never detaches a manager.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia + Svelte 5 (runes), Pest, PHPStan level 7, Postgres, Sanctum API.

**Spec:** `docs/superpowers/specs/2026-10-30-roles-on-people-design.md` (and the programs spec it builds on, `docs/superpowers/specs/2026-10-29-programs-design.md`).

## Global Constraints

- Every role decision goes through `WorkspaceAccess`; never re-implement one inline. Twins stay twins.
- Pattern per feature: Service (returns `ServiceResult`) + FormRequest (authorization in `authorize()`) + JsonResource, a thin method in **both** `Workspace\` and `Api\` controllers, routes in **both** route files. Read restricted fields through `validated()`, not `safe()`.
- Copy: "Organizational admin" (sentence case in labels; "organizational admin" mid-sentence). Identifiers stay: `is_office_admin`, `isOfficeAdmin`, `officeAdminOrganizationId`, `officeAdminOrganization`, `canCreateMember`... Never rename a database column, prop key or API field.
- The default project's title is exactly "General".
- A program or project is never left without a manager; a team never gets a second manager (appoint only through `AppointProjectManagerService`).
- UI: semantic utilities only, no `dark:`, no raw palette colours, no em-dashes in copy, Lucide icons only, no `rounded-xl`/`shadow-*` outside `.popover`, Svelte 5 runes, no `{@html}`.
- Stage only your task's files; never `git add -A`; never commit `public/build`. Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Per task: `vendor/bin/pint --dirty` (restore files outside the task), `composer types:check`, the task's tests, the full suite once; frontend tasks also `npm run lint:check && npm run format:check && npm run types:check && npm run build`.

## Review Focus

1. **Replacing a manager through the person form when the new manager is already on the team as a member**: they become the manager once, the old manager stays a member, no duplicate pivot rows. Test in Task 5.
2. **A person-form save that both adds "Project manager of X" and removes "Member of X"**: the manager role wins; they are not taken off X's team. Test in Task 5.
3. **A program manager posting `program_id` of a program they don't manage, or none**: refused, never created elsewhere. Test in Task 3.
4. **Creating a program when the program manager is inactive or from another organization**: refused before "General" is made, nothing half-created. Test in Task 4.
5. **The SDOC migration on a database where a project's key was already hand-chosen, or the agreed key is taken**: that project is left alone. Test in Task 2.

---

### Task 1: "Organizational admin"

**Files:**
- Modify: every user-facing string saying "office admin" (any case): `resources/js/**` (at least `AppShell.svelte`, `ProjectTeamPanel.svelte`, `Guide.svelte`, `Organizations/Index.svelte`, `Team/Index.svelte`, `Projects/Index.svelte`, `ProgramForm.svelte` if present), PHP messages in `app/Http/Requests/*`, `app/Services/**`, notifications, `docs/api.md`, `docs/forgejo.md`, `README.md`, `CLAUDE.md` (prose: "Organizational admin (`is_office_admin`)" on first mention, then "organizational admin").
- Test: `tests/**` only where a test asserts the old copy.

**Interfaces:** none (copy only). Code comments may keep "office admin"; user-facing strings, validation and flash messages, docs and the Guide may not.

- [ ] **Step 1:** `grep -rni "office admin" resources/js app docs README.md CLAUDE.md` and list each user-facing hit.
- [ ] **Step 2:** Replace each with "Organizational admin" / "organizational admin", keeping sentence grammar ("an organizational admin"). The checkbox reads "Organizational admin of this organization". The Guide's role card and "In a ministry" row read "Organizational admin".
- [ ] **Step 3:** `grep -rni "office admin" resources/js` shows no user-facing string; run the full suite, fix tests that assert the old copy, run the frontend checks and build.
- [ ] **Step 4:** Commit.

### Task 2: SDOC's agreed keys, by migration

**Files:**
- Create: `database/migrations/2026_10_31_140000_give_sdoc_projects_their_agreed_keys.php`
- Modify: `database/seeders/SdocProjectsSeeder.php` (make the title → key map `public const PROJECTS` and the organization name `public const ORGANIZATION` so the migration reuses them)
- Test: `tests/Feature/Workspace/SdocKeysMigrationTest.php`

**Interfaces:**
- `up()`: find the organization named `SdocProjectsSeeder::ORGANIZATION`; if none, return. For each title → key, find the project in it with that exact title; skip if missing, if its key already equals the agreed key, or if `ProjectKeys::isAutomatic($title, $project->key)` is false. Otherwise rename through `UpdateProjectService::execute($project, ['key' => $key])` (it keeps the old key as an alias and refuses a taken key); on failure, skip that project and log a warning (never throw: a taken key must not block a deploy).
- `down()`: no-op with a comment (the aliases keep every old key resolving; renaming back is an admin's call).

- [ ] **Step 1: Failing tests:** `it('gives SDOC projects their agreed keys and keeps the old ones as aliases')` (SDOC with "eGP v2" key `EV` and "Hello Sarkar" key `HS`: after `up()`, `EGP` with alias `EV`, `HS` untouched); `it('leaves a hand-chosen key alone')` ("eGP v2" with key `PROC`); `it('skips a project whose agreed key is taken')`; `it('does nothing without SDOC')`. Run the migration by `(require base_path('database/migrations/2026_10_31_140000_give_sdoc_projects_their_agreed_keys.php'))->up()`.
- [ ] **Step 2:** See them fail; implement; see them pass; full suite; PHPStan.
- [ ] **Step 3: Check on the local clone** (the dev DB `pmopm` is a copy of production): `php artisan migrate`, then confirm SDOC keys are SG, EGP, GIWMS, SUTRA, GIOMS, HS, NAGARIK, OSOP with aliases EV, GV, S, G, NA, OSOW, and that MoLCFAGA and OPMCM projects are unchanged. Put the before/after in the report.
- [ ] **Step 4:** Commit.

### Task 3: Program managers create projects and appoint managers (security critical)

**Files:**
- Modify: `app/Support/WorkspaceAccess.php`, `app/Http/Requests/StoreProjectRequest.php`, `app/Http/Requests/UpdateProjectRequest.php`, `app/Http/Requests/UpdateTeamMemberRoleRequest.php`, `app/Queries/ProjectIndexQuery.php` (`canCreate`, `managerCandidates` and the program list for a program manager), `app/Queries/ProjectShowQuery.php` (the edit form's manager picker for a program manager, if it is admin-only today), `resources/js/pages/Projects/Index.svelte` (the create form for a program manager: Program select limited to and required from their programs, manager picker defaulting to themselves, no key / public / cross-organization fields), `resources/js/components/project/ProjectEditForm.svelte` and `ProjectTeamPanel.svelte` (the manager picker when allowed), `docs/api.md`, `CLAUDE.md` (Authorization model)
- Test: `tests/Feature/Workspace/ProgramManagerBuildsTest.php`

**Interfaces:**
- `canCreateProject(?Authenticatable $user): bool` also true when `managedProgramIds($user) !== []`.
- `canCreateProjectInProgram(?Authenticatable $user, Program $program): bool` = `administers($user, $program->organization_id)` or `in_array($program->id, managedProgramIds($user), true)`.
- `canAppointProjectManager(?Authenticatable $user, Team $team): bool` = `canEditTeam($user, $team)` or the program manager of the team's project (`managesProgramOf` on `$team->project()`).
- `StoreProjectRequest` for a user who does not administer the organization: `program_id` required, must pass `canCreateProjectInProgram` ("Choose one of your programs."), the organization is the program's; `manager_id` accepted (an active person of that organization who can join, default chosen in the UI); `key`, `is_public`, `is_cross_organization` dropped in `validated()`.
- `UpdateProjectRequest`: `manager_id` kept for `canAppointProjectManager` (today: `administers` only); `program_id`, `key`, `is_public`, `is_cross_organization` unchanged (admins only).
- `UpdateTeamMemberRoleRequest::authorize()` uses `canAppointProjectManager`.

- [ ] **Step 1: Failing tests** (setup: organization O with an organizational admin, program P managed by `js` with project A (manager `us`), program Q managed by someone else with project B, organization X):
  - `it('lets a program manager create a project in their program and choose its manager')`: POST with `program_id` P and `manager_id` a person of O → created in O, in P, that manager leads its team.
  - `it('drops key, publishing and cross-organization from a program manager')`.
  - `it('refuses a program manager a project outside their programs')` (Q, X's program, no `program_id`): errors, nothing created (Review Focus 3).
  - `it('lets a program manager replace the manager of a project in their program')`: PATCH A `manager_id` → new manager leads, `us` stays a member; the team role route works too; on B both are refused.
  - `it('still never lets a program manager remove a manager')`: DELETE `us` from A's team 403.
  - `it('takes the new powers away from a deactivated program manager')`.
  - `it('changes nothing for a project manager or member')`: they can't create projects or appoint managers.
- [ ] **Step 2:** Fail; implement; pass; full suite; PHPStan; frontend checks and build.
- [ ] **Step 3:** Commit.

### Task 4: "General" with every program, and a New project button on the program page

**Files:**
- Modify: `app/Services/Workspace/CreateProgramService.php` (create "General" in the same transaction through `CreateProjectService`, manager = the program's manager, `program_id` = the program), `app/Http/Middleware/ShareWorkspaceData.php` (`quickAddContext.projects[*].program` name, so several "General" projects can be told apart), the quick-add picker component(s) that list projects, `resources/js/pages/Programs/Show.svelte` ("New project" for whoever passes `canCreateProjectInProgram`, opening the create form with the program fixed), `app/Http/Controllers/Workspace/ProgramController.php` or `ProgramProgressQuery` (a `canCreateProject` flag and manager candidates for that form), `resources/js/pages/Guide.svelte` (Program manager card: they can create projects and choose their managers; a new program comes with "General"), `docs/api.md`
- Create: `resources/js/components/project/ProjectCreateForm.svelte` (extracted from `Projects/Index.svelte`, used there and on the program page)
- Test: `tests/Feature/Workspace/ProgramGeneralProjectTest.php`

**Interfaces:** `CreateProgramService::execute` still returns the program; its `data` is the program. "General" gets the suggested key (`ProjectKeys`), is not public, and its team's leader is the program manager.

- [ ] **Step 1: Failing tests:** `it('creates a General project with every program')` (in the program, its manager leads it, the activity says "Started in the {name} program"); `it('lets the program manager add a task to General and assign someone on its team')`; `it('creates nothing when the program is refused')` (Review Focus 4: inactive manager, other organization's manager); `it('labels quick-add projects with their program')`; `it('shows New project on the program page to its manager and admins only')`.
- [ ] **Step 2:** Fail; implement; pass; full suite; PHPStan; frontend checks and build.
- [ ] **Step 3:** Commit.

### Task 5: Roles on the person form

**Files:**
- Create: `app/Services/Workspace/UpdateMemberRolesService.php`
- Modify: `app/Http/Requests/StoreMemberRequest.php`, `app/Http/Requests/UpdateMemberRequest.php`, `app/Http/Controllers/Workspace/MemberController.php`, `app/Http/Controllers/Api/MemberController.php` (replace the raw `teams()->sync(...)` with the service), `app/Queries/TeamIndexQuery.php` (the organization's programs and projects with their current managers, for people who may set roles), `app/Http/Resources/MemberResource.php` (`managed_program_ids`, `managed_project_ids` alongside `team_ids`), `resources/js/pages/Team/Index.svelte` (the role fields; the person row lists their roles), `resources/js/lib/types.ts`, `resources/js/pages/Guide.svelte` (admin card: set roles on the person form), `docs/api.md`, `CLAUDE.md` (the member rules)
- Test: `tests/Feature/Workspace/MemberRolesTest.php`, `tests/Feature/Workspace/Api/MemberRolesApiTest.php`

**Interfaces:**
- Request fields (all optional, each the complete wanted set): `managed_program_ids: list<int>` (programs this person manages), `managed_project_ids: list<int>` (projects this person manages), `team_ids: list<int>` (teams this person is on; a team they manage counts as on).
- Who: anyone who passes `canManageMember` for the person (create: `canCreateMember`), and every program or team named, added or removed must be in an organization the user `administers`. `is_office_admin` and `organization_id` stay super-admin only. `team_ids` is no longer super-admin only.
- Validation (after-hooks), errors on the field:
  - a program or project the person manages now but that is missing from the new set: "Choose another manager for the {name} program first." / "Choose another manager for {title} first."
  - a team they manage missing from `team_ids`: the manager role wins, it is kept, no error (Review Focus 2).
  - a manager role for an inactive person: "Only an active person can manage."
  - `canJoinTeam` false: "{title} belongs to another organization."
- `UpdateMemberRolesService::execute(Member $member, array $roles): ServiceResult`, in one transaction: set each newly managed program's `manager_id` (ProgramObserver notifies); appoint each newly managed project through `AppointProjectManagerService` (old manager stays a member); join new teams as `member`; leave removed teams through `RemoveTeamMemberService` (never one they manage). Never `sync`. Messages for the flash: "Roles saved."
- UI, for those allowed: under the existing fields, "Program manager of" (the organization's programs), "Project manager of" and "Member of" (its projects), as checkbox lists. Each program and project option shows "now {manager}" in muted text. A role they hold that can only be handed over shows checked and disabled, with "To hand this over, choose another manager." Hints under the two manager lists: "In a ministry, usually a Joint Secretary." and "In a ministry, usually an Under Secretary." The person row shows their roles before the team list: "Organizational admin", "Program manager of …", "Manages …".

- [ ] **Step 1: Failing tests:** for each of: an organizational admin makes someone program manager (replacing, notified), project manager (replacing, old manager stays member, Review Focus 1), member of a team, off a team; the refusals above; an organizational admin refused another organization's program and team (403 or field error, nothing changed); a super-admin across organizations through `canJoinTeam`; the API twin; a person-form save never leaves a team without a manager.
- [ ] **Step 2:** Fail; implement; pass; full suite; PHPStan; frontend checks and build.
- [ ] **Step 3:** Commit.
