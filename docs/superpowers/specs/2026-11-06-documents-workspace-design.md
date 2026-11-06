# Documents workspace: every document in one place, personal documents and sharing

Date: 2026-11-06. Follows `2026-11-05-documents-design.md` (the editor and a
project's Documents tab, live since 2026-11-06). The owner's words:

- "I don't see a documents tab where I can list of all of my documents, a
  dedicated documents workspace as google has"
- Asked whether every document should still belong to a project, or whether
  he also wants personal documents shared person by person: "yes, i want
  that"
- "docs.sdoc.gov.np should open something else than that onlyoffice"
- When the owner of personal documents leaves and is deactivated: "those
  documents will remain as they are as proofs that only superadmins can
  access. simple."
- Deleting (not deactivating) someone who owns personal documents: refuse,
  and have them deactivated instead (chosen from the options offered).
- A person moved to another organization: "unless a document is within a
  project, the docuemnts belong to the person, not the organization."
- "i think sharing should be simple: Share it among everyone in
  organization, share it within the project, or share individually". The
  model below (three ways that combine; within a project means the document
  moves into the project and belongs to it; who may share a project
  document; notifications only for a named person; one way only) was played
  back to him and approved: "awesome. go ahead".

Everything else below is the builder's call, marked "(call)" where it is a
choice the owner may want to check.

## Why

Today a document lives only inside its project, and nothing lists them all.
People work the way Google Docs taught them: open one page, see every
document they have, most recent first, start a new one without first
choosing a project, and share it. Without that, drafts that belong to no
project (a letter, notes for a meeting, a first SRS before the project
exists) go back to email attachments and USB sticks, which is what the
editor was built to end.

## What people get

1. **A Documents page** (`/workspace/documents`), in the sidebar under
   Projects, for everyone while documents are on: every document the viewer
   may open, most recently edited first.
2. **Personal documents**: a document in no project, owned by the person who
   made it. Private to them until they share it.
3. **One Share button on every document**, with three ways that combine:
   - **Everyone in the organization**, as Can view or Can edit.
   - **Within a project**: the document is in that project and belongs to
     it. Chosen when it is made, or later with **Move to project** on a
     personal document. One way only: a project document never goes back to
     personal.
   - **Individually**: chosen people, each as Can edit or Can view. On a
     project document too, to bring in someone off the team for one
     document without adding them to the team.
4. **docs.sdoc.gov.np opens the Documents page** instead of the editor's own
   status page.

The project's Documents tab stays, and shows the project's documents as
today.

## Where a document lives

Every document has one home:

- **A project** (`project_id`): it belongs to the project. The project's
  people use it as today, and it stays with them whoever made it leaves.
  Never frozen.
- **Personal** (`owner_id`): it belongs to the person who made it, not to an
  organization (owner's words). Moved to another organization, the owner
  keeps all of them.

## Who may do what

One home for every rule, in `WorkspaceAccess` (the existing Documents
section), and one answer per person and document: their **access level**,
`edit`, `view` or none, the highest of:

1. **Home**: on a project document, `edit` for whoever passes
   `canUseProjectDocuments` (unchanged: the project's organization's admins,
   and, while active, its program's manager and team). On a personal
   document, `edit` for the owner while the owner is active.
2. **Everyone in the organization**: the document's `organization_access`
   (`view`, `edit` or off) for every active person of the document's
   organization: the project's organization for a project document, the
   owner's current organization for a personal one.
3. **Individually**: an effective share's permission. A share counts while
   the person it was given to is active and in the document's organization
   now. A share that stops counting stays stored and gives nothing; it
   counts again if the condition comes back (the same idea as
   `GitRepositoryGrant`'s `scopeEffective`).
4. **Frozen** (below): `view` for super-admins, and nothing for anyone
   else, whatever 1 to 3 say.

What each level allows:

- **Can edit** (`edit`): open in edit mode, rename (call: renaming is part
  of editing, as on project documents today), versions, download.
- **Can view** (`view`): open read-only, versions, download. Never edits or
  renames.

Beyond the level:

- **Share** (organization access, individual shares, and seeing who has
  it): on a personal document, the owner while active; on a project
  document, whoever passes `canDeleteDocument` (whoever runs the project,
  or the person who made the document while they may still use the
  project's documents). Others on the team edit it but can't open it up to
  more people.
- **Trash and restore**: on a personal document, the owner while active; on
  a project document, `canDeleteDocument` as today.
- **Move to project**: the owner of a personal document, while active, into
  a project whose documents they may use. The document then belongs to the
  project (`project_id` set, `owner_id` cleared, `created_by` kept), its
  individual shares and organization setting are kept and judged against
  the project's organization from then on; if that is another organization
  than the owner's, the organization setting is turned off (call: so a
  document never opens to a whole organization nobody chose).
- Nothing is shared, renamed, moved or edited while a document is in the
  trash.

**Active owner** means: unless they are a super-admin, their person record
is active. (A super-admin with no person record counts as active.)

**Nobody else** sees a personal document: not the organization's admins,
not program or project managers, and not super-admins while the owner is
active (call: the same privacy as a private to-do, "Just for me").

**Sharing stays inside the document's organization.** Nothing in the
tracker crosses organizations except a cross-organization project team, so
sharing doesn't either (a cross-organization team's outsiders already have
the project's documents through the team). A personal document's owner with
no organization (a super-admin with no person record) can share it with
nobody. The share picker offers active people of the document's
organization, never a personal document's owner; a request naming anyone
else gets one generic 422 on the person field that never says who they are
(as the repository grants do).

**Deleting someone who owns personal documents is refused** (owner's
choice), on the web and the API: "They own personal documents; deactivate
them instead." Their trashed personal documents count. The database backs
it (`owner_id` restricts deletion of the login), so every personal document
always names its owner.

**When the owner leaves** (owner's words: "remain as they are as proofs that
only superadmins can access"): once a personal document's owner is no
longer active (their person record is deactivated), the document is
**frozen**:

- Super-admins, and only they, may open it **read-only** (call: "as proofs"
  read as never changed again), see its versions and download them. No one
  edits, renames, shares, moves, trashes or restores it.
- Its organization access and shares stop counting, so everyone it was
  shared with loses it.
- This includes one the owner had moved to the trash before leaving (call):
  it shows in a super-admin's list marked "In the trash" and opens
  read-only like the rest. The editor's file route, which refuses trashed
  documents today, must let that read-only session load the file and
  nothing else.
- Nothing is deleted: the document, its versions and its stored shares stay
  as they were. If the owner is reactivated, everything is back as before.
- A frozen document shows as "Read-only: its owner has left" on the page and
  in the editor's bar.
- Known consequence, stated so nobody is surprised: a super-admin can read
  someone's personal documents by deactivating them. Deactivation already
  ends that person's sign-in, so it is never silent.

An editor session that is already open when access ends (a share removed,
the owner deactivated) keeps running until it is closed, and its save is
kept as a version, as project documents already behave when someone leaves
a team. Access is decided when the editor opens.

The list's query and the rules must agree: a document appears on someone's
Documents page exactly when their access level is not none. Keep the query
scope (`Document::scopeVisibleTo` or similar) and the level as twins, as
`Project::scopeCodeVisibleTo` and `canViewProjectCode` are, with a test that
checks one against the other. The project part needs a scope twin of
`canUseProjectDocuments` (`scopeCodeVisibleTo` without the git condition).

## The Documents page

- **Rows**: type icon, title, where it is ("Personal", "Shared by {owner}",
  "{organization}" for one opened to everyone, or the project's label as
  `Project::label()` gives it), last edited by whom and when, and a mark
  for "Can view" and for frozen documents.
- **Filters** (all in the URL, like the reports):
  - Show: Everything (default), Mine (personal documents I own), Shared with
    me (individual shares to me), Everyone in {organization} (opened to the
    whole organization), In projects (optionally one project).
  - Type: document, spreadsheet, presentation.
  - Search by title.
- **Order**: most recently edited first, 50 at a time with "Show more"
  (call).
- **New**: type and title, plus **Where**: "Just me" (default) or one of the
  projects whose documents the person may use. Opens the editor, as the tab
  does today.
- **Upload**: the same Where choice; same formats and 50 MB limit.
- **Row menu**: Open, Rename, Share, Move to project, Versions, Download,
  Move to trash, each shown only to whoever may do it.
- **Trash**: what the viewer may restore (their personal documents, and
  project documents where they pass `canDeleteDocument`), plus, for
  super-admins, trashed frozen documents (read-only).
- **A super-admin's organization switch** narrows the project documents,
  those opened to an organization and the frozen ones (by the owner's
  current organization), as it narrows the Projects list; the viewer's own
  and individually shared documents always show.
- **Phones**: the page works below `md` (one column), and Documents is
  reachable from the phone's account sheet (call).
- **Empty state**: says what personal documents and sharing are, with New
  and Upload.

## Sharing

- **Share** opens from the editor's top bar and from the row menu, for
  whoever may share the document (above), while it is not frozen or
  trashed.
- It shows who has it:
  - the home: "Only you" for a personal document, "Everyone on {project}"
    (Can edit, fixed) for a project document;
  - **Everyone in {organization}**: Off, Can view or Can edit;
  - **People**: name, designation, Can edit or Can view, remove; add by name
    search, default Can edit (call).
- A new individual share sends the person an in-app notification,
  `DocumentShared` (kind `document_shared`, the shared payload shape with
  `task: null` and a `document {id, title}`, url the editor page), and the
  right-away email (one entry in `SendEmailUpdates::KINDS` and `item`).
  Changing or removing a share, opening a document to the organization and
  moving it to a project send nothing. Nobody is notified about their own
  action. A person who can't open the document (inactive, say) gets nothing.
- On a project document, sharing changes and a move into the project go
  into the project's activity (call); a personal document writes no
  activity. Who saved each version is in the version list (call: no
  separate sharing history for personal documents in this round).

## The editor

- Every document can open on `/workspace/documents/{id}`. Whether project
  documents move to that page or keep their current route is the builder's
  call; old links must keep working.
- **Can view** and frozen documents open in the editor's view mode:
  `mode: view` and no edit, comment or review permission, inside the signed
  token, so the browser can't turn it into an editing session.
- The editor's back link goes to the project's Documents tab for a project
  document and to the Documents page for a personal one.
- Saves work as today (`SaveEditedDocumentService`), for both homes.

## docs.sdoc.gov.np

Opening `https://docs.sdoc.gov.np/` (and `/welcome/`) redirects to
`https://tasks.sdoc.gov.np/workspace/documents` (302, so it can change
later). The tracker asks people to sign in first if they need to. Every
other path stays the editor's (the browser loads `api.js` and the editor
from there). Done with a Dokploy redirect on the `docs-onlyoffice`
application (Traefik), with no change to the ONLYOFFICE image, and written
up in `docs/onlyoffice.md` (call). Checked by: `/` and `/welcome/` answer
302 to the page, `/healthcheck` still answers, and a document still opens.

## Data

- `documents.project_id` becomes nullable: null means personal.
- `documents.owner_id`: a personal document's owner (users, restrict on
  delete). Null exactly when the document belongs to a project; cleared by
  Move to project.
- `documents.organization_access`: null (off), `view` or `edit`.
- `document_shares`: document (cascade), member (cascade), `permission`
  (`view|edit`), `shared_by` (users, null on delete), timestamps; unique
  (document, member). Shares go to a person record, so someone without a
  login yet gets the document once they sign in.
- Files keep going to RustFS through `DocumentFiles::write`, never deleted.
  Personal documents need an object key form that can never collide with
  `projects/{project}/documents/...` (the builder picks it); each version
  keeps the path it was written to, so a moved document's old versions stay
  where they are.
- Observers write project activity only for project documents.

## API

Twins for every action, for the future app (`docs/api.md` updated): the
list with the same filters, create and upload with Where, show (with the
editor config and the viewer's level), rename, move to project, versions,
download, trash, restore, the sharing settings (organization access,
individual shares: list, add, change, remove) and the share picker's
search. `userPayload` already says `documents_enabled`. Exact routes are
the builder's.

## Also update

- The Guide's Documents section: personal documents, the three ways to
  share, who sees what, what happens when someone leaves.
- `CLAUDE.md`'s Documents section and the WorkspaceAccess method list.
- Tests: happy, failure and privilege-escalation cases for each new rule:
  sharing with someone in another organization, inactive, unknown or the
  owner; organization access reaching the right organization only (a
  project's, the owner's current one); the owner moving organization (old
  shares and organization access stop counting there, new ones work);
  moving a document into a project (of the owner's organization, of
  another, a project the owner can't use, a frozen or trashed document);
  a team member who may edit but not share a project document; deleting a
  person who owns personal documents, trashed ones included, refused on both
  surfaces; a non-owner sharing, trashing or restoring; a viewer getting an
  edit session or renaming; an organizational admin, a manager or a
  super-admin reaching an active owner's private document; the frozen rules
  (organization access and shares stop counting, super-admin read-only,
  trashed included, reactivation restores); the list scope agreeing with
  the level.

## Not in this round

Folders, starred documents, comment-only access, sharing by link, sharing
outside the organization, moving a project document back to personal or
between projects, handing ownership to someone else, storage quotas,
searching inside documents, templates, documents in search and the command
palette, the Flutter app.

## Left to the builder

Route layout, page and component structure, the personal object key form,
the paging mechanism, the exact copy, and how project and personal
documents share controllers and services, as long as each rule above has
one home and both surfaces (web and API) use it.
