## Attendance: the clock

For an active person of an organization whose attendance is on
(`attendance_enabled` in `GET /user`, the web's `attendance` prop being
set); anyone else gets 403 (a deactivated person 401, as on every route).
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
