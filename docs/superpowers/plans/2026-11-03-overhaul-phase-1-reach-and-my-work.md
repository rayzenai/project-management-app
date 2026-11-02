# Overhaul phase 1: reach and My work. Implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Everyone lands on one "My work" list, finds it on a phone, is told by email when something waits for them, and can be invited in bulk or reset a forgotten password.

**Architecture:** One new read (`MyWorkQuery`) feeds the My work page, its API twin and the morning email, so all three agree on what is "today". Email never goes out during a page load: production runs the sync queue with no worker, so the scheduler (already running `schedule:work`) sends right-away and morning email from commands, and invites go through `Background::dispatch` (after the response). The shell becomes one sidebar with an account menu, plus a bottom bar on phones. Two small support classes (`BikramSambat`, `WorkingDays`) hold the Nepal calendar rules.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia + Svelte 5 (runes), Tailwind v4, Pest, PHPStan level 7, Postgres (SQLite in tests), Sanctum API.

**Spec:** `docs/superpowers/specs/2026-11-02-workspace-overhaul-design.md` (phase 1). Mockups (open in a browser; they render with the canvas's `support.js`, so read the HTML if they don't): `docs/superpowers/specs/2026-11-02-workspace-overhaul-mockups/` — `my-work.html`, `adding-a-task.html`, `phone.html`, `morning-email.html`, `inviting-people.html`. The names in them are invented.

## Global Constraints

- Everything in `CLAUDE.md` and `~/rayzen/CLAUDE.md`: Route → thin controller → FormRequest `authorize()` through `WorkspaceAccess` → service returning `ServiceResult` → JsonResource; every new action and read on **both** web and API; every new role decision is a `WorkspaceAccess` method with happy, failure and privilege-escalation tests.
- JSON columns are `jsonb`. Redis/cache keys always expire. Dates are `CarbonImmutable`; the app timezone is `Asia/Kathmandu`.
- UI: semantic utilities only (no raw palette colours, no `dark:`), Lucide icons, no em-dashes in copy, no coloured pills or dots, no `rounded-xl`/`shadow-*` outside `.popover`, no black/white inverted buttons, no mono-uppercase eyebrow labels, Svelte 5 runes, no `{@html}`. Every list, dialog and popover has a maximum height and scrolls inside.
- Copy uses the spec's words: "My work", "Updates", "People on it", "Nobody on it", "Show done", "You asked others", "No date", "Coming up", "Later", "Tomorrow", "Today", "Snoozed".
- The week starts on the organization's first working day; without attendance on, Sunday to Friday are working days (`WorkingDays`, Task 1).
- No email link changes anything (mail scanners open links). Email copy is English, plain and short, with one link into the app, sent from the configured `MAIL_FROM_*` (production: `no-reply@sdoc.gov.np`).
- No mail is sent inside a web request except the single forgotten-password email; everything else is sent by a scheduled command or `Background::dispatch`.
- Stage only your task's files; never `git add -A`; never commit `public/build`. Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Work in `/Users/kiran/rayzen/projects/.worktrees/overhaul` on branch `overhaul-phase-1`; never push.
- Per task: `vendor/bin/pint --dirty`, `composer types:check`, the task's tests, then the full suite once (`php artisan test --compact --parallel`; a PHP fatal empties pao's JSON, rerun with `php -d display_errors=stderr vendor/bin/pest > /tmp/x 2>&1`). Frontend tasks also `npm run lint:check && npm run format:check && npm run types:check && npm run build` (the Inertia tests need `public/build/manifest.json`, so build before the suite after any frontend change).

## Review Focus

1. **A person in no organization (a super-admin) or in an organization with attendance off**: My work's Tomorrow, the morning email's "is today a working day" and the week start fall back to Sunday to Friday, never crash on a null organization. Tests in Tasks 1, 4 and 10.
2. **A task with both a personal date and a task date, and a task snoozed past its date**: the personal date decides the group, the red "N days late" comes only from the task's own deadline (the one overdue rule), and a snoozed task sits only in Snoozed. Tests in Task 4.
3. **Several notifications for one person inside a few minutes, one of them read in the app first, and a person who turned email off**: one email with the unread ones, none for the read one, none for the person who opted out, and nothing sent twice when the command overlaps or reruns. Tests in Task 9.
4. **A forgotten-password or invite request for an unknown, deactivated or other-organization email**: the same answer as for a known one, no email, no token; an invite never creates or relinks a person outside the inviter's organization. Tests in Tasks 11 and 12.
5. **Old links**: `/workspace`, `/workspace/my`, the `/workspace` fallback in notifications and the organization switch's fallback all land on My work; the old API `home` and `my` endpoints keep answering. Tests in Task 4.

---

### Task 1: The Nepal calendar: Bikram Sambat and working days

**Files:**
- Create: `app/Support/BikramSambat.php`, `app/Support/WorkingDays.php`
- Test: `tests/Unit/BikramSambatTest.php`, `tests/Feature/Workspace/WorkingDaysTest.php`

**Interfaces:**
- Produces: `BikramSambat::fromGregorian(CarbonInterface $date): ?array{year: int, month: int, day: int}` (null outside BS 2070 to 2100); `BikramSambat::monthName(int $month): string` (Nepali: बैशाख, जेठ, असार, श्रावण, भदौ, आश्विन, कार्तिक, मंसिर, पुष, माघ, फाल्गुन, चैत्र); `BikramSambat::digits(int|string $value): string` (Devanagari digits); `BikramSambat::short(CarbonInterface $date): ?string` → `"कार्तिक १६"`.
- Produces: `WorkingDays::forOrganization(?int $organizationId): self`, `WorkingDays::forUser(?Authenticatable $user): self` (the user's member's organization through `WorkspaceAccess::memberFor`, never `Member::forUser`), `isWorkingDay(CarbonInterface $date): bool`, `nextWorkingDay(CarbonInterface $after): CarbonImmutable` (first working day strictly after; gives up after 31 days and returns the next day), `weekStart(CarbonInterface $date): CarbonImmutable` (the latest date on or before `$date` that is the week's first working day, where the week is read Sunday first: the first ISO weekday in the order 7,1,2,3,4,5,6 that is a workday).
- Rules: with `WorkspaceAccess::attendanceEnabled($organizationId)` true, a working day is `AttendanceCalendar::for($id)->day($date)->expectsWork()` (workdays and holidays); otherwise ISO weekdays `[7, 1, 2, 3, 4, 5]` (Sunday to Friday) and no holidays. Build the calendar once per instance.
- Data: copy the month lengths for BS 2070 to 2100 and each year's AD start date (Baisakh 1) from `~/rayzen/patro/api/app/Services/BikramSambat/DataSource.php` (its lines ~266-296 and ~568-598) into private constants; cite the source in the class docblock.

- [ ] **Step 1: Failing tests.** `BikramSambatTest`: `it('converts known dates')` with a dataset: `2026-04-14` → `[2083, 1, 1]`; `2026-11-02` → `[2083, 7, 16]`; `2026-11-06` → `[2083, 7, 20]`; `2013-04-14` → `[2070, 1, 1]`; the day before `2026-04-14` → `[2082, 12, <last day of 2082 Chaitra from the table>]`. `it('formats the short date')`: `short(2026-11-02)` is `'कार्तिक १६'`. `it('returns null outside the table')` for `2010-01-01` and `2050-01-01`. `it('writes Devanagari digits')`: `digits(2083)` is `'२०८३'`. `WorkingDaysTest`: Sunday to Friday working and Saturday not without attendance (2026-11-07 is a Saturday); `nextWorkingDay(Friday 2026-11-06)` is Sunday 2026-11-08; `weekStart(Wednesday 2026-11-04)` is Sunday 2026-11-01; with attendance enabled on Mon to Fri and a holiday on 2026-11-09, `isWorkingDay(2026-11-09)` is false, `nextWorkingDay(Friday 2026-11-06)` is Tuesday 2026-11-10 and `weekStart(2026-11-04)` is Monday 2026-11-02; `forUser` of a super-admin with no member and of a member with no organization both use Sunday to Friday.
- [ ] **Step 2:** Run them, see them fail; implement; see them pass; PHPStan; full suite.
- [ ] **Step 3:** Commit `Calendar: Bikram Sambat dates and working days`.

### Task 2: `last_seen_at`

**Files:**
- Create: `database/migrations/2026_11_03_100000_add_last_seen_at_to_users.php`, `app/Http/Middleware/TouchLastSeen.php`
- Modify: `app/Models/User.php` (docblock `@property CarbonImmutable|null $last_seen_at`, cast `datetime`, not fillable), `routes/web.php` (the authenticated `workspace` group, after `EnsureCanSignIn`), `routes/api.php` (the `auth:sanctum` group, after `EnsureCanSignIn`)
- Test: `tests/Feature/Workspace/LastSeenTest.php`

**Interfaces:**
- Produces: `users.last_seen_at` (nullable timestamp, indexed). `TouchLastSeen` writes it with a query update (no model events, no `updated_at` change) only when it is null or older than one hour, after the response is built.

- [ ] **Step 1: Failing tests:** a web GET sets it; a second GET 10 minutes later (Carbon test time) leaves it; one 61 minutes later moves it; an API GET with Sanctum sets it; `users.updated_at` is unchanged by it; a guest request writes nothing.
- [ ] **Step 2:** Fail, implement, pass, PHPStan, full suite.
- [ ] **Step 3:** Commit `People: remember when each person was last seen`.

### Task 3: Only you change your own star, snooze and dates

My work's row menu writes `is_focused`, `snoozed_until` and `personal_due_at` through `PATCH /workspace/assignments/{assignment}`, whose request today authorizes any signed-in user.

**Files:**
- Modify: `app/Support/WorkspaceAccess.php`, `app/Http/Requests/UpdateAssignmentRequest.php`
- Test: `tests/Feature/Workspace/AssignmentUpdateAccessTest.php` (web and API)

**Interfaces:**
- Produces: `WorkspaceAccess::canUpdateAssignment(?Authenticatable $user, ProjectAssignment $assignment): bool` = `canViewProject` of the assignment's task's project. `WorkspaceAccess::ownsAssignment(?Authenticatable $user, ProjectAssignment $assignment): bool` = the assignment's member is active and linked to this user.
- `UpdateAssignmentRequest::PERSONAL_FIELDS = ['is_focused', 'snoozed_until', 'personal_progress', 'personal_due_at', 'personal_status_note']`. `authorize()`: `canUpdateAssignment`, and when any personal field is present, also `ownsAssignment`. `role` and `priority` keep today's rule apart from the new project visibility check. Before changing it, `grep -rn "assignments/" resources/js` and confirm no current screen sends a personal field for someone else's assignment; list what you found in the report.

- [ ] **Step 1: Failing tests:** the assignee stars and snoozes their own assignment (200 / redirect); a teammate who can view the project gets 403 for `is_focused` and for `personal_due_at` on someone else's assignment; a person who cannot view the project gets 403 even for `priority`; a deactivated assignee gets refused (sign-in middleware); the API route behaves the same.
- [ ] **Step 2:** Fail, implement, pass, PHPStan, full suite.
- [ ] **Step 3:** Commit `Assignments: only the person on it changes their star, snooze and own dates`.

### Task 4: `MyWorkQuery`, its API, and the redirects

**Files:**
- Create: `app/Queries/MyWorkQuery.php`, `app/Http/Controllers/Workspace/MyWorkController.php`, `app/Http/Controllers/Api/MyWorkController.php`, `app/Http/Requests/MyWorkRequest.php` (only `done` boolean; authorize = signed in)
- Modify: `routes/web.php` (`GET /workspace/my` → `Workspace\MyWorkController`, name stays `workspace.my`; `GET /workspace` → redirect to `route('workspace.my')`, name stays `workspace.home`), `routes/api.php` (`GET /api/v1/workspace/my-work`, name `api.workspace.my-work`; the old `home` and `my` API routes stay as they are), `app/Http/Controllers/Workspace/HomeController.php` (becomes the redirect, keeping the query string), `app/Http/Controllers/Workspace/AuthController.php` (`intended(route('workspace.my'))`), `app/Notifications/Concerns/BuildsWorkspaceNotification.php` (`taskUrl(null)` → `/workspace/my`), `app/Http/Controllers/Workspace/OrganizationSwitchController.php` (fallback `route('workspace.my')`)
- Delete: `app/Http/Controllers/Workspace/MyWorkspaceController.php` (the API's `MyWorkspaceController`, `HomeQuery` and `MyWorkspaceQuery` stay for the old API routes)
- Test: `tests/Feature/Workspace/MyWorkTest.php`, `tests/Feature/Workspace/Api/MyWorkApiTest.php`; update every test that GETs `/workspace` and asserts Inertia props to GET `/workspace/my` instead (`grep -rln "get('/workspace')\|route('workspace.home')" tests`); move `tests/Feature/Workspace/HomeTest.php`'s assertions onto `GET /api/v1/workspace/home` (Sanctum) so the kept endpoint stays covered; `tests/Feature/WorkspaceAccessTest.php` keeps both URLs (guest → login; signed in → `/workspace` redirects, `/workspace/my` renders `MyWork`).

**Interfaces:**
- Produces: `MyWorkQuery::get(User $user, ?int $organizationId = null, bool $withDone = false, ?CarbonImmutable $today = null): array` returning:
  ```
  {
    today: {date: 'Y-m-d', label: 'Monday, 2 November', bs: 'कार्तिक १६'|null},
    groups: list<{key: 'today'|'tomorrow'|'coming_up'|'later'|'asked_others'|'no_date'|'snoozed'|'done', label: string, rows: list<Row>}>,  // in this order, empty groups left out
  }
  Row = {
    kind: 'task'|'subtask',
    key: string,                       // 'task-12' / 'subtask-7', unique in the payload
    assignment: {id, is_focused, snoozed_until: 'Y-m-d'|null, personal_due_at: 'Y-m-d'|null}|null,   // null for asked_others and subtasks
    task: {id, slug, number, code, title, status, priority, deadline_at: 'Y-m-d'|null, completed_at: ISO|null,
           project: {id, slug, title, code, label}},       // label = Project::label()
    subtask: {id, body, due_at: 'Y-m-d', is_done}|null,
    due: 'Y-m-d'|null,                 // the date that placed it: personal_due_at ?? deadline_at (subtask: due_at)
    days_late: int,                    // > 0 only when the task passes the one overdue rule (Task::is_overdue)
    people: list<{id, name}>,          // the task's active assignees (member id and name)
    latest_update: {author: string, body: string, at: ISO}|null,
  }
  ```
- Who is on the list: tasks with an assignment of the user's member, not closed (`notClosed`), in active projects the user may view (`Project::visibleTo` + `inOrganization($organizationId)` + not archived). "You asked others": tasks with `created_by` = the user, not closed, with at least one assignee who is not the user's member and none who is. Subtasks: the user's open subtasks with a `due_at`, on tasks in those same projects, placed by `due_at`.
- Groups (`$today` defaults to `today()`; `WorkingDays::forUser($user)`):
  - snoozed (`snoozed_until` after today) → `snoozed` only;
  - `days_late > 0`, or `due` on or before today → `today`;
  - after today and on or before `nextWorkingDay(today)` → `tomorrow`;
  - on or before `weekStart(today) + 13 days` → `coming_up`;
  - any later date → `later`; no date → `no_date`;
  - `asked_others` is its own group (its rows are not also placed by date).
  - `withDone`: `done` holds the user's assigned tasks completed (`completed_at`) in the last 7 days, newest first.
  - Order inside a group: `today` puts `days_late` rows first (most late first); then starred (`is_focused`) before the rest; then by `due`, then task number. Subtask rows sort by `due` with the tasks.
- Latest update: per task, the newest non-deleted `task_comments` row from the last 7 days, read for all listed tasks in one query (a `whereIn('id', <max id per task_id subquery>)` that also runs on SQLite), with the author's name; `body` is one line (whitespace collapsed) cut to 140 characters with "…". No N+1: assignees, projects and comments are each loaded once.
- Group labels: Today, Tomorrow, Coming up, Later, You asked others, No date, Snoozed, Done.
- Web: `Inertia::render('MyWork', [...MyWorkQuery::get($user, WorkspaceOrganization::chosen($user), $request->boolean('done'))])`. API: JSON of the same array, no organization argument (like the other API lists).

- [ ] **Step 1: Failing tests** (`MyWorkTest`, fix today with `Carbon::setTestNow('2026-11-04 10:00')`, a Wednesday):
  - `it('places tasks by their date')`: overdue (task deadline 2026-11-02) and due today in `today`, overdue first with `days_late` 2; due 2026-11-05 in `tomorrow`; due 2026-11-12 in `coming_up` (end is Saturday 2026-11-14); due 2026-11-20 in `later`; none in `no_date`.
  - `it('uses the personal date to place a row but the task date to call it late')`: deadline 2026-11-20, `personal_due_at` 2026-11-03 → in `today` with `days_late` 0.
  - `it('keeps snoozed tasks only in Snoozed')`.
  - `it('puts starred rows first in their group')`.
  - `it('lists tasks the user created for others under You asked others')`, and not when the user is also on it.
  - `it('shows the newest update of the last 7 days, one line')`, none when the newest is 8 days old, and the query count does not grow with the number of tasks (assert the same count of queries for 2 and for 6 tasks with `DB::enableQueryLog`).
  - `it('leaves out closed tasks, archived projects and projects the user cannot see')`.
  - `it('places dated subtasks by their date')`.
  - `it('shows done tasks of the last 7 days only when asked')`.
  - `it('treats Friday\'s tomorrow as Sunday')` (today 2026-11-06: a task due 2026-11-08 is in `tomorrow`).
  - `it('works for a super-admin with no member')` (empty groups, no error).
  - `it('follows the organization switch on the web only')`.
  - Redirects: `/workspace` → `/workspace/my` (query string kept); login lands on `/workspace/my`; the switch's fallback lands on `/workspace/my`; a notification without a task links to `/workspace/my`.
  - `MyWorkApiTest`: the same payload shape at `/api/v1/workspace/my-work`, `?done=1`, 401 for guests; `/api/v1/workspace/home` and `/api/v1/workspace/my` still answer 200.
- [ ] **Step 2:** Fail, implement, pass. The frontend page `MyWork.svelte` does not exist yet: render a stub page `resources/js/pages/MyWork.svelte` that only shows the groups' labels and titles, so the build and Inertia tests pass; Task 5 replaces it. Delete the web `MyWorkspaceController` and point the route at the new controller. PHPStan, update the tests named above, build, full suite.
- [ ] **Step 3:** Commit `My work: one list of your work, on the web and the API`.

### Task 5: The My work page

**Files:**
- Create: `resources/js/components/mywork/MyWorkRow.svelte`, `resources/js/components/mywork/RowMenu.svelte`
- Modify: `resources/js/pages/MyWork.svelte` (replaces the stub), `resources/js/lib/types.ts` (`MyWorkPayload`, `MyWorkGroup`, `MyWorkRow` matching Task 4's shape), `resources/js/components/CommandPalette.svelte` (its nav: "My work" → `/workspace/my`, "Projects"; drop "Overview" `/workspace` and "My Workspace"), `resources/js/pages/Guide.svelte` (the `/workspace/my` link reads "My work")
- Delete: `resources/js/pages/Home.svelte`, `resources/js/pages/MyWorkspace.svelte`, and any component only they used (check with grep: `AssignmentRow.svelte`, `OpenTodos.svelte`, `ContactChips.svelte`, `NotesStrip.svelte`, `TaskRegisterHead.svelte` are candidates; delete only those with no other importer)

**Interfaces:**
- Consumes: Task 4's payload; `CompleteCheckbox.svelte` (marks done through the task PATCH, with undo); `PATCH /workspace/assignments/{id}` (Task 3) for `is_focused`, `snoozed_until`, `personal_due_at`; `PATCH /workspace/subtasks/{id}` `{is_done}` for subtask rows; `peek` (`lib/peek.svelte`) to open a task.
- Look (mockup `my-work.html`, phone `phone.html` left): header "My work", the date line `today.label · today.bs` (Mukta via `font-np` for the BS part), "Show done" toggle (a link/button that visits `?done=1` and back, `preserveScroll`). Under it the add field (Task 7 makes it the one-line field; until then the existing `QuickAddBar`). Then the groups, each with its label and count; Later is collapsed when it has more than 5 rows, No date and Snoozed are collapsed by default (a click opens them; remembered per group in `localStorage`, wrapped in try/catch).
- Row (at least 44 px high, one line on desktop, two on a phone): the done circle (`CompleteCheckbox`; subtasks get a plain check that PATCHes `is_done`), the title (opens Peek), `project.label` in secondary text, on the right the person (asked_others) or the date (`due` written as "Today", "Tomorrow", a weekday for this week, else "Thu 12 Nov"); a red "2 days late" when `days_late > 0` (text colour only, no pill). Under the title, when `latest_update`: "Author: body · 2 days ago" (`lib/format.ts`'s `formatDistance`) in secondary text, one line, truncated. A starred row shows a filled star before the title.
- Row menu (`RowMenu`, a `Popover`, only for rows with an `assignment`): Star / Remove star; Snooze until tomorrow, for a week (today + 7 days), or a picked date; Stop snoozing (in Snoozed); My own date (date input) and Clear my own date. Dates sent as the local `YYYY-MM-DD` of the office (use `lib/dates.ts`, never `toISOString()`).
- Empty state: when every group is empty, one line "Nothing waiting for you." and the add field.

- [ ] **Step 1:** Build the page and components against the payload; delete the old pages and their only-used components; update the palette and the Guide link.
- [ ] **Step 2:** `npm run lint:check && npm run format:check && npm run types:check && npm run build`; full PHP suite (the Inertia component name is `MyWork`).
- [ ] **Step 3: Look at it.** Seed (`php artisan db:seed --class=WorkspaceDemoSeeder` on a scratch database, never the local `projects` database: `createdb projects_overhaul_scratch`, point a copied `.env` at it via `DB_DATABASE`, and drop it when done) and serve (`php artisan serve --port=8123`), sign in as a demo member, and take Playwright screenshots of My work at 1440×900 and 390×844, light and dark, with and without "Show done"; put the paths in the report and describe what you checked (rows ≥ 44 px, nothing overflows, the date line shows the BS date).
- [ ] **Step 4:** Commit `My work: the page`.

### Task 6: One sidebar, an account menu, and a phone bar

**Files:**
- Create: `resources/js/components/shell/Sidebar.svelte`, `resources/js/components/shell/AccountMenu.svelte`, `resources/js/components/shell/PhoneTabs.svelte`
- Modify: `resources/js/lib/navigation.ts` (rewrite), `resources/js/components/AppShell.svelte`, `resources/js/lib/types.ts` if needed
- Delete: `resources/js/components/shell/NavRail.svelte`, `resources/js/components/shell/NavPanel.svelte`

**Interfaces:**
- `navigation.ts` exports `mainLinks(options: {isSuperAdmin: boolean}): NavLink[]` → Search is a button, not a link; links: My work `/workspace/my` (`ListChecks`), Projects `/workspace/projects` (`FolderKanban`), People `/workspace/team` (`Users`). Phase 2 adds Overview here. `accountGroups(office: OfficeLinks, options: {gitEnabled: boolean; isSuperAdmin: boolean}): {label: string; links: NavLink[]}[]` → "You": Notifications `/workspace/notifications` (with the unread count), Emails `/workspace/settings/emails` (Task 8 adds the page; link it now), Guide `/workspace/guide`; "Office" (each only when `office` allows it): Needs attention `/workspace/maintenance` (count `maintenance_count`), Organogram, Attendance, Leave and kaaj, Attendance settings; "Reports": Today's work, Team's work, My week, Code activity (with git); "Admin": Organizations (super-admin). Keep `currentHref(path, links)` (longest match). Every page that used to be in the rail stays reachable from here.
- `Sidebar` (shown at `md` and up, 768 px): the logo (links to `/workspace/my`), the organization switch for super-admins (the existing popover, moved from `NavPanel`), "New task" (primary, `N`) and Search (`/`, opens the palette), the main links, then "Programs" (the managed programs, as today) and "Projects" with its count, the `+` for a new project and the filter from 9 projects (moved from `NavPanel`, unchanged behaviour), the project list scrolling inside the sidebar; at the bottom the account button (avatar, name, the role line) opening `AccountMenu` upward. The `[` key hides and shows the sidebar as today (stored the same way).
- `AccountMenu`: a `Popover` (desktop) / a bottom sheet (phone) with the groups above, then Appearance (opens the existing settings dialog through `onsettings`) and Sign out. Bounded: max height `min(560px, 80vh)`, scrolls.
- `PhoneTabs` (below `md`, fixed to the bottom, safe-area padding): My work, Projects, Updates (`/workspace/notifications`, the unread count as a number badge), Me (opens `AccountMenu` as a sheet). Phase 2 adds Overview. The active tab uses the accent colour. `AppShell` pads the content so the bar never covers it, and drops the phone drawer and hamburger (the sidebar is desktop only; the bar replaces it).
- `AppShell` keeps: the top bar (`bar` snippet, `ClockButton`, `ThemeToggle`), adds the sticky-notes button there (`StickyNote`, toggles `notesBoard`, shows the note count), Peek, quick add, the palette, toasts, the settings dialog, all keyboard shortcuts; polling `unreadNotifications` every 30 s stays.

- [ ] **Step 1:** Rewrite `navigation.ts`; build the three components; rewire `AppShell`; delete the rail and panel.
- [ ] **Step 2:** Frontend checks and build; full PHP suite.
- [ ] **Step 3: Look at it** (scratch database and serve as in Task 5): screenshots at 1440×900 (sidebar, account menu open, a super-admin's organization switch), 390×844 (bottom bar, Me sheet open), light and dark; check that every old rail destination is one click away from the account menu and that a 60-project list scrolls inside the sidebar. Paths in the report.
- [ ] **Step 4:** Commit `Shell: one sidebar, an account menu and a bar for phones`.

### Task 7: Bigger type, touch-size controls, readable faint text

**Files:**
- Modify: `resources/css/app.css`, `config/themes.php`, `tests/Feature/ThemeTokenTest.php` (only if it pins the changed values), any component whose hard-coded height breaks at the new sizes (fix only what you see break)

**Interfaces:**
- `html` font size 15px (from 13.5px). In the `@theme` block set `--text-xs: 0.875rem` (13.1px) and `--text-sm: 0.9375rem` (14px) with matching line heights, so the many `text-xs` meta lines stay readable.
- `.btn` family: height 36px (`h-9`), 14px text; `.btn-icon` 36×36; `.btn-lg` 44px. `.input`: 16px text below `md` (so iOS does not zoom on focus), 14px from `md`, at least 40px high. `.chip` 24px high, 12.5px text. `.menu-item` at least 40px high, 14px. `.group-head` 40px. `.row`: `min-height: 44px`. `.task-head` and `.col-head` 12.5px.
- Contrast: `textFaint` in light becomes a value with at least 4.5:1 on `surface` and on `bg`, and in dark at least 4.5:1 on `surface` and `surfaceAlt`; `textMuted` checked the same way. Change `config/themes.php` and the `:root` / dark blocks in `app.css` together (the test keeps them in step). Put the before/after ratios in the report.

- [ ] **Step 1:** Change the tokens and component classes.
- [ ] **Step 2:** Frontend checks, build, full suite.
- [ ] **Step 3: Look at it:** screenshots of My work, a project's list and board, a task page, the People page, Maintenance and the login page at 1440×900 and 390×844, light and dark; fix what overflows or clips. Paths and a one-line verdict per page in the report.
- [ ] **Step 4:** Commit `Look: 15px type, touch-size controls and readable faint text`.

### Task 8: Quick add: Nepali date words, chips, and the last project

**Files:**
- Modify: `app/Support/QuickAddParser.php`, `resources/js/lib/quickAddTokens.ts`, `app/Http/Middleware/ShareWorkspaceData.php` (`quickAddContext.lastProjectId`), `resources/js/lib/types.ts` (`QuickAddContext.lastProjectId: number | null`), `resources/js/components/QuickAddForm.svelte` (the `inline` variant), `resources/js/components/QuickAddBar.svelte`, `resources/js/pages/MyWork.svelte` (uses the bar)
- Test: `tests/Feature/Workspace/QuickAddParserTest.php`, `tests/Feature/Workspace/QuickAddLastProjectTest.php`

**Interfaces:**
- Parser date words added beside the English ones, resolved the same way (a weekday that is today means today): आज (today), भोलि (tomorrow), पर्सि and पर्सी (the day after tomorrow), आइतबार, सोमबार, मंगलबार, बुधबार, बिहिबार (also बिहीबार), शुक्रबार, शनिबार (Sunday to Saturday). Devanagari vowel signs are `\p{M}`, not `\w`, so write them as literal alternations with the same `(^|\s)` before and `(?=\s|$)` after. The TypeScript mirror gets the same words so they highlight.
- `quickAddContext.lastProjectId`: the project of the newest task the user created (`created_by`) among the projects already in `quickAddContext.projects` (visible, active), else null. One query.
- The `inline` variant of `QuickAddForm` (mockup `adding-a-task.html`): one `TokenInput` line ("Add a task"); under it a row of chips: the date (Today, Tomorrow, a date input; shows the chosen date as "Thu 5 Nov"), the project (a select of `projects`, defaulting to `defaultProjectId ?? lastProjectId`), Who (opens the existing `AssigneePicker`, bounded); a typed `#project`, `@person` or date fills the chip it matches (display only; the server stays the authority). Enter adds and keeps the field focused and empty for the next one; the chips keep their project and clear date and people. With no project chosen and no `#project` typed: "Pick a project" under the field, as today. The `overlay` variant keeps all its fields.

- [ ] **Step 1: Failing tests:** parser dataset (Carbon fixed on Wednesday 2026-11-04): `"Report आज"` → date 2026-11-04 and title "Report"; `भोलि` → 2026-11-05; `पर्सि` → 2026-11-06; `शुक्रबार` → 2026-11-06; `बुधबार` → 2026-11-04; `आइतबार` → 2026-11-08; a Nepali word inside another word ("आजको") is not a date. The dispatcher test: `QuickAddDispatcher` with "Call the district भोलि @<member>" stores `deadline_at` 2026-11-05. `QuickAddLastProjectTest`: `lastProjectId` is the project of the user's newest created task; null with none; never a project the user can no longer see (a task created in a project they were then removed from).
- [ ] **Step 2:** Fail, implement the parser and the shared prop, pass; then the TypeScript mirror and the inline form; frontend checks, build, full suite.
- [ ] **Step 3: Look at it:** screenshots of the add field on My work at 1440 and 390 wide, empty and with "Call the district भोलि @..." typed. Paths in the report.
- [ ] **Step 4:** Commit `Quick add: Nepali date words, chips and your last project`.

### Task 9: Email: settings, layout, and right-away email

**Files:**
- Create: `database/migrations/2026_11_03_110000_add_email_settings.php` (adds `user_preferences.email_morning` and `email_weekly`, boolean default true; adds `notifications.email_handled_at` nullable timestamp with an index on `(notifiable_type, notifiable_id, email_handled_at)`; sets `email_handled_at = now()` on every existing notification so nothing old is emailed after deploy), `app/Console/Commands/SendEmailUpdates.php`, `app/Mail/UpdatesMail.php`, `resources/views/emails/layout.blade.php`, `resources/views/emails/updates.blade.php`, `resources/views/emails/updates-text.blade.php`, `app/Http/Controllers/Workspace/EmailSettingsController.php`, `resources/js/pages/Settings/Emails.svelte`
- Modify: `app/Models/UserPreference.php`, `app/Models/User.php` (`emailPreferences(): array{now: bool, morning: bool, weekly: bool}`, `now` = `email_notifications`, every flag true with no row), `app/Http/Requests/UpdatePreferenceRequest.php` (`email_morning`, `email_weekly`: `sometimes boolean`), the web and API preference controllers (return the new fields), `app/Http/Middleware/ShareWorkspaceData.php` and `resources/js/components/AppearanceConfig.svelte` (the existing email switch moves to the Emails page; Appearance keeps the rest), `app/Observers/ProjectAssignmentObserver.php` (no `TaskAssigned` when the assignee is the person who assigned), `routes/web.php` (`GET /workspace/settings/emails`, name `workspace.settings.emails`), `routes/console.php`, `config/project-management.php` (`emails` block: `updates_quiet_minutes` 2, `updates_max_wait_minutes` 5, `morning_at` env `PM_MORNING_EMAIL_AT` default `09:00`)
- Test: `tests/Feature/Workspace/EmailSettingsTest.php`, `tests/Feature/Workspace/EmailUpdatesTest.php`, the API preference test

**Interfaces:**
- `SendEmailUpdates::KINDS = [TaskAssigned::class, MentionedInComment::class, AddedToProjectTeam::class, MadeProjectManager::class, MadeProgramManager::class]` (phase 2 adds its own). Signature `workspace:send-email-updates {--pretend}`, scheduled `everyMinute()->withoutOverlapping(5)`.
- Per user with pending notifications (`type` in KINDS, `read_at` null, `email_handled_at` null, created in the last 24 hours): wait while the newest is younger than `updates_quiet_minutes` and the oldest younger than `updates_max_wait_minutes`; then, when the user's `now` setting is off or `WorkspaceAccess::canSignIn` is false, mark them handled without mail; else send one `UpdatesMail` with every pending item and mark them handled. Claim the rows before sending (an `update ... where email_handled_at is null` that returns the claimed ids), so two overlapping runs never send the same item; if the send throws, `report()` it and clear the claim so the next run retries.
- `UpdatesMail(User $user, list<array{title: string, line: string, url: string}> $items)`: subject = the single item's title ("Task assigned: Collect revenue data") or "N updates for you"; body: "Hello, {first name}." then each item (title, `body` from the payload, a link `url(...)` of its `url`), one "Open my work" button to `/workspace/my`, the footer "You get these when something is given to you, you are mentioned, or you join a project. Change your emails" linking `/workspace/settings/emails`. HTML from `emails/layout.blade.php` (the look of `morning-email.html`: one card, the "S" square and app name, 15-16px text, inline styles, the accent `#2f62d8`) plus a text version.
- Emails page (`Settings/Emails.svelte`, reached from the account menu and every email): three switches, "Right away" (given to you, mentioned, joined a project), "Morning email" (on working days at 9:00, only when something waits), "Weekly brief" (for people who run something; first working day of the week), each saving through `PATCH /workspace/preferences` with an inline "Saved".

- [ ] **Step 1: Failing tests** (`EmailUpdatesTest`, `Mail::fake()`, Carbon test time): one assignment → no mail at 1 minute, one mail at 3 minutes; three notifications 1 minute apart → one mail with three items once quiet; a stream that never goes quiet → one mail once the oldest is 5 minutes old; a notification read in the app before the run → not in the mail; a person with right-away off → nothing sent and the rows handled; a deactivated member → nothing; a second run sends nothing more; `--pretend` sends and marks nothing; existing notifications from before the migration are never emailed; assigning yourself makes no `TaskAssigned` notification; a mail transport exception leaves the rows pending for the next run. `EmailSettingsTest`: the page renders with the three values; PATCH saves each; the API returns and accepts them.
- [ ] **Step 2:** Fail, implement, pass; frontend checks, build, full suite.
- [ ] **Step 3: Look at it:** render the mail (`php artisan tinker` or a test that writes `(new UpdatesMail(...))->render()` to a file in your scratch directory) and screenshot it at 640 wide; screenshot the Emails page at 1440 and 390. Paths in the report.
- [ ] **Step 4:** Commit `Email: right-away email for work given to you, with settings`.

### Task 10: The morning email

**Files:**
- Create: `database/migrations/2026_11_03_120000_create_email_digests_table.php` (`id`, `user_id` fk cascade, `kind` string, `sent_on` date, `sent_at` timestamp, unique `(user_id, kind, sent_on)`), `app/Models/EmailDigest.php`, `app/Queries/MorningEmailQuery.php`, `app/Console/Commands/SendMorningEmails.php`, `app/Mail/MorningMail.php`, `resources/views/emails/morning.blade.php`, `resources/views/emails/morning-text.blade.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Workspace/MorningEmailTest.php`

**Interfaces:**
- `MorningEmailQuery::for(User $user, CarbonImmutable $today): array{late: list<Row>, today: list<Row>, new: list<Row>}`: `late` and `today` are `MyWorkQuery::get($user, null, false, $today)`'s `today` group split by `days_late > 0` (subtask rows left out); `new` = tasks named by the user's `TaskAssigned` notifications created since their last morning digest (`EmailDigest` kind `morning`, else the last 24 hours) that are still open and on their list, minus those already in `late` or `today`.
- `workspace:send-morning-emails {--pretend}`, scheduled `dailyAt(config('project-management.emails.morning_at'))->withoutOverlapping()`. For each user who can sign in, whose `morning` setting is on, and for whom `WorkingDays::forUser($user)->isWorkingDay($today)`: build the query; when all three lists are empty send nothing; else claim the day by inserting the `EmailDigest` row (a unique violation means already sent: skip), send `MorningMail`, and delete the claim if sending throws (report it).
- `MorningMail` (mockup `morning-email.html`): subject "Today: 3 tasks, 1 late" (count = late + today + new; ", N late" only when late > 0; "Today: 1 task"); "Good morning, {first name}."; the date line "Monday, 2 November · कार्तिक १६"; sections Late (red "2 days late"), Today, New for you ("From {creator name} · due Thu 5 Nov" when known), each item's title linking to the task; "Open my work"; footer "You get this on working days at 9:00, only when something is waiting for you. Change your emails". Same layout as Task 9.

- [ ] **Step 1: Failing tests:** a member with one overdue and one due-today task gets one mail with subject "Today: 2 tasks, 1 late"; nobody with nothing waiting gets mail; Saturday sends nothing (no attendance) and a holiday sends nothing (attendance on with a holiday); a person with the morning email off gets nothing; a deactivated member gets nothing; running twice the same day sends once; "New for you" lists a task assigned by someone else since the last digest and not one assigned yesterday before the previous digest; a super-admin with no member uses Sunday to Friday; `--pretend` sends nothing and writes no digest.
- [ ] **Step 2:** Fail, implement, pass, PHPStan, full suite.
- [ ] **Step 3: Look at it:** render one `MorningMail` to a file and screenshot it at 640 wide next to `morning-email.html`. Path in the report.
- [ ] **Step 4:** Commit `Email: the morning email on working days`.

### Task 11: Forgotten password

**Files:**
- Create: `app/Services/Workspace/PasswordLinkService.php`, `app/Mail/SetPasswordLink.php`, `resources/views/emails/set-password.blade.php`, `resources/views/emails/set-password-text.blade.php`, `app/Http/Requests/Auth/ForgotPasswordRequest.php`, `app/Http/Requests/Auth/SetPasswordRequest.php`, `app/Http/Controllers/Workspace/PasswordController.php`, `app/Http/Controllers/Api/PasswordController.php`, `resources/js/pages/Auth/ForgotPassword.svelte`, `resources/js/pages/Auth/SetPassword.svelte`
- Modify: `routes/web.php` (guest group: `GET /workspace/forgot-password` `workspace.password.request`; `POST /workspace/forgot-password` `workspace.password.email`; `GET /workspace/reset-password/{token}` `workspace.password.reset`; `GET /workspace/invite/{token}` `workspace.invite.accept`; `POST /workspace/set-password` `workspace.password.store`), `routes/api.php` (unauthenticated: `POST /api/v1/forgot-password`, `POST /api/v1/set-password`), `config/auth.php` (a second broker `invites`: provider `users`, table `invite_tokens`, expire 10080, throttle 0), a migration `2026_11_03_130000_create_invite_tokens_table.php` (same shape as `password_reset_tokens`) and `users.invited_at` nullable timestamp, `resources/js/pages/Auth/Login.svelte` ("Forgot your password?" under the password field)
- Test: `tests/Feature/Workspace/ForgotPasswordTest.php`, `tests/Feature/Workspace/Api/PasswordApiTest.php`

**Interfaces:**
- `PasswordLinkService::sendReset(string $email): ServiceResult`: always `success(null, 'If that email has a login here, a link to set a new password is on its way.')`; only when a user with that email exists and `WorkspaceAccess::canSignIn` is it, creates a token with `Password::broker('users')->createToken($user)` and sends `SetPasswordLink` (kind `reset`) synchronously inside a try/catch that reports and still answers the same.
- `PasswordLinkService::sendInvite(User $user, User $inviter): ServiceResult`: token from the `invites` broker, `SetPasswordLink` (kind `invite`), sets `users.invited_at`. Task 12 calls it from a job.
- `PasswordLinkService::setPassword(string $kind, string $email, string $token, string $password): ServiceResult`: validates through the matching broker (`users` for `reset`, `invites` for `invite`), refuses when `canSignIn` is false with the same message as a bad token ("This link has expired or was already used."), sets the password (hashed), deletes the token, rotates `remember_token`, returns `success($user)`.
- `SetPasswordLink(User $user, string $url, string $kind, ?string $inviterName)`: reset: subject "Set a new password", "Someone asked to reset the password for {email}. The link works for 60 minutes. If it wasn't you, ignore this email."; invite: subject "{inviter} invited you to {app name}", "Set your password to start. The link works for 7 days." One button "Set my password". Same layout as Task 9 (no "Change your emails" footer).
- Throttle: `ForgotPasswordRequest` limits by IP and by email (5 per 15 minutes each) through `RateLimiter`, answering 429 with "Too many tries. Wait a few minutes." on the `email` field; the API route the same.
- Web: after setting a password, sign the person in and go to `/workspace/my`; a bad or expired link shows the page's error and a "Send me a new link" button that posts to forgot-password with the email. API `set-password` returns `{message}` (no token; the app signs in with `POST /api/v1/login`).
- Pages: `ForgotPassword` (email field, "Send link", the answer shown in place, a link back to sign in) and `SetPassword` (props `kind`, `token`, `email`; password with show toggle and confirmation; heading "Set your password" / "Set a new password"), styled like `Login.svelte`.

- [ ] **Step 1: Failing tests:** a known active email gets one mail with a working link; an unknown email and a deactivated member's email get the same answer and no mail; a token sets the password and signs in; the same token twice fails the second time; a reset token older than 60 minutes fails; an invite token is refused by the `reset` kind and a reset token by the `invite` kind; an invite token works after 6 days and fails after 8; a deactivated person's valid token is refused; the sixth request in 15 minutes from one IP gets 429; the API twins answer the same; the login page links to forgot-password.
- [ ] **Step 2:** Fail, implement, pass; frontend checks, build, full suite.
- [ ] **Step 3: Look at it:** screenshots of the login, forgot-password and set-password pages at 1440 and 390, and the rendered reset mail. Paths in the report.
- [ ] **Step 4:** Commit `Sign in: forgotten password by email`.

### Task 12: Inviting people

**Files:**
- Create: `app/Support/InvitePlan.php`, `app/Services/Workspace/InvitePeopleService.php`, `app/Jobs/SendInvite.php`, `app/Http/Requests/InvitePeopleRequest.php`, `app/Http/Controllers/Workspace/InviteController.php`, `app/Http/Controllers/Api/InviteController.php`, `resources/js/components/team/InviteDialog.svelte`
- Modify: `routes/web.php` (`POST /workspace/invites/preview` `workspace.invites.preview`, JSON; `POST /workspace/invites` `workspace.invites.store`), `routes/api.php` (`POST /api/v1/workspace/invites/preview`, `POST /api/v1/workspace/invites`), `resources/js/pages/Team/Index.svelte` ("Invite people" beside the existing add-person button, for `canCreateMember`), `app/Queries/TeamIndexQuery.php` if the page needs the organization's designations and the count of people without a login
- Test: `tests/Feature/Workspace/InvitePeopleTest.php`, `tests/Feature/Workspace/Api/InvitePeopleApiTest.php`

**Interfaces:**
- `InvitePlan::parse(string $text): list<array{name: string, email: string|null}>`: one person per non-empty line, "Name, email", "Name <email>" or "Name email"; trims; an email is anything `filter_var(..., FILTER_VALIDATE_EMAIL)` accepts; a line that is only an email gives `name` = ''. At most 200 lines.
- `InvitePlan::build(User $inviter, int $organizationId, list<array{name: string, email: string|null, designation_id?: int|null}> $people, bool $withoutLogin): list<array{name, email, designation_id, status: 'ready'|'resend'|'needs_email'|'needs_name'|'already_here'|'deactivated'|'not_allowed', member_id: int|null}>`:
  - no email → `needs_email`; no name → `needs_name`; the same email twice → the second `already_here`;
  - a user with that email exists: their member is in this organization, active, `invited_at` set and `last_seen_at` null → `resend` (when `canManageMember`), else `already_here` (also for a login in another organization: the answer never says which);
  - a member of this organization with that email and no login: active → `ready` (when `canManageMember`, else `not_allowed`), inactive → `deactivated`;
  - a member with that email in another organization → `already_here`;
  - otherwise `ready` (a new person).
  - `$withoutLogin` adds every active member of the organization with an email and no login, not already listed, as `ready`.
  - A `designation_id` not of this organization is dropped (null).
- `InvitePeopleService::execute(User $inviter, int $organizationId, list<...> $people, bool $withoutLogin): ServiceResult`: rebuilds the plan (never trusts the client's statuses); in one transaction, for `ready` creates the `User` (name, email, a random 40-character password, hashed) and the `Member` (organization, name, email, `designation_id`, active) for new people, or creates the login and links it to the existing member; then for `ready` and `resend` dispatches `SendInvite` through `Background::dispatch` (after commit and the response). Returns `success(['invited' => n, 'skipped' => list<{name, email, status}>], "Invited n people.")`. Creating a login never changes a member's organization, role, teams or admin flag.
- `SendInvite(int $userId, int $inviterId)` (`ShouldQueue`): reloads both; skips when the user can no longer sign in; calls `PasswordLinkService::sendInvite`.
- `InvitePeopleRequest`: `organization_id` (required for a super-admin, else the inviter's admin organization, as `StoreMemberRequest::organizationId()` does), `people` (array, max 200, each `name` string max 255, `email` nullable string max 255, `designation_id` nullable int), `without_login` boolean; for preview also `text` (string, max 20000) as the alternative to `people`. `authorize()` = `WorkspaceAccess::canCreateMember($user, $organizationId)`.
- Preview answers `{rows: list<plan row>, without_login_count: int}`; the web route answers JSON to a `fetch` (the pattern in `TaskPeek.svelte` / `DependsOnPicker.svelte`), the API the same.
- `InviteDialog` (mockup `inviting-people.html`): the textarea ("Paste names and emails, one person a line."), the preview list (debounced 400 ms) with each person's name, email or "Needs an email", a designation select (the organization's designations) for `ready` rows, and the status in words ("Ready", "Will get a new link", "Needs an email", "Needs a name", "Already here", "Deactivated", "Can't invite"); the checkbox "Also invite the N people in the organogram who have never signed in" when N > 0; "Links work for 7 days."; the button "Send N invites" (N = ready + resend), disabled at 0. Bounded: max height 620px, the list scrolls. After sending: the flash and the dialog closes.

- [ ] **Step 1: Failing tests:** an organizational admin invites two new people → two users and members in their organization, `invited_at` set, two `SetPasswordLink` mails (run the job synchronously in tests); a pasted line without email is `needs_email` and not created; an existing member without a login gets a login linked to that member, no second member; a person already signed in is `already_here`; an email of another organization's person is `already_here` and nothing about them changes; a deactivated member is `deactivated`; re-inviting someone who never signed in sends a fresh link and creates nothing; `without_login` adds the organization's members with no login; a designation of another organization is dropped; a project manager (not an admin) gets 403; an admin cannot invite into another organization (403); a super-admin must name the organization; posting forged `status: ready` rows for an `already_here` email creates nothing; 201 people is a 422. API twins answer the same.
- [ ] **Step 2:** Fail, implement, pass; frontend checks, build, full suite.
- [ ] **Step 3: Look at it:** screenshots of the dialog with 4 rows and with 40 rows (scrolling) at 1440 and 390, and the rendered invite mail. Paths in the report.
- [ ] **Step 4:** Commit `People: invite many at once by email`.

### Task 13: Guide, CLAUDE.md and the API reference

**Files:**
- Modify: `resources/js/pages/Guide.svelte`, `CLAUDE.md`, `docs/api.md`, `README.md` (only the parts that describe the sidebar, Home, My Workspace, email or the queue)

**Interfaces:** documentation only.
- Guide: the sidebar section describes My work, Projects, People and the account menu (where Notifications, Emails, Guide and the office pages now are), the phone bar, the morning email and right-away email (and how to turn them off), "Forgot your password?", and inviting people (for organizational admins). Keep its role and "In a ministry" sections.
- `CLAUDE.md`: "Sidebar" (Structure section) describes `Sidebar`, `AccountMenu`, `PhoneTabs` and `navigation.ts`'s `mainLinks` / `accountGroups`; "In-app notifications" adds email (`SendEmailUpdates`, its kinds, `email_handled_at`, the quiet and max-wait minutes, preferences) and the morning email (`SendMorningEmails`, `MorningEmailQuery`, `email_digests`); "Two delivery surfaces" names My work (`MyWorkQuery`, `/workspace/my`, `/api/v1/workspace/my-work`) and that `/workspace` redirects there; the authorization section adds `canUpdateAssignment` / `ownsAssignment`, invites (`InvitePlan`, `canCreateMember` / `canManageMember`, never another organization) and the password brokers; a short "Calendar" note for `BikramSambat` and `WorkingDays`; the commands list adds `workspace:send-email-updates --pretend` and `workspace:send-morning-emails --pretend`.
- `docs/api.md`: My work, forgot / set password, invites (preview and send), the preference fields.

- [ ] **Step 1:** Write them; `grep -n "My Workspace\|NavRail\|NavPanel\|rail of areas" CLAUDE.md docs README.md resources/js` returns nothing stale.
- [ ] **Step 2:** Frontend checks, build, full suite.
- [ ] **Step 3:** Commit `Docs: the guide, CLAUDE.md and the API for phase 1`.

---

## Deploy notes (for the owner, not a task)

- Migrations: `last_seen_at`, email settings (marks every existing notification as handled), `email_digests`, `invite_tokens`, `invited_at`. Back up first (`./dump_tasks.sh`).
- The scheduler container sends all new email; no queue worker is needed. `PM_MORNING_EMAIL_AT` defaults to 09:00.
- Check that the SMTP settings work from the VM before telling people (`php artisan tinker` → send one `SetPasswordLink` to yourself).
