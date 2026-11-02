# Workspace overhaul: one list, for everyone

Date: 2026-11-02. Status: approved by the owner on 2026-11-03 ("go ahead,
build it"), with the open questions left to the designer (see the end).
Mockups: the "Workspace overhaul" canvas (https://claude.ai/artifact/DM2EcqxiXrf8pHtiKPfREu),
row "Final. One list, for everyone" plus row "A. One list". Rows B and C are
the directions not taken; two pieces are borrowed from them (see Decisions).

## Why

People say the whole system is hard to use, and most of the people it is for
do not use it. A usage audit of production (dump of 2026-11-02):

- 12 people in 3 organizations; one administrator did about 315 of roughly 380
  recorded actions.
- 84 tasks: 47 with nobody on them, 67 with no date. 3 comments in total.
- Projects are used as workarounds: projects named after people ("Dubesh
  Shrestha"), a personal list ("A. Personal"), and numbered projects that are
  really sections of one project ("1. Resources" to "9. Miscellaneous").
- Home and My Workspace both open on counters, and repeat each other.
- About 15 places to go, in 4 areas (Work, Office, Reports, Admin). Attendance,
  leave and git are unused.
- Small grey text.

The target, in the owner's words: those who don't use it today use it. That
is the devs, QA and engineers, the officers, the joint secretaries, the
secretary, the chief secretary and the minister.

## What success looks like

Measured per organization, from a baseline taken on the day phase 1 ships:

1. People active in a week, out of the people with a login (most people have
   no login today, so invitations count too).
2. Share of open tasks with someone on them, and with a date.
3. Updates (comments) posted per week.
4. Distinct people who added or closed a task in a week (today it is mostly one).

The Overview shows the first number to whoever administers the organization
("Who is using it"). Nothing ranks people by a number.

## The idea in one paragraph

Something is waiting for you, you are told outside the app, you can answer in
one tap from your phone, and your boss reads what you answered. Every part of
this design serves that loop: a single list of your work, email that brings
you to it, a phone layout, a way for leaders to ask for an update, and an
overview that leaders actually open, so that updating it is worth it for
everyone below them.

## Goals

- One page for your own work, the same for every person: My work.
- One page for whoever runs something (a project, a program, an organization,
  or all of them): Overview.
- Three to five places in the menu instead of about fifteen.
- Email that brings people back: when something is given to you, when you are
  mentioned, when someone asks you for an update, and one short morning email
  on working days when something is waiting.
- Getting everyone in: invite people in bulk; a forgotten password can be
  reset by email.
- Every page works on a phone.
- Projects that fit how people already work: sections inside a project, a
  people view to hand out work, and a private list for your own to-dos.
- Fit for a Nepali government office: the week starts on Sunday, dates also
  show in Bikram Sambat, and quick add understands Nepali date words.
- A path for developers and QA: copy the branch name, see the code on the
  task, send work for checking and get it back.

## Non-goals

- No native mobile app. The web app works on phones.
- No Viber, SMS or WhatsApp messages in this overhaul (email only).
- No change to who may do what (the five tiers in CLAUDE.md stay). New
  actions get their own rules in `WorkspaceAccess`.
- Attendance, leave, the organogram, work reports, code activity and git stay
  as they are. They leave the main menu; they are not deleted.
- No automatic moving of existing production data. The numbered projects and
  the projects named after people stay as they are until an administrator
  moves their tasks (see Projects).
- No AI features.

## Anti-goals (would make this a failure even if it works)

- A system people fill in for their boss and nothing comes back to them.
- Pages that open on counters and charts instead of the work.
- Ranking or shaming people by numbers.
- Email people learn to ignore: more than one digest a day, a digest when
  nothing is waiting, or one email per small change.
- Old links in emails, bookmarks and notifications that stop working.
- A layout that breaks when a list grows (every list, dialog and popover is
  bounded and scrolls).

## Constraints

Everything in `CLAUDE.md` and `~/rayzen/CLAUDE.md` applies. The ones that
shape this work most:

- Route, thin controller, FormRequest `authorize()` through `WorkspaceAccess`,
  action service returning `ServiceResult`, JsonResource. Every new action and
  read exists on both web and API.
- Every new role decision is a `WorkspaceAccess` method with happy, failure and
  privilege-escalation tests. List twins (a query scope that must agree with a
  single-item rule) are named and kept in step.
- Activity rows are written only by observers; new polymorphic subjects go in
  the morph map.
- The theme layer stays: semantic tokens only, Geist / Geist Mono / Mukta, one
  calm blue accent, green / amber / red only for status, deadlines and
  priority; no coloured pills, no em-dashes, Lucide icons, no eyebrow labels.
- JSON columns are `jsonb`. Redis keys always expire.
- Another session works in the same checkout: build on a branch in a
  worktree, never amend or reset its commits.
- Commit, push and deploy only when the owner asks. Back up with
  `./dump_tasks.sh` before any deploy that migrates.
- The Guide (`pages/Guide.svelte`) is rewritten to match when the menu changes.

## Who it is for, and where each person lands

| Person | Role in the system | Lands on | What they do most |
|---|---|---|---|
| Officer, engineer, developer, QA | member, often a project's manager | My work | do today's tasks, post an update, add a task |
| Under / joint secretary | project or program manager | My work (Overview one click away) | hand out work, ask for updates |
| Secretary, minister | organizational admin | Overview | read the week, ask for updates, give directives |
| Chief secretary, SDOC | super-admin | Overview, all organizations | see which ministry is stuck |
| Office IT / admin | organizational admin | Overview | invite people, keep people and projects tidy |

"Runs something" means: leads a project's team, manages a program,
administers an organization, or is a super-admin. Whoever runs something sees
Overview in the menu. Organizational admins and super-admins land on it;
everyone else lands on My work.

## Menu

Desktop: a single sidebar (as in the mockups), replacing the rail of areas.

- Search (`/`)
- My work
- Overview (only for whoever runs something)
- Projects, with the projects you are on listed under it (pinned first)
- People (the people directory, today's Team page)

Everything else moves to the account menu at the bottom of the sidebar:
Notifications, Appearance, Guide, and for those allowed: Needs attention
(today's Maintenance), Organogram, Attendance and Leave (only when attendance
is on for the organization), Code activity (only with git), Organizations
(super-admin), the 100-point tracker (where it is used today).

Phone (narrower than 768 px): a bottom bar with My work, Overview (only for
whoever runs something), Projects, Updates (notifications, with the unread
count) and Me (the account menu). Adding a task is the field at the top of My
work and of a project.

The organization switch for super-admins stays (top of the sidebar), with
"All organizations" as today.

## Screens

### My work (everyone)

Mockups: "A: My work", "Final: On a phone" (left).

- Header: "My work", the date in both calendars ("Monday, 2 November ·
  कार्तिक १६"), "Show done".
- The add field (see Adding a task).
- Groups, in this order, each with a count; empty groups are not shown:
  - Today: late tasks first (red "2 days late"), then tasks due today.
  - Tomorrow (the next working day, so on a Friday it is Sunday's work).
  - Coming up: the rest of this week and the next.
  - Later: anything further out (collapsed when more than 5).
  - You asked others: open tasks you created that are on other people, with
    their person and date.
  - No date: collapsed by default.
- A task is on your list when you are one of its people (assigned) and it is
  not closed. "You asked others" is tasks you created (`tasks.created_by`)
  where someone else is on it and you are not.
- Each row: the circle (tap to mark done; half-filled when started), the
  title, project / section, and on the right the person or the date. Under
  the title, the latest update, when there is one in the last 7 days
  ("Bibek: Three districts have not sent their data yet. · 2 days ago").
  A pending "asked for an update" shows instead as a blue line ("Rabin asked
  for an update").
- Your private to-dos (see Projects) sit in the same groups, marked "Only you"
  where the project would be.
- The date that decides a row's group is the person's own due date on the
  assignment (`personal_due_at`) when set, otherwise the task's date.
- Kept from My Workspace (designer's call): starring (`is_focused`; starred
  rows first within their group) and snoozing (`snoozed_until`; a snoozed task
  leaves its group until that date and sits in a collapsed "Snoozed" group at
  the end), both from the row's menu. Open subtasks with a date appear in the
  date groups under their task's title, as they appear on My Workspace today.
- Sticky notes stay as they are (top bar and command palette). The recent task
  notes and contacts lists leave My work; they stay on each task.
- Replaces Home and My Workspace. `/workspace` sends each person to their
  landing page; `/workspace/my` redirects to My work.

### Overview (whoever runs something)

Mockup: "Final: Overview for leaders".

- Scope: the largest thing the viewer runs, with a picker to narrow it: all
  organizations (super-admin), an organization (its admins), a program (its
  manager), a project (its manager). Period: this week (from Sunday) or this
  month.
- One plain sentence first: how many tasks were finished in the period, how
  many are late, how many have nobody on them.
- Late: open tasks past their date in scope, most late first, each with its
  person, how late, the latest update, and "Ask for an update" (or "Asked
  yesterday" once asked, see below). The first 5, then "Show N more".
- Nobody on it: open tasks in scope with no one assigned, counted per project;
  each opens that project's people view to hand them out.
- Projects: one row per active project in scope: name (with its Nepali name
  when it has one), manager, done of total with a thin bar, late count, and
  when it was last updated (amber text when quiet for `QUIET_DAYS`, the same
  constant Maintenance uses).
- You asked: tasks the viewer created that are on others (same as My work's
  group), with their latest update.
- Who is using it (organizational admins and super-admins only): people active
  this week out of people in the organization, how many have never signed in,
  and "Invite them".
- For all organizations: the same sections, with Projects replaced by one row
  per organization (late, nobody on it, active people).
- Overdue, closed and complete always come from the existing rules
  (`scopeOverdue`, `Task::closedStatuses()`, `Task::completeStatuses()`).

### A project

Mockups: "A: A project" (list), "C: A project" (people view).

- Header: title (and Nepali title), manager, people, "Add a section".
- Three views, remembered per person: List (default), People, Timeline (the
  existing Gantt / progress page).
- List: tasks grouped by section, in section order, then "No section". Each
  section can be collapsed, renamed, moved and deleted (its tasks move to "No
  section"). A task with nobody on it shows "Assign" in the person column.
- People: one column per person on the team plus "Nobody yet" first; drag a
  card onto a person to give it to them (the same assignment rules as today:
  `Assignees::joiners`); on a phone, the card's menu has "Give to".
- The Board view and the current tabs stay reachable from the List view's
  menu during phase 3 and are removed only if nobody uses them for a month.

### A task

Mockups: "Final: A task, for devs and QA", "Final: On a phone" (right).

- Breadcrumb (project / section) and the task code (`LLS-42`).
- Title; then status, people, date (with Bikram Sambat), "Asked by" the
  creator.
- Actions: "Mark done" (primary); "Send for checking" (see Developers and QA);
  the existing menu (move, delete when allowed, and so on).
- Description.
- Updates: one thread, oldest first, newest at the bottom, mixing comments
  with the events people care about (asked for an update, status changes, pull
  request opened / merged, tests passed / failed). The box at the bottom posts
  an update; @ mentions as today. When an update was asked for, the box is
  shown first, highlighted, with the asker's name, and a "New date" choice.
- Side: Code (branch name to copy, pull requests with their checks; only where
  `canViewProjectCode`), People on it.
- On a phone the same, in one column, with "Mark done" fixed at the bottom.
- Slide-over (Peek) and full page show the same content.

### Adding a task

Mockup: "A: Adding a task".

- One line. Typed words that are understood are highlighted and become chips:
  dates (English as today: today, tomorrow, weekday names, `2026-11-06`, "nov
  6"; and Nepali: आज, भोलि, पर्सि, आइतबार to शनिबार), a person with `@`, a
  project with `#`, priority with `!`. The server's `QuickAddParser` stays the
  authority; `quickAddTokens.ts` mirrors it for highlighting.
- Chips under the field (date, project / section, who) can be set without
  typing anything special. Typing `@` or clicking "Who" opens a people list
  (bounded, scrolls).
- The project defaults to the project being viewed, otherwise the last project
  the person added to. With no project and nobody else on it, the item is a
  private to-do. With someone else on it, a project is required ("Pick a
  project"), as today.
- Enter adds and keeps the field open for the next one.

### Email

Mockup: "Final: The morning email".

- Production already sends email: adding a person emails their login
  details (`SendLoginDetailsService`) through `smtp.nepal.gov.np` as
  `no-reply@sdoc.gov.np` (`docs/forgejo.md`). New email uses the same sender.
- Sent by mail, queued (never in the request), in English, plain and short,
  with one link into the app. Links only open pages; no email link changes
  anything (mail scanners open links).
- Right away (one email per event, grouped when several arrive within 5
  minutes for the same person): a task given to you, you were mentioned,
  someone asked you for an update, an update you asked for was posted, you
  joined a project's team or became its manager.
- Morning email at 9:00 on the organization's working days, only to people
  with something late, due today or new since the last one. Subject says it
  ("Today: 3 tasks, 1 late").
- Weekly brief on the first working day of the week at 9:00, to whoever runs
  something: the Overview's sentence, late tasks, nobody on it, their own
  "You asked".
- Each person can turn off each kind (right away / morning / weekly) on a
  small Emails settings page, linked from every email. All on by default.
- In-app notifications stay as they are; email is added beside them.
- The existing deadline reminders stay in the app; the morning email replaces
  any email version of them.

### Getting people in

Mockup: "Final: Inviting people".

- Whoever may create people (`canCreateMember`) can invite many at once:
  paste "Name, email" lines; a preview shows each person as ready, needs an
  email, or already here; a designation can be chosen per person. A checkbox
  adds the organization's people who have no login yet.
- Inviting creates the person (if new) and a login with no usable password,
  and emails a link to set one. Links last 7 days; an expired link offers to
  send a new one. Re-inviting sends a fresh link.
- The login page gets "Forgot your password?", using the same email and
  link. It always answers the same way whether or not the email is known, and
  is throttled.
- A deactivated person can neither be invited nor reset a password
  (`canSignIn`).
- Today's add-person flow stays as it is (the admin sees the password and the
  login details are emailed), so a person whose mailbox rejects our email can
  still be given a login by hand (designer's call). Invites are added beside
  it; the person form and the API's create-person contract do not change.

## Projects: sections and private to-dos

- **Sections**: a project has ordered sections (name, position). A task is in
  at most one section of its own project. Anyone who can edit the project
  manages its sections; anyone who can add tasks can choose a section. Moving
  a task to another project clears its section. Sections are logged like other
  project changes.
- **Moving the old workarounds**: a "Move to" action on a selection of tasks
  (to another project and section) lets an administrator turn "1. Resources"
  to "9. Miscellaneous" into sections of one project and empty the projects
  named after people. No automatic migration; a one-off command can be written
  later if the owner asks. The rules (designer's calls):
  - Who: whoever passes `canManageProjectAccess` on both the source and the
    target project, and only within one organization (never into another
    organization's project, cross-organization teams included). A new
    `WorkspaceAccess::canMoveTask(User, Task, Project)`.
  - People: every person on a moved task must be on the target project's team
    already, or be someone the mover may bring onto it (`Assignees::joiners`
    for the target), who then joins through `AddTeamMemberService` in the same
    transaction. If anyone fails that, the whole move is refused and the
    message names who blocks it. Nobody loses an assignment by a move.
  - Numbers: a moved task gets the next number of its new project (numbers
    stay unique per project and are never reused). Its old code keeps working
    in search, commit and branch references and `/go` links, through a table
    of old task codes, like `project_key_aliases` for project keys.
  - Dependencies: dependencies between tasks moved together are kept;
    dependencies to tasks left behind are removed, and the move dialog says
    how many before it is confirmed. The removal is logged.
  - The section is cleared unless one of the target's sections is chosen.
- **Private to-dos**: a person's own items with a title, an optional date and
  done / not done. Only their owner can see or change them, through any
  surface. They show in My work and nowhere else, are never counted in
  Overview or reports, and never notify anyone. "Move to a project" turns one
  into a task (the person picks a project they may add to) and removes the
  to-do. This replaces using a project as a personal list.

## Asking for an update

- On a task that is open and has someone on it, "Ask for an update" sends the
  people on it an in-app notification and an email, and adds "X asked for an
  update" to the task's updates.
- Who may ask: the task's creator, and whoever runs its project (its manager,
  its program's manager, its organization's admins, super-admins), while they
  can view the task. A new `WorkspaceAccess::canAskForUpdate(User, Task)`.
- At most once per task per day per asker; asking again inside that time
  answers "Asked yesterday" (or the time) and sends nothing.
- The request is answered by the next update (comment) posted by one of the
  task's people; the asker is then notified. Changing the date or marking it
  done also answers it.

## Developers and QA

- Branch name to copy on the task (the key and number are already parsed from
  branch names: `LLS-42-...`).
- A new status `in_review` (label "In review"): started, not complete, not
  closed. "Send for checking" picks who checks it (from whom the sender may
  assign, `Assignees::joiners`), adds them to the task's people, sets
  `in_review` and posts the optional note as an update. The checker gets it in
  Today on My work. Then two buttons: "Passed" (done) and "Send back"
  (back to `in_progress`, with a note that becomes an update).
- It is a convenience, not a gate (designer's call): whoever may change the
  task's status today (anyone who can view its project) may send it, pass it
  or send it back, so who may do what does not change. The checker sees the
  two buttons first; others find them in the status menu.
- One checker at a time, never the sender. "Send back" takes the checker off
  the task's people (the updates keep the history), so it leaves their list
  and their morning email; "Passed" leaves them on the closed task.
- Nothing about git changes status automatically in this overhaul.

## Dates and the week

- The week starts on the organization's first working day; with attendance
  on, `AttendanceCalendar` decides working days and holidays; without it,
  Sunday to Friday are working days. "Tomorrow" and the morning email follow
  working days.
- Bikram Sambat shows beside the date in page headers, the date picker and a
  task's date, never in every row. One converter in PHP and one in TypeScript,
  from the same month table (2070 to 2100 BS, the table in
  `~/rayzen/patro/api/app/Services/BikramSambat/DataSource.php`), tested
  against known dates (2026-04-14 is 2083 Baisakh 1; 2026-11-02 is 2083
  Kartik 16).

## Look

- The existing theme layer and fonts stay; the mockups of row A and the Final
  row use it.
- Body text 15 px, secondary text 13 to 13.5 px, rows at least 44 px high
  (touch), muted text at least 4.5:1 contrast in light and dark.
- Every list, dialog and popover has a maximum height and scrolls inside
  (the first New project mockup broke when many people were added).
- Words: shorter labels, the names people use ("Updates", "People on it",
  "Nobody on it", "Ask for an update", "Send for checking").

## Data changes (outline; exact columns are the builder's)

- `users`: `last_seen_at` (updated at most once an hour by middleware on
  authenticated requests, for "Who is using it" and the success measures),
  email preferences (three on/off flags or one `jsonb`).
- `project_sections` (project, name, position); `tasks.section_id` nullable,
  null on delete.
- `task_update_requests` (task, asked by, asked at, answered at).
- `personal_todos` (user, title, due date, done at).
- Old task codes kept after a move (old project, old number, task), read by
  search, `TaskReferences` and `/go`.
- A password-reset broker for invites with a 7-day expiry, separate from the
  forgotten-password one (60 minutes).
- Status `in_review` in `config/project-management.php`, with its own glyph in
  `StatusGlyph` (no new colour).

## Phases

Each phase ships on its own, behind nothing, with its tests, and is deployed
only when the owner says so.

1. **Reach and My work**: the new menu and phone bar, bigger type, My work
   (replacing Home and My Workspace, with redirects), latest update on rows,
   quick add with Nepali date words and chips, email right away (given to you,
   mentioned, joined a team), the morning email, email settings, invites,
   forgotten password, `last_seen_at`.
2. **Leaders**: Overview with its scopes, asking for an update (with the
   answered notification), the weekly brief, "Who is using it".
3. **Projects and tasks**: sections, List / People / Timeline views, the task
   page with its updates thread, "Move to", private to-dos.
4. **Devs, QA and dates**: `in_review` and sending for checking, branch name
   copy, Bikram Sambat dates.

A Nepali interface (all labels in Nepali, chosen per person) is not in these
phases; see Open questions.

## Left to the builder

- Exact column names, indexes, and query shapes (keep every list under one
  query per section; no N+1 for latest updates).
- How the morning and weekly emails are scheduled per organization (one
  command walking organizations is fine).
- Email templates (Laravel mail notifications or Markdown mailables), as long
  as they read like the mockup.
- Component structure in Svelte, and whether Peek and the full task page share
  one component.
- Which existing pages become redirects and which keep a route without a menu
  entry, following "no old link stops working".

## Open questions, settled by the designer

The owner approved the design without answering these; the designer's calls
stand until the owner says otherwise.

1. Email delivery: assumed to reach the ministries, since adding a person
   already emails login details that way. If it does not, today's add-person
   flow (the admin sees the password) is the fallback, and the Emails
   settings page lets nobody miss work because of it: everything an email
   says is also on My work and in the in-app notifications.
2. Nepali interface: not now. Labels stay in English, written plainly; dates
   show in Bikram Sambat and quick add understands Nepali date words. A
   translation layer is a separate piece of work if the owner asks for it.
3. Leaders' role: no sixth tier. The minister and the secretary are
   organizational admins and get the organization-wide Overview.
