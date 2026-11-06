<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import {
        Download,
        Ellipsis,
        FileSpreadsheet,
        FileText,
        History,
        Pencil,
        Plus,
        Presentation,
        RotateCcw,
        Trash2,
        Upload,
    } from '@lucide/svelte';
    import { tick } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import { formatBytes, formatTimeAgo, plural } from '../../lib/format';
    import type {
        DocumentFileType,
        DocumentVersionItem,
        Project,
        DocumentItem,
        ProjectDocuments,
    } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import Spinner from '../Spinner.svelte';

    /**
     * A project's Documents tab: its Word, Excel and PowerPoint files, each
     * opened in the editor (ONLYOFFICE) where the team edits it together.
     * Every save is a version kept for good; the trash is never emptied.
     */
    let {
        project,
        documents,
    }: {
        project: Project;
        documents: ProjectDocuments;
    } = $props();

    const uid = $props.id();
    const base = $derived(`/workspace/projects/${project.slug}/documents`);

    const ICONS = {
        docx: FileText,
        xlsx: FileSpreadsheet,
        pptx: Presentation,
    } as const;

    const SOURCE = {
        created: 'Created',
        uploaded: 'Uploaded',
        edited: 'Saved',
    } as const;

    // New document.
    let newOpen = $state(false);
    let newTitle = $state('');
    let newType = $state<DocumentFileType>('docx');
    let newErrors = $state<Record<string, string>>({});
    let creating = $state(false);

    // Upload.
    let fileInput = $state<HTMLInputElement | null>(null);
    let uploading = $state(false);
    let uploadError = $state<string | null>(null);

    // Rename, one row at a time.
    let renamingId = $state<number | null>(null);
    let renameTitle = $state('');
    let renameInput = $state<HTMLInputElement | null>(null);

    // Versions, loaded when a row's list is opened.
    const versions = new SvelteMap<
        number,
        DocumentVersionItem[] | 'loading' | 'failed'
    >();

    // The trash, loaded when opened.
    let trashOpen = $state(false);
    let trashed = $state<DocumentItem[] | null>(null);
    let trashFailed = $state(false);

    const placeholder = $derived(
        `Untitled ${(documents.types.find((t) => t.value === newType)?.label ?? 'document').toLowerCase()}`,
    );

    function create(event: SubmitEvent) {
        event.preventDefault();
        creating = true;
        newErrors = {};
        router.post(
            base,
            { title: newTitle.trim() || placeholder, type: newType },
            {
                // The server opens the new document in the editor.
                onError: (errors) => (newErrors = errors),
                onFinish: () => (creating = false),
            },
        );
    }

    function chooseFile() {
        uploadError = null;
        fileInput?.click();
    }

    function upload() {
        const file = fileInput?.files?.[0];

        if (!file) {
            return;
        }

        if (file.size > documents.max_upload_mb * 1024 * 1024) {
            uploadError = `Files can be at most ${documents.max_upload_mb} MB.`;
            fileInput!.value = '';

            return;
        }

        uploading = true;
        router.post(
            `${base}/upload`,
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    (uploadError =
                        errors.file ??
                        errors.__global ??
                        "The file couldn't be uploaded."),
                onFinish: () => {
                    uploading = false;

                    if (fileInput) {
                        fileInput.value = '';
                    }
                },
            },
        );
    }

    async function startRename(document: DocumentItem) {
        renamingId = document.id;
        renameTitle = document.title;
        await tick();
        renameInput?.focus();
        renameInput?.select();
    }

    function rename(event: SubmitEvent, document: DocumentItem) {
        event.preventDefault();
        const title = renameTitle.trim();

        if (title === '' || title === document.title) {
            renamingId = null;

            return;
        }

        router.patch(
            `/workspace/documents/${document.id}`,
            { title },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (renamingId = null),
            },
        );
    }

    async function toggleVersions(document: DocumentItem) {
        if (versions.has(document.id)) {
            versions.delete(document.id);

            return;
        }

        versions.set(document.id, 'loading');

        try {
            const response = await fetch(
                `/workspace/documents/${document.id}/versions`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const json = (await response.json()) as {
                data: DocumentVersionItem[];
            };

            if (versions.has(document.id)) {
                versions.set(document.id, json.data);
            }
        } catch {
            if (versions.has(document.id)) {
                versions.set(document.id, 'failed');
            }
        }
    }

    function trash(document: DocumentItem) {
        router.delete(`/workspace/documents/${document.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                versions.delete(document.id);

                if (trashOpen) {
                    void loadTrash();
                }
            },
        });
    }

    function restore(document: DocumentItem) {
        router.post(
            `/workspace/documents/${document.id}/restore`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => void loadTrash(),
            },
        );
    }

    async function loadTrash() {
        trashFailed = false;

        try {
            const response = await fetch(`${base}/trash`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            trashed = ((await response.json()) as { data: DocumentItem[] })
                .data;
        } catch {
            trashFailed = true;
        }
    }

    function toggleTrash() {
        trashOpen = !trashOpen;

        if (trashOpen) {
            trashed = null;
            void loadTrash();
        }
    }

    /** Who last changed it; a document never saved since is "Added". */
    function edited(document: DocumentItem): string {
        const added = document.version === 1;
        const who = (added ? document.created_by : document.updated_by)?.name;
        const verb = added ? 'Added' : 'Edited';
        const when = formatTimeAgo(
            added ? document.created_at : document.updated_at,
        );

        return who ? `${verb} ${when} by ${who}` : `${verb} ${when}`;
    }

    function downloadUrl(document: DocumentItem, version: number): string {
        return `/workspace/documents/${document.id}/versions/${version}/download`;
    }
</script>

<section>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="section-title mb-1">
                Documents <span class="section-count"
                    >{documents.documents.length}</span
                >
            </h2>
            <p class="text-xs text-fg-muted">
                Word, Excel and PowerPoint files the team edits together. Every
                save is kept as a version.
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <input
                bind:this={fileInput}
                type="file"
                accept=".docx,.xlsx,.pptx"
                class="hidden"
                aria-label="Choose a file to upload"
                onchange={upload}
            />
            <button
                type="button"
                class="btn"
                disabled={uploading}
                onclick={chooseFile}
            >
                {#if uploading}
                    <Spinner size={14} label="Uploading" />Uploading
                {:else}
                    <Upload class="h-3.5 w-3.5" />Upload
                {/if}
            </button>
            <Popover
                bind:open={newOpen}
                role="dialog"
                align="right"
                label="New document"
                triggerClass="btn-primary"
                panelClass="w-72"
            >
                {#snippet trigger()}
                    <Plus class="h-3.5 w-3.5" />New
                {/snippet}

                <form class="p-1.5" onsubmit={create}>
                    <fieldset>
                        <legend class="label mb-1.5">Kind</legend>
                        <div class="grid grid-cols-3 gap-1">
                            {#each documents.types as type (type.value)}
                                {@const Icon = ICONS[type.value]}
                                <label
                                    class={`flex cursor-pointer flex-col items-center gap-1 rounded-md border px-1 py-2 text-xs ${
                                        newType === type.value
                                            ? 'border-accent bg-accent-soft text-fg'
                                            : 'border-line text-fg-muted hover:bg-hover'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="{uid}-type"
                                        value={type.value}
                                        bind:group={newType}
                                        class="sr-only"
                                    />
                                    <Icon class="h-4 w-4" />
                                    {type.label}
                                </label>
                            {/each}
                        </div>
                    </fieldset>
                    <label for="{uid}-title" class="label mt-3 mb-1 block"
                        >Title</label
                    >
                    <!-- svelte-ignore a11y_autofocus -->
                    <input
                        id="{uid}-title"
                        bind:value={newTitle}
                        maxlength="200"
                        autocomplete="off"
                        autofocus
                        {placeholder}
                        aria-invalid={newErrors.title ? 'true' : undefined}
                        class="input"
                    />
                    {#if newErrors.title || newErrors.type || newErrors.__global}
                        <p role="alert" class="mt-1 text-xs text-danger">
                            {newErrors.title ??
                                newErrors.type ??
                                newErrors.__global}
                        </p>
                    {/if}
                    <div class="mt-3 flex justify-end">
                        <button
                            type="submit"
                            class="btn-primary"
                            disabled={creating}>Create and open</button
                        >
                    </div>
                </form>
            </Popover>
        </div>
    </div>

    {#if uploadError}
        <p role="alert" class="mt-2 text-xs text-danger">{uploadError}</p>
    {/if}

    {#if documents.documents.length === 0}
        <p class="mt-3 text-[13px] text-fg-muted">
            No documents yet. Start one, or upload a .docx, .xlsx or .pptx file
            of up to {documents.max_upload_mb} MB.
        </p>
    {:else}
        <ul class="mt-3 border-t border-line">
            {#each documents.documents as document (document.id)}
                {@const Icon = ICONS[document.file_type]}
                {@const list = versions.get(document.id)}
                <li class="border-b border-line py-2.5">
                    <div class="flex min-h-7 items-center gap-2.5">
                        <Icon class="h-4 w-4 shrink-0 text-fg-faint" />
                        <div class="min-w-0 flex-1">
                            {#if renamingId === document.id}
                                <form
                                    class="flex items-center gap-2"
                                    onsubmit={(e) => rename(e, document)}
                                >
                                    <label
                                        for="{uid}-rename-{document.id}"
                                        class="sr-only">New title</label
                                    >
                                    <input
                                        id="{uid}-rename-{document.id}"
                                        bind:this={renameInput}
                                        bind:value={renameTitle}
                                        maxlength="200"
                                        class="input h-7"
                                        onkeydown={(e) => {
                                            if (e.key === 'Escape') {
                                                renamingId = null;
                                            }
                                        }}
                                    />
                                    <button type="submit" class="btn shrink-0"
                                        >Save</button
                                    >
                                    <button
                                        type="button"
                                        class="btn-ghost shrink-0"
                                        onclick={() => (renamingId = null)}
                                        >Cancel</button
                                    >
                                </form>
                            {:else}
                                <a
                                    href="/workspace/documents/{document.id}"
                                    use:inertia
                                    class="block truncate font-medium text-fg hover:text-accent"
                                    >{document.title}</a
                                >
                                <p class="truncate text-xs text-fg-muted">
                                    {document.kind} · Version {document.version}
                                    · {edited(document)}
                                </p>
                            {/if}
                        </div>
                        <Popover
                            align="right"
                            triggerClass="btn-icon shrink-0"
                            triggerLabel={`More for ${document.title}`}
                            panelClass="w-52"
                        >
                            {#snippet trigger()}
                                <Ellipsis class="h-4 w-4" />
                            {/snippet}
                            <button
                                type="button"
                                data-popover-item
                                class="menu-item"
                                onclick={() => startRename(document)}
                                ><Pencil class="h-3.5 w-3.5" />Rename</button
                            >
                            <button
                                type="button"
                                data-popover-item
                                class="menu-item"
                                onclick={() => toggleVersions(document)}
                                ><History class="h-3.5 w-3.5" />{list
                                    ? 'Hide versions'
                                    : 'Versions'}</button
                            >
                            <a
                                data-popover-item
                                class="menu-item"
                                href={downloadUrl(document, document.version)}
                                download
                                ><Download class="h-3.5 w-3.5" />Download</a
                            >
                            {#if document.can_delete}
                                <button
                                    type="button"
                                    data-popover-item
                                    class="menu-item text-danger"
                                    onclick={() => trash(document)}
                                    ><Trash2 class="h-3.5 w-3.5" />Move to trash</button
                                >
                            {/if}
                        </Popover>
                    </div>

                    {#if list === 'loading'}
                        <p
                            class="mt-2 ml-6.5 flex items-center gap-2 text-xs text-fg-muted"
                        >
                            <Spinner size={12} />Loading versions
                        </p>
                    {:else if list === 'failed'}
                        <p class="mt-2 ml-6.5 text-xs text-danger">
                            The versions didn't load.
                        </p>
                    {:else if list}
                        <ol class="mt-2 ml-6.5 border-l border-line-soft">
                            {#each list as version (version.version)}
                                <li
                                    class="flex items-center gap-3 py-1 pl-3 text-xs text-fg-muted"
                                >
                                    <span class="w-20 shrink-0 text-fg"
                                        >Version {version.version}</span
                                    >
                                    <span class="min-w-0 flex-1 truncate"
                                        >{SOURCE[version.source]}
                                        {formatTimeAgo(
                                            version.created_at,
                                        )}{version.user
                                            ? ` by ${version.user.name}`
                                            : ''}</span
                                    >
                                    <span class="shrink-0 tabular-nums"
                                        >{formatBytes(version.size)}</span
                                    >
                                    <a
                                        class="btn-ghost shrink-0"
                                        href={downloadUrl(
                                            document,
                                            version.version,
                                        )}
                                        download
                                        aria-label={`Download version ${version.version}`}
                                        ><Download class="h-3.5 w-3.5" /></a
                                    >
                                </li>
                            {/each}
                        </ol>
                    {/if}
                </li>
            {/each}
        </ul>
    {/if}

    {#if documents.trashed_count > 0 || trashOpen}
        <div class="mt-4">
            <button
                type="button"
                class="btn-ghost -ml-2"
                aria-expanded={trashOpen}
                onclick={toggleTrash}
            >
                <Trash2 class="h-3.5 w-3.5" />Trash
                <span class="section-count">{documents.trashed_count}</span>
            </button>
            {#if trashOpen}
                {#if trashFailed}
                    <p class="mt-2 text-xs text-danger">
                        The trash didn't load.
                    </p>
                {:else if trashed === null}
                    <p
                        class="mt-2 flex items-center gap-2 text-xs text-fg-muted"
                    >
                        <Spinner size={12} />Loading
                    </p>
                {:else if trashed.length === 0}
                    <p class="mt-2 text-xs text-fg-muted">
                        The trash is empty.
                    </p>
                {:else}
                    <ul class="mt-2 border-t border-line">
                        {#each trashed as document (document.id)}
                            {@const Icon = ICONS[document.file_type]}
                            <li
                                class="flex min-h-10 items-center gap-2.5 border-b border-line py-1.5"
                            >
                                <Icon class="h-4 w-4 shrink-0 text-fg-faint" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-fg-muted">
                                        {document.title}
                                    </p>
                                    <p class="text-xs text-fg-faint">
                                        Moved to the trash {formatTimeAgo(
                                            document.deleted_at,
                                        )} · {plural(
                                            document.version,
                                            'version',
                                        )} kept
                                    </p>
                                </div>
                                {#if document.can_delete}
                                    <button
                                        type="button"
                                        class="btn-ghost shrink-0"
                                        onclick={() => restore(document)}
                                        ><RotateCcw
                                            class="h-3.5 w-3.5"
                                        />Restore</button
                                    >
                                {/if}
                            </li>
                        {/each}
                    </ul>
                {/if}
            {/if}
        </div>
    {/if}
</section>
