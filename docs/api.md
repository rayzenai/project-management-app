# Projects HTTP API

The app exposes the workspace through **two HTTP surfaces that share the same
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
  unauthenticated (also a deactivated person's token, with `This account is
  deactivated. Ask your organizational admin to turn it back on.`), `403`
  forbidden, `404` not found, `422` validation.
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
| POST | `/login` | `email`, `password`, `device_name` | `{ token, user }`; a deactivated person (not a super-admin) gets 422 on `email` with `This account is deactivated. Ask your organizational admin to turn it back on.` and no token; after five wrong passwords for an email from one address (counted together with the web login, and started again by a right password), 429 on `email` with `Too many login attempts. Please try again in {n} seconds.` until the minute is up |
| POST | `/logout` | — | `{ message }` (revokes the current token) |
| GET | `/user` | — | the caller's workspace context: `{ id, name, email, member, organization, is_super_admin, is_office_admin, led_team_ids, managed_program_ids, features, git_enabled, attendance_enabled, office }`; `managed_program_ids` lists the programs the person manages (while active, in their own organization), the web's `managedProgramIds`; `features` is `{ task_notes, contacts }`, the optional features the person turned on (both start off; a client hides what is off); `git_enabled` is true when the person may use git somewhere (an organization with git that is theirs, or of a project whose team they are on; every one for a super-admin), the web's `gitEnabled`; `attendance_enabled` is true when the person may clock in (an active member of an organization with attendance on); `office` says which office pages they may open, the web's `office`: `{ organogram, attendance, attendance_settings, leave, team_work, my_work_member_id }` |

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
| POST | `/workspace/quick-add` | `{ title, project_id }` natural-language task capture (a `#token` in the title beats `project_id`); without `project_id`, 422 `Pick a project.` on `project_id`. `start_general: true` in place of `project_id` starts the organization's first project, "General", managed by the caller, and files the task there in one transaction (both or neither): only an organizational admin whose organization has no active project, else 422 `Pick a project.`. Optional `assignee_member_ids: int[]` (see Assignments: who may be assigned), `depends_on_task_ids: int[]` (tasks of other projects are ignored) |

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
| GET | `/workspace/projects/{slug}/tasks/{taskSlug}` | task hub (incl. `comments`). `team` is who can be given the task; `joinCandidates` (`[{ id, name, title }]`) is who else the caller may give it to, people of the project's organization who join its team when assigned (only for whoever runs the team, else `[]`); `mentionCandidates` (`[{ id, name }]`) is who the comment box offers: the team plus, on a program project, the program's manager (they can see the task, so a mention notifies them) |
| PATCH | `/workspace/projects/{slug}/tasks/{taskSlug}` | update (status, fields) |
| DELETE | `/workspace/projects/{slug}/tasks/{taskSlug}` | soft-delete |
| POST | `/workspace/projects/{slug}/tasks/{taskSlug}/restore` | undo delete |
| GET | `/workspace/tasks/{task}/preview` | lightweight peek (incl. `comments_count`, `dependency_candidates`: the project's other tasks, and `join_candidates`: the task hub's `joinCandidates`) |

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

**Who may be assigned** (here and on quick add): anyone on the project's
team (or, for a public project without a team, any active person of its
organization), as always; and, for whoever runs the team (its manager, its
program's manager, an organizational admin, a super-admin), any active person
of the project's own organization, who first joins the team as a member (as
if added on its People tab, so they are told) and is then assigned. People of
another organization never join this way, even a cross-organization team's.
Anyone else gets 422 `That person is not on this project's teams.` on
`member_id`.

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
| POST | `/workspace/organizations/{organization}/git` | `base_url` (https; plain http only for `127.0.0.1`, `localhost` or `::1`), `owner` (the forge organization), `token` (a bot token). Checks the token against the forge (`GET /api/v1/user`, then the user's permissions on `owner`) and saves it encrypted; calling again replaces the connection, and pointing it at another forge or forge organization unlinks the repositories it had (`Git is on. Unlinked {n} repositories that were on the old forge.`), leaving their forge collaborators as they are. 422 with `Couldn't reach the forge at {base_url}.`, `The forge refused that token.` or `{login} doesn't own {owner} on the forge.`, and nothing is saved. Validation 422s: `base_url` for plain http elsewhere, `owner` with `{owner} is already connected to another office.` when another organization has the same forge organization (case-insensitive) on the same forge |
| DELETE | `/workspace/organizations/{organization}/git` | Turns git off: deletes the forge webhook, points each linked repository back at the forge's own issue tracker (best effort; a forge that is down or refuses never keeps git on), then removes the connection and its links; every repository's forge collaborators stay as they are |

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
| POST | `/workspace/programs` | `name` (unique in the organization), `description` (optional), `manager_id` (required: an active person of the program's organization; 422 on `manager_id` otherwise). A super-admin sends `organization_id`; for an organizational admin it is always their own, whatever is posted. 201 with the program; the manager is notified. The program is created together with a project titled `General` (not public, managed by the program manager, with the key the title suggests), all or nothing |
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
program page also gets `managerCandidates: [{ id, name, designation }]` (the
organization's active people, for its Edit form) outside `progress`, empty
for anyone who may not manage the program.

## Project teams *(roster: the project's manager, its program's manager, its organizational admin or a super-admin; choosing the manager: organizational admin, super-admin or its program's manager; taking the manager off, leaving none: organizational admin or super-admin)*

A team is made with its project and never on its own; `GET /workspace/projects/{slug}`
returns it as `team { id, is_cross_organization, members: [{ id, name, email, designation, organization, is_active, is_manager, can_remove, can_make_manager }], can_manage, can_choose_manager, can_remove_manager }`. `can_make_manager` is whether the caller may make that person its manager: an active non-manager who can join the team, and for a program manager only a person of the team's own organization, even on a cross-organization team.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/team` | the People directory: `{ teams (each with its project and roster), members, organizations, canCreateMember, canSetRoles, roleOptions }`; super-admin may add `?organization=`. Each team's `project` is `{ slug, title, program, is_archived }` (`program`: its program's name, or null). Each member carries `team_ids`, `led_team_ids` (the teams they manage), `managed_project_ids` (those teams' projects) and `managed_program_ids`. `canSetRoles` is true for whoever may set people's roles (an organizational admin, a super-admin); `roleOptions` is then `{ programs: [{ id, name, organization_id, manager { id, name } \| null }], projects: [{ id, title, program (its program's name) \| null, organization_id, team_id, manager { id, name } \| null }] }` (active projects only) for the organizations they administer (all of them for a super-admin, whatever the filter), and empty lists otherwise |
| GET | `/workspace/teams/{team}/candidates?q=` | up to 8 people who can join (min 2 characters): its organization, or anyone for a cross-organization team |
| POST | `/workspace/teams/{team}/members` | `member_id` (an existing person; 422 if they can't join) |
| DELETE | `/workspace/teams/{team}/members/{member}` | the manager too, by an organizational admin or super-admin only (403 for anyone else); the project then has no manager |
| PATCH | `/workspace/teams/{team}/members/{member}` | `role: leader` makes them the project's manager (the old one stays as a member; 422 on `role` if they can't join, or for a program manager if they are of another organization); `role: member` on the manager leaves the project with none (`{name} no longer manages {team}.`), by an organizational admin or super-admin only (403 for anyone else) |

## Code repositories *(only in an organization with git on; list: the project's team, its program's manager, its organizational admin or a super-admin; the rest: its manager, its program's manager, its organizational admin or a super-admin)*

A project links repositories of its organization's forge organization. Every
route answers 403 when the organization has no git connection, whoever asks.
A repository payload is `{ id, owner, name, full_name, html_url, clone_url,
ssh_url, default_branch, protects_main, main_checks, last_pushed_at,
created_at }`; nothing of the connection (never the bot token).
`protects_main`: the tracker's rule is on the default branch (nobody pushes
to it; a pull request merges with one approval once every check passed).
`main_checks`: the checks of the commit the default branch points at, or
`null` while unknown. On the project show it adds `open_pull_requests` (the
10 newest open ones) and `open_pull_requests_count`. A pull request is `{ id,
number, title, state (open|merged|closed), url, head_branch,
author_username, repository?, opened_at, merged_at, closed_at, checks }`
(`repository` is the `full_name`, given in a task's `code`; `checks` its
head commit's); its body stays on the forge.

**Checks** are the tests the forge's runners ran on a commit: `null` until
the tracker has asked the forge (within about two minutes of a push, at once
when a run finishes), else `{ state, checks: [{ name, state, url }] }`.
Each check (`name` as the forge names it, `tests / pest (pull_request)`) is
`passed`, `failed` or `running`, `url` its run on the forge or `null`; the
overall `state` is `failed` if one failed, else `running` if one runs, else
`passed`, or `none` when nothing checks the commit.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/projects/{slug}/repositories` | the project's repositories, by name (JSON API only; the web Code tab gets them with the project) |
| GET | `/workspace/projects/{slug}/repositories/candidates?q=` | `{ data: [{ full_name, name, description, html_url }] }`: up to 50 repositories of the forge organization that no project links yet, by name, `q` matching the name in any case. 502 with the forge's answer (`The forge answered 500.`) when it can't list them |
| POST | `/workspace/projects/{slug}/repositories` | `full_name` (`owner/name`, no `.` or `..` part). The forge decides: 422 with `That repository isn't in the {owner} organization on the forge.` (also when the forge has no such repository), `That repository is already linked to {project title}.` (`another project` when the caller can't see it), `Couldn't reach the forge at {base_url}.` or `The forge answered {status}. Try again shortly.`. On success: `Linked {full_name}.`, the forge's external tracker is set to `{APP_URL}/workspace/go/{user}/{index}` (style `alphanumeric`), and a backfill is queued (open pull requests and those updated in the last 90 days, every branch, the default branch's commits of the last 90 days, all linked to tasks without changing any). When the forge refuses the external tracker the link stands and the message adds ` The forge link back couldn't be set; the nightly reconcile will retry.` |
| POST | `/workspace/projects/{slug}/repositories/new` | Creates a repository in the forge organization and links it. `name`: the project's key lowercased (`tp`) or that followed by `-` and more (`tp-api`); lowercase letters and digits with a single `.`, `_` or `-` between them; at most 100 characters; not ending in `.git`, `.wiki`, `.rss` or `.atom` (validation 422s: `Start the name with {key}, such as {key}-api.`, `Use lowercase letters and digits, with a single ., _ or - between them.`, `The name can't end in .git, .wiki, .rss or .atom.`). `description`: optional, at most 255 characters. On the forge it is private, with a README on `main`, its default branch, and `main` is protected: nobody pushes to it, admins included, and a pull request merges with one approval once every check on it passed (a repository with no tests merges nothing). The access sync puts the same rule on every linked repository's default branch, and back whenever it was changed on the forge. Then it is linked as above. 422 with `A repository named {name} already exists in {owner}. Link it instead.`, `Couldn't reach the forge at {base_url}.` or `The forge answered {status}. Try again shortly.` (nothing is linked), or `Created {owner}/{name} on the forge, but couldn't link it: {why}` (it exists on the forge and can be linked). On success: `Created and linked {owner}/{name}.`, or `Created and linked {owner}/{name}, but main couldn't be protected yet; the tracker keeps trying.` when the forge refused the rule (the repository is linked all the same, with `protects_main` false) |
| DELETE | `/workspace/projects/{slug}/repositories/{repository}` | `Unlinked {full_name}. The repository is untouched on the forge. People who had access keep it on the forge until it's changed there or the repository is linked again.`: its branches, commits, pull requests and task links go; nothing changes on the forge, its collaborators included (the access sync stops writing them). 404 for a repository of another project |

On the JSON API these are `/api/v1/workspace/projects/{slug}/repositories…`
(names `api.workspace.projects.repositories.index|candidates|store|create|destroy`);
linking and creating answer 201 with the repository. On the web they answer with a
redirect and `workspace_flash`, except `candidates`, which is JSON.

### Repository access *(its manager, its program's manager, its organizational admin or a super-admin; git on)*

Who may pull and push a linked repository on the forge. Organizational
admins (the forge organization's Owners) and super-admins reach every
repository and are not listed. The **maintainers**, the project's manager
and its program's manager (active), are admin on the forge; their access
follows the role and can't be edited. Everyone else needs a **grant**,
`read` or `write`, given here and never on the forge; the forge follows
within moments. A grant counts while the person is active and belongs to
the project's organization or is on its team.

To whom: an organizational admin or a super-admin gives access to any
active person of the project's organization or anyone on its team; the
manager and the program manager only to people on its team. Never to
someone inactive, someone with no organization or a maintainer. The same
rule decides who may change a grant; removing one needs only the right to
manage access.

A grant is `{ id, permission, waiting, effective, member: { id, name, designation },
granted_at }`. `waiting` is true while the person has never signed in to
the forge (the forge can't have them until they do). `effective` is false
while the grant gives nothing: the person is deactivated, or no longer in
the project's organization or on its team; it stays listed so it can be
removed, and counts again if that changes. No email anywhere.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/projects/{slug}/repositories/{repository}/access` | `{ data: { maintainers: [{ id, name, title, role, waiting }], grants } }`: `role` is `manager` or `program manager` (someone who is both is listed once, as `manager`); `grants` by name |
| GET | `/workspace/projects/{slug}/repositories/{repository}/access/candidates?q=` | `{ data: [{ id, name, title }] }`: up to 20 people the caller may give access to and who have none yet, `q` matching the name or the email in any case |
| POST | `/workspace/projects/{slug}/repositories/{repository}/access` | `member_id`, `permission` (`read` or `write`). `Gave {name} {permission} access to {full_name}.`; for someone who already has a grant it sets the permission (`{name} now has {permission} access to {full_name}.`). 422 on `member_id` with `Choose someone from this organization or this project's team.` for anyone else (an unknown id too: someone outside is never named), and `You can't give {name} access to this repository.` when the caller may not give that person access |
| PATCH | `/workspace/projects/{slug}/repositories/{repository}/access/{grant}` | `permission`. `{name} now has {permission} access to {full_name}.`; 422 on `permission` with `You can't give {name} access to this repository.` when the caller may not give that person access (a manager can't change an organizational admin's grant to someone off the team, only remove it) |
| DELETE | `/workspace/projects/{slug}/repositories/{repository}/access/{grant}` | `Removed {name}'s access to {full_name}.` |

On the JSON API these are `/api/v1/workspace/projects/{slug}/repositories/{repository}/access…`
(names `api.workspace.projects.repositories.access.index|candidates|store|update|destroy`);
`store` answers 201 with the grant, `update` 200 with the grant. On the web
`index` and `candidates` are JSON and the others redirect with
`workspace_flash`. A `{repository}` of another project or a `{grant}` of
another repository is a 404. Every grant, change and removal writes a row
into the project's activity.

```bash
# who reaches sdoc/tp-api (repository 7)
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  $BASE/workspace/projects/tax-portal/repositories/7/access
```

```json
{
  "message": "Repository access retrieved.",
  "data": {
    "maintainers": [
      { "id": 3, "name": "Mira Thapa", "designation": "PM", "role": "manager", "waiting": false },
      { "id": 2, "name": "Pradeep Rai", "designation": "Engineering head", "role": "program manager", "waiting": true }
    ],
    "grants": [
      {
        "id": 12,
        "permission": "write",
        "waiting": false,
        "effective": true,
        "member": { "id": 9, "name": "Asha Gurung", "designation": "Senior developer" },
        "granted_at": "2026-10-30T09:15:00+00:00"
      }
    ]
  }
}
```

```bash
# give Bina (member 14) read access
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -d '{"member_id":14,"permission":"read"}' \
  $BASE/workspace/projects/tax-portal/repositories/7/access
```

```json
{
  "message": "Gave Bina Shrestha read access to sdoc/tp-api.",
  "data": {
    "id": 13,
    "permission": "read",
    "waiting": true,
    "effective": true,
    "member": { "id": 14, "name": "Bina Shrestha", "designation": "Analyst" },
    "granted_at": "2026-10-30T10:02:11+00:00"
  }
}
```

```bash
# raise it to write, then take it away
curl -s -X PATCH -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -d '{"permission":"write"}' \
  $BASE/workspace/projects/tax-portal/repositories/7/access/13
# { "message": "Bina Shrestha now has write access to sdoc/tp-api.", "data": { "id": 13, "permission": "write", ... } }

curl -s -X DELETE -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  $BASE/workspace/projects/tax-portal/repositories/7/access/13
# { "message": "Removed Bina Shrestha's access to sdoc/tp-api.", "data": null }
```

```json
{
  "message": "You can't give Bina Shrestha access to this repository.",
  "errors": { "member_id": ["You can't give Bina Shrestha access to this repository."] }
}
```

(the 422 a project manager gets for someone of the organization who is not
on the project's team).

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
  subject, short_sha, url, author, repository, committed_at, unreachable,
  checks }` (see Checks above);
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

## Code activity *(anyone who may use git: `git_enabled` on `/user`; 403 otherwise)*

Who committed what, per person, within what the caller runs. Whole
repositories: every one for a super-admin; those of the projects of their
organization for an organizational admin; of the projects in the programs
they manage for a program manager; of the projects they manage for a
project manager. Everyone also sees their own commits (and pull requests),
wherever they are, and nothing else.

Counted: commits that are not merges and are still reachable from a branch
(`unreachable_at` null: a rebased branch's old commits would count twice),
by `committed_at`, a day being a whole local day (Asia/Kathmandu). A
commit's author is the user whose forge username it names.

Query string, on both routes:

- `from`, `to`: dates `YYYY-MM-DD`, both ends included; default the last 30
  days ending today (`from` defaults to 29 days before `to`). 422 on `to`
  when it is before `from` (`The end date can't be before the start date.`)
  or the range has more than 366 days (`Choose a range of at most 366
  days.`).
- `project`, `program`: a slug, to narrow to that project's or program's
  repositories. One outside the caller's whole repositories, or a slug
  nothing has, is a 403.
- `members[]`: up to 4 user ids to compare (422 on `members` above 4).

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/workspace/reports/code` | `{ data: { range { from, to }, people, series, uncounted, repositories, options }, filters { from, to, project, program, members } }`. `people`: one row per person, by name (case-insensitive; never ranked by a figure), the unmatched row last: `{ user_id, name, designation, commits, active_days, repositories, files_changed, code_added, code_removed, comment_added, comment_removed, blank_added, blank_removed, docs_added, docs_removed, checks_passed, checks_failed, pull_requests_opened, pull_requests_merged, tasks }` (`name` and `designation` are the person's in the People directory; `checks_passed` and `checks_failed` count their commits whose tests passed and failed, running and untested ones in neither; `pull_requests_*` count pull requests opened and merged in the range, by author; `tasks` the tasks their commits are linked to). For a caller with whole repositories, the commits no one is matched to are one row with `user_id: null` and `name: "Not matched to anyone"`. `series`: for each of `members[]`, `{ user_id, days: [{ date, code_changed, commits }] }`, every day of the range (zero days included). `uncounted`: commits whose lines are still being counted (they are in `commits` already). `repositories`: how many linked repositories' code the caller may see (0: nothing linked yet, so no commit can count). `options`: the `projects` (`[{ slug, title, label }]`, `label` naming the program too: "General (Revenue)") and `programs` (`[{ slug, name }]`) in the caller's whole repositories that have a linked repository, for the filters |
| GET | `/workspace/reports/code/people/{user}` | `{ data: [{ sha, message, repository, url, committed_at, code_added, code_removed, comment_added, comment_removed, stats_status, checks }] }`: that user's latest 50 counted commits in the range and the caller's scope, newest first; `message` is the first line, `repository` the `full_name`, `url` the commit on the forge, `stats_status` `ok`, `too_large`, `missing` or `null` (not counted yet). Someone outside the caller's scope, or no one, is `{ data: [] }`, never a 403 or 404 |

On the JSON API these are `/api/v1/workspace/reports/code…` (names
`api.workspace.reports.code` and `api.workspace.reports.code.person`). On
the web, `/workspace/reports/code` (name `workspace.reports.code`) is the
Inertia page `Reports/CodeActivity`, whose props are `data`'s fields plus
`filters`; `/workspace/reports/code/people/{user}` (name
`workspace.reports.code.person`) answers the same JSON.

## Members *(create/edit/delete: organizational admin of their organization or super-admin)*

`POST /workspace/members` takes `name`, `email` (required, not already a login),
optional `password` (min 8) and the role fields below. A super-admin also sends
`organization_id` (required) and may send `is_office_admin`; an organizational admin's
people always join their own organization. Every member gets a login: the
password you send, or a generated one if you send none. Either way the member is
emailed their email and password; if that email fails the member is still
created and `message` says so. `PATCH` accepts `organization_id` and
`is_office_admin` from a super-admin only (403 otherwise).

**Roles.** `POST` and `PATCH` take three optional role fields, each the
complete wanted set of ids; a field left out leaves that role as it is:
`managed_program_ids` (programs this person manages), `managed_project_ids`
(projects this person manages) and `team_ids` (project teams this person is
on; a team they manage counts as on). Anyone who may create or edit the
person may send them, and every program, project or team they add or take
away must be in an organization the caller administers, checked against the
person's organization after any move in the same request (422 on the field,
`{name} belongs to another organization.`, or `The {name} program belongs to
another organization.`; a program must be of the person's own organization,
and a project or team one they can join). Making someone manager of a program
replaces its manager; of a project, makes them its team's one manager, and
the old manager stays on the team as a member. Leaving out a project the
person manages leaves it with no manager and keeps them on its team as a
member (only for a caller who administers the project's organization;
otherwise 422, `{name} belongs to another organization.`); leaving out a
program they manage is a 422 (`Choose another manager for the {name} program
first.`); leaving out a team they still manage keeps them on it. A manager role for an inactive person is a 422 (`Only an active person can
manage.`). A refused role undoes the whole request. When a role field was sent,
`PATCH`'s `message` starts `Member updated. Roles saved.`; the new manager or
team member is notified as from the program and team routes. A super-admin
moving someone to another organization must first take them off managing its
projects (422 on `organization_id`: `Take them off managing {names} first.`). The member payload
returned by `POST`, `PATCH` and restore carries `team_ids`, `led_team_ids`,
`managed_project_ids` and `managed_program_ids`.

Deactivating (`is_active: false`) or deleting someone who has a forge account removes them from every connected
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

## Structure *(organogram: super-admins and the organization's active people; designations and reporting lines: its organizational admins and super-admins)*

An organization holds its people with a **designation** from its own list
and **one person each reports to** (their higher authority). The lines
decide who sees whose attendance and work, so only whoever administers the
organization changes them (`WorkspaceAccess::canManageStructure`); everyone
active in it sees the organogram (`canViewOrganogram`): names, designations
and lines, never emails.

**Organogram.** `GET /workspace/organizations/{organization}/organogram`
returns `{ data: { organization, designations, people, can_manage } }`
(`OrganogramQuery`; 403 for anyone outside the organization, 404 for no such
organization):

- `organization`: `{ id, name }`.
- `designations`: most senior first (`rank`, then name), each
  `{ id, organization_id, name, rank, people_count }`; `people_count` counts
  active people who hold it.
- `people`: the organization's active people, by designation rank (people
  without one last), then name, each `{ id, name, designation_id,
  designation, reports_to_id, is_office_admin, direct_reports_count }`.
  `designation` is its name or null. `reports_to_id` is null when they report
  to no one, or to someone who is not an active person of the organization
  (a deactivated higher authority keeps the line, but the person shows at the
  top). `direct_reports_count` counts the active people whose
  `reports_to_id` is them.
- `can_manage`: whether the caller may change designations and lines.

The web page is `GET /workspace/organogram` (`workspace.organogram`, Inertia
`Organogram/Index` with `organogram` and `organizations`): the caller's own
organization; a super-admin picks one with `?organization={id}`
(`organizations` lists them; with none picked, `organogram` is null). Anyone
else's `?organization=` is ignored.

**Designations.** `rank` is 0 to 32767, smaller more senior (default 100 on
create). `name` is required, at most 120 characters, and unique in the
organization whatever its case (422 on `name`: `There is already a
designation called {name}.`). Deleting one leaves its people with no
designation.

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| POST | `/workspace/organizations/{organization}/designations` | `name`, `rank?` | 201, the designation `{ id, organization_id, name, rank }`; message `Added {name}.` |
| PATCH | `/workspace/designations/{designation}` | `name?`, `rank?` (empty keeps it) | the designation; message `Saved {name}.` |
| DELETE | `/workspace/designations/{designation}` | — | `{ message }`: `Removed {name}. The people who held it have no designation now.` |

**Reports to.** `PUT /workspace/members/{member}/reports-to` with
`reports_to_id` (a member id, or `null` for no one; the field must be sent)
returns the member (`MemberResource`, with `designation` and
`reports_to_id`) and `{name} now reports to {boss}.` or `{name} reports to
no one now.`. 403 unless the caller administers the person's organization.
422 on `reports_to_id` when the higher authority is:

- not a current person of the same organization: `Choose someone in the same
  organization.`
- the person: `{name} can't report to themselves.`
- deactivated: `{boss} is deactivated. Choose someone active.`
- already below the person: `{boss} already reports to {name}, so {first
  name} can't report to {boss's first name}.` (e.g. `Sita Sharma already
  reports to Anil Gurung, so Anil can't report to Sita.`)

Changes run one at a time per organization (the organization row is locked)
and write nothing to project activity.

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/workspace/organizations/{organization}/organogram` | — | `{ data: organogram }` |
| PUT | `/workspace/members/{member}/reports-to` | `reports_to_id` (int or null) | the member |
| PUT | `/workspace/members/{member}/designation` | `designation_id` (int or null) | the member |

**Designation.** `PUT /workspace/members/{member}/designation` with
`designation_id` (one of the person's organization's designations, or
`null` for none; the field must be sent) returns the member and `{name} is
now {designation}.` or `{name} has no designation now.`. The same callers as
reports-to (403 otherwise); another organization's or an unknown
designation is a 422 on `designation_id`: `Choose one of the organization's
designations.`

**Person form.** `POST /workspace/members` and `PATCH
/workspace/members/{member}` also take `designation_id` (one of the person's
organization's designations, after any move in the same request; null
clears) and `reports_to_id` (a current person of that organization; null
clears). Left out, each stays as it is. They are applied only for a caller
who may change the organization's structure and silently dropped for anyone
else. `reports_to_id` goes through the same checks as the route above, and a
refusal undoes the whole save (422 on `reports_to_id`). Member payloads
(`POST`, `PATCH`, restore, the People page's `members`) add `designation`
(`{ id, name }` or null) and `reports_to_id`. The People page (`GET
/workspace/team`) adds `canSetStructure` and `designationOptions`
(`[{ id, organization_id, name }]`, most senior first, for the organizations
the caller administers; empty otherwise).

**When people leave.** Deleting a person, or moving them to another
organization, moves the people who reported to them up to that person's own
higher authority (or to no one). Someone moved to another organization also
loses their own higher authority, and a designation of the organization they
left. Deactivating someone keeps every line.

The web twins of the writes (same paths under `/workspace`, names
`workspace.designations.store|update|destroy`, `workspace.members.reports-to`
and `workspace.members.designation`) redirect back with a `workspace_flash`, and
errors on the field.

## Maintenance *(whoever administers the organization)*

`GET /workspace/organizations/{organization}/maintenance` lists what needs
looking at in the organization (403 for anyone who does not administer it).
Every list is empty when all is well:

```json
{
  "data": {
    "organization": { "id": 1, "name": "SDOC" },
    "quiet_days": 30,
    "projects_without_manager": [{ "slug": "gioms", "label": "GIOMS", "manager": null }],
    "projects_without_people": [{ "slug": "gioms", "label": "GIOMS" }],
    "unassigned_tasks": [{ "slug": "sutra", "label": "SuTRA", "count": 3 }],
    "tasks_with_inactive_people": [],
    "quiet_projects": [{ "slug": "sms-gateway", "label": "SMS Gateway", "last_activity_at": "2026-09-21T14:36:10+05:45" }],
    "programs_without_manager": [],
    "people_off_projects": [{ "id": 7, "name": "Lal Bahadur", "designation": "Driver" }],
    "people_without_designation": [],
    "people_reporting_to_inactive": [],
    "people_outside_organogram": [],
    "people_without_login": [],
    "attendance_without_office": false,
    "repositories": 4,
    "repositories_failing_main": [{ "full_name": "sdoc/tp-api", "slug": "tax-portal", "label": "Tax Portal" }],
    "repositories_unprotected": [],
    "repositories_without_tests": []
  }
}
```

- Projects are the organization's active ones. `projects_without_manager`'s
  `manager` is a deactivated manager, or null when there is none;
  `projects_without_people` have no active person on the team.
- `unassigned_tasks`: open tasks nobody holds; `tasks_with_inactive_people`:
  open tasks only deactivated people hold (`count` per project).
- `quiet_projects`: no activity for `quiet_days` (`last_activity_at`, or the
  project's creation when it has none).
- `programs_without_manager`: no manager, or a deactivated one (`manager`).
- People are the organization's active ones: on no active project's team,
  with no designation, reporting to someone deactivated (`reports_to`),
  outside the organogram (reporting to no one, and no one reporting to them),
  with no login.
- `attendance_without_office`: attendance is on, but no office network or
  office place is set.
- `repositories`: how many repositories its active projects link (the
  page's Code part shows only when there are some). Of them:
  `repositories_failing_main`, the latest commit of the default branch
  failed its tests; `repositories_unprotected`, the tracker's rule isn't on
  the default branch yet; `repositories_without_tests`, no commit has run
  any check yet, so nothing can merge there. `slug` and `label` are the
  project's.

The web page is `GET /workspace/maintenance` (name `workspace.maintenance`):
the viewer's organization, and a super-admin picks one with
`?organization=` (anyone else's pick is ignored).

## Attendance: the clock

For an active person of an organization whose attendance is on
(`attendance_enabled` in `GET /user`, the web's `clock` prop being set); anyone else gets 403 (a deactivated person 401, as on every route).
A day is one sequence: `in`, then optionally `lunch_start` and `lunch_end`
once, then `out`; each kind at most once a day. The time is always the
server's (Asia/Kathmandu), never the client's. Each punch keeps the address
the request came from, the location the device reported at that moment (or
none) and a place verdict: `office_network` (the address is in one of the
organization's office networks), `near_office` (the location is within an
office place's radius, allowing for the reading's accuracy up to one more
radius), `outside` (a location, not near) or `unknown` (no location, not on
the office network). The nearest office place and the distance to it are
kept whenever there is a location. Location is sent only with a press,
never in between.

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/workspace/attendance/me` | — | `{ data: { today, punches, next, state } }`. `today`: `{ date, is_workday, holiday, expects_work, start, end, late_after }` (`HH:MM`, the season's hours when one applies; `holiday` its name or `null`). `punches`: today's, in order, each a Punch. `next`: the kinds allowed now (`[]` after clocking out). `state`: `not_in`, `in`, `at_lunch` or `out` |
| POST | `/workspace/attendance/punches` | `kind` (`in`, `lunch_start`, `lunch_end`, `out`), optional `latitude` and `longitude` (both or neither), `accuracy_m` (metres), `note` (max 500) | 201 `{ message, data: { punch, next } }`; `message` is `Clocked in at 9:52.` (`Lunch started at …`, `Lunch ended at …`, `Clocked out at …`). Source `app` (the web's own punches are `web`). 422 on `kind` for a kind out of order: `Clock in first.`, `Start lunch first.`, `End lunch first.`, `You've already clocked in today.` (`started lunch`, `ended lunch`), `You've already clocked out today.`, and `Already done.` for a double press. 422 on `note` with `Say why you're away from the office.` when the verdict is `outside` or `unknown` and there is no note: send the same press again with a `note` |

**Punch**: `{ id, kind, at, work_date, source, place, place_name, distance_m, note }`.
`at` is ISO 8601 with the server's offset (`2026-11-02T09:52:10+05:45`);
`place_name` is the nearest office place (or `null`), `distance_m` the
metres to it (or `null` without a location).

On the web, `POST /workspace/attendance/punches` (name
`workspace.attendance.punches.store`) takes the same body and redirects
back with the flash message, or with the same errors; every workspace page
shares the `me` data as the Inertia prop `attendance` (`null` when the user
can't punch), which the top bar's clock button reads.

## Attendance settings

For whoever administers the organization (its organizational admins,
super-admins); others get 403. Turning attendance on or off is for
super-admins only. A place, network or holiday in the path must belong to
the organization in the path, else 403.

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/workspace/organizations/{organization}/attendance/settings` | — | `{ data: { organization { id, name }, settings, places, networks, holidays } }`. `settings`: `{ enabled, enabled_at, enabled_by, workdays, start_time, end_time, seasons, grace_minutes }` (`enabled_by` the name of whoever last turned it on; an organization never turned on shows the defaults, off). `places`: `[{ id, name, latitude, longitude, radius_m }]` by name. `networks`: `[{ id, label, cidr }]` by label. `holidays`: `[{ id, date, name }]` from the start of this year, by date |
| PUT | `/workspace/organizations/{organization}/attendance` | `enabled` (bool) | super-admins only. `{ message, data: settings }`. The first time makes the settings with the defaults (Monday to Friday, 10:00 to 17:00, winter 10:00 to 16:00 from 11-02 to 01-29, 15 minutes' grace); turning it on records who and when; turning it off keeps every punch |
| PUT | `/workspace/organizations/{organization}/attendance/settings` | `workdays` (ISO weekdays 1 to 7, at least one), `start_time`, `end_time` (`HH:MM`, start before end), `seasons` (a list, may be empty: `[{ name, from, to, start, end }]`, `from`/`to` a real `MM-DD`, a season may wrap the year end, `start` before `end`), `grace_minutes` (0 to 120) | `{ message, data: settings }`. Works before attendance is on (the hours are ready for then) |
| POST | `/workspace/organizations/{organization}/attendance/places` | `name`, `latitude` (-90 to 90), `longitude` (-180 to 180), `radius_m` (20 to 5000) | 201 `{ message, data: place }` |
| PATCH | `/workspace/organizations/{organization}/attendance/places/{place}` | same | `{ message, data: place }` |
| DELETE | `/workspace/organizations/{organization}/attendance/places/{place}` | — | `{ message }` (punches already judged keep their verdict) |
| POST | `/workspace/organizations/{organization}/attendance/networks` | `label`, `cidr`: an IPv4 or IPv6 address or range (`203.0.113.0/24`, `2001:db8:20::/48`, `203.0.113.7`); a range wider than /8 (IPv4) or /16 (IPv6) is refused. Never the VPN's range | 201 `{ message, data: network }` |
| PATCH | `/workspace/organizations/{organization}/attendance/networks/{network}` | same | `{ message, data: network }` |
| DELETE | `/workspace/organizations/{organization}/attendance/networks/{network}` | — | `{ message }` |
| POST | `/workspace/organizations/{organization}/attendance/holidays` | `date` (`Y-m-d`), `name` | 201 `{ message, data: holiday }`; 422 on `date` with `That date is already a holiday.` for a date the organization already has |
| PATCH | `/workspace/organizations/{organization}/attendance/holidays/{holiday}` | same | `{ message, data: holiday }` |
| DELETE | `/workspace/organizations/{organization}/attendance/holidays/{holiday}` | — | `{ message }` |

On the JSON API these are `/api/v1/workspace/...` (names
`api.workspace.attendance.me`, `api.workspace.attendance.punches.store`,
`api.workspace.organizations.attendance.*`). On the web, the writes are the
same paths under `/workspace/...` (names `workspace.organizations.attendance.*`)
and redirect back with a flash; `GET /workspace/attendance/settings` (name
`workspace.attendance.settings`) is the Inertia page `Attendance/Settings`,
for the viewer's own organization or, for a super-admin, `?organization=<id>`
(default: the first by name). Its props are `data`'s fields plus
`canEnable` (super-admin), `organizations` (`[{ id, name }]`, the
super-admin's switcher, else `[]`) and `yourAddress` (the address the
request came from, to add the office's range).

`GET /user` gains `attendance_enabled`: the caller may clock in (an active
person of an organization whose attendance is on).

## Attendance register *(attendance on; its organizational admins, super-admins, and anyone with people below them, who see only those people)*

One day of an organization: who should be in, who is, who came late, who
is on leave or kaaj. The people are those the caller sees
(`PeopleInViewQuery`: everyone for its admins and super-admins, else the
people below the caller), active only, people exempt from attendance left
out.
`under` narrows to one person and everyone below them.

- `date` (`Y-m-d`, default today; 422 otherwise).
- `under`: a member id of that organization the caller may see (themselves
  or someone below them; anyone for its admins). Someone not below the
  caller, of another organization, or no one is a 403.
- The organization is the path's; attendance off, another organization, or
  a caller with nobody below them there is a 403.

A person's `state` comes from their punches first (`in`, `at_lunch` after
starting lunch, `out` after clocking out); with none: `off` on a day that
expects no work (not a working day, or a holiday), `on_kaaj` or
`on_leave` under approved leave or kaaj covering the day, else `not_in`.
Someone who comes in despite their leave is `in`, counts as present and as
expected. `late` is a clock-in after `late_after` on a day that expects
work.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/workspace/organizations/{organization}/attendance` | `{ data: { date, schedule, under, summary, people, options }, filters { date, organization, under } }`. `under`: `{ id, name }` of the person narrowed to, or null; `options`: the list reports' options (see Work reports), organizations with attendance on only. `schedule`: `{ date, is_workday, holiday, expects_work, start, end, late_after }` (HH:MM, seasonal hours applied). `summary`: `{ staff, on_leave, on_kaaj, expected, present, late, not_in, outside_office, percentage }`: `staff` the people listed, `expected` staff minus those on leave or kaaj (0 on a day that expects no work), `present` those who clocked in, `not_in` those expected and not in, `outside_office` clock-ins outside the office or from an unknown place, `percentage` present of expected, rounded (`null` when nobody is expected). `people`, by name: `{ member { id, name, designation }, state, in_at, lunch_start_at, lunch_end_at, out_at, late, place, distance_m, note, leave }`, times ISO-8601 or `null`; `place`, `distance_m` and `note` are the clock-in's (`office_network`, `near_office`, `outside`, `unknown`); `leave` `{ kind, place, starts_on, ends_on }` or `null`. `teams`: the people in view with someone in view reporting to them, `{ id, name, designation }`, for an `under` filter |
| GET | `/workspace/organizations/{organization}/attendance/summary` | `{ data: [{ member { id, name, designation }, days_expected, days_present, days_late, minutes_worked, no_clock_out_days, outside_punches, leave_days, kaaj_days, usual_in }], filters { from, to, organization, under } }`, one row per person as above. `from`, `to` (`Y-m-d`; default the last 7 days ending today; at most 92 days, 422 on `to` otherwise) |

The totals count days up to today only, and working days only while the
organization has attendance on (from the day it was turned on):
`days_expected` working days, less those under approved leave or kaaj
without a clock-in (people exempt from attendance have no totals); `days_present` and `days_late`
working days with a clock-in (after `late_after`); `leave_days` and
`kaaj_days` working days under approved leave or kaaj without a clock-in;
`minutes_worked` every day with a clock-in, clock-in to clock-out less lunch
(an unfinished lunch runs to the clock-out; a past day without a clock-out
runs to the scheduled end and counts in `no_clock_out_days`, today runs to
now); `outside_punches` punches of any kind outside the office or from an
unknown place; `usual_in` the median clock-in, `HH:MM`, or `null`.

On the web, `/workspace/attendance` (name `workspace.attendance`) is the
Inertia page `Attendance/Register`, whose props are `data`'s fields plus
`filters` and `organizations` (`[{ id, name }]`, the organizations with
attendance on, for super-admins; empty for everyone else). There the
organization is `?organization=<id>`, else the caller's own, else (a
super-admin) the first with attendance on by name.

## Leave and kaaj

Leave, or kaaj (official duty away from the office), over whole days.
Approved leave and kaaj take a person out of the expected count; kaaj is
duty, never absence.

- **Ask** for oneself: an active member of an organization with attendance
  on (403 otherwise). The request is `pending`.
- **Approve or reject**: whoever oversees the person (anyone above them,
  any number of levels, while active; their organization's admins;
  super-admins), never the person themselves (403). A request no longer
  pending is a 422.
- **Cancel**: only the person it is for (403 otherwise), while it is
  `pending`, or `approved` and starting after today (422 otherwise).
- **Record** for someone: whoever oversees an active person of an
  organization with attendance on (403 otherwise; never oneself). It is
  `approved` at once, the recorder its decider.

Asking and recording take `kind` (`leave` or `kaaj`), `starts_on`,
`ends_on` (`Y-m-d`, not before `starts_on`, at most 90 days), `reason`
(up to 500 characters) and, for kaaj, `place` (required). Days overlapping
the person's own pending or approved leave or kaaj are a 422 on
`starts_on` (`You already have leave or kaaj on some of these days.`).

A request: `{ id, kind, starts_on, ends_on, days, reason, place, status, can_cancel, member { id, name, designation }, decided_by { id, name }, decided_at, decision_note, created_at }`; `status` is `pending`, `approved`, `rejected` or `cancelled`; `member` is left out of `mine`; `decided_by` is `null` for a super-admin without a member.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/workspace/leave` | `{ data: { can_ask, mine, to_decide, can_record_for } }`: `can_ask` whether the caller may ask; `mine` their requests, upcoming and those ending in the last 60 days, newest first; `to_decide` the pending requests of the people they oversee (every organization for a super-admin), soonest first, never their own; `can_record_for` the people they may record leave for, `[{ id, name, designation, organization }]` (organization name), by organization and name |
| POST | `/workspace/leave` | 201 `{ message, data: request }` (`Leave requested.` / `Kaaj requested.`) |
| POST | `/workspace/leave/{leaveRequest}/approve` | `{ message: "Approved.", data: request }`. Optional `note` (up to 500 characters) |
| POST | `/workspace/leave/{leaveRequest}/reject` | `{ message: "Rejected.", data: request }`. Optional `note` |
| POST | `/workspace/leave/{leaveRequest}/cancel` | `{ message: "Cancelled.", data: request }` |
| POST | `/workspace/members/{member}/leave` | 201 `{ message, data: request }` (`Leave recorded for {name}.`) |

On the web, `/workspace/leave` (name `workspace.leave`) is the Inertia page
`Attendance/Leave`, whose props are `data`'s fields; the writes are the
same paths (names `workspace.leave.store`, `workspace.leave.approve`,
`workspace.leave.reject`, `workspace.leave.cancel`,
`workspace.members.leave.store`) and redirect back with a flash (and field
errors on a 422).

## Work reports *(a person: themselves and everyone above them; lists: an organization's admins, super-admins, and anyone with people below them, who see only those people)*

What people got done: the tasks they finished and started, their commits and
the pull requests they merged, for a day or a range of days. One read,
`App\Queries\Work\WorkActivityQuery`, serves all three reports; the people
in a list come from `App\Queries\Structure\PeopleInViewQuery` (everyone in
the organization for its admins and super-admins, otherwise the people below
the caller; active people only, by name).

How things count:

- **Finished / started**: a task counts for whoever changed its status (the
  activity log's actor), on the day they did it. Finished = it entered a
  closed status (`done` or `failed`, and the item says which); started = it
  entered a status flagged `is_started` (`in_progress`). A task counts once
  per list in a range, as its latest change. Trashed tasks count nowhere.
- **Commits**: those matched to the person's login (`git_commits.user_id`),
  not merges, still reachable, by `committed_at`. Lines are the classified
  code and comment lines (see Code activity).
- **Pull requests merged**: `merged_at` in the range, author = the person's
  `git_username`, in any case.
- **Overdue** (a person's week and a team's week): tasks assigned to the
  person that are overdue now (not closed, past their deadline), in projects
  not archived. It does not depend on the range.
- **Counted, never named**: tasks of projects the caller can't open, and
  commits and pull requests in repositories whose code the caller can't see,
  are counted (`hidden_*`) but never named.
- People are always listed by name, never ranked by a figure.
- Days are local days (`Asia/Kathmandu`).
- `attendance` is the person's attendance (a day: `{ state, in_at, late,
  place }`; a range: `{ days_expected, days_present, days_late,
  minutes_worked, no_clock_out_days, outside_punches, leave_days, kaaj_days,
  usual_in }`), or `null` while the organization's attendance is off.

Filters:

- `date` (`YYYY-MM-DD`, today unless given) for a day; `from` and `to`
  (`YYYY-MM-DD`) for a range, this week (Monday to today) unless given, at
  most 92 days, `to` not before `from` (422 on `to`).
- `under`: a member id; narrows a list to that person and everyone below
  them. They must be in the organization and the caller must be allowed to
  see them (themselves or someone below them, or anyone there for its
  admins); otherwise, or when no such person exists, 403.

Shapes used below:

- task item: `{ id, code, title, project, project_slug, slug, status, at }`
  (`code` `KEY-12`; `project` the project's label, "General (Revenue)";
  `status` the status entered; `at` when, ISO-8601). Newest first.
- overdue item: `{ id, code, title, project, project_slug, slug, status,
  deadline_at }`, soonest deadline first.
- code: `{ count, code_added, code_removed, comment_added, comment_removed,
  repositories: [{ name, count }], hidden_commits }` (`count` and the lines
  include `hidden_commits`; `repositories` only those the caller may see,
  most commits first).
- pull request: `{ title, number, repository, url, merged_at }`, newest
  first.
- member: `{ id, name, designation }`.
- options: `{ organizations: [{ id, name }], teams: [{ id, name,
  designation }] }`: `organizations` lists every organization for a
  super-admin (empty for anyone else); `teams` the people in the caller's
  view whom someone else in view reports to, for `under`. The attendance
  register answers the same shape.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/workspace/organizations/{organization}/work/day?date=&under=` | `{ data: { date, organization_id, under, summary, people, options }, filters { date, organization, under } }`. `summary`: `{ people, tasks_closed, tasks_started, commits, committers, pull_requests_merged, nothing_recorded }` (hidden ones included; `committers` the people with a commit; `nothing_recorded` the people with no task finished or started, no commit and no pull request merged). `people`: one row per person, by name: `{ member, attendance, closed: [task item], hidden_closed, started: [task item], hidden_started, commits: code, pull_requests_merged: [pull request], hidden_pull_requests, nothing_recorded }`. `under`: `{ id, name }` or `null`. Allowed to the organization's admins, super-admins and anyone with people below them there (403 otherwise; an unknown organization 404) |
| GET | `/workspace/organizations/{organization}/work/team?from=&to=&under=` | `{ data: { range { from, to }, organization_id, under, people, options }, filters { from, to, organization, under } }`. `people`: one row per person, by name: `{ member, attendance, tasks_closed, tasks_started, overdue, commits, code_added, code_removed, pull_requests_merged }` (counts, hidden ones included). Same access as the day |
| GET | `/workspace/members/{member}/work?from=&to=` | `{ data: { member { id, name, designation, organization_id, reports_to { id, name } \| null }, range { from, to }, attendance, tasks { closed: [task item], started: [task item], overdue: [overdue item], counts { closed, started, overdue, hidden_closed, hidden_started, hidden_overdue } }, code, pull_requests_merged: [pull request], hidden_pull_requests }, filters { from, to } }` (`counts` include the hidden ones). For the person themselves, everyone above them in the organization, its admins and super-admins; 403 for anyone else (a peer, someone below, another organization). A deactivated caller's token answers 401 on all three |

On the JSON API these are `/api/v1/workspace/…` (names
`api.workspace.organizations.work.day`, `api.workspace.organizations.work.team`
and `api.workspace.members.work`). On the web, `/workspace/work` (name
`workspace.work`, page `Work/Day`), `/workspace/work/team` (name
`workspace.work.team`, page `Work/Team`) and `/workspace/people/{member}/work`
(name `workspace.people.work`, page `Work/Person`) take the same filters plus
`?organization=<id>` on the first two (the caller's own organization unless
given; a super-admin with none opens the first by name; an organization that
does not exist is a 422 on `organization`). Each page's props are `data`'s
fields plus `filters`.

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
BASE=https://projects.example.com/api/v1
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

**Runners, protected main and test results.**

- A repository payload adds `protects_main` (now for every linked
  repository: the access sync puts the rule on each default branch) and
  `main_checks`; a pull request adds `checks`, as do a task's `code.commits`
  and the code activity report's commits. The report's people add
  `checks_passed` and `checks_failed`. See Checks under Code repositories.
- The rule changed: nobody pushes to the default branch, maintainers and
  Owners included; a pull request needs one approval and every check
  passed. A new repository starts with a README on `main`. The message when
  the forge refuses the rule is now `Created and linked {owner}/{name}, but
  main couldn't be protected yet; the tracker keeps trying.`
- Maintenance adds `repositories`, `repositories_failing_main`,
  `repositories_unprotected` and `repositories_without_tests`, counted in
  `maintenance_count`.

**Projects may have no manager; the maintenance list.**

- An organizational admin or super-admin can take a project's manager off:
  `DELETE /workspace/teams/{team}/members/{member}` and `role: member` on the
  team route now succeed for the manager (403 for anyone else), and leaving
  a managed project out of `managed_project_ids` leaves it manager-less. The
  project page's `team` adds `can_remove_manager`.
- New: `GET /workspace/organizations/{organization}/maintenance` (see
  Maintenance); `/user`'s `office` adds `maintenance` and
  `maintenance_count` (how many rows the maintenance page lists for the
  organization it opens on: each project, program and person once, and
  attendance; cached up to five minutes, refreshed when the page or the
  endpoint is read; 0 when it is not offered).
- The code activity report adds `repositories`.

**Designations replace titles.**

- People have no `title` any more: members no longer take or return one, and
  every place that named a person with their title (team and repository
  access candidates, maintainers and grants, progress reports, the code
  activity report, program and project managers, `/user`'s `member`) gives
  `designation` (its name, or null) instead. Titles people had became
  designations of their organization.
- New: `PUT /workspace/members/{member}/designation` (see Structure).

**Structure, attendance and work reports.**

- The attendance register's `teams` became `options { organizations, teams }`
  (the work reports' shape) and it names the person narrowed to as `under`;
  people exempt from attendance get no totals from the summary.
- New: `GET /workspace/organizations/{organization}/organogram`,
  `POST /workspace/organizations/{organization}/designations`,
  `PATCH|DELETE /workspace/designations/{designation}`,
  `PUT /workspace/members/{member}/reports-to`; the web page
  `GET /workspace/organogram`.
- Members: `designation_id`, `reports_to_id` and `attendance_exempt` on create
  and edit (organizational admins and super-admins only; silently dropped
  for anyone else); member payloads add `designation`, `reports_to_id` and
  `attendance_exempt`.
- New: the clock (`GET /workspace/attendance/me`, `POST /workspace/attendance/punches`) and attendance settings (see Attendance: the clock, Attendance settings).
- New: `GET /workspace/organizations/{organization}/attendance` and
  `GET …/attendance/summary` (see Attendance register).
- New: `GET|POST /workspace/leave`, `POST /workspace/leave/{leaveRequest}/approve|reject|cancel`
  and `POST /workspace/members/{member}/leave` (see Leave and kaaj).
- `/user` adds `attendance_enabled` (the person may clock in) and `office`
  (which office pages they may open: `organogram`, `attendance`,
  `attendance_settings`, `leave`, `team_work`, `my_work_member_id`).
- New: the work reports, `GET /workspace/organizations/{organization}/work/day`,
  `GET /workspace/organizations/{organization}/work/team` and
  `GET /workspace/members/{member}/work` (see Work reports).

**Quick add that always works.**

- `POST /workspace/quick-add` without `project_id` answers 422
  `Pick a project.`; it accepts `start_general: true` instead (see Feeds &
  overview).
- Assigning (quick add and `POST /workspace/tasks/{task}/assignments`) may
  bring a person of the project's organization onto its team first, for
  whoever runs it (see Assignments).
- The task hub adds `joinCandidates`, the task preview `join_candidates`.

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

**Git phase 2 (repositories, access and code reports).**

- New: `POST /workspace/projects/{slug}/repositories/new` (see Code
  repositories).
- New: `GET|POST /workspace/projects/{slug}/repositories/{repository}/access`,
  `GET …/access/candidates` and `PATCH|DELETE …/access/{grant}` (see
  Repository access).
- Unlinking says that people keep their access on the forge (see Code
  repositories).
- New: `GET /workspace/reports/code` and
  `GET /workspace/reports/code/people/{user}` (see Code activity).
