# Structure, attendance and work reports: design

Date: 2026-10-31. Owner: SDOC's Executive Director (a super-admin).

## Intent

At 11 in the morning the ED opens the tracker and sees who has arrived and
the attendance percentage, because the tracker knows who should have
arrived. Every organization holds its people with a designation and one
higher authority each, drawn as an organogram. Staff clock in, start lunch,
end lunch and clock out from the tracker (and soon a Flutter app, later a
fingerprint or face machine); each punch keeps the network address and the
location, so a punch says where it came from. The ED, and everyone above a
person, sees that person's work: tasks closed and started, commits, pull
requests, hours at work, for a day or a week.

The owner's standing rule for this work: every read lives in one query
class and every write in one service, so a future API (or the Flutter app)
only calls them; no controller, page or job writes its own query.

## What the owner decided

- Organization structure as proposed: a designation per person from the
  organization's own list, one "reports to" per person, an organogram you
  edit by dragging or by picking "Reports to" on the person's form.
- SDOC works Monday to Friday; winter hours 10:00 to 16:00 from 2 November.
- No fingerprint or face machine yet: attendance through the tracker now, a
  Flutter app soon, a machine later that must plug in.
- All three parts get built (structure, attendance, work), plus a "today's
  work" report: commits, tasks closed, tasks started.

## Calls made while the owner was away (each can change)

1. Screens: the 11 am screen is the register (mockup 1A); the organogram
   is the outline with drag handles plus a printable chart view (2B); the
   clock is one top-bar button on every page, phone included (3A); work has
   both one person's week (4A) and a team's week (4B); today's work is by
   person (5A).
2. Hours: default 10:00 to 17:00, winter 10:00 to 16:00 from 2 November to
   29 January (Magh 15, as in government offices), 15 minutes' grace before
   "late". All editable per organization.
3. Expected today = active people of the organization who aren't exempt, on
   a working day that isn't a holiday, minus approved leave and kaaj. Kaaj
   is duty, shown apart, never counted absent.
4. The structure decides who sees whose attendance and work: the person,
   everyone above them, the organization's admins and super-admins.
5. Only organizational admins and super-admins change designations and
   reporting lines (otherwise someone could make themselves another
   person's boss and see their location). Everyone active in the
   organization sees its organogram: names, designations and lines, never
   emails.
6. Turning attendance on or off for an organization: super-admins only
   ("which I can enable"). Hours, office places, office networks and
   holidays: whoever administers the organization.
7. A day is one sequence: clock in, optionally start and end lunch once,
   clock out. Time is the server's. A punch keeps the address the request
   came from, the reported location and its accuracy, and gets a place
   verdict: office network (address in one of the organization's network
   ranges), near office (within an office place's radius, default 150 m),
   outside office, or unknown (no location, not on the office network). A
   punch outside or unknown needs a short note. Punches are never edited
   or deleted (corrections are a later feature); a day without a clock-out
   shows "no clock-out" and counts hours to the scheduled end.
8. Leave and kaaj: the person asks (dates, reason; a place for kaaj);
   anyone above them or an admin of their organization approves or
   rejects; those people can also record approved leave or kaaj directly
   for someone below them. The person can cancel their own request while
   it is pending, or approved and not yet started.
9. Work attribution: a task is "closed" or "started" by whoever changed its
   status that day (the activity log's actor). Started = entered a status
   flagged `is_started` (In Progress); closed = entered a closed status
   (Done, Failed; which one is shown). Commits count by their matched user
   (`git_commits.user_id`), non-merge and reachable, by `committed_at`. Pull
   requests count by `author_username` = the person's `git_username`, by
   `merged_at`.
10. A viewer only sees the titles of tasks in projects they may view and
    the names of repositories whose code they may view; the rest are
    counted, never named.
11. People are listed by name, never ranked by a figure.
12. Nothing is deployed or pushed: the work stays on branch
    `sdoc-structure-attendance` for the owner.

## Data

All JSON columns are `jsonb`. Dates in Asia/Kathmandu (`config('app.timezone')`).

- `designations`: `organization_id` (cascade), `name` (unique per
  organization), `rank` (smaller is more senior; orders lists),
  timestamps.
- `members`: `designation_id` (null on delete), `reports_to_id` (a member,
  null on delete), `attendance_exempt` (bool, default false).
- `attendance_settings`: one per organization (`organization_id` unique):
  `enabled`, `enabled_by` (user), `enabled_at`, `workdays` (jsonb ISO
  weekdays, default [1,2,3,4,5]), `start_time`, `end_time` (default
  10:00, 17:00), `seasons` (jsonb list of `{name, from: "MM-DD", to:
  "MM-DD", start: "HH:MM", end: "HH:MM"}`, may wrap the year end),
  `grace_minutes` (default 15).
- `attendance_places`: `organization_id`, `name`, `latitude`, `longitude`
  (decimal 10,7), `radius_m` (default 150).
- `attendance_networks`: `organization_id`, `label`, `cidr` (IPv4 or IPv6).
- `holidays`: `organization_id`, `date`, `name`; unique per organization
  and date.
- `attendance_punches`: `organization_id`, `member_id`, `work_date`,
  `kind` (`in|lunch_start|lunch_end|out`), `punched_at`, `source`
  (`web|app|device`), `device_ref` (nullable, for a machine), `ip`,
  `latitude`, `longitude`, `accuracy_m`, `place`
  (`office_network|near_office|outside|unknown`), `attendance_place_id`
  (the nearest place, nullable), `distance_m`, `note`, `user_agent`,
  `recorded_by` (user, nullable), timestamps. Unique
  `(member_id, work_date, kind)`.
- `leave_requests`: `organization_id`, `member_id`, `kind` (`leave|kaaj`),
  `starts_on`, `ends_on`, `reason`, `place` (kaaj), `status`
  (`pending|approved|rejected|cancelled`), `decided_by` (member),
  `decided_at`, `decision_note`, `requested_by` (user), timestamps.

## Shared core (built first, used by every part)

- `App\Support\OrgChart`: `descendantIds(Member $m): list<int>` (everyone
  below, any depth, recursive CTE), `ancestorIds(Member $m): list<int>`,
  `wouldLoop(Member $m, ?Member $boss): bool`.
- `WorkspaceAccess` (keep every rule here):
  `canManageStructure($user, $organizationId)` = `administers`;
  `canViewOrganogram($user, $organizationId)` = super-admin or an active
  member of it;
  `oversees($user, Member $member)`: super-admin, an admin of the member's
  organization, or an active member the person is below (not themselves);
  `overseenMemberIds($user, $organizationId): ?list<int>` (null = the whole
  organization, for its admins and super-admins; else the viewer's
  descendants in it);
  `canViewPersonOf($user, Member $member)` = themselves or `oversees`;
  `canEnableAttendance($user)` = super-admin;
  `canManageAttendance($user, $organizationId)` = `administers`;
  `attendanceEnabled($organizationId)` (memoized);
  `canPunch($user)`: an active member of an organization with attendance on;
  `canViewAttendanceRegister($user, $organizationId)`: attendance on and
  (`administers`, or someone in it is below them);
  `canDecideLeave($user, LeaveRequest $leave)`: `oversees` the requester,
  not themselves.
- `App\Support\Attendance\AttendanceCalendar`:
  `for(Organization $o)`, `day(CarbonImmutable $date): DaySchedule`
  (`isWorkday`, `holiday` name or null, `start`, `end`, `lateAfter`).
- `Task::startedStatuses()` from a config flag `is_started`.

## The three parts

Each part follows the codebase's pattern: FormRequest (authorization
through `WorkspaceAccess`) → action service returning `ServiceResult` →
JsonResource; reads in `app/Queries/<Part>/`; a thin web controller and a
thin API controller over the same request, service, query and resource;
routes in both files.

### Structure

- Services (`App\Services\Structure`): create, rename, re-rank and delete a
  designation (delete clears it from people); `SetReportsToService`
  (refuses another organization, themselves, a loop; logs nothing in
  project activity). The person form takes `designation_id` and
  `reports_to_id`, applied through that service.
- When someone leaves the organization or is deleted, the people reporting
  to them move up to that person's own higher authority.
- Query: `OrganogramQuery` (people of an organization with designation,
  reports-to, direct-report counts, active only) for the tree and the chart.
- Web: Organogram page (outline with drag handles, fold/unfold, a chart view
  that prints), designations managed on the same page, the two fields on
  the person form.

### Attendance

- Services (`App\Services\Attendance`): `EnableAttendanceService`,
  `UpdateAttendanceSettingsService`, places, networks and holidays (add,
  change, remove), `PunchService` (the day sequence under a row lock, the
  place verdict through `App\Support\Attendance\PunchPlace`, note rule),
  `RequestLeaveService`, `DecideLeaveService`, `CancelLeaveService`,
  `RecordLeaveService`.
- Queries (`App\Queries\Attendance`): `MyAttendanceQuery` (today's punches
  and next allowed action, for the clock button and the app),
  `AttendanceRegisterQuery` (one day: summary and per-person state, for the
  organization or the viewer's people), `AttendanceSummaryQuery`
  (`forMembers(list<int>, from, to)`: days expected, present, late,
  minutes worked, outside punches, leave and kaaj days, per member),
  `LeaveQuery` (mine, waiting for my decision).
- Web: the clock button in the top bar (geolocation in the browser), the
  Attendance page (register, any date), Leave and kaaj page, settings page.

### Work

- Query (`App\Queries\Work\WorkActivityQuery`): per member over a range,
  tasks closed and started (with project visibility applied), commits
  (count, code lines, repositories, code visibility applied), pull requests
  merged; the one read the three views share.
- `WorkDayQuery` (today's work by person, with arrival from attendance when
  on), `PersonWorkQuery` (one person's week: attendance summary, tasks,
  code, overdue), `TeamWorkQuery` (everyone under a person or the whole
  organization: one row each).
- Web: Today's work, a person's work, a team's work.

## API

Every web screen has a `/api/v1/workspace/...` twin returning the same
query's data through the same resource; punches from the API carry source
`app`. `docs/api.md` documents them.

## Non-goals

Payroll, overtime pay, correction requests for punches, the machine
integration itself, continuous location tracking (never), ranking people.
