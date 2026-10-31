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
- options: `{ organizations: [{ id, name }], teams: [{ id, name }] }`:
  `organizations` lists every organization for a super-admin (empty for
  anyone else); `teams` the people in the caller's view whom someone else in
  view reports to, for `under`.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/workspace/organizations/{organization}/work/day?date=&under=` | `{ data: { date, organization_id, under, summary, people, options }, filters { organization, date, under } }`. `summary`: `{ people, tasks_closed, tasks_started, commits, committers, pull_requests_merged, nothing_recorded }` (hidden ones included; `committers` the people with a commit; `nothing_recorded` the people with no task finished or started, no commit and no pull request merged). `people`: one row per person, by name: `{ member, attendance, closed: [task item], hidden_closed, started: [task item], hidden_started, commits: code, pull_requests_merged: [pull request], hidden_pull_requests, nothing_recorded }`. `under`: `{ id, name }` or `null`. Allowed to the organization's admins, super-admins and anyone with people below them there (403 otherwise; an unknown organization 404) |
| GET | `/workspace/organizations/{organization}/work/team?from=&to=&under=` | `{ data: { range { from, to }, organization_id, under, people, options }, filters { organization, from, to, under } }`. `people`: one row per person, by name: `{ member, attendance, tasks_closed, tasks_started, overdue, commits, code_added, code_removed, pull_requests_merged }` (counts, hidden ones included). Same access as the day |
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
