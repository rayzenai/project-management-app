## Structure *(organogram: super-admins and the organization's active people; designations and reporting lines: its organizational admins and super-admins)*

An organization holds its people with a **designation** from its own list
and **one person each reports to** (their higher authority). The lines
decide who sees whose attendance and work, so only whoever administers the
organization changes them (`WorkspaceAccess::canManageStructure`); everyone
active in it sees the organogram (`canViewOrganogram`): names, designations
and lines, never emails.

**Organogram.** `GET /workspace/organizations/{organization}/organogram`
returns `{ data: { organization, designations, people, can_manage } }`
(`OrganogramQuery`; 403 for anyone outside the organization, 404 for no such
organization):

- `organization`: `{ id, name }`.
- `designations`: most senior first (`rank`, then name), each
  `{ id, organization_id, name, rank, people_count }`; `people_count` counts
  active people who hold it.
- `people`: the organization's active people, by designation rank (people
  without one last), then name, each `{ id, name, designation_id,
  designation, reports_to_id, is_office_admin, direct_reports_count }`.
  `designation` is its name or null. `reports_to_id` is null when they report
  to no one, or to someone who is not an active person of the organization
  (a deactivated higher authority keeps the line, but the person shows at the
  top). `direct_reports_count` counts the active people whose
  `reports_to_id` is them.
- `can_manage`: whether the caller may change designations and lines.

The web page is `GET /workspace/organogram` (`workspace.organogram`, Inertia
`Organogram/Index` with `organogram` and `organizations`): the caller's own
organization; a super-admin picks one with `?organization={id}`
(`organizations` lists them; with none picked, `organogram` is null). Anyone
else's `?organization=` is ignored.

**Designations.** `rank` is 0 to 32767, smaller more senior (default 100 on
create). `name` is required, at most 120 characters, and unique in the
organization whatever its case (422 on `name`: `There is already a
designation called {name}.`). Deleting one leaves its people with no
designation.

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| POST | `/workspace/organizations/{organization}/designations` | `name`, `rank?` | 201, the designation `{ id, organization_id, name, rank }`; message `Added {name}.` |
| PATCH | `/workspace/designations/{designation}` | `name?`, `rank?` (empty keeps it) | the designation; message `Saved {name}.` |
| DELETE | `/workspace/designations/{designation}` | — | `{ message }`: `Removed {name}. The people who held it have no designation now.` |

**Reports to.** `PUT /workspace/members/{member}/reports-to` with
`reports_to_id` (a member id, or `null` for no one; the field must be sent)
returns the member (`MemberResource`, with `designation` and
`reports_to_id`) and `{name} now reports to {boss}.` or `{name} reports to
no one now.`. 403 unless the caller administers the person's organization.
422 on `reports_to_id` when the higher authority is:

- not a current person of the same organization: `Choose someone in the same
  organization.`
- the person: `{name} can't report to themselves.`
- deactivated: `{boss} is deactivated. Choose someone active.`
- already below the person: `{boss} already reports to {name}, so {first
  name} can't report to {boss's first name}.` (e.g. `Sita Sharma already
  reports to Anil Gurung, so Anil can't report to Sita.`)

Changes run one at a time per organization (the organization row is locked)
and write nothing to project activity.

| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/workspace/organizations/{organization}/organogram` | — | `{ data: organogram }` |
| PUT | `/workspace/members/{member}/reports-to` | `reports_to_id` (int or null) | the member |

**Person form.** `POST /workspace/members` and `PATCH
/workspace/members/{member}` also take `designation_id` (one of the person's
organization's designations, after any move in the same request; null
clears) and `reports_to_id` (a current person of that organization; null
clears). Left out, each stays as it is. They are applied only for a caller
who may change the organization's structure and silently dropped for anyone
else. `reports_to_id` goes through the same checks as the route above, and a
refusal undoes the whole save (422 on `reports_to_id`). Member payloads
(`POST`, `PATCH`, restore, the People page's `members`) add `designation`
(`{ id, name }` or null) and `reports_to_id`. The People page (`GET
/workspace/team`) adds `canSetStructure` and `designationOptions`
(`[{ id, organization_id, name }]`, most senior first, for the organizations
the caller administers; empty otherwise).

**When people leave.** Deleting a person, or moving them to another
organization, moves the people who reported to them up to that person's own
higher authority (or to no one). Someone moved to another organization also
loses their own higher authority, and a designation of the organization they
left. Deactivating someone keeps every line.

The web twins of the writes (same paths under `/workspace`, names
`workspace.designations.store|update|destroy` and
`workspace.members.reports-to`) redirect back with a `workspace_flash`, and
errors on the field.

### Changes

- New: `GET /workspace/organizations/{organization}/organogram`,
  `POST /workspace/organizations/{organization}/designations`,
  `PATCH|DELETE /workspace/designations/{designation}`,
  `PUT /workspace/members/{member}/reports-to`; the web page
  `GET /workspace/organogram`.
- Members: `designation_id` and `reports_to_id` on create and edit; member
  payloads add `designation` and `reports_to_id`.
