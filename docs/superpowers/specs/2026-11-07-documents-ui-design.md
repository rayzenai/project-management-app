# Documents page: UI/UX design

From a design review of `/workspace/documents` (2026-11-07). The owner asked
"is this the best UI/UX we could imagine?"; the answer was no, and the items
below are what was recommended. Items 1 to 4 were built on 2026-11-07
(branch `documents-ui`); item 5 is a separate, later change. The decisions
the review left open are settled at the end ("Decisions").

Background to read first:

- `docs/superpowers/specs/2026-11-06-documents-workspace-design.md`: the
  ownership and sharing model (project vs personal home, organization access,
  individual shares, frozen documents). Every rule there still holds; this is
  a UI change, not a model change.
- `CLAUDE.md` in this repo: thin controllers, FormRequests, `WorkspaceAccess`
  for every access decision, `composer test` + `npm run lint && npm run format
  && npm run types:check && npm run build` before calling it done.

## What the page is today

Files: `resources/js/pages/Documents/Index.svelte` (page),
`resources/js/components/documents/DocumentList.svelte` (rows, shared with a
project's Documents tab in `resources/js/components/project/DocumentsView.svelte`),
`NewDocument.svelte` (New + Upload popovers), `ShareDialog.svelte`,
`DocumentTrash.svelte`. Data: `app/Queries/Documents/DocumentLibraryQuery.php`,
`app/Http/Resources/DocumentResource.php`, row type `DocumentItem` in
`resources/js/lib/types.ts`.

Layout: top bar with Upload + New; a pill group "Everything / Mine / Shared
with me / Whole office / In projects"; "Search titles" input; "All kinds"
select (plus an "Any project" select that only appears under In projects);
rows with a grey 16px type icon, the title (the only link), and a line like
"IRD Prize · Document · Edited 21 hours ago by Timsinakiran"; a ⋯ menu with
Rename, Share, Move to project, Versions, Download, Move to trash.

## Problems found (verified in code)

1. **"Mine" excludes documents you made in a project.** The filter is
   `whereNull('documents.project_id')->where('documents.owner_id', $viewer->id)`
   (`DocumentLibraryQuery.php:109`), i.e. personal documents only. The owner's
   own "Test" document in IRD Prize does not appear under Mine.
2. **"Shared with me" is individual shares only** (`:110-112`); documents
   open to the whole office are under a separate "Whole office" pill (`:113`).
   The five pills mirror the access model (home / organization / individual /
   project), not how people think. On a phone they scroll sideways and "In
   projects" is off screen.
3. **Creating takes several steps**: New → popover → title, kind, Where →
   create. Upload is a second popover with a file picker. There is no
   drag-and-drop anywhere (no `ondrop`/`dragover` in the documents components).
4. **Rows don't say who can see a document.** A private personal draft and one
   shared with chosen people look the same; only "Whole office" appears, as
   text in the details line. `DocumentItem` has `organization_access`,
   `access`, `can_share`, but nothing about individual shares (no count).
5. **Row details are hard to scan**: the project name is plain text, not a
   link; "Document" repeats the icon; the icon is grey and the same weight for
   every kind; "by Timsinakiran" should be "by you" for the viewer; only the
   title is clickable, not the row; Share is hidden in the ⋯ menu.
6. **Too much chrome for the content**: five pills, search and a kind select
   above one row. The kind select isn't worth a control for three kinds.
7. Minor, global (AppShell, not this page): the sun-and-mountains pill at the
   top right is the theme toggle (`ThemeToggle`) but reads as a profile photo.

## What to build

### 1. One-click start + drag-and-drop upload

- A row of tiles at the top of the Documents page: **Document** (blue),
  **Spreadsheet** (green), **Presentation** (orange), **Upload, or drop a
  file**.
- Clicking a kind tile creates "Untitled document" / "Untitled spreadsheet" /
  "Untitled presentation" via the existing `POST /workspace/documents` and
  opens the editor (the server already redirects to the editor on create).
- One remembered **"New ones go to: Just me ▾"** choice beside the tiles
  (Just me when `can_create_personal`, else the viewer's projects, from
  `props.projects`) replaces the per-creation Where field. Remember it per
  viewer in `localStorage` (wrapped in try/catch; fall back to the current
  default in `NewDocument.svelte`).
- Dropping a .docx/.xlsx/.pptx anywhere on the page uploads it through the
  existing `POST /workspace/documents/upload` to the same "go to" place, with
  a visible drop overlay, the same size check (`max_upload_mb`) and the same
  error messages. Several files at once: upload one after another.
- The editor (`resources/js/pages/Documents/Edit.svelte:155`) shows the title
  as plain text with no rename. Since the tile skips the title prompt, add
  rename in the editor's bar (click the title → input, `PATCH
  /workspace/documents/{id}` with `title`, as `DocumentList` does), for people
  with edit access on a non-frozen document.
- The project's Documents tab keeps its fixed project (`NewDocument` with
  `project`); decide whether it gets the tiles too (see Decisions).

Check: Pest feature tests still green; a Playwright or manual check that a
tile click lands in the editor with an Untitled title, a drop uploads into the
chosen place, a too-big file shows the size error, and rename in the editor
sticks.

### 2. Four tabs instead of five

- **Recent** (was Everything): every document the viewer may open, most
  recently edited first. Default.
- **Personal** (was Mine): the same query as today's `mine`, renamed so the
  label matches what it lists.
- **Shared with me**: individual shares **plus** documents open to the whole
  office that the viewer doesn't own and that aren't in one of the viewer's
  projects, each with an "Office" badge on the row.
- **Projects**: instead of a flat filter with a hidden project select, a card
  per project the viewer can use (title, document count, last edit), each
  linking to that project's Documents tab: `/workspace/projects/{slug}?view=documents`
  (`resources/js/pages/Projects/Show.svelte:77-80` reads `?view=`).
- Keep filters in the URL (as today). Keep the old `show=` values working
  (`all`, `mine`, `shared`, `organization`, `projects`) by mapping them to the
  new tabs so existing links don't break.
- Drop the "All kinds" select; the coloured icons from item 3 make kinds
  easy to tell apart.

Check: query tests per tab in `tests/Feature` (Personal excludes project
documents; Shared with me includes office-wide ones and excludes the viewer's
own; Projects lists only projects whose documents the viewer may use, per
`WorkspaceAccess::canUseProjectDocuments`); old `show=` URLs still land on the
right tab.

### 3. Rows that answer what, where, who can see it, when

Row layout (desktop): coloured type icon (20px) · title · project as a link
chip (or "Personal" / "Shared by {name}") · access badge · "You · 21 h ago"
right-aligned · ⋯.

- Coloured icons: docx blue, xlsx green, pptx orange, using theme tokens in
  `resources/css/app.css` (light and dark both).
- Drop the kind word ("Document") from the details.
- Project name links to `/workspace/projects/{slug}?view=documents`.
- Access badge:
  - viewer can share (`can_share`): **Only you** (no org access, no effective
    shares) / **Shared** (effective individual shares) / **Office**
    (`organization_access` set; Office wins over Shared, or show both, see
    Decisions);
  - viewer can't share: their own level, **Can edit** / **Can view** (the
    existing "Can view" chip), plus **Office** when it's open to the office.
  - Needs an effective-share count in `DocumentResource` (e.g.
    `shares_count`, using the shares' effective scope), and only for viewers
    who pass the share rule, because the spec limits "seeing who has it" to
    people who can share. Eager-count it in `DocumentLibraryQuery` so the list
    doesn't run one query per row.
  - The "Read-only: its owner has left" chip stays as is.
- Say "you" for the viewer: compare `created_by` / `updated_by` id with the
  shared `auth.user.id` (`app/Http/Middleware/HandleInertiaRequests.php:41`).
- Make the whole row the link (the ⋯ button and its menu must not trigger it;
  keep one real `<a>` for keyboard and screen readers).
- Show **Share** as a visible button on row hover/focus for `can_share`
  documents (it stays in the ⋯ menu too, for touch).
- Time in its own right-aligned column with `tabular-nums`; on mobile, fold it
  back into the line under the title.

Check: the component is shared with the project tab, so check both pages
(on the project tab `showWhere` is false: no project chip). Axe (WCAG 2.1 AA)
clean in light and dark; ⋯ and Share buttons at least 24px, rows easy to tap
on mobile.

### 4. Show search only when it's useful

Hide search until there are more than about eight documents in total for the
viewer (or whenever `q` is in the URL), so a nearly empty page leads with the
tiles. Empty state: keep today's copy (it explains the ownership model) under
the tiles.

### 5. Later: "new to you" dot

A ● on documents shared with the viewer that they haven't opened yet, and
optionally "Recent" sorted by when the viewer last opened a document. Needs
the server to record when each person opens a document (a
`document_views`-style table, written on `GET /workspace/documents/{id}`),
which doesn't exist today. Do this as a separate change.

## Keep as they are

Recent-first ordering; filters in the URL; cursor-based "Show more"; menus
offering only what the viewer may do; the Can view and frozen chips; the
inline versions list; the trash link; the overall calm styling and the shared
AppShell bar.

## Decisions

The owner's (2026-11-07, as recommended):

1. **One click makes the document**, in the remembered "New ones go to"
   place (Just me by default), with no title prompt; the editor opens with
   the title selected, so typing names it (the create's flash
   `created_document`).
2. **Four tabs**: office-wide documents go under Shared with me with a
   Whole office mark, and the Whole office tab goes away.
3. **Projects tab = a card per project**, opening its Documents tab.

The builder's calls:

4. The project's Documents tab gets the same tiles, fixed to its project
   (one way to start a document everywhere).
5. A document both open to the office and shared with people says Whole
   office only; the Share dialog lists the people.
6. The Documents page's top bar has no Upload: the tile and the drop zone
   cover it.
7. Rows show "Can view" when that is the viewer's level and nothing for
   edit, which is the usual case (a "Can edit" mark on most rows would hide
   the one that matters).
8. Who can see it is said as Only you, Shared with N people or Whole office;
   a project document with neither says nothing more than its project (its
   team). On a phone the mark is its icon, the words kept for screen readers.
9. The kind colours (`--ws-doc-docx|xlsx|pptx` and `-soft`, CSS-only like
   the chart series) are their own tokens, never the status colours, and
   carry only the kind icon.
10. Search shows from 9 documents (`DocumentLibraryQuery::page`'s `total`)
    or whenever `q` is in the URL. On the Projects tab a search lists the
    matching documents in projects instead of the cards.
11. The API gets the cards as `GET /api/v1/workspace/documents/projects`;
    its `show=organization` now means Shared with me, as on the web.

Left as found (app-wide, the accent colour's call): axe reports the
sidebar's current link and a segmented control's active segment (accent on
accent-soft, about 3.8 to 4.1:1) and, in dark, the primary button (white on
the light accent, 2.9:1) below WCAG AA.
