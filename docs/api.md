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
  (super-admin → office admin → project manager → member). Clients mirror role
  gating in the UI only.
- **Projects and teams:** every project has exactly one team of its own, made
  with it, and that team's one leader is the project's **manager**. People on
  several projects sit on several project teams.
- **Project visibility:** a project is visible to a user iff it is public within
  their organization, OR the user is on its team, OR they are its organization's
  office admin, OR a super-admin.
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
| GET | `/user` | — | the caller's workspace context: `{ id, name, email, member, is_super_admin, led_team_ids }` |

## Appearance, preferences & themes

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/themes` | — | `{ data: { themes, font_allow_list } }` from `config/themes.php` |
| GET | `/user/preferences` | — | `{ data: { theme, font_override, email_notifications, configured, resolved_tokens } }` |
| PATCH | `/user/preferences` | `theme?`, `font_override?`, `email_notifications?` | same shape as GET |

The web app exposes a session twin at `PATCH /workspace/preferences`.

## Notifications (in-app inbox)

Top-level under `/api/v1` (not the `workspace` prefix). In-app only — email/push
are deferred.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/notifications?page=` | paginated `[Notification]`, newest first (`data` + `meta` + `links`), plus `counts` (all / unread / per kind) and the echoed `filters`. `?scope=unread` and `?type=assigned\|status\|mention\|deadline` narrow the list; `counts` always describe the whole inbox |
| GET | `/notifications/unread-count` | `{ data: { count } }` |
| POST | `/notifications/{id}/read` | `{ message }` (sets `read_at`) |
| POST | `/notifications/read-all` | `{ message }` |

Each notification's `data` payload is `{ kind, title, body, task, actor, url }`.

## Feeds & overview (`/workspace/*`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/home` | the overview: headline `stats`, `status_breakdown`, per-project `projects` rollup, `recent_activity`, plus open work bucketed by due date (`overdue`, `today`, `week`, `later`, `unscheduled`) and `recently_done`. `?scope=mine\|all` scopes every field together — defaults to `mine` when the caller has open assignments, `all` otherwise |
| GET | `/workspace/dashboard` | per-project rollups, status breakdown, recent activity |
| GET | `/workspace/my` | the caller's focused tasks, open todos, assigned work |
| GET | `/workspace/plan-tracker` | the 100-point plan tracker (`config/government.php`) |
| GET | `/workspace/search?q=` | task search across active projects |
| POST | `/workspace/quick-add` | `{ text }` natural-language task capture; optional `depends_on_task_ids: int[]` (tasks of other projects are ignored) |

## Projects

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/projects` | list — visibility-scoped. `?archived=1` for archived |
| GET | `/workspace/projects` | each project also carries `manager { id, name, title }`, `team { id, is_cross_organization, members_count }`, `percent_complete`, `open_tasks_count`, `overdue_tasks_count`, `next_deadline_at`; plus `managerCandidates` for the create form |
| POST | `/workspace/projects` | create (office admin or super-admin). `manager_id` (required: an active person of the project's organization) becomes the leader of the project's new team; `is_public: bool` (public means everyone in its organization). A super-admin sends `organization_id`; otherwise it is the office admin's organization |
| GET | `/workspace/projects/{slug}` | show (board + tasks) — **403** if not visible to the caller |
| GET | `/workspace/projects/{slug}/progress` | `{ data: { project, members, tasks, activity, generated_at } }`: everyone's workload on it, its tasks for a Gantt, recent activity. For its manager, its office admin and super-admins (`project.can_view_progress`) |
| PATCH | `/workspace/projects/{slug}` | update (its manager, office admin or super-admin). `manager_id` (office admin / super-admin; the old manager stays on the team), `is_public` (office admin / super-admin), `is_cross_organization` (super-admin; refused while outsiders are on the team); dropped silently for anyone else. The team keeps the project's title |
| PATCH | `/workspace/projects/{slug}/archive` | soft-archive (its manager, its office admin, or a super-admin) |
| PATCH | `/workspace/projects/{slug}/restore` | un-archive |

## Tasks

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/workspace/projects/{slug}/tasks` | create |
| POST | `/workspace/projects/{slug}/tasks/reorder` | persist board ordering |
| GET | `/workspace/projects/{slug}/tasks/{taskSlug}` | task hub (incl. `comments`) |
| PATCH | `/workspace/projects/{slug}/tasks/{taskSlug}` | update (status, fields) |
| DELETE | `/workspace/projects/{slug}/tasks/{taskSlug}` | soft-delete |
| POST | `/workspace/projects/{slug}/tasks/{taskSlug}/restore` | undo delete |
| GET | `/workspace/tasks/{task}/preview` | lightweight peek (incl. `comments_count`, and `dependency_candidates`: the project's other tasks) |

Task statuses come from `config/project-management.php` (`not_started`,
`unclear`, `in_progress`, `done`, `failed`); `done` is the only complete status.

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
progress, an office admin their whole organization, a super-admin everything.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/organizations` | `{ organizations }` with counts and office admins |
| POST | `/workspace/organizations` | `name` (unique) |
| PATCH | `/workspace/organizations/{organization}` | `name` |
| DELETE | `/workspace/organizations/{organization}` | 422 unless it has no people, teams or projects |

## Project teams *(roster: the project's manager, its office admin or a super-admin; choosing the manager: office admin or super-admin)*

A team is made with its project and never on its own; `GET /workspace/projects/{slug}`
returns it as `team { id, is_cross_organization, members: [{ id, name, email, title, organization, is_active, is_manager, can_remove }], can_manage, can_choose_manager }`.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/team` | the People directory: `{ teams (each with its project and roster), members, organizations, canCreateMember }`; super-admin may add `?organization=` |
| GET | `/workspace/teams/{team}/candidates?q=` | up to 8 people who can join (min 2 characters): its organization, or anyone for a cross-organization team |
| POST | `/workspace/teams/{team}/members` | `member_id` (an existing person; 422 if they can't join) |
| DELETE | `/workspace/teams/{team}/members/{member}` | 422 for the manager: make someone else manager first |
| PATCH | `/workspace/teams/{team}/members/{member}` | `role: leader` makes them the project's manager (the old one stays as a member); `role: member` on the manager is a 422 |

## Members *(create/edit/delete: office admin of their organization or super-admin)*

`POST /workspace/members` takes `name`, `email` (required, not already a login),
optional `password` (min 8), `title` and `team_ids`. A super-admin also sends
`organization_id` (required) and may send `is_office_admin`; an office admin's
people always join their own organization. Every member gets a login: the
password you send, or a generated one if you send none. Either way the member is
emailed their email and password; if that email fails the member is still
created and `message` says so. `PATCH` accepts `organization_id` and
`is_office_admin` from a super-admin only.

| Method | Path |
| --- | --- |
| POST | `/workspace/members` |
| PATCH | `/workspace/members/{member}` |
| DELETE | `/workspace/members/{member}` |
| POST | `/workspace/members/{member}/restore` |

## Task dependencies *(anyone who can see the task's project)*

Finish-to-start, within one project: the task cannot start until the other is
finished. Loops are refused. Task payloads carry `dependencies` and `dependents`
(each `{ id, slug, title, item_number, status, status_label, is_complete,
is_late, deadline_at, completed_at }`), and the project progress payload gives
each task `depends_on: int[]`.

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
