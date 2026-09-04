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
| GET | `/notifications?page=` | paginated `[Notification]`, newest first (`data` + `meta` + `links`) |
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
| POST | `/workspace/projects` | create. `team_ids: int[]` (required & non-empty **unless** `is_public`; non-super-admins may only attach teams they lead) and `is_public: bool` (super-admin only — ignored from others) |
| GET | `/workspace/projects/{slug}` | show (board + tasks) — **403** if not visible to the caller |
| PATCH | `/workspace/projects/{slug}` | update. Same `team_ids` / `is_public` rules as create |
| PATCH | `/workspace/projects/{slug}/archive` | soft-archive (leader of an attached team / super-admin) |
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

## Teams *(roster: leader/super-admin; rename + reassignment: super-admin only)*

| Method | Path |
| --- | --- |
| GET | `/workspace/team` |
| POST | `/workspace/teams` |
| PATCH | `/workspace/teams/{team}` |
| DELETE | `/workspace/teams/{team}` |
| POST | `/workspace/teams/{team}/restore` |
| POST | `/workspace/teams/{team}/members` |
| DELETE | `/workspace/teams/{team}/members/{member}` |
| PATCH | `/workspace/teams/{team}/members/{member}` (role) |

## Members

| Method | Path |
| --- | --- |
| POST | `/workspace/members` |
| PATCH | `/workspace/members/{member}` |
| DELETE | `/workspace/members/{member}` |
| POST | `/workspace/members/{member}/restore` |

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
