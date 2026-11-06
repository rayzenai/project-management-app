# Documents: Word, Excel and PowerPoint files edited together in the tracker

Date: 2026-11-05. Owner's request: "let's build onlyoffice first then just like
we build forgejo" (after choosing documents over the mobile app as the next
thing to build, and skipping the design questions: "okay, let's do it"). Every
decision below not quoted from the owner is the builder's call.

## Why

SDOC's own work runs on documents (agency requests, SRS documents,
agreements, test reports, acceptance documents; the sdoc portal's
agency-services plan lists them as future work), and today they travel as
email attachments. A project's documents should live with the project: one
copy, edited together in the browser, every saved version kept, seen by the
people who run and do the project and nobody else, with files staying on
SDOC's own servers.

## Shape

- **The editor** is ONLYOFFICE Docs Community Edition, self-hosted at
  docs.sdoc.gov.np (`onlyoffice/`, runbook `docs/onlyoffice.md`). It has no
  users and keeps no documents: the tracker signs every session with a
  shared JWT secret, serves the file, and receives each saved version. We
  never write an editor.
- **The files** are in RustFS (bucket `tasks-documents`), never on the VM's
  root disk (the owner's rule of 2026-11-01). Locally and in tests a local
  disk stands in.
- **The tracker** owns who may see what, the versions, and the activity log.

## v1 scope

- A project's **Documents** tab (beside Code): new blank document,
  spreadsheet or presentation; upload a `.docx`, `.xlsx` or `.pptx` (at most
  50 MB); open it in the editor, where everyone with access edits together;
  rename; download; the version list with a download for each version; move
  to the trash and restore from it.
- Every save is a new version, kept for good: the tracker never deletes a
  file, and its RustFS key has no delete permission. Trash is a mark, never
  purged.
- Web and API twins for every action (the API serves the future app).

Not in v1: documents on tasks, PDF and older formats (`.doc`, `.odt`),
sharing outside the project, restoring an old version as the current one,
notifications, letter templates, approvals and signatures.

## Who

- **Use a project's documents** (see, open, edit, create, upload, rename,
  versions, download): `WorkspaceAccess::canUseProjectDocuments`, the same
  people as code (`canViewProjectCode`) without the git condition: whoever
  administers the project's organization (organizational admins,
  super-admins), and, while active, its program's manager and everyone on
  its team. Not other viewers of a public project.
- **Trash and restore**: `canDeleteDocument`, the twin of `canDeleteTask`:
  whoever runs the project (`canManageProjectAccess`), or the document's
  creator while they may still use the project's documents.
- Documents are on only when the server is configured
  (`config('documents.enabled')`: an editor URL and a JWT secret).

## How a session works

1. Opening a document, the tracker builds the editor config
   (`DocumentEditorQuery`): the file URL (a temporary signed route), the
   callback URL (a signed route), the document's current `editor_key`, the
   person's id and name (never their email), edit or view mode, and the
   customization (AI tools, plugins, macros and chat off; no forced saves).
   The whole config is signed (HS256) as `token`.
2. The editor downloads the file from the tracker. The request carries a
   Bearer JWT (`operation: download`); the tracker checks it and the URL's
   signature, then streams the file from the disk.
3. When the last editor closes (status 2), or on a forced save (status 6),
   the editor posts the callback with a Bearer JWT (`operation: callback`).
   The token's `payload` is the truth, never the body. The tracker refuses a
   saved-file URL off the editor's own host, downloads it (at most 100 MB),
   stores it as the next version and, for status 2 only, gives the document
   a new `editor_key`, so the next session starts from the saved file. A
   callback for a key the document no longer has is answered `{"error": 0}`
   and changes nothing (already saved). A failure answers `{"error": 1}` so
   the editor tries again.

## Data

- `documents`: project, title (at most 200), `file_type`
  (`docx|xlsx|pptx`), `version` (current), `editor_key` (unique), size,
  created_by, updated_by, timestamps, soft deletes with `deleted_by`.
- `document_versions`: document, version (unique per document), `path` on
  the disk, size, `sha256`, user (who saved it), `source`
  (`created|uploaded|edited`), created_at.
- Object keys: `projects/{project}/documents/{document}/v{n}.{ext}`.
- Activity (`DocumentObserver`, `DocumentVersionObserver`): created,
  uploaded, saved (version n), renamed, trashed, restored, by the person who
  did it (for a save, the editor's last user).

## Server hardening (onlyoffice/)

JWT required both ways; the editor fetches directly only from the tracker's
origin, every other private address stays blocked; no demo app, welcome page,
bundled plugins, public Document Builder or conversion API; pinned release;
the working cache on the rustfs volume. The ONLYOFFICE logo stays (the
Community Edition's licence requires it).
