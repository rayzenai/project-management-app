# Roles on people, program managers who build, and SDOC's keys: design

Date: 2026-10-30. Status: approved in conversation (owner's answers below).

## Intent

Programs shipped on 2026-10-30 (`docs/superpowers/specs/2026-10-29-programs-design.md`). Using them on real data showed four gaps:

1. "Office admin" reads badly. The role is the organization's administrator.
2. An admin can only make someone an admin from the person form. Making someone a program manager, a project manager or a team member means going to each program or project. The owner expects to set a person's roles where the person is edited.
3. A program manager (a Joint Secretary) should build their division's work: create projects in their program and choose who manages them.
4. A new program manager should be able to add a task and assign someone straight away, even before any project exists.

Production also carries six SDOC projects whose keys are still the automatic ones, not the keys SDOC agreed. The owner does not want `SdocProjectsSeeder` run on production.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Name | "Organizational admin" everywhere a person reads it (sentence case in labels). Code, database and API field names stay (`is_office_admin`, `isOfficeAdmin`, `officeAdminOrganizationId`). | Owner, 2026-10-30. Renaming identifiers would break the API and buy nothing. |
| Roles on the person form | For people who may manage the person (an organizational admin of their organization, or a super-admin): "Organizational admin" (super-admin only, as today), "Program manager of", "Project manager of", "Member of". A person can hold several. | Owner: "I can only set admin, but not other roles". One person often holds several roles (Avishek is admin and manages six projects). |
| Replacing a manager | Choosing "Project manager of X" makes them X's manager; X's previous manager stays on the team as a member. Choosing "Program manager of P" replaces P's manager. Each option shows who manages it now. | One manager per project and per program is a rule the app depends on. |
| Taking a role away on the person form | Not for manager roles: a program or project always has a manager, so it is handed over by choosing someone else. A person can be taken off a team they don't manage. | Never leaves a project or program without a manager. |
| Program manager creates projects | In their own program(s) only. They choose the project's manager among active people of their organization (default themselves). The key is suggested automatically; publishing and opening a team to other organizations stay with admins. | Owner, 2026-10-30. |
| Program manager replaces project managers | On every project in their program, they can replace its manager (the Joint Secretary assigns Under Secretaries). They still can't remove a manager without replacing them, or set the cross-organization flag. | Owner's answer: "They choose, can change later". |
| Default project | Creating a program also creates a project called "General" in it, managed by the program manager. | Owner's answer: "Named General". |
| Tasks | No change: anyone who can open a project can add tasks to it, so project managers and program managers already can. | Already true. |
| SDOC keys | A migration renames SDOC's projects to the agreed keys (SG, EGP, GIWMS, SUTRA, GIOMS, HS, NAGARIK, OSOP), only where a project still has its automatic key, matched by title. The old key stays as an alias. It does nothing where SDOC or a project is missing. | Owner: fix by migration, don't run the seeder. |

## Non-goals

- Creating people, projects or programs from a migration.
- Choosing SDOC's organizational admin or its projects' managers (data the owner decides).
- A program manager managing people's accounts, publishing projects, changing keys, opening teams to other organizations, or moving projects between programs.
- Organizational admins making someone an organizational admin (still super-admin only).

## Anti-goals

- A project or program left without a manager by anything in this change.
- A team ending up with two managers.
- An organizational admin reaching another organization's programs, projects or people through the person form.
- A program manager creating a project outside their program, or appointing a manager on a project outside it.
- Anything changing for an organization that uses no programs, beyond the renamed label and the person form's new fields.

## Access (security critical, `WorkspaceAccess`)

- `canCreateProject(user)`: gains program managers (`managedProgramIds` not empty).
- New `canCreateProjectInProgram(user, Program)`: administers its organization, or manages it.
- New `canAppointProjectManager(user, Team)`: `canEditTeam`, or the program manager of the team's project. Used wherever a project's manager is chosen (project update `manager_id`, the team role change). `canEditTeam` itself is unchanged, so `canRemoveFromTeam` keeps its "never the manager" limit for program managers.
- Person form role fields: allowed when the user may manage the member (`canManageMember`), and each program or team touched is in an organization the user administers. `canJoinTeam` still gates every team join and appointment.

## Tests

Happy, failure and privilege-escalation tests for every access change, as `CLAUDE.md` requires. At least: a program manager creates a project in their program and not elsewhere, and their `is_public`, `is_cross_organization` and `key` are dropped. They replace a manager on their program's projects only. A deactivated program manager can do neither. Creating a program creates "General". Every person-form role path, including replacement, refusal to remove a manager role, and an organizational admin refused another organization's programs and teams. The SDOC migration, on matching data and on missing data.

## Owner's answers (2026-10-30)

- Don't run `SdocProjectsSeeder`; fix data by migration.
- Rename "office admin" to "Organizational Admin".
- Roles belong on the person form too.
- A program manager creates projects in their program, chooses each one's manager and can change it later.
- Default project: "General".
