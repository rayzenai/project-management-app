## Attendance register *(attendance on; its organizational admins, super-admins, and anyone with people below them, who see only those people)*

One day of an organization: who should be in, who is, who came late, who
is on leave or kaaj. The people are those the caller sees
(`PeopleInViewQuery`: everyone for its admins and super-admins, else the
people below the caller), active only, attendance-exempt people left out.
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
| GET | `/workspace/organizations/{organization}/attendance` | `{ data: { date, schedule, summary, people, teams }, filters { date, under, organization } }`. `schedule`: `{ date, is_workday, holiday, expects_work, start, end, late_after }` (HH:MM, seasonal hours applied). `summary`: `{ staff, on_leave, on_kaaj, expected, present, late, not_in, outside_office, percentage }`: `staff` the people listed, `expected` staff minus those on leave or kaaj (0 on a day that expects no work), `present` those who clocked in, `not_in` those expected and not in, `outside_office` clock-ins outside the office or from an unknown place, `percentage` present of expected, rounded (`null` when nobody is expected). `people`, by name: `{ member { id, name, designation }, state, in_at, lunch_start_at, lunch_end_at, out_at, late, place, distance_m, note, leave }`, times ISO-8601 or `null`; `place`, `distance_m` and `note` are the clock-in's (`office_network`, `near_office`, `outside`, `unknown`); `leave` `{ kind, place, starts_on, ends_on }` or `null`. `teams`: the people in view with someone in view reporting to them, `{ id, name, designation }`, for an `under` filter |
| GET | `/workspace/organizations/{organization}/attendance/summary` | `{ data: [{ member { id, name, designation }, days_expected, days_present, days_late, minutes_worked, no_clock_out_days, outside_punches, leave_days, kaaj_days, usual_in }], filters { from, to, under, organization } }`, one row per person as above. `from`, `to` (`Y-m-d`; default the last 7 days ending today; at most 92 days, 422 on `to` otherwise) |

The totals count days up to today only, and working days only while the
organization has attendance on (from the day it was turned on):
`days_expected` working days the person isn't exempt, less those under
approved leave or kaaj without a clock-in; `days_present` and `days_late`
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

### Changes

- New: `GET /workspace/organizations/{organization}/attendance` and
  `GET …/attendance/summary` (see Attendance register).
- New: `GET|POST /workspace/leave`, `POST /workspace/leave/{leaveRequest}/approve|reject|cancel`
  and `POST /workspace/members/{member}/leave` (see Leave and kaaj).
