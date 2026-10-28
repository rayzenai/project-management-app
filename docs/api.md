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
  (three tiers: super-admin → team-leader → member). Clients mirror role gating
  in the UI only.
- **Project visibility:** a project is visible to a user iff `is_public` OR the
  user's member is in a team attached to the project OR the user is a super-admin.
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
| POST | `/workspace/quick-add` | `{ text }` natural-language task capture |

## Projects

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/projects` | list — visibility-scoped. `?archived=1` for archived |
| POST | `/workspace/projects` | create. `team_ids: int[]` (required & non-empty **unless** `is_public`; all from the project's organization; leaders may only attach teams they lead) and `is_public: bool` (office admin / super-admin; public means everyone in its organization, ignored from others). A super-admin sends `organization_id`; otherwise it is the office admin's organization or that of the leader's teams |
| GET | `/workspace/projects/{slug}` | show (board + tasks) — **403** if not visible to the caller |
| PATCH | `/workspace/projects/{slug}` | update. Same `team_ids` / `is_public` rules as create |
| PATCH | `/workspace/projects/{slug}/archive` | soft-archive (leader of an attached team, its office admin, or a super-admin) |
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
| GET | `/workspace/tasks/{task}/preview` | lightweight peek (incl. `comments_count`) |

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

Every team, project and member (except super-admins) belongs to one
organization. Everything below is scoped to what the caller may see: a member
sees their teams and teammates, a leader the same plus their teams' rosters and
progress, an office admin their whole organization, a super-admin everything.

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/organizations` | `{ organizations }` with counts and office admins |
| POST | `/workspace/organizations` | `name` (unique) |
| PATCH | `/workspace/organizations/{organization}` | `name` |
| DELETE | `/workspace/organizations/{organization}` | 422 unless it has no people, teams or projects |

## Teams *(create/edit/delete/leaders: office admin of its organization or super-admin; roster: also its leaders)*

| Method | Path | Body / notes |
| --- | --- | --- |
| GET | `/workspace/team` | `{ teams (each with roster), members, organizations, canCreateTeam, canCreateMember }`; super-admin may add `?organization=` |
| POST | `/workspace/teams` | `name`, `description`; super-admin also `organization_id` (required), `is_cross_organization` |
| PATCH | `/workspace/teams/{team}` | `name`, `description`; `is_cross_organization` (super-admin only; refused while outsiders are on it) |
| DELETE | `/workspace/teams/{team}` | |
| POST | `/workspace/teams/{team}/restore` | |
| GET | `/workspace/teams/{team}/candidates?q=` | up to 8 people who can join (min 2 characters): its organization, or anyone for a cross-organization team |
| POST | `/workspace/teams/{team}/members` | `member_id` (an existing person; 422 if they can't join) |
| DELETE | `/workspace/teams/{team}/members/{member}` | leaders can't remove another leader |
| PATCH | `/workspace/teams/{team}/members/{member}` | `role`: `member` or `leader` (office admin / super-admin) |
| GET | `/workspace/teams/{team}/progress` | `{ data: { team, members, tasks, activity, generated_at } }` for its leaders, office admin and super-admins |

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
is_late, deadline_at, completed_at }`), and the team progress payload gives each
task `depends_on: int[]`.

| Method | Path | Body / notes |
| --- | --- | --- |
| POST | `/workspace/tasks/{task}/dependencies` | `depends_on_task_id`; 422 for itself, another project, a duplicate or a loop; answers with the task |
| DELETE | `/workspace/tasks/{task}/dependencies/{dependsOn}` | 404 if it was not a dependency |

## Integrating another system

Any system that can send HTTPS requests can drive the workspace through the JSON
API above. It acts **as a user**, so authorization follows that user's role: give
it a dedicated service account (a normal user, created via the seeder or the
member UI) and add that account to the teams whose projects it should touch, or
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
