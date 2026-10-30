# PMOPM HTTP API

PMOPM exposes the workspace through **two HTTP surfaces that share the same
services, FormRequest authorization, and JsonResources** — only the response
envelope differs:

- **JSON API** — prefix `/api/v1`, route names `api.*`, **Sanctum bearer** auth.
  Used by the companion mobile client and by any external system that needs to
  read or manage tasks (see [Integrating another system](#integrating-another-system)).
- **Web (Inertia)** — prefix `/workspace`, route names `workspace.*`, **session**
  auth. Returns Inertia redirects carrying a `workspace_flash` instead of JSON.

Route sources: `routes/api.php` and `routes/web.php`. Controllers live in
`app/Http/Controllers/Api` and `app/Http/Controllers/Workspace`; each pair calls
the same `app/Services/Workspace/*` action service.

## Conventions (JSON API)

- **Base:** `${APP_URL}/api/v1`. **Auth:** `Authorization: Bearer <token>` on
  everything except `POST /login`. Send `Accept: application/json`.
- **Success:** `{ "message": string, "data": <resource|array> }` — `200`, or
  `201` on create.
- **Error:** `{ "message": string, "errors": { field: message } }` — `401`
  unauthenticated, `403` forbidden, `404` not found, `422` validation.
- **Authorization** is enforced server-side via `App\Support\WorkspaceAccess`
  (super-admin → organizational admin → project manager → member). Clients mirror role
  gating in the UI only.
- **Projects and teams:** every project has exactly one team of its own, made
  with it, and that team's one leader is the project's **manager**. People on
  several projects sit on several project teams.
- **Project visibility:** a project is visible to a user iff it is public within
  their organization, OR the user is on its team, OR they are its organization's
  organizational admin, OR a super-admin.
  Implemented as `Project::scopeVisibleTo($user)` and enforced on the project
  index, project/task `show` (403 otherwise), dashboard, My Workspace, search,
  and the quick-add picker.
- **Soft delete + undo:** every `DELETE` soft-deletes; each resource has a
  matching `…/restore` (authorization for restore equals authorization for the
  delete). Trashed rows are pruned after `PM_TRASH_TTL_DAYS` (default 30).
- **Timestamps** are ISO-8601 (UTC). Projects and tasks are **slug-routed**.

## Account & session

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| POST | `/login` | `email`, `password`, `device_name` | `{ token, user }` |
| POST | `/logout` | — | `{ message }` (revokes the current token) |
| GET | `/user` | — | the caller's workspace context: `{ id, name, email, member, organization, is_super_admin, is_office_admin, led_team_ids, managed_program_ids, features, git_enabled }`; `managed_program_ids` lists the programs the person manages (while active, in their own organization), the web's `managedProgramIds`; `features` is `{ task_notes, contacts }`, the optional features the person turned on (both start off; a client hides what is off); `git_enabled` is true when the person may use git somewhere (an organization with git that is theirs, or of a project whose team they are on; every one for a super-admin), the web's `gitEnabled` |

## Appearance, preferences & themes

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/themes` | — | `{ data: { themes, font_allow_list } }` from `config/themes.php` |
| GET | `/user/preferences` | — | `{ data: { theme, font_override, email_notifications, task_notes_enabled, contacts_enabled, configured, resolved_tokens } }` |
| PATCH | `/user/preferences` | `theme?`, `font_override?`, `email_notifications?`, `task_notes_enabled?`, `contacts_enabled?` | same shape as GET |

The web app exposes a session twin at `PATCH /workspace/preferences`.

## Notifications (in-app inbox)

Top-level under `/api/v1` (not the `workspace` prefix). In-app only — email/push
are deferred.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/notifications?page=` | paginated `[Notification]`, newest first (`data` + `meta` + `links`), plus `counts` (all / unread / per kind) and the echoed `filters`. `?scope=unread` and `?type=assigned\|status\|mention\|deadline\|team` narrow the list; `counts` always describe the whole inbox |
| GET | `/notifications/unread-count` | `{ data: { count } }` |
| POST | `/notifications/{id}/read` | `{ message }` (sets `read_at`) |
| POST | `/notifications/read-all` | `{ message }` |

Each notification's `data` payload is `{ kind, title, body, task, actor, url }`. Team notifications (`kind` `team_added` or `made_manager`) have `task: null` and add `project { slug, title }`.

Task payloads carry `is_overdue`: still being worked on (not done, not failed) and past the deadline. Every overdue count in the API uses that rule; failed tasks are closed and never on the Home to-do buckets.

## Feeds & overview (`/workspace/*`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/home` | the overview: headline `stats`, `status_breakdown`, per-project `projects` rollup, `recent_activity`, plus open work bucketed by due date (`overdue`, `today`, `week`, `later`, `unscheduled`) and `recently_done`. `?scope=mine\|all` scopes every field together — defaults to `mine` when the caller has open assignments, `all` otherwise |
| GET | `/workspace/dashboard` | per-project rollups, status breakdown, recent activity |
| GET | `/workspace/my` | the caller's focused tasks, open todos, assigned work |
| GET | `/workspace/plan-tracker` | the 100-point plan tracker (`config/government.php`) |
| GET | `/workspace/search?q=` | search across active projects (2 characters at least): `{ projects, tasks, notes, contacts }`. Tasks match on title, short title and Nepali title; a bare number (`12`) also finds tasks by their `number`, and a task code (`SG-12`, any case) finds task 12 of the project whose key is `SG` now or was before (an old key stays an alias). A number above 2147483647 is searched as text only. Each task is `{ id, slug, number, title, short_title, status, status_label, project { slug, title, code } }` |
| POST | `/workspace/quick-add` | `{ text }` natural-language task capture; optional `depends_on_task_ids: int[]` (tasks of other projects are ignored) |

## Projects

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/projects` | list — visibility-scoped. `?archived=1` for archived |
| GET | `/workspace/projects` | each project also carries `program { slug, name }` (or `null`), `manager { id, name, title }`, `team { id, is_cross_organization, members_count }`, `percent_complete`, `open_tasks_count`, `overdue_tasks_count`, `next_deadline_at`; plus `managerCandidates` for the create form (active people of the organization the project lands in: a program manager's own), `programs` (the programs the caller may see, each a program payload with `projects_count`), `canManagePrograms`, `canCreate`, `programRequired` (true for a program manager who is not an organizational admin: their create form requires one of the `programs` whose `can_create_project` is true) and `defaultManagerId` (the caller's person id when `programRequired`, else `null`: who the form's manager picker starts on). The web page groups projects in a program under it (by each project's `program`; the heading links to the program only when it is in `programs`) and the rest by manager |
| POST | `/workspace/projects` | create (organizational admin, super-admin, or a program manager in one of their programs). `manager_id` (required: an active person of the project's organization) becomes the leader of the project's new team; `is_public: bool` (public means everyone in its organization; organizational admin / super-admin); optional `key` (see below), otherwise the title suggests a free one. A super-admin sends `organization_id`; otherwise it is the organizational admin's organization. Optional `program_id` (a program of that organization, else 422 on `program_id`). A program manager who is not an organizational admin must send `program_id`, one of the programs they manage (else 422 `Choose one of your programs.` on `program_id`); the project lands in that program's organization, and their `key`, `is_public` and `is_cross_organization` are dropped silently |
| GET | `/workspace/projects/{slug}` | show (board + tasks) — **403** if not visible to the caller. `programs: [{ id, slug, name }]` lists its organization's programs for whoever may move it (organizational admin, super-admin), else `[]` |
| GET | `/workspace/projects/{slug}/progress` | `{ data: { project, members, tasks, activity, generated_at } }`: everyone's workload on it, its tasks for a Gantt, recent activity. `project` carries `code`; each task carries `number` and `project { slug, title, code }`. For its manager, its program's manager, its organizational admin and super-admins (`project.can_view_progress`) |
| PATCH | `/workspace/projects/{slug}` | update (its manager, its program's manager, organizational admin or super-admin). `manager_id` (organizational admin / super-admin / its program's manager: an active person who can join its team, and for a program manager one of the project's organization even on a cross-organization team, else 422; the old manager stays on the team), `is_public` (organizational admin / super-admin), `is_cross_organization` (super-admin; refused while outsiders are on the team), `key` (organizational admin / super-admin, see below), `program_id` (a program of the project's organization, or `null` to take it out; organizational admin / super-admin; 422 on `program_id` for another organization's program); dropped silently for anyone else. The team keeps the project's title |
| PATCH | `/workspace/projects/{slug}/archive` | soft-archive (its manager, its program's manager, its organizational admin, or a super-admin) |
| PATCH | `/workspace/projects/{slug}/restore` | un-archive |

**Keys.** A project's `key` is the first half of its task codes (`SG` in
`SG-12`). Only its organization's organizational admin or a super-admin may set it, on
create or update; anyone else's well-formed `key` is dropped silently. It is
1 to 10 letters, sent in any case and stored upper case (`^[A-Z]{1,10}$`), and
unique in the organization across every project's current key and old keys: a
taken one is a 422 `That key is already used in this organization.` Changing it
keeps the old key as an alias that still finds the project and its tasks and
stays reserved for it, so an old commit never points at another project.

Project payloads carry `key`, `code` (the same key, as task codes use it) and
`can_change_key` (whether the caller may change it). `can_choose_manager`
says whether the caller may make someone its manager (organizational admin,
super-admin, its program's manager).

## Tasks

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/workspace/projects/{slug}/tasks` | create |
| POST | `/workspace/projects/{slug}/tasks/reorder` | persist board ordering |
| GET | `/workspace/projects/{slug}/tasks/{taskSlug}` | task hub (incl. `comments`). `team` is who can be given the task; `mentionCandidates` (`[{ id, name }]`) is who the comment box offers: the team plus, on a program project, the program's manager (they can see the task, so a mention notifies them) |
| PATCH | `/workspace/projects/{slug}/tasks/{taskSlug}` | update (status, fields) |
| DELETE | `/workspace/projects/{slug}/tasks/{taskSlug}` | soft-delete |
| POST | `/workspace/projects/{slug}/tasks/{taskSlug}/restore` | undo delete |
| GET | `/workspace/tasks/{task}/preview` | lightweight peek (incl. `comments_count`, and `dependency_candidates`: the project's other tasks) |

Task statuses come from `config/project-management.php` (`not_started`,
`unclear`, `in_progress`, `done`, `failed`); `done` is the only complete status.

Every task has a `number`, given when it is created: one more than the highest
its project has ever given, so a deleted (even purged) task's number is never
reused. It is never editable; a `number` sent on create or update is ignored.
With the project's key it makes the task code `SG-12` (`project.code`, a
hyphen, `number`), the form people copy into commit messages. Task payloads
carry `number`, and `project { id, slug, code, title }` when the project is
loaded. `item_number` is the plan tracker's own number: optional, editable,
an integer from 1 to 2147483647.

## Subtasks *(personal to the caller)*

| Method | Path |
| --- | --- |
| POST | `/workspace/tasks/{task}/subtasks` |
| PATCH | `/workspace/subtasks/{subtask}` |
| DELETE | `/workspace/subtasks/{subtask}` |
| POST | `/workspace/subtasks/{subtask}/restore` |

## Assignments

| Method | Path |
| --- | --- |
| POST | `/workspace/tasks/{task}/assignments` |
| PATCH | `/workspace/assignments/{assignment}` |
| DELETE | `/workspace/assignments/{assignment}` |
| POST | `/workspace/assignments/{assignment}/restore` |

## Comments + @mentions

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/tasks/{task}/comments?page=` | paginated `[TaskComment]` |
| POST | `/workspace/tasks/{task}/comments` | `{ body }` (201) |
| PATCH | `/workspace/comments/{comment}` | author-only |
| DELETE | `/workspace/comments/{comment}` | author-only soft-delete |
| POST | `/workspace/comments/{comment}/restore` | author-only |

Mentions are stored canonically as `@[Display Name](member:ID)`; the resolved
`mentions` array on each comment is authoritative.

## Task notes & contacts

| Method | Path |
| --- | --- |
| POST | `/workspace/tasks/{task}/notes` |
| DELETE | `/workspace/notes/{note}` |
| POST | `/workspace/notes/{note}/restore` |
| POST | `/workspace/tasks/{task}/contacts` |

## Personal workspace notes *(draggable stickies, owner-only)*

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/my-notes` | `{ data: { workspace_notes, task_notes } }` — own stickies plus task notes they authored or that live on a task assigned to them (latest 50) |
| POST | `/workspace/my-notes` | create |
| PATCH | `/workspace/my-notes/{note}` | edit body/color |
| PATCH | `/workspace/my-notes/{note}/placement` | move (`position_x/y`) |
| DELETE | `/workspace/my-notes/{note}` | soft-delete |
| POST | `/workspace/my-notes/{note}/restore` | undo |

## Organizations *(super-admin only)*

Every project (and its team) and member (except super-admins) belongs to one
organization. Everything below is scoped to what the caller may see: a member
sees their projects and teammates, a manager the same plus their projects'
progress, an organizational admin their whole organization, a super-admin everything.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/organizations` | `{ organizations }` with counts and organizational admins |
| POST | `/workspace/organizations` | `name` (unique) |
| PATCH | `/workspace/organizations/{organization}` | `name` |
| DELETE | `/workspace/organizations/{organization}` | 422 unless it has no people, teams or projects |
| POST | `/workspace/organizations/{organization}/git` | `base_url` (https; plain http only for `127.0.0.1`, `localhost` or `::1`), `owner` (the forge organization), `token` (a bot token). Checks the token against the forge (`GET /api/v1/user`, then the user's permissions on `owner`) and saves it encrypted; calling again replaces the connection, and pointing it at another forge or forge organization unlinks the repositories it had (`Git is on. Unlinked {n} repositories that were on the old forge.`). 422 with `Couldn't reach the forge at {base_url}.`, `The forge refused that token.` or `{login} doesn't own {owner} on the forge.`, and nothing is saved. Validation 422s: `base_url` for plain http elsewhere, `owner` with `{owner} is already connected to another office.` when another organization has the same forge organization (case-insensitive) on the same forge |
| DELETE | `/workspace/organizations/{organization}/git` | Turns git off: deletes the forge webhook, points each linked repository back at the forge's own issue tracker (best effort; a forge that is down or refuses never keeps git on), then removes the connection and its links |

On the JSON API these are `POST|DELETE /api/v1/workspace/organizations/{organization}/git`
(names `api.workspace.organizations.git.store|destroy`); success returns the
organization. The token is write-only: organization payloads show only
`git: { base_url, owner, url }` (or `null`), and only to a super-admin.

## Programs *(create/edit/delete: organizational admin of their organization or super-admin)*

A program is a named collection of projects in one organization with one
program manager, who runs every project in it as that project's own manager
does. Deleting a program keeps its projects.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/programs` | `{ data: [program] }`, only the programs the caller may see: all for a super-admin, their organization's for an organizational admin, the ones they manage for a program manager, none for anyone else |
| POST | `/workspace/programs` | `name` (unique in the organization), `description` (optional), `manager_id` (required: an active person of the program's organization; 422 on `manager_id` otherwise). A super-admin sends `organization_id`; for an organizational admin it is always their own, whatever is posted. 201 with the program; the manager is notified |
| PATCH | `/workspace/programs/{slug}` | any of `name`, `description`, `manager_id` (same checks; a new manager is notified). The organization never changes |
| DELETE | `/workspace/programs/{slug}` | the program goes, its projects stay with no program |
| GET | `/workspace/programs/{slug}/progress` | the program manager's weekly view (its manager, its organization's organizational admin or a super-admin; else 403): `{ data: { program, projects: [{ slug, title, code, manager { id, name } \| null, tasks_count, done_tasks_count, overdue_tasks_count, next_deadline_at }], members, tasks, truncated, activity, generated_at } }`. `projects` lists only active (not archived) projects, by title; `members`, `tasks` (first 500) and `activity` are the project progress report across all of them, each person once, `role: leader` for anyone who manages one of the projects or the program. The web page is `GET /workspace/programs/{slug}` (Inertia `Programs/Show`, prop `progress`) |

Program payload: `{ id, slug, name, description, organization { id, name },
manager { id, name, title } | null, projects_count (index only), can_manage,
can_create_project }`. `can_create_project` is whether the caller may create
a project in it (its organization's organizational admin, a super-admin, its
manager).
`manager` is `null` while the manager's person is in the trash, and after
they were pruned. Anyone who is not an organizational admin of the program's
organization or a super-admin gets 403 on POST, PATCH and DELETE. The web surface has the same writes at
`/workspace/programs` (names `workspace.programs.store|update|destroy`); a web
DELETE lands on the Projects page, since the program's page is gone. The web
program page also gets `managerCandidates: [{ id, name, title }]` (the
organization's active people, for its Edit form) outside `progress`, empty
for anyone who may not manage the program.

## Project teams *(roster: the project's manager, its program's manager, its organizational admin or a super-admin; choosing the manager: organizational admin, super-admin or its program's manager, who never removes one)*

A team is made with its project and never on its own; `GET /workspace/projects/{slug}`
returns it as `team { id, is_cross_organization, members: [{ id, name, email, title, organization, is_active, is_manager, can_remove, can_make_manager }], can_manage, can_choose_manager }`. `can_make_manager` is whether the caller may make that person its manager: an active non-manager who can join the team, and for a program manager only a person of the team's own organization, even on a cross-organization team.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/team` | the People directory: `{ teams (each with its project and roster), members, organizations, canCreateMember }`; super-admin may add `?organization=` |
| GET | `/workspace/teams/{team}/candidates?q=` | up to 8 people who can join (min 2 characters): its organization, or anyone for a cross-organization team |
| POST | `/workspace/teams/{team}/members` | `member_id` (an existing person; 422 if they can't join) |
| DELETE | `/workspace/teams/{team}/members/{member}` | 422 for the manager: make someone else manager first |
| PATCH | `/workspace/teams/{team}/members/{member}` | `role: leader` makes them the project's manager (the old one stays as a member; 422 on `role` if they can't join, or for a program manager if they are of another organization); `role: member` on the manager is a 422 |

## Code repositories *(only in an organization with git on; list: the project's team, its program's manager, its organizational admin or a super-admin; the rest: its manager, its program's manager, its organizational admin or a super-admin)*

A project links repositories of its organization's forge organization. Every
route answers 403 when the organization has no git connection, whoever asks.
A repository payload is `{ id, owner, name, full_name, html_url, clone_url,
ssh_url, default_branch, last_pushed_at, created_at }`; nothing of the
connection (never the bot token). On the project show it adds
`open_pull_requests` (the 10 newest open ones) and `open_pull_requests_count`.
A pull request is `{ id, number, title, state (open|merged|closed), url,
head_branch, author_username, repository?, opened_at, merged_at, closed_at }`
(`repository` is the `full_name`, given in a task's `code`); its body stays
on the forge.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/projects/{slug}/repositories` | the project's repositories, by name (JSON API only; the web Code tab gets them with the project) |
| GET | `/workspace/projects/{slug}/repositories/candidates?q=` | `{ data: [{ full_name, name, description, html_url }] }`: up to 50 repositories of the forge organization that no project links yet, by name, `q` matching the name in any case. 502 with the forge's answer (`The forge answered 500.`) when it can't list them |
| POST | `/workspace/projects/{slug}/repositories` | `full_name` (`owner/name`, no `.` or `..` part). The forge decides: 422 with `That repository isn't in the {owner} organization on the forge.` (also when the forge has no such repository), `That repository is already linked to {project title}.` (`another project` when the caller can't see it), `Couldn't reach the forge at {base_url}.` or `The forge answered {status}. Try again shortly.`. On success: `Linked {full_name}.`, the forge's external tracker is set to `{APP_URL}/workspace/go/{user}/{index}` (style `alphanumeric`), and a backfill is queued (open pull requests and those updated in the last 90 days, every branch, the default branch's commits of the last 90 days, all linked to tasks without changing any). When the forge refuses the external tracker the link stands and the message adds ` The forge link back couldn't be set; the nightly reconcile will retry.` |
| DELETE | `/workspace/projects/{slug}/repositories/{repository}` | `Unlinked {full_name}. The repository is untouched on the forge.`: its branches, commits, pull requests and task links go; nothing changes on the forge. 404 for a repository of another project |

On the JSON API these are `/api/v1/workspace/projects/{slug}/repositories…`
(names `api.workspace.projects.repositories.index|candidates|store|destroy`);
linking answers 201 with the repository. On the web they answer with a
redirect and `workspace_flash`, except `candidates`, which is JSON.

**The forge's link back** (web only): `GET /workspace/go/{org}/{index}` (name
`workspace.go`), where the forge fills `{org}` with the forge organization
(matched in any case) and `{index}` with a task code such as `TP-12`. The key
resolves within the office connected to that forge organization, current keys
and old ones alike, and redirects to the task. 404, never 403, when the code,
key, number or forge organization is unknown, the task is trashed, or the
caller can't see the task. A guest signs in first and lands on the task.

**Code on projects and tasks.** Only where the project's organization has
git and the caller may see its code: someone on the project's team (any
role, while active), its program's manager, its organizational admin or a
super-admin, not other viewers of a public project. Elsewhere `git` and
`code` are `null` and `has_pull_request` is absent.

- `GET /workspace/projects/{slug}` adds `git: { repositories, can_manage }`:
  the linked repositories by `full_name`, and whether the caller may link
  and unlink (its manager, its program's manager, its organizational admin or a
  super-admin). Each of its
  `tasks` adds `has_pull_request` (the board's mark).
- `GET /workspace/projects/{slug}/tasks/{taskSlug}` and
  `GET /workspace/tasks/{task}/preview` add `code: { branch_name,
  pull_requests, branches, commits }`. `branch_name` is the branch to start
  for the task (`tp-12-fix-the-login`). `pull_requests`: up to 10 that
  mention the task, open first, then newest. `branches`: every one that
  names it, `{ id, name, repository, url }`. `commits`: the latest 10, `{ id,
  subject, short_sha, url, author, repository, committed_at, unreachable }`;
  `unreachable` is true for a commit no branch holds any more (force-pushed,
  or squash-merged and its branch deleted), which stays listed.

A task code resolves anywhere in the repository's organization, so a
repository of another project can mention a task. Its pull requests,
branches and commits show (and count for `has_pull_request`) only to someone
who may see that other project's code too, and its pull requests write no
row into this task's activity. Nothing in the code changes a task: a merged
pull request never moves it, and the activity rows the forge brings in
carry no user.

The forge's webhook, `POST /git/webhooks/{connection}` (name `git.webhook`),
is server to server: no session, CSRF or token, a body signed with the
connection's webhook secret. See `docs/forgejo.md`, section 12.

## Members *(create/edit/delete: organizational admin of their organization or super-admin)*

`POST /workspace/members` takes `name`, `email` (required, not already a login),
optional `password` (min 8), `title` and `team_ids`. A super-admin also sends
`organization_id` (required) and may send `is_office_admin`; an organizational admin's
people always join their own organization. Every member gets a login: the
password you send, or a generated one if you send none. Either way the member is
emailed their email and password; if that email fails the member is still
created and `message` says so. `PATCH` accepts `organization_id` and
`is_office_admin` from a super-admin only. Deactivating (`is_active: false`)
or deleting someone who has a forge account removes them from every connected
forge organization and adds a sentence to `message`: `{git_username} was removed
from the forge.` when every removal succeeded, or `Couldn't remove
{git_username} from the forge; ask a super-admin to suspend them there today.`
when any failed (the change itself still stands). Nothing is added for someone
without a forge account. Changing `is_office_admin`, or moving or
reactivating an organizational admin, puts the forge organizations of the offices
concerned in step after the response: active organizational admins in its Owners
team, nobody else the tracker knows. A forge failure there is only logged;
the nightly `git:reconcile` does it again.

| Method | Path |
| --- | --- |
| POST | `/workspace/members` |
| PATCH | `/workspace/members/{member}` |
| DELETE | `/workspace/members/{member}` |
| POST | `/workspace/members/{member}/restore` |

## Task dependencies *(anyone who can see the task's project)*

Finish-to-start, within one project: the task cannot start until the other is
finished. Loops are refused. Task payloads carry `dependencies` and `dependents`
(each `{ id, slug, title, number, item_number, status, status_label,
is_complete, is_late, is_overdue, deadline_at, completed_at }`), and the project
progress payload gives each task `depends_on: int[]`.

| Method | Path | Body / notes |
| --- | --- | --- |
| POST | `/workspace/tasks/{task}/dependencies` | `depends_on_task_id`; 422 for itself, another project, a duplicate or a loop; answers with the task |
| DELETE | `/workspace/tasks/{task}/dependencies/{dependsOn}` | 404 if it was not a dependency |

## Integrating another system

Any system that can send HTTPS requests can drive the workspace through the JSON
API above. It acts **as a user**, so authorization follows that user's role: give
it a dedicated service account (a normal user, created via the seeder or the
member UI) and add that account to the teams of the projects it should touch, or
list its email in `PM_SUPER_ADMINS` if it needs everything.

### 1. Issue a token

Instead of the interactive `POST /login`, mint a long-lived token on the server:

```bash
php artisan workspace:api-token bot@example.com --name=crm-sync              # never expires
php artisan workspace:api-token bot@example.com --name=crm-sync --expires-days=90
php artisan workspace:api-token bot@example.com --name=crm-sync --revoke      # revoke every token with that name
```

The plain-text token is printed **once**; store it in the other system's secret
store. Tokens are Sanctum personal access tokens (table `personal_access_tokens`),
so they can also be audited and deleted there.

### 2. Call the API

```bash
BASE=https://pmopm.example.com/api/v1
TOKEN=...

# who am I / which teams do I lead
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" $BASE/user

# projects visible to the service account
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" $BASE/workspace/projects

# create a task
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"title":"Publish the Q3 report","deadline_at":"2026-09-30","priority":"high"}' \
  $BASE/workspace/projects/{slug}/tasks

# move it to done
curl -s -X PATCH -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -d '{"status":"done"}' \
  $BASE/workspace/projects/{slug}/tasks/{taskSlug}
```

Validation rules for each body are the FormRequests in `app/Http/Requests`
(`StoreTaskRequest`, `UpdateTaskRequest`, …) — the API and the web UI share them.

### Behaviour to expect

- Every mutation the integration performs is attributed to the service account in
  the activity log and notifications, exactly as if a person did it.
- Assigning a task (`POST /workspace/tasks/{task}/assignments`) notifies the
  assignee in-app; status changes to `done`/`failed` notify the task's assignees.
- Rate limiting is Laravel's default `api` throttle (60 requests/minute per token).

## Changes

**Git phase 0 (project keys and task numbers).**

- Search results' tasks and project progress tasks carry `number` (the task's
  stable number) instead of `item_number`; search tasks' `project` adds
  `code`.
- A bare-number search matches `number`, not `item_number`; `SG-12` codes
  (current or old keys) work too.
- `code`, on projects and on a task's `project`, is now the project's stored
  key, no longer worked out from its title on every read.
- Project payloads add `key` and `can_change_key`; task payloads and
  dependency references add `number`.

**Git phase 1 (projects link repositories, tasks show code).**

- New: `GET|POST /workspace/projects/{slug}/repositories`,
  `GET …/repositories/candidates` and `DELETE …/repositories/{repository}`
  (see Code repositories), and the web-only `GET /workspace/go/{org}/{index}`.
- The project show adds `git` and its tasks `has_pull_request`; the task
  show and the task preview add `code`. Each is `null` or absent where the
  organization has no git.
