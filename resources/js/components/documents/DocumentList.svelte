<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import {
        Building2,
        Download,
        Ellipsis,
        FolderInput,
        History,
        Lock,
        Pencil,
        Trash2,
        Users,
    } from '@lucide/svelte';
    import { tick } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import { formatBytes } from '../../lib/format';
    import { digits, msg, t, tc, timeAgo } from '../../lib/i18n';
    import type {
        DocumentItem,
        DocumentVersionItem,
        ProjectChoices,
        ProjectPick,
        SharedProps,
    } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import ProjectPicker from '../ProjectPicker.svelte';
    import Spinner from '../Spinner.svelte';
    import DocumentKindIcon from './DocumentKindIcon.svelte';
    import ShareDialog from './ShareDialog.svelte';

    /**
     * Documents as rows, for a project's Documents tab and the Documents
     * page. Each row says what it is (the kind's colour), where it lives
     * (the project, linked when the viewer may open its tab, or Personal,
     * Shared by), who can see it (Only you, Shared with N people, Whole
     * office; Can view when that is the viewer's level) and who touched it
     * last and when. The whole row opens it; Share shows on hover for whoever
     * may share, and the menu holds rename, share, move into a project,
     * versions, download and trash, each as the row's flags allow
     * (DocumentResource). Changes are Inertia visits back to the page,
     * which brings its rows again.
     */
    let {
        documents,
        showWhere = false,
        moveTargets = { projects: [], organizations: [] },
    }: {
        documents: DocumentItem[];
        /** Say where each lives (the Documents page; the tab is one project). */
        showWhere?: boolean;
        /** Projects a personal document can move into. */
        moveTargets?: ProjectChoices;
    } = $props();

    const uid = $props.id();

    // How each version came to be, by whom: the viewer, someone, or no one known.
    const VERSION_LINES = {
        created: {
            you: msg('Created :when by you'),
            name: msg('Created :when by :name'),
            none: msg('Created :when'),
        },
        uploaded: {
            you: msg('Uploaded :when by you'),
            name: msg('Uploaded :when by :name'),
            none: msg('Uploaded :when'),
        },
        edited: {
            you: msg('Saved :when by you'),
            name: msg('Saved :when by :name'),
            none: msg('Saved :when'),
        },
    } as const;

    function versionLine(version: DocumentVersionItem): string {
        const lines = VERSION_LINES[version.source];
        const when = timeAgo(version.created_at);

        if (!version.user) {
            return t(lines.none, { when });
        }

        return version.user.id === viewerId
            ? t(lines.you, { when })
            : t(lines.name, { when, name: version.user.name });
    }

    const viewerId = $derived(
        (page.props as unknown as SharedProps).auth.user?.id ?? null,
    );

    let renamingId = $state<number | null>(null);
    let renameTitle = $state('');
    let renameInput = $state<HTMLInputElement | null>(null);
    let movingId = $state<number | null>(null);
    let moveTo = $state<ProjectPick>(null);
    const moveSlug = $derived(
        moveTargets.projects.find((choice) => choice.id === moveTo)?.slug ??
            null,
    );
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

    /** Who last changed it ("You" for the viewer) and when; never saved since is when it was added. */
    function lastTouched(document: DocumentItem): string {
        const added = document.version === 1;
        const person = added ? document.created_by : document.updated_by;
        const when = timeAgo(added ? document.created_at : document.updated_at);

        if (!person) {
            return when;
        }

        return person.id === viewerId
            ? t('You · :when', { when })
            : t(':name · :when', { name: person.name, when });
    }

    /** Where it lives, said from the viewer's side, when it isn't a project. */
    function personalHome(document: DocumentItem): string {
        const name = document.owner?.name ?? t('someone');

        if (document.frozen) {
            return t('Left by :name', { name });
        }

        return document.owner?.id === viewerId
            ? t('Personal')
            : t('Shared by :name', { name });
    }

    /**
     * Who can see it. For whoever may share: Whole office, else the people
     * it is shared with, else Only you (a personal one; a project's is its
     * team's, which the project's name says). For anyone else: Whole office
     * when it is open to the office.
     */
    function audience(
        document: DocumentItem,
    ): { icon: typeof Lock; label: string } | null {
        if (document.frozen) {
            return null;
        }

        if (document.organization_access) {
            return { icon: Building2, label: t('Whole office') };
        }

        if (!document.can_share) {
            return null;
        }

        if ((document.shares_count ?? 0) > 0) {
            return {
                icon: Users,
                label: tc(
                    'Shared with :count person|Shared with :count people',
                    document.shares_count ?? 0,
                ),
            };
        }

        return document.home === 'personal'
            ? { icon: Lock, label: t('Only you') }
            : null;
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
        moveTo = null;
        moveError = null;
    }

    function move(event: SubmitEvent, document: DocumentItem) {
        event.preventDefault();
        router.post(
            `${url(document)}/move`,
            { project: moveSlug },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (movingId = null),
                onError: (errors) =>
                    (moveError =
                        errors.project ??
                        errors.__global ??
                        t("It didn't move. Try again.")),
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
        {@const list = versions.get(document.id)}
        {@const seen = audience(document)}
        {@const touched = lastTouched(document)}
        <li class="border-b border-line">
            <div
                class="group relative -mx-2 flex min-h-[56px] items-center gap-3 rounded-md px-2 py-2 hover:bg-hover"
            >
                <DocumentKindIcon type={document.file_type} />
                <div class="min-w-0 flex-1">
                    {#if renamingId === document.id}
                        <form
                            class="relative z-10 flex items-center gap-2"
                            onsubmit={(e) => rename(e, document)}
                        >
                            <label
                                for="{uid}-rename-{document.id}"
                                class="sr-only">{t('New title')}</label
                            >
                            <input
                                id="{uid}-rename-{document.id}"
                                bind:this={renameInput}
                                bind:value={renameTitle}
                                maxlength="200"
                                class="input h-8 lg:min-h-8"
                                onkeydown={(e) => {
                                    if (e.key === 'Escape') {
                                        renamingId = null;
                                    }
                                }}
                            />
                            <button type="submit" class="btn shrink-0"
                                >{t('Save')}</button
                            >
                            <button
                                type="button"
                                class="btn-ghost shrink-0"
                                onclick={() => (renamingId = null)}
                                >{t('Cancel')}</button
                            >
                        </form>
                    {:else}
                        <div class="flex min-w-0 items-center gap-2">
                            <!-- The row's one link: its ::after covers the row. -->
                            <a
                                href={url(document)}
                                use:inertia
                                class="truncate font-medium text-fg outline-none after:absolute after:inset-0 after:rounded-md after:content-[''] group-hover:text-accent focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-accent"
                                >{document.title}</a
                            >
                            {#if document.frozen}
                                <span class="chip shrink-0"
                                    >{t('Read-only: its owner has left')}</span
                                >
                            {:else if document.access === 'view'}
                                <span class="chip shrink-0"
                                    >{t('Can view')}</span
                                >
                            {/if}
                        </div>
                        <p
                            class="flex min-w-0 items-center gap-1.5 text-xs text-fg-muted"
                        >
                            {#if showWhere}
                                {#if document.home === 'project' && document.project}
                                    {#if document.project.can_open}
                                        <a
                                            href={`/workspace/projects/${document.project.slug}?view=documents`}
                                            use:inertia
                                            class="relative z-10 truncate hover:text-accent hover:underline"
                                            >{document.project.label}</a
                                        >
                                    {:else}
                                        <span class="truncate"
                                            >{document.project.label}</span
                                        >
                                    {/if}
                                {:else}
                                    <span class="truncate"
                                        >{personalHome(document)}</span
                                    >
                                {/if}
                            {/if}
                            {#if seen}
                                {#if showWhere}<span
                                        class="text-fg-faint"
                                        aria-hidden="true">·</span
                                    >{/if}
                                <span
                                    class="inline-flex shrink-0 items-center gap-1"
                                    title={seen.label}
                                    ><seen.icon
                                        class="h-3 w-3"
                                        aria-hidden="true"
                                    /><span class="sr-only sm:not-sr-only"
                                        >{seen.label}</span
                                    ></span
                                >
                            {/if}
                            <!-- On a phone the time folds into this line. -->
                            {#if showWhere || seen}<span
                                    class="text-fg-faint sm:hidden"
                                    aria-hidden="true">·</span
                                >{/if}
                            <span class="truncate tabular-nums sm:hidden"
                                >{touched}</span
                            >
                        </p>
                    {/if}
                </div>
                <span
                    class="hidden w-44 shrink-0 truncate text-right text-xs text-fg-muted tabular-nums sm:block"
                    >{touched}</span
                >
                <!-- The same slot on every row, so the times line up. -->
                <span class="hidden w-[5.5rem] shrink-0 justify-end md:flex">
                    {#if document.can_share && renamingId !== document.id}
                        <button
                            type="button"
                            class="btn-ghost relative z-10 opacity-0 group-focus-within:opacity-100 group-hover:opacity-100 focus-visible:opacity-100"
                            onclick={() => share(document)}
                            aria-label={t('Share :title', {
                                title: document.title,
                            })}
                            ><Users class="h-3.5 w-3.5" />{t('Share')}</button
                        >
                    {/if}
                </span>
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
                    wrapperClass="relative z-10 shrink-0"
                    triggerClass="btn-icon"
                    triggerLabel={t('More for :title', {
                        title: document.title,
                    })}
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
                            ><Pencil class="h-3.5 w-3.5" />{t('Rename')}</button
                        >
                    {/if}
                    {#if document.can_share}
                        <button
                            type="button"
                            data-popover-item
                            class="menu-item"
                            onclick={() => share(document)}
                            ><Users class="h-3.5 w-3.5" />{t('Share')}</button
                        >
                    {/if}
                    {#if document.can_move && moveTargets.projects.length > 0}
                        <button
                            type="button"
                            data-popover-item
                            class="menu-item"
                            onclick={() => startMove(document)}
                            ><FolderInput class="h-3.5 w-3.5" />{t(
                                'Move to project',
                            )}</button
                        >
                    {/if}
                    <button
                        type="button"
                        data-popover-item
                        class="menu-item"
                        onclick={() => toggleVersions(document)}
                        ><History class="h-3.5 w-3.5" />{list
                            ? t('Hide versions')
                            : t('Versions')}</button
                    >
                    <a
                        data-popover-item
                        class="menu-item"
                        href={downloadUrl(document, document.version)}
                        download
                        ><Download class="h-3.5 w-3.5" />{t('Download')}</a
                    >
                    {#if document.can_delete}
                        <button
                            type="button"
                            data-popover-item
                            class="menu-item text-danger"
                            onclick={() => trash(document)}
                            ><Trash2 class="h-3.5 w-3.5" />{t(
                                'Move to trash',
                            )}</button
                        >
                    {/if}
                </Popover>
            </div>

            {#if movingId === document.id}
                <form
                    class="mb-2.5 ml-11 flex flex-wrap items-center gap-2"
                    onsubmit={(e) => move(e, document)}
                >
                    <span class="min-w-0 flex-1">
                        <ProjectPicker
                            projects={moveTargets.projects}
                            organizations={moveTargets.organizations}
                            bind:value={moveTo}
                            field
                            placeholder={t('Project to move it to')}
                            triggerClass={`input flex h-8 items-center gap-2 text-left lg:min-h-8 ${moveTo === null ? 'text-fg-faint' : 'text-fg'}`}
                        />
                    </span>
                    <button
                        type="submit"
                        class="btn shrink-0"
                        disabled={moveSlug === null}>{t('Move')}</button
                    >
                    <button
                        type="button"
                        class="btn-ghost shrink-0"
                        onclick={() => (movingId = null)}>{t('Cancel')}</button
                    >
                    <p class="w-full text-xs text-fg-muted">
                        {t(
                            "It then belongs to the project: its team edits it, and it can't come back to Personal.",
                        )}
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
                    class="mb-2.5 ml-11 flex items-center gap-2 text-xs text-fg-muted"
                >
                    <Spinner size={12} />{t('Loading versions')}
                </p>
            {:else if list === 'failed'}
                <p class="mb-2.5 ml-11 text-xs text-danger">
                    {t("The versions didn't load.")}
                </p>
            {:else if list}
                <ol class="mb-2.5 ml-11 border-l border-line-soft">
                    {#each list as version (version.version)}
                        <li
                            class="flex items-center gap-3 py-1 pl-3 text-xs text-fg-muted"
                        >
                            <span class="w-20 shrink-0 text-fg"
                                >{t('Version :version', {
                                    version: version.version,
                                })}</span
                            >
                            <span class="min-w-0 flex-1 truncate"
                                >{versionLine(version)}</span
                            >
                            <span class="shrink-0 tabular-nums"
                                >{digits(formatBytes(version.size))}</span
                            >
                            <a
                                class="btn-ghost shrink-0"
                                href={downloadUrl(document, version.version)}
                                download
                                aria-label={t('Download version :version', {
                                    version: version.version,
                                })}><Download class="h-3.5 w-3.5" /></a
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
