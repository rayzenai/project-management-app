# Documents Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Documents page listing every document a person may open, personal documents, one Share dialog (everyone in the organization, within a project, individually) and docs.sdoc.gov.np opening that page.

**Architecture:** Every per-document action moves to document-keyed routes (`/workspace/documents/{document}`, `/api/v1/.../documents/{document}`) authorized by one access level, `WorkspaceAccess::documentAccess` (`edit|view|null`), with `Document::scopeVisibleTo` as its query twin. Project documents keep `canUseProjectDocuments` as their home rule; personal documents add an owner, organization access and individual shares. The project tab and the new page share Svelte components under `components/documents/`.

**Tech Stack:** Laravel 13, PHP 8.5, Pest, Inertia 2 + Svelte 5, ONLYOFFICE Docs 10 (unchanged image), Dokploy/Traefik.

**Spec:** `docs/superpowers/specs/2026-11-06-documents-workspace-design.md`

## Global Constraints

- Every role decision in `App\Support\WorkspaceAccess` (Documents section); never inline.
- Route → thin controller → FormRequest `authorize()` → service returning `ServiceResult` → JsonResource; web and API twins share requests, services and queries.
- Access levels are exactly `'edit'`, `'view'`, or `null`; share permissions and `organization_access` use the same two strings.
- Delete refusal copy, verbatim: `They own personal documents; deactivate them instead.`
- Frozen mark copy, verbatim: `Read-only: its owner has left`.
- Upload: `.docx|.xlsx|.pptx`, at most `config('documents.max_upload_kilobytes')` (50 MB); 50 rows per page.
- Files only through `DocumentFiles::write`; nothing deletes a file; personal object keys start `personal/`.
- No emails in share candidates (PersonRef `{id, name, designation}`); outsiders never named in a 422.
- Svelte: semantic utilities only, Lucide icons, no em-dashes in copy, Svelte 5 runes, no `{@html}`.
- `composer test` (Pint, PHPStan level 7, Pest) and `npm run lint && npm run format && npm run types:check && npm run build` pass before every commit.
- No test reaches the network (`Http::preventStrayRequests`); `Storage::fake('documents')` in every documents test.
- Never write to the local `projects` database (a production clone); tests run on their own database.
- Stage only this work's files; never `git add -A`.

## Review Focus

1. A personal document's owner moves to another organization: org access and old shares must stop counting there, and work in the new organization; nobody at the old office sees it (Task 2 test `follows the owner to a new organization`).
2. A viewer (Can view) opening the editor must get `mode: view` with `edit: false` inside the signed token, and a rename or trash must 403 (Task 3 test `opens a Can view document read-only`).
3. A document shared individually to someone who is also on the project's team with `view`: the highest level wins, they still edit (Task 2 test `gives the highest level`).
4. A frozen document already in the trash: a super-admin opens it read-only and its file loads, while any other trashed document's file route answers 404 (Task 3 test `opens a trashed frozen document for a super-admin only`).
5. The Documents page for an admin of a busy organization: "Show more" must page without repeating or skipping rows when two documents share an `updated_at` (Task 4 test `pages by updated_at then id`).

---

### Task 1: Data: personal documents, organization access, shares

**Files:**
- Create: `database/migrations/2026_11_06_120000_documents_personal_and_sharing.php`
- Create: `app/Models/DocumentShare.php`
- Modify: `app/Models/Document.php`, `app/Models/DocumentVersion.php` (`pathFor`)
- Modify: `tests/Pest.php` (move the `docs*` helpers and `DOCS_SECRET` out of `tests/Feature/Workspace/DocumentsTest.php` so every documents test file can use them)
- Test: `tests/Feature/Workspace/DocumentsAccessTest.php`

**Interfaces:**
- Produces:
  - `documents.project_id` nullable; `documents.owner_id` (FK users, `restrictOnDelete`, nullable); `documents.organization_access` (string 8, nullable); index `(owner_id, deleted_at, updated_at)`.
  - `document_shares`: `id`, `document_id` (cascade), `member_id` (cascade), `permission` (string 8), `shared_by` (users, nullOnDelete), timestamps, unique `(document_id, member_id)`.
  - `Document::ACCESS_EDIT = 'edit'`, `Document::ACCESS_VIEW = 'view'`, `Document::ACCESSES = ['view', 'edit']`; `Document::isPersonal(): bool`; relations `owner(): BelongsTo<User>`, `shares(): HasMany<DocumentShare>`; `@property int|null $project_id`, `int|null $owner_id`, `string|null $organization_access`, `Project|null $project`.
  - `Document::hasPersonalDocuments(User $user): bool` (trashed included).
  - `DocumentShare` (fillable `document_id`, `member_id`, `permission`, `shared_by`; `document()` withTrashed, `member()`, `sharer()`).
  - `DocumentVersion::pathFor`: `personal/documents/{id}/v{n}.{ext}` for a personal document, unchanged for a project one.

- [ ] **Step 1: Write the failing tests** in `DocumentsAccessTest.php`: `it('stores a personal document's versions under personal/')` (create a personal `Document` row with `owner_id`, assert `DocumentVersion::pathFor($doc, 1) === "personal/documents/{$doc->id}/v1.docx"`); `it('refuses to delete a login that owns a personal document')` (`expect(fn () => $owner->delete())->toThrow(QueryException::class)`); `it('keeps one share per person per document')` (second insert throws).
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Workspace/DocumentsAccessTest.php`. Expected: FAIL (no column `owner_id`).
- [ ] **Step 3: Write the migration and models.** `project_id` via `->nullable()->change()` (keep its FK). Move helpers to `tests/Pest.php` unchanged except `docsCreate`, which Task 3 changes.
- [ ] **Step 4: Run** the new file and `tests/Feature/Workspace/DocumentsTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `Documents: personal documents, organization access and shares (data)`.

### Task 2: The access level and its query twin

**Files:**
- Modify: `app/Support/WorkspaceAccess.php` (Documents section), `app/Models/Document.php` (scopes), `app/Models/DocumentShare.php` (scope), `app/Models/Project.php` (scope)
- Modify callers of `canDeleteDocument` (`ProjectDocumentsQuery`, `Api\DocumentController`, `DeleteDocumentRequest`)
- Test: `tests/Feature/Workspace/DocumentsAccessTest.php`

**Interfaces:**
- Consumes: Task 1 columns and constants.
- Produces (all `public static` on `WorkspaceAccess`):
  - `documentAccess(?Authenticatable $user, Document $document): ?string`: the highest of home (`canUseProjectDocuments` → edit; personal owner while `documentOwnerActive` → edit), organization access (active person whose member `organization_id === documentOrganizationId`), effective share permission; for a frozen document `'view'` for a super-admin, `null` for anyone else. Ignores the trash (requests check it). `null` while documents are off.
  - `documentOwnerActive(Document $document): bool` (personal only: owner is a super-admin, or the owner's member exists and is active).
  - `isDocumentFrozen(Document $document): bool` (personal and not `documentOwnerActive`).
  - `documentOrganizationId(Document $document): ?int` (project's organization, or the owner's member's organization).
  - `canCreatePersonalDocuments(?Authenticatable $user): bool` (documents on; super-admin, or an active member).
  - `canShareDocument(?Authenticatable $user, Document $document): bool` (not trashed, not frozen; personal: the owner; project: `canDeleteDocument`).
  - `canShareDocumentWith(Document $document, Member $member): bool` (member active, `organization_id === documentOrganizationId` and not null, not a personal document's owner).
  - `canDeleteDocument(?Authenticatable $user, Document $document): bool` (new signature; project: unchanged rule through `$document->project`; personal: the owner while `documentOwnerActive`).
  - `canMoveDocument(?Authenticatable $user, Document $document): bool` (personal, not trashed, owner while active; the target project is checked by the request).
  - A per-user memo for `canUseProjectDocuments` by project id (WeakMap like `memberMemo`, flushed in `flush()`), so a list of 50 rows asks each project once.
  - `Project::scopeDocumentsVisibleTo(Builder, ?Authenticatable)`: `scopeCodeVisibleTo` without the git condition.
  - `DocumentShare::scopeEffective(Builder)`: member active and in the document's organization now (project's, or the owner's member's).
  - `Document::scopeFrozen(Builder)`, `Document::scopeVisibleTo(Builder, ?Authenticatable)`: exactly the documents where `documentAccess !== null`.

- [ ] **Step 1: Write the failing tests:**
  - `it('gives the owner edit and nobody else anything on a private document')`: owner edit; teammate, office admin, program manager, super-admin `null`.
  - `it('gives everyone in the organization the chosen level')`: `organization_access = 'view'` → same-org active person `view`, other-org `null`, deactivated `null`; on a project document, the project's organization is used.
  - `it('counts a share only while the person is active and in the organization')`.
  - `it('gives the highest level')`: team member (edit) with a `view` share → `edit`; org `view` plus share `edit` → `edit`.
  - `it('follows the owner to a new organization')`: move the owner's member to org B; old-org share and old-org colleague → `null`; org-B colleague with `organization_access = 'view'` → `view`.
  - `it('freezes a personal document when its owner is deactivated')`: shares and org access → `null`, super-admin → `view`, `canShareDocument`/`canDeleteDocument`/`canMoveDocument` false for everyone; reactivate → back as before.
  - `it('lets whoever runs the project and the maker share a project document, not the rest of the team')`.
  - `it('lists exactly the documents the level opens')`: build 3 organizations × users (owner, teammate, admin, program manager, super-admin, outsider, deactivated, cross-organization team outsider) × documents (private, org view, org edit, shared, frozen, project, project with share, trashed) and assert `Document::withTrashed()->visibleTo($u)->pluck('id')` equals the ids where `documentAccess($u, $d) !== null`, for every user.
- [ ] **Step 2: Run** the file. Expected: FAIL (`documentAccess` undefined).
- [ ] **Step 3: Implement** the methods and scopes above; update the three `canDeleteDocument` callers to the new signature.
- [ ] **Step 4: Run** the file and `DocumentsTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `Documents: one access level per person and document, and its query twin`.

### Task 3: Document-keyed routes and the editor

**Files:**
- Modify: `routes/web.php`, `routes/api.php` (replace the per-document project routes; keep `GET /workspace/projects/{project}/documents/{document}` as a redirect to `workspace.documents.show`)
- Modify: `app/Http/Controllers/Workspace/DocumentController.php`, `app/Http/Controllers/Api/DocumentController.php` (no `Project` parameter)
- Create: `app/Http/Requests/Documents/DocumentRequest.php` (open: `documentAccess !== null`, and a trashed document only when frozen); `EditDocumentRequest` (rename: level `edit`, not trashed, not frozen; rules `title`)
- Modify: `DeleteDocumentRequest` (`canDeleteDocument($user, $document)`), `UpdateDocumentRequest` becomes `EditDocumentRequest` (delete the old one)
- Modify: `app/Queries/Documents/DocumentEditorQuery.php` (`for(Document $document, User $viewer, string $access)`), `app/Queries/Documents/ProjectDocumentsQuery.php` (`show(Document, ?Authenticatable)`), `app/Http/Resources/DocumentResource.php` (`withViewer(?Authenticatable)` replaces `withCanDelete`), `app/Http/Controllers/DocumentServerController.php` (file route: `withTrashed`, 404 for a trashed document unless frozen)
- Modify: `resources/js/pages/Documents/Edit.svelte` (`project` optional; marks; links to the new URLs), `resources/js/lib/types.ts`
- Test: `tests/Feature/Workspace/DocumentsTest.php` (move every per-document URL to `/workspace/documents/{id}`), `tests/Feature/Workspace/DocumentsLibraryTest.php`

**Interfaces:**
- Consumes: Task 2 methods.
- Produces:
  - Web routes (prefix `workspace`, names `workspace.documents.*`, `whereNumber`): `GET documents/{document}` `show` (withTrashed), `GET documents/{document}/versions` `versions` (withTrashed), `GET documents/{document}/versions/{version:version}/download` `download` (withTrashed, scopeBindings), `PATCH documents/{document}` `update`, `DELETE documents/{document}` `destroy`, `POST documents/{document}/restore` `restore` (withTrashed). API twins under the existing documents API group, names `api.documents.*`.
  - `DocumentResource` fields added: `home` (`'project'|'personal'`), `project` (`{slug, title, label}|null`, when loaded), `owner` (`{id, name}|null`), `organization_access`, `access`, `frozen`, `can_delete`, `can_share`, `can_move`.
  - `DocumentEditorQuery::for` config: `editorConfig.mode` = `'edit'|'view'`; `document.permissions.edit|comment|review|fillForms|modifyFilter|modifyContentControl` = `$access === 'edit'`; `download`, `print` true; `chat` false; `goback` = the project's Documents tab for a project document, `/workspace/documents` ("Back to Documents") for a personal one.
  - Edit page props: `document` (resource), `versions`, `editor`, `project` (`ProjectResource|null`).
  - `types.ts`: `DocumentItem` (renames `ProjectDocument`, adds the fields above), `DocumentAccess = 'edit' | 'view'`.

- [ ] **Step 1: Write the failing tests** in `DocumentsLibraryTest.php`:
  - `it('opens a Can view document read-only')`: share `view`; decode the page's `editor.config.token` with `DOCS_SECRET`; assert `editorConfig.mode === 'view'` and `document.permissions.edit === false`; `PATCH` and `DELETE` → 403.
  - `it('opens a trashed frozen document for a super-admin only')`: owner trashes, is deactivated; super-admin `GET /workspace/documents/{id}` 200 with mode `view`; the file route (signed, download token) 200; the same for a trashed non-frozen project document → 404 on both.
  - `it('sends old editor links to the new page')`: `GET /workspace/projects/{slug}/documents/{id}` → redirect to `/workspace/documents/{id}`.
  - `it('lets someone off the team open a project document shared with them')`.
- [ ] **Step 2: Run** both files. Expected: the new ones FAIL; update `DocumentsTest.php` URLs (helpers in `tests/Pest.php`: `docsCreate` posts to `/workspace/documents` with `project`, Task 4) and expect them to FAIL until Step 3.
- [ ] **Step 3: Implement** the routes, requests, controllers, editor query, resource, file route and Edit page.
- [ ] **Step 4: Run** `php artisan test --compact --filter=Documents`, `npm run types:check`. Expected: PASS.
- [ ] **Step 5: Commit** `Documents: one page and one set of routes per document, opened at the viewer's level`.

### Task 4: The Documents page, personal create and upload

**Files:**
- Create: `app/Queries/Documents/DocumentLibraryQuery.php`, `app/Http/Controllers/Workspace/DocumentLibraryController.php`, `app/Http/Controllers/Api/DocumentLibraryController.php`, `app/Http/Requests/Documents/DocumentLibraryRequest.php` (filters), `CreateDocumentRequest.php` (base for store and upload: `project` slug optional; authorize documents on; validation: a given project must pass `canUseProjectDocuments` else 422 on `project` `Choose one of your projects.`; no project needs `canCreatePersonalDocuments`, else 403)
- Modify: `StoreDocumentRequest`, `UploadDocumentRequest` (extend `CreateDocumentRequest`), `CreateDocumentService` (`blank(?Project ...)`, `upload(?Project ...)`: no project → `owner_id` = the user), `ProjectDocumentsQuery::tab` (rows through `withViewer`; `trashed_count` unchanged), `routes/web.php`, `routes/api.php` (remove the project `trash`, `store`, `upload`, `index` routes)
- Create: `resources/js/pages/Documents/Index.svelte`
- Test: `tests/Feature/Workspace/DocumentsLibraryTest.php`

**Interfaces:**
- Consumes: Task 2 scope, Task 3 resource.
- Produces:
  - Web routes: `GET documents` `index` (page `Documents/Index`), `GET documents/list` `list` (JSON `{data, next_cursor}`), `GET documents/trash` `trash` (JSON `{data}`), `POST documents` `store`, `POST documents/upload` `upload` (`throttle:30,1,document-upload`). API: `GET documents` (list), `GET documents/trash`, `POST documents`, `POST documents/upload` (201).
  - Filters (query string, validated): `show` in `all|mine|shared|organization|projects` (default `all`), `project` (slug), `type` in `DocumentTypes::all()`, `q` (max 200), `cursor`.
  - `DocumentLibraryQuery::page(User $viewer, array $filters, ?int $organizationId): array{documents: list, next_cursor: ?string, filters: array, projects: list<array{slug: string, title: string, label: string}>, organization: array{id: int, name: string}|null, types: list, max_upload_mb: int, can_create_personal: bool, trashed_count: int}`; `list(User, array $filters, ?int $organizationId): array{data: list, next_cursor: ?string}`; `trash(User, ?string $projectSlug, ?int $organizationId): list`. Rows via `DocumentResource::withViewer`, eager-loading `project`, `owner`, `creator`, `updater`. Order `updated_at desc, id desc`, `cursorPaginate(50)`. `$organizationId` = `WorkspaceOrganization::chosen` (web only): keeps the viewer's own and individually shared documents, and the rest only when `documentOrganizationId` is it. `projects` = projects passing `Project::documentsVisibleTo`, active, by title.
  - The project tab's New, Upload and Trash use `POST /workspace/documents` with `project` and `GET /workspace/documents/trash?project={slug}`.

- [ ] **Step 1: Write the failing tests:**
  - `it('lists every document the viewer may open, newest first')` (own private, shared, org-wide, project; not an outsider's).
  - `it('filters by show, project, type and title')`, one assertion per filter value.
  - `it('pages by updated_at then id')`: 51 documents, two with the same `updated_at` across the page boundary; page 1 has 50, `next_cursor` set, page 2 has the rest, no id repeated.
  - `it('makes a personal document from the page and opens it')`: `POST /workspace/documents {title, type}` → redirect to `/workspace/documents/{id}`, `owner_id` = user, `project_id` null, version path under `personal/`.
  - `it('refuses a project the person may not use')` → 422 `project`.
  - `it('uploads a personal document')` and `it('uploads into a project')`.
  - `it('narrows a super-admin's page to the chosen organization')`.
  - API twins: `GET /api/v1/.../documents?show=mine` and `POST` 201.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the query, requests, controllers, routes, service change and the page. The page: AppShell bar (title Documents; New and Upload on the right through `components/documents/NewDocument.svelte` from Task 7, or inline until then), filter row (Show as a segmented control, project and type selects, search input; each change a `router.get` with `preserveState`, filters in the URL), rows (Task 7's `DocumentList`), "Show more" fetching `documents/list` with the cursor and appending, Trash at the bottom, an empty state saying what personal documents and sharing are.
- [ ] **Step 4: Run** `php artisan test --compact --filter=Documents`. Expected: PASS.
- [ ] **Step 5: Commit** `Documents: the Documents page, personal documents, new and upload anywhere`.

### Task 5: Sharing

**Files:**
- Create: `app/Services/Documents/DocumentSharingService.php`, `app/Queries/Documents/DocumentSharingQuery.php`, `app/Queries/Documents/DocumentShareCandidatesQuery.php`, `app/Http/Controllers/Workspace/DocumentSharingController.php`, `app/Http/Controllers/Api/DocumentSharingController.php`, `app/Http/Requests/Documents/ShareDocumentRequest.php` (authorize `canShareDocument`), `StoreDocumentShareRequest.php`, `UpdateDocumentShareRequest.php`, `OrganizationAccessRequest.php`, `app/Notifications/DocumentShared.php`, `app/Observers/DocumentShareObserver.php`
- Modify: `app/Observers/DocumentObserver.php`, `app/Observers/DocumentVersionObserver.php` (skip personal documents; record `organization_access` changes on project documents), `app/Providers/AppServiceProvider.php` (observer), `app/Console/Commands/SendEmailUpdates.php` (`KINDS` + `item`), `app/Queries/NotificationIndexQuery.php` (`'documents' => [DocumentShared::class]`), `resources/js/pages/Notifications/Index.svelte` (icon `FileText`, chip `Documents`), routes
- Test: `tests/Feature/Workspace/DocumentsSharingTest.php`

**Interfaces:**
- Consumes: Task 2 (`canShareDocument`, `canShareDocumentWith`, `documentOrganizationId`, `documentAccess`).
- Produces:
  - Web routes (`workspace.documents.sharing.*`): `GET documents/{document}/sharing` (`show`), `PUT documents/{document}/sharing/organization` (`organization`, body `{access: null|'view'|'edit'}`), `GET documents/{document}/sharing/candidates?q=` (`candidates`), `POST documents/{document}/shares` (`store`, `{member_id, permission}`), `PATCH documents/{document}/shares/{share}` (`update`, `{permission}`, scopeBindings), `DELETE documents/{document}/shares/{share}` (`destroy`). API twins.
  - `DocumentSharingQuery::for(Document $document): array{home: array{type: string, project: array{slug: string, title: string}|null}, organization: array{id: int, name: string}|null, organization_access: ?string, shares: list<array{id: int, member: array{id: int, name: string, designation: string|null}, permission: string, effective: bool}>}`, shares by member name.
  - `DocumentShareCandidatesQuery::get(Document $document, string $term): list<array{id: int, name: string, designation: string|null}>`: active members of `documentOrganizationId`, not the owner's member, not already shared, name or email like the term, by name, at most 8.
  - `DocumentSharingService`: `setOrganizationAccess(Document $document, ?string $access, Authenticatable $user): ServiceResult`; `share(Document $document, Member $member, string $permission, Authenticatable $user): ServiceResult` (`updateOrCreate`; on a new row, notify the member's linked user with `DocumentShared` when `documentAccess` gives them a level and they are not the actor); `changePermission(DocumentShare $share, string $permission): ServiceResult`; `unshare(DocumentShare $share): ServiceResult`.
  - `StoreDocumentShareRequest`: `member_id` bail, required, integer, closure: a member of `documentOrganizationId` (active or not), else the generic `Choose someone in {organization name}.`; after: `canShareDocumentWith` false → `You can't share this with {name}.`; `permission` in `Document::ACCESSES`. `OrganizationAccessRequest`: `access` nullable, in `ACCESSES`; 422 `This document has no organization to share with.` when `documentOrganizationId` is null.
  - `DocumentShared` payload: `kind 'document_shared'`, `title` = document title, `body` = `"{actor} shared {title} with you. You can edit it."` or `"... You can view it."`, `action` = `"{actor} shared it with you"`, `task` null, `document {id, title}`, `actor {name}`, `url "/workspace/documents/{id}"`. Email title `Shared with you: {title}`.
  - Project activity (project documents only, subject the document, `ProjectActivityRecorder::recordForProject`): `Shared document {title} with {name} (can edit|can view)`, `Changed {name}'s access to document {title} to can view`, `Removed {name}'s access to document {title}`, `Opened document {title} to everyone in {organization} (can view)`, `Closed document {title} to the organization`.

- [ ] **Step 1: Write the failing tests:**
  - `it('shares a personal document with someone in the organization and tells them')`: 201/redirect; `DocumentShared` stored for the recipient; `SendEmailUpdates::item($data)['title'] === 'Shared with you: Notes'`.
  - `it('answers an outsider, an unknown id and another organization's person with one generic message')`: three requests, identical `errors.member_id` text, none containing the outsider's name.
  - `it('refuses the owner, an inactive person, and a non-owner sharing')`.
  - `it('changes and removes a share without telling anyone')`.
  - `it('opens a document to the organization and closes it')`, and 422 for a super-admin's document with no organization.
  - `it('lets a project's manager and its maker share a project document and records it')` (activity rows), and `it('refuses a teammate')` (403).
  - `it('refuses sharing a frozen or trashed document')`.
  - `it('offers candidates of the document's organization only, without emails')`.
  - API twins for store, update, destroy and organization.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the above.
- [ ] **Step 4: Run** `php artisan test --compact --filter=Documents` and `--filter=SendEmailUpdates`. Expected: PASS.
- [ ] **Step 5: Commit** `Documents: share with everyone in the organization or chosen people`.

### Task 6: Move to project, and the delete refusal

**Files:**
- Create: `app/Services/Documents/MoveDocumentService.php`, `app/Http/Requests/Documents/MoveDocumentRequest.php`
- Modify: `Workspace\DocumentController`, `Api\DocumentController` (`move`), routes (`POST documents/{document}/move`, body `{project}` slug), `DocumentObserver` (record `Moved document {title} into this project` on the new project), `app/Http/Controllers/Workspace/MemberController.php` and `Api/MemberController.php` (`destroy`)
- Test: `tests/Feature/Workspace/DocumentsLibraryTest.php`

**Interfaces:**
- Consumes: `canMoveDocument`, `canUseProjectDocuments`, `documentOrganizationId`, `Document::hasPersonalDocuments`.
- Produces:
  - `MoveDocumentService::execute(Document $document, Project $project): ServiceResult`: one transaction, `project_id` set, `owner_id` null, `organization_access` null when the project's organization differs from the document's before the move; message `Moved {title} to {Project::label()}.`
  - `MoveDocumentRequest`: authorize `canMoveDocument`; `project` required slug of a project passing `canUseProjectDocuments`, else 422 `Choose one of your projects.`
  - Member delete: when `$user !== null && Document::hasPersonalDocuments($user)`, nothing is deleted; web `back()` with an error flash, API 422 `{message}`, both `They own personal documents; deactivate them instead.`

- [ ] **Step 1: Write the failing tests:** `it('moves a personal document into a project and keeps its shares')`; `it('turns off organization access when moving into another organization's project')`; `it('refuses a project the owner may not use, a frozen, a trashed and a project document')`; `it('refuses to delete a person who owns personal documents, trashed ones included')` (web and API; the member and login still exist).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test --compact --filter=Documents` and `--filter=Member`. Expected: PASS.
- [ ] **Step 5: Commit** `Documents: move a personal document into a project; keep owners from being deleted`.

### Task 7: The shared Svelte parts, the tab and the editor's Share

**Files:**
- Create: `resources/js/components/documents/DocumentList.svelte` (rows, row menu, rename, versions, trash; Share and Move to project opening the dialogs), `NewDocument.svelte` (New popover with Kind, Title and, when `places` is given, Where: "Just me" first then the projects; Upload with the same Where), `DocumentTrash.svelte` (`url`, `count`), `ShareDialog.svelte`, `MoveDocument.svelte` (a Popover with a project select)
- Modify: `resources/js/components/project/DocumentsView.svelte` (compose the above for one project), `resources/js/pages/Documents/Index.svelte` (Task 4) and `Edit.svelte` (Share button when `document.can_share`; "Can view" mark when `access === 'view'`; frozen mark), `resources/js/lib/navigation.ts` (`mainLinks` option `documentsEnabled`: Documents, icon `FileText`, `/workspace/documents`, after Projects), `resources/js/components/AppShell.svelte` (pass `documentsEnabled`), `resources/js/lib/types.ts` (`DocumentSharing`, `DocumentShareItem`, `DocumentLibrary`, `ProjectChoice`)
- Test: `npm run lint && npm run format && npm run types:check && npm run build`; browser check in Task 9

**Interfaces:**
- Consumes: Task 3 to 6 routes and shapes.
- Produces:
  - `DocumentList` props: `documents: DocumentItem[]`, `showWhere: boolean`, `moveTargets: ProjectChoice[]`. The Where line reads "Personal" (own), "Shared by {owner}" (someone else's personal), or the project label; a small "Everyone in {organization}" mark when `organization_access` is set; "Can view" and `Read-only: its owner has left` marks. Menu items appear only by `access`, `can_share`, `can_move`, `can_delete`, `frozen`.
  - `ShareDialog` props: `document: DocumentItem`, `open` (bindable). Loads `GET .../sharing`; the home line ("Only you" or "Everyone on {project}", Can edit); "Everyone in {organization}" select Off / Can view / Can edit (hidden when no organization); People list with permission select and remove; an add field searching `.../sharing/candidates`, debounced 200 ms, default Can edit. Each change is a `fetch` with the CSRF header and `X-Workspace-Quiet`, then reloads the dialog's data; errors show under the field.

- [ ] **Step 1: Build** the components and wire them; delete the code they replace from `DocumentsView.svelte`.
- [ ] **Step 2: Run** `npm run lint && npm run format && npm run types:check && npm run build`. Expected: clean.
- [ ] **Step 3: Run** `php artisan test --compact --filter=Documents` (Inertia page props unchanged by the refactor). Expected: PASS.
- [ ] **Step 4: Commit** `Documents: the Documents link, shared list parts, Share and Move in the page and the editor`.

### Task 8: Words: Guide, CLAUDE.md, API reference, runbook

**Files:**
- Modify: `resources/js/pages/Guide.svelte` (Documents: the Documents page, personal documents, the three ways to share, who sees what, what happens when someone leaves), `CLAUDE.md` (Documents section; the WorkspaceAccess list: `documentAccess`, `documentOwnerActive`, `isDocumentFrozen`, `documentOrganizationId`, `canCreatePersonalDocuments`, `canShareDocument`, `canShareDocumentWith`, `canMoveDocument`, the new `canDeleteDocument` signature; the scope twin `Document::scopeVisibleTo`), `docs/api.md` (Documents rewritten for the new routes, a Changes entry for the moved and removed project routes), `docs/onlyoffice.md` (the docs.sdoc.gov.np redirect and how to change it)
- [ ] **Step 1: Write** the changes.
- [ ] **Step 2: Run** `npm run lint && npm run format && npm run types:check`. Expected: clean.
- [ ] **Step 3: Commit** `Documents: Guide, API reference and runbook for the Documents page and sharing`.

### Task 9: Whole-branch check and review

- [ ] **Step 1: Run** `composer test` and the frontend commands. Expected: all green, the same pre-existing `composer audit` advisories as main and nothing new.
- [ ] **Step 2: Browser check** on a preview database (never `projects`): seed SDOC with two people and a project; as the owner make a personal document, share it Can view with the other, open it as them (read-only editor, mark shown), open it to the organization, move it into the project; phone width 390 px for the page and the Share dialog; screenshots to `.playwright-mcp/`.
- [ ] **Step 3: Review** by one fresh reviewer on the whole branch diff against the spec, asked to look hardest at `documentAccess` and its twin, the requests' trash and frozen checks, and the generic 422s. Fix what it confirms.
- [ ] **Step 4: Commit** fixes.

### Task 10: Deploy and the docs.sdoc.gov.np redirect

- [ ] **Step 1: Back up** with `./dump_tasks.sh` (the migration changes `documents`). Expected: a new `dumps/tasks-*.dump`.
- [ ] **Step 2: Merge** to `main` (fast-forward), push to the forge and GitHub as main is pushed today.
- [ ] **Step 3: Deploy** the tracker's web and scheduler apps in Dokploy; watch until done; `php artisan migrate:status` shows the new migration ran.
- [ ] **Step 4: Redirect**: on `docs-onlyoffice`, add a Dokploy redirect, regex `^https?://docs\.sdoc\.gov\.np/(welcome/?)?$`, replacement `https://tasks.sdoc.gov.np/workspace/documents`, not permanent; redeploy the app's router if Dokploy asks.
- [ ] **Step 5: Verify** from outside (check-host or the VPN): `/` and `/welcome/` → 302 to the page; `/healthcheck` 200; `/web-apps/apps/api/documents/api.js` 200; the tracker's `/workspace/documents` answers (302 to login when signed out).
- [ ] **Step 6: Update** the memory file `onlyoffice-documents.md`.
