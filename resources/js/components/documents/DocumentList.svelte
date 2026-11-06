<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import {
        Download,
        Ellipsis,
        FileSpreadsheet,
        FileText,
        FolderInput,
        History,
        Pencil,
        Presentation,
        Trash2,
        Users,
    } from '@lucide/svelte';
    import { tick } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import { formatBytes, formatTimeAgo } from '../../lib/format';
    import type {
        DocumentItem,
        DocumentVersionItem,
        ProjectChoice,
    } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import Spinner from '../Spinner.svelte';
    import ShareDialog from './ShareDialog.svelte';

    /**
     * Documents as rows, for a project's Documents tab and the Documents
     * page: open, rename, share, move into a project, versions, download and
     * trash, each offered only as the row's flags allow (DocumentResource).
     * Changes are Inertia visits back to the page, which brings its rows
     * again.
     */
    let {
        documents,
        showWhere = false,
        moveTargets = [],
    }: {
        documents: DocumentItem[];
        /** Say where each lives (the Documents page; the tab is one project). */
        showWhere?: boolean;
        /** Projects a personal document can move into. */
        moveTargets?: ProjectChoice[];
    } = $props();

    const uid = $props.id();

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

    let renamingId = $state<number | null>(null);
    let renameTitle = $state('');
    let renameInput = $state<HTMLInputElement | null>(null);
    let movingId = $state<number | null>(null);
    let moveTo = $state('');
    let moveError = $state<string | null>(null);
    let sharing = $state<DocumentItem | null>(null);
    let shareOpen = $state(false);
    // The row whose menu is open: a choice closes it, so nothing it opens
    // (a form under the row) sits behind it.
    let menuFor = $state<number | null>(null);

    // Versions, loaded when a row's list is opened.
    const versions = new SvelteMap<
        number,
        DocumentVersionItem[] | 'loading' | 'failed'
    >();

    function url(document: DocumentItem): string {
        return `/workspace/documents/${document.id}`;
    }

    function downloadUrl(document: DocumentItem, version: number): string {
        return `${url(document)}/versions/${version}/download`;
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

    /** Where it lives, said from the viewer's side. */
    function where(document: DocumentItem): string {
        if (document.home === 'project') {
            return document.project?.label ?? 'A project';
        }

        if (document.frozen) {
            return `Left by ${document.owner?.name ?? 'someone'}`;
        }

        // Only the owner may trash a personal document.
        return document.can_delete
            ? 'Personal'
            : `Shared by ${document.owner?.name ?? 'someone'}`;
    }

    /** The line under the title: where, kind, last change, whole office. */
    function details(document: DocumentItem): string {
        return [
            showWhere ? where(document) : null,
            document.kind,
            edited(document),
            document.organization_access ? 'Whole office' : null,
        ]
            .filter((part) => part !== null)
            .join(' · ');
    }

    const canRename = (document: DocumentItem): boolean =>
        document.access === 'edit' && !document.frozen;

    async function startRename(document: DocumentItem) {
        menuFor = null;
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
            url(document),
            { title },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (renamingId = null),
            },
        );
    }

    function startMove(document: DocumentItem) {
        menuFor = null;
        movingId = document.id;
        moveTo = moveTargets[0]?.slug ?? '';
        moveError = null;
    }

    function move(event: SubmitEvent, document: DocumentItem) {
        event.preventDefault();
        router.post(
            `${url(document)}/move`,
            { project: moveTo },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (movingId = null),
                onError: (errors) =>
                    (moveError =
                        errors.project ??
                        errors.__global ??
                        "It didn't move. Try again."),
            },
        );
    }

    function share(document: DocumentItem) {
        menuFor = null;
        sharing = document;
        shareOpen = true;
    }

    async function toggleVersions(document: DocumentItem) {
        menuFor = null;

        if (versions.has(document.id)) {
            versions.delete(document.id);

            return;
        }

        versions.set(document.id, 'loading');

        try {
            const response = await fetch(`${url(document)}/versions`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

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
        menuFor = null;
        router.delete(url(document), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => versions.delete(document.id),
        });
    }
</script>

<ul class="border-t border-line">
    {#each documents as document (document.id)}
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
                        <div class="flex min-w-0 items-center gap-2">
                            <a
                                href={url(document)}
                                use:inertia
                                class="truncate font-medium text-fg hover:text-accent"
                                >{document.title}</a
                            >
                            {#if document.frozen}
                                <span class="chip shrink-0"
                                    >Read-only: its owner has left</span
                                >
                            {:else if document.access === 'view'}
                                <span class="chip shrink-0">Can view</span>
                            {/if}
                        </div>
                        <p class="truncate text-xs text-fg-muted">
                            {details(document)}
                        </p>
                    {/if}
                </div>
                <Popover
                    bind:open={
                        () => menuFor === document.id,
                        (open) =>
                            (menuFor = open
                                ? document.id
                                : menuFor === document.id
                                  ? null
                                  : menuFor)
                    }
                    align="right"
                    triggerClass="btn-icon shrink-0"
                    triggerLabel={`More for ${document.title}`}
                    panelClass="w-52"
                >
                    {#snippet trigger()}
                        <Ellipsis class="h-4 w-4" />
                    {/snippet}
                    {#if canRename(document)}
                        <button
                            type="button"
                            data-popover-item
                            class="menu-item"
                            onclick={() => startRename(document)}
                            ><Pencil class="h-3.5 w-3.5" />Rename</button
                        >
                    {/if}
                    {#if document.can_share}
                        <button
                            type="button"
                            data-popover-item
                            class="menu-item"
                            onclick={() => share(document)}
                            ><Users class="h-3.5 w-3.5" />Share</button
                        >
                    {/if}
                    {#if document.can_move && moveTargets.length > 0}
                        <button
                            type="button"
                            data-popover-item
                            class="menu-item"
                            onclick={() => startMove(document)}
                            ><FolderInput class="h-3.5 w-3.5" />Move to project</button
                        >
                    {/if}
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
                        download><Download class="h-3.5 w-3.5" />Download</a
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

            {#if movingId === document.id}
                <form
                    class="mt-2 ml-6.5 flex flex-wrap items-center gap-2"
                    onsubmit={(e) => move(e, document)}
                >
                    <label for="{uid}-move-{document.id}" class="sr-only"
                        >Project to move it to</label
                    >
                    <select
                        id="{uid}-move-{document.id}"
                        bind:value={moveTo}
                        class="input h-7 w-auto max-w-full min-w-0 flex-1"
                    >
                        {#each moveTargets as target (target.slug)}
                            <option value={target.slug}>{target.label}</option>
                        {/each}
                    </select>
                    <button type="submit" class="btn shrink-0">Move</button>
                    <button
                        type="button"
                        class="btn-ghost shrink-0"
                        onclick={() => (movingId = null)}>Cancel</button
                    >
                    <p class="w-full text-xs text-fg-muted">
                        It then belongs to the project: its team edits it, and
                        it can't come back to Personal.
                    </p>
                    {#if moveError}
                        <p role="alert" class="w-full text-xs text-danger">
                            {moveError}
                        </p>
                    {/if}
                </form>
            {/if}

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
                                {formatTimeAgo(version.created_at)}{version.user
                                    ? ` by ${version.user.name}`
                                    : ''}</span
                            >
                            <span class="shrink-0 tabular-nums"
                                >{formatBytes(version.size)}</span
                            >
                            <a
                                class="btn-ghost shrink-0"
                                href={downloadUrl(document, version.version)}
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

{#if sharing}
    <ShareDialog
        document={sharing}
        bind:open={shareOpen}
        onchanged={() => router.reload({ only: ['documents'] })}
    />
{/if}
