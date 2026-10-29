# Programs: design

Date: 2026-10-29. Status: draft for approval.

## Intent

A ministry wants the tracker to follow its hierarchy:

1. Minister, Secretary, Secretariat: all of the ministry.
2. Joint Secretary: all of their division.
3. Under Secretary: all of their sub-division.
4. Officer: their own work.

The tracker already has three of these as project roles. The owner's rule is
that the ministry adopts the tracker's project-management roles, each mapped
to a government title, rather than the tracker learning government ranks. The
one role missing is the level between an organization and a project. That is
a **program**: a named collection of projects with one program manager.

| Ministry | Tracker role | Today |
|---|---|---|
| Minister, Secretary, Secretariat | Office admin | exists, unchanged |
| Joint Secretary | Program manager | **new** |
| Under Secretary | Project manager | exists, unchanged |
| Officer | Team member | exists, unchanged |

Government titles stay free text on each person (`members.title`), as they do
today. The code never knows what a Joint Secretary is; it knows who manages
which program. The mapping lives in hints and the Guide only.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Shape | A program is a collection of projects: name, optional description, one manager, its organization. No tasks, no team, no deadline of its own. | Owner, 2026-10-29: "just a collection of projects, nothing more". |
| Program manager's powers | On every project in their program, exactly what that project's own manager can do today. | Owner: "basically a project manager but can manage all projects within that program". |
| The project's own manager | Unchanged. The Under Secretary stays the team's one leader; the program manager manages from above, through the program, and is never added to the team as leader. | Keeps the one-leader-per-team rule and every check built on it. |
| Who creates programs and puts projects in them | Office admins (and super-admins) only. | Owner: office admins are the ministry's leadership and set things up; project managers can't create projects today either. |
| Oversight follows projects, not people | A program manager sees the work inside their program's projects, not their people's work on other programs' projects. | Visibility in the tracker is already decided per project; following people would need an org chart and task-level visibility. The owner chose the program mapping over an org chart. |
| Optional | An organization with no programs behaves exactly as today. A project outside any program behaves exactly as today. | Owner: these roles must not be compulsory. SDOC may never use them. |
| Officers | Team members exactly as today: they see the whole board of every project they're on. | Changes nothing; "individual access" is read as "no oversight of anyone else". |
| Cross-organization teams (builder's call, owner to check) | On a project whose team a super-admin opened to other organizations, the program manager has the same roster powers as that project's manager: search people of any organization, add and remove them. | Follows the owner's rule literally. The super-admin opened that door on purpose; the program manager stands above the manager who already has it. The program page only shows those people's work on the program's projects. |
| People directory, mentions, assignment (builder's call) | A program manager sees the teams of their program's projects and the people on them, as a project manager sees their team. They can be @mentioned on their program's tasks. They can be given a task only after joining that project's team as an ordinary member. | Same reach as a project manager, who sees their team because they sit on it. Assignment stays tied to the team, as today (`scopeAssignableFor`). |

## Goals

- An office admin creates a program, names its program manager, and puts projects in it.
- A program manager manages every project in their program as its own manager can.
- A program manager has a program page: every project's progress, overdue work, the workload of everyone on those projects, one timeline and recent activity, across the whole program.
- The Projects page shows projects grouped under their program when the organization has programs.
- Hints next to each role picker, and a table in the Guide, make the ministry mapping obvious.
- The JSON API offers everything the web does (`docs/api.md`).

## Non-goals

- Program tasks, program teams, program deadlines, budgets or reports beyond the program page.
- Programs inside programs.
- Divisions, sections, heads or any org chart; reporting lines between people.
- Automatic project managers (an Under Secretary is chosen as the project's manager, as today).
- Government ranks as data or code. Titles stay free text.
- A program manager creating projects or programs, choosing any project's manager, or editing people.
- Any change to what officers (team members) see.

## Anti-goals (a failure even if it works)

- An organization without programs notices any difference beyond the hint text and an admin's "New program" button.
- A program manager reaches a project outside their program, anyone's account, or anything in another organization beyond what that project's own manager reaches (its cross-organization team, when a super-admin opened one).
- A project's team ends up with a second leader.
- Removing a project from a program, or deleting the program, leaves the program manager with access.

## The model

- **`programs`**: `id`, `organization_id` (required), `name`, `slug` (route key, unique), `description` (nullable), `manager_id` (required, a `members` row), timestamps.
- **`projects.program_id`**: nullable, foreign key to `programs`, `nullOnDelete`. A project is in at most one program.
- `Program` model: `belongsTo` organization and manager (`Member`), `hasMany` projects; `Project::program()`; `Organization::programs()`. Add `program` to the strict morph map in `AppServiceProvider` only if it becomes a polymorphic subject (activity or notifications).
- Rules, enforced in the FormRequests and services:
  - A program's manager is an active person of the program's organization.
  - A project joins only a program of its own organization.
  - Deleting a program never deletes a project: its projects leave the program.

## Access (`app/Support/WorkspaceAccess.php`, security critical)

Add, next to `ledTeamIds`:

- `managedProgramIds(user)`: the ids of programs the user's member manages, only while that member is active (mirror `officeAdminOrganizationId`'s `is_active` check). Memoized per user and flushed with the other memos.
- `managesProgramOf(user, Project)`: the project's `program_id` is in `managedProgramIds`.
- `canManagePrograms(user, organizationId)`: `administers(user, organizationId)`.

Extend:

| Method | Gains |
|---|---|
| `canViewProject` | `managesProgramOf` |
| `Project::scopeVisibleTo` (its twin) | `orWhereIn('program_id', managedProgramIds)` |
| `canManageProjectAccess` | `managesProgramOf`. This carries progress (`canViewProjectProgress`), archiving and repository linking with it, as for a project manager. |
| `canViewProjectCode` and its twin `Project::scopeCodeVisibleTo` (git phase 1) | `managesProgramOf`, where git is on. A project manager sees code by sitting on the team; the program manager stands above them and can already link repositories. |
| `canManageRosterOf`, `canViewTeamProgress` | the program manager of the team's project |
| `canRemoveFromTeam` | the program manager of the team's project, under the same limit as a leader: never the project's manager |
| `Team::scopeVisibleTo`, `Member::scopeVisibleTo` | the teams of projects in a program they manage, and the people on those teams |

Unchanged on purpose: `canEditTeam` (choosing a project's manager),
`canCreateProject`, `canPublishProject`, `canChangeProjectKey`,
`canManageMember`, `canJoinTeam`, `Member::scopeAssignableFor`. A program
manager gains no power over anyone's account. Their projects are all in
their own organization; the only way past it is a team a super-admin opened
to other organizations, where they reach exactly what that project's manager
reaches (`canJoinTeam` and `TeamCandidatesQuery` as today).

`UpdateProjectRequest` drops `program_id` for anyone who doesn't administer the
project's organization, the way it drops `manager_id`, `is_public` and
`is_cross_organization` today.

## Web and API

Following the repo's pattern (Service + FormRequest + Resource once, thin
methods in both controller pairs, routes in both route files):

- **Programs**: list, create, update (name, description, manager), delete; office admins and super-admins. Show (the program page) for them and the program's manager.
- **Projects**: `program_id` on create and update (admins only); `ProjectResource` carries `program {slug, name}` when it has one.
- **Program page**: `/workspace/programs/{program}`, a report polled every 15 s like the project progress page. Top: one row per project (progress, open and overdue counts, next deadline, its manager). Below: `ProgressReport::build` over every task of the program's projects, with everyone on those teams as people. `ProgressReport::TASK_LIMIT` and its `truncated` flag apply.
- **Shared context**: `managedProgramIds` in `ShareWorkspaceData`, `managed_program_ids` in `Api\AuthController::userPayload`.
- **Projects page**: when the organization has programs, group projects under their program (each heading links to the program page), then "No program". With none, keep today's grouping by manager. Office admins get "New program" next to "New project".
- **Sidebar**: a program manager sees the programs they manage, linking to each program page.
- **Activity**: a project moving into or out of a program is a project-level entry (`task_id` null), written by an observer like every other entry.
- **Mentions**: the comment box on a program task offers the program manager alongside the team, and a mention notifies them (they pass `canViewProject`).
- **Notification**: `MadeProgramManager`, in-app, when someone becomes a program's manager (never to the person who did it), with the stable payload and a `program {slug, name}`.

## Hints and Guide

- Hint under the **program manager** picker: "In a ministry, usually a Joint Secretary."
- Hint under the **project manager** picker: "In a ministry, usually an Under Secretary."
- Hint by the **office admin** switch (super-admin only today): "In a ministry: the Minister, Secretary and Secretariat."
- **Guide** (`pages/Guide.svelte`):
  - "The idea" gains Program between Organization and Project, marked optional.
  - "Who does what" gains a Program manager card, and the reader's role is detected as program manager when `managedProgramIds` is not empty (after office admin, before project manager).
  - A new section, "In a ministry", with the mapping table above.
- Copy follows the theming rules in `CLAUDE.md` (no em-dashes, semantic colours, Lucide icons).

## Tests (Pest)

`CLAUDE.md` asks for happy, failure and privilege-escalation tests on any
change to `WorkspaceAccess` or a service. At least:

- A program manager sees, edits, archives and manages the team of every project in their program, and sees its progress.
- They see nothing of a project outside the program, nor of another organization's.
- On a cross-organization team in their program they search, add and remove exactly whom the project's manager can; on a team that is not cross-organization, only people of their organization.
- The People directory shows them the teams of their program's projects and those teams' people, and nobody else beyond what they saw before.
- They can be @mentioned on a program task and are notified; they can't be given a task until they join its team.
- They cannot: create a project or program, set `program_id`, choose or remove a project's manager, publish a project, change a key, edit or deactivate a person.
- A deactivated program manager has no program powers.
- Taking a project out of the program, and deleting the program, both end access; deleting leaves every project intact.
- A program's manager must be an active person of its organization; a project joins only a program of its organization.
- `canViewProject` and `scopeVisibleTo` agree for a program manager, as do `canViewProjectCode` and `scopeCodeVisibleTo`.
- Only office admins and super-admins manage programs, on web and API.
- Every existing test passes unchanged, except ones that list every shared prop or resource key, which gain the new ones.

## Docs to keep in step

`CLAUDE.md` (the four tiers become five; the domain model gains Program),
`docs/api.md`, and the Guide.

## Left to the builder

- Layout of the program page, the Projects grouping and the sidebar entry, within the theming rules.
- Program slug format.
- What happens when a program's manager is deactivated or deleted: mirror today's handling of a project manager. Either way they lose every program power (tested above).
- Whether the program page reuses `ProjectProgressQuery` or gets its own query next to it.

## Owner's answers (2026-10-29)

- Map the tracker's roles to government titles; don't model ranks.
- Office admin = Minister, Secretary, Secretariat. Program manager = Joint Secretary. Project manager = Under Secretary.
- A program is just a collection of projects; its manager manages all of them as a project manager would.
- Everything here is optional per organization.
- Show hints so the mapping is clear, and list it in the Guide.
