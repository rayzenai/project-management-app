<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { ExternalLink, FolderGit2, FolderPlus } from '@lucide/svelte';
    import { tick } from 'svelte';
    import { MediaQuery } from 'svelte/reactivity';
    import { plural } from '../../lib/format';
    import { suggestProjectKey } from '../../lib/projectKey';
    import type {
        Id,
        UnlinkedRepositories,
        UnlinkedRepository,
    } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import ProjectPicker from '../ProjectPicker.svelte';

    /**
     * The forge repositories no project links, at the top of Code activity
     * for whoever administers the organization: their commits count nowhere
     * until each is linked to a project (the project picker) or given a
     * project of its own ("New project for it": a popover on a desktop, a
     * bottom sheet on a phone). The list scrolls past about six.
     */
    let { notices }: { notices: UnlinkedRepositories[] } = $props();

    const shown = $derived(
        notices.filter((n) => n.unavailable || n.repositories.length > 0),
    );
    /** Several organizations in view: each notice says whose it is. */
    const named = $derived(notices.length > 1);

    // Tailwind's md: below it the form rises from the bottom as a sheet.
    const wide = new MediaQuery('(min-width: 48rem)', true);

    const uid = $props.id();
    const rowKey = (notice: UnlinkedRepositories, r: UnlinkedRepository) =>
        `${notice.connection.id}:${r.forge_id}`;

    /** A forge address worth a link (never a `javascript:` one). */
    const webUrl = (url: string | null) =>
        url !== null && /^https?:\/\//i.test(url) ? url : null;

    /** The row an action is under way on; the others wait for it. */
    let busy = $state<string | null>(null);
    /** Why the last action on a row failed, under that row. */
    let failure = $state<{ key: string; message: string } | null>(null);

    // ---- Link to a project ----
    /** Each row's picked project, until the link answers. */
    let picked = $state<Record<string, Id | null>>({});

    function link(
        notice: UnlinkedRepositories,
        repository: UnlinkedRepository,
        projectId: Id,
    ) {
        const key = rowKey(notice, repository);
        const project = notice.projects.find((p) => p.id === projectId);

        if (!project) {
            return;
        }

        busy = key;
        failure = null;
        router.post(
            `/workspace/projects/${project.slug}/repositories`,
            { full_name: repository.full_name },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    picked[key] = null;
                    failure = {
                        key,
                        message:
                            errors.full_name ??
                            errors.__global ??
                            "That repository couldn't be linked.",
                    };
                },
                onFinish: () => (busy = null),
            },
        );
    }

    // ---- New project for it ----
    let creating = $state<string | null>(null);
    let title = $state('');
    /** What the person typed as the key; until they do, it follows the title. */
    let projectKey = $state('');
    let keyEdited = $state(false);
    let programId = $state('');
    let errors = $state<Record<string, string>>({});
    let sheetEl = $state<HTMLElement | null>(null);
    let sheetOpener: HTMLElement | null = null;

    const creatingRow = $derived.by(() => {
        for (const notice of shown) {
            for (const repository of notice.repositories) {
                if (rowKey(notice, repository) === creating) {
                    return { notice, repository };
                }
            }
        }

        return null;
    });

    /**
     * The key the title suggests: the server's (free in the organization)
     * for the title it suggested, else the title's initials (the server
     * makes them unique when the form is sent without a key).
     */
    const suggestedKey = $derived.by(() => {
        const repository = creatingRow?.repository;

        if (!repository) {
            return '';
        }

        return title.trim() === repository.suggested_title
            ? repository.suggested_key
            : suggestProjectKey(title);
    });
    const shownKey = $derived(keyEdited ? projectKey : suggestedKey);

    function startCreating(
        notice: UnlinkedRepositories,
        repository: UnlinkedRepository,
    ) {
        creating = rowKey(notice, repository);
        title = repository.suggested_title;
        projectKey = '';
        keyEdited = false;
        programId = '';
        errors = {};
        failure = null;
    }

    function stopCreating() {
        creating = null;
        sheetOpener?.focus();
        sheetOpener = null;
    }

    async function openSheet(
        event: MouseEvent,
        notice: UnlinkedRepositories,
        repository: UnlinkedRepository,
    ) {
        sheetOpener = event.currentTarget as HTMLElement;
        startCreating(notice, repository);
        await tick();
        // The sheet takes the focus, not the title: no keyboard over the form.
        sheetEl?.focus();
    }

    function create(
        event: SubmitEvent,
        notice: UnlinkedRepositories,
        repository: UnlinkedRepository,
    ) {
        event.preventDefault();
        const key = rowKey(notice, repository);
        busy = key;
        errors = {};
        router.post(
            `/workspace/git/connections/${notice.connection.id}/repositories/${repository.forge_id}/project`,
            {
                title: title.trim(),
                // Untouched (or cleared), the server suggests it from the title.
                key:
                    keyEdited && projectKey.trim() !== ''
                        ? projectKey.trim()
                        : null,
                program_id: programId === '' ? null : Number(programId),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (creating = null),
                // A field's error goes under it, any other under the form.
                onError: (e) => (errors = e),
                onFinish: () => (busy = null),
            },
        );
    }

    function onSheetKey(e: KeyboardEvent) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            stopCreating();
        }
    }

    /** The sheet sits on <body>, so no container can clip or cover it. */
    function portal(node: HTMLElement) {
        document.body.appendChild(node);

        return {
            destroy() {
                node.remove();
            },
        };
    }
</script>

{#snippet newProjectForm(
    notice: UnlinkedRepositories,
    repository: UnlinkedRepository,
)}
    {@const id = `${uid}-${rowKey(notice, repository).replace(':', '-')}`}
    <form
        class="flex flex-col gap-3 p-2"
        onsubmit={(e) => create(e, notice, repository)}
    >
        <div>
            <h3 class="text-[14px] font-semibold text-fg">
                New project for
                <span class="font-mono font-normal">{repository.full_name}</span
                >
            </h3>
            <p class="mt-0.5 text-xs text-fg-muted">
                Made in {notice.connection.organization.name}, with the
                repository linked to it.
            </p>
        </div>
        <div>
            <label for={`${id}-title`} class="label mb-1 block">Title</label>
            <input
                id={`${id}-title`}
                type="text"
                class="input"
                maxlength="255"
                required
                bind:value={title}
            />
            {#if errors.title}
                <p class="mt-1 text-xs text-danger">{errors.title}</p>
            {/if}
        </div>
        <div>
            <label for={`${id}-key`} class="label mb-1 block">Key</label>
            <input
                id={`${id}-key`}
                type="text"
                class="input font-mono uppercase"
                maxlength="10"
                autocapitalize="characters"
                autocomplete="off"
                spellcheck="false"
                value={shownKey}
                oninput={(e) => {
                    keyEdited = true;
                    projectKey = e.currentTarget.value;
                }}
            />
            <p class="mt-1 text-xs text-fg-muted">
                Tasks are written <span class="font-mono"
                    >{shownKey.trim().toUpperCase() || 'KEY'}-12</span
                > in commits and branches.
            </p>
            {#if errors.key}
                <p class="mt-1 text-xs text-danger">{errors.key}</p>
            {/if}
        </div>
        {#if notice.programs.length > 0}
            <div>
                <label for={`${id}-program`} class="label mb-1 block"
                    >Program</label
                >
                <select
                    id={`${id}-program`}
                    class="input"
                    bind:value={programId}
                >
                    <option value="">No program</option>
                    {#each notice.programs as program (program.id)}
                        <option value={String(program.id)}
                            >{program.name}</option
                        >
                    {/each}
                </select>
                {#if errors.program_id}
                    <p class="mt-1 text-xs text-danger">{errors.program_id}</p>
                {/if}
            </div>
        {/if}
        {#if errors.__global}
            <p role="alert" class="text-xs text-danger">{errors.__global}</p>
        {/if}
        <div class="flex justify-end gap-2">
            <button type="button" class="btn-ghost" onclick={stopCreating}
                >Cancel</button
            >
            <button
                type="submit"
                class="btn-primary"
                disabled={busy !== null || title.trim() === ''}
                >{busy === rowKey(notice, repository)
                    ? 'Creating'
                    : 'Create and link'}</button
            >
        </div>
    </form>
{/snippet}

{#each shown as notice (notice.connection.id)}
    {@const count = notice.repositories.length}
    <section
        class="panel"
        aria-label={`Repositories not linked to a project in ${notice.connection.organization.name}`}
    >
        <div class="flex items-start gap-2.5 px-3 py-2.5">
            <FolderGit2 class="mt-0.5 h-4 w-4 shrink-0 text-fg-muted" />
            <p class="text-[13px] text-fg">
                {#if named}
                    <span class="font-medium"
                        >{notice.connection.organization.name}:</span
                    >
                {/if}
                {#if notice.unavailable}
                    Couldn't read the forge's repositories just now, so any not
                    linked to a project aren't listed. Try again in a few
                    minutes.
                {:else}
                    {plural(count, 'repository', 'repositories')} on the forge
                    {count === 1 ? "isn't" : "aren't"} linked to a project, so
                    {count === 1 ? 'its' : 'their'} commits don't count here.
                {/if}
            </p>
        </div>

        {#if count > 0}
            <ul
                class="max-h-[min(22rem,60vh)] divide-y divide-line-soft overflow-y-auto border-t border-line"
            >
                {#each notice.repositories as repository (repository.forge_id)}
                    {@const key = rowKey(notice, repository)}
                    {@const forge = webUrl(repository.html_url)}
                    <li
                        class="flex flex-col gap-2 px-3 py-2.5 md:flex-row md:items-center md:gap-3"
                    >
                        <div class="flex min-w-0 flex-1 items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate font-mono text-[13px] text-fg"
                                >
                                    {repository.full_name}
                                </p>
                                {#if repository.description}
                                    <p class="truncate text-xs text-fg-muted">
                                        {repository.description}
                                    </p>
                                {/if}
                                {#if failure?.key === key}
                                    <p
                                        role="alert"
                                        class="mt-1 text-xs text-danger"
                                    >
                                        {failure.message}
                                    </p>
                                {/if}
                            </div>
                            {#if forge}
                                <!-- A phone keeps the row's two actions on one line. -->
                                <a
                                    href={forge}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="btn-icon -mt-1.5 -mr-1.5 shrink-0 md:hidden"
                                    aria-label="Open on the forge"
                                    title="Open on the forge"
                                >
                                    <ExternalLink class="h-4 w-4" />
                                </a>
                            {/if}
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            {#if forge}
                                <a
                                    href={forge}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="btn-ghost hidden md:inline-flex"
                                >
                                    <ExternalLink class="h-3.5 w-3.5" />
                                    Open on the forge
                                </a>
                            {/if}
                            <ProjectPicker
                                projects={notice.projects}
                                organizations={[notice.connection.organization]}
                                homeOrganizationId={notice.connection
                                    .organization.id}
                                currentMemberId={null}
                                bind:value={
                                    () => picked[key] ?? null,
                                    (v) =>
                                        (picked[key] =
                                            typeof v === 'number' ? v : null)
                                }
                                disabled={busy !== null}
                                placeholder="Link to a project"
                                triggerClass="btn max-w-full"
                                onpick={(v) => {
                                    if (typeof v === 'number') {
                                        link(notice, repository, v);
                                    }
                                }}
                            />
                            {#if wide.current}
                                <Popover
                                    bind:open={
                                        () => creating === key,
                                        (open) =>
                                            open
                                                ? startCreating(
                                                      notice,
                                                      repository,
                                                  )
                                                : (creating = null)
                                    }
                                    role="dialog"
                                    align="right"
                                    label={`New project for ${repository.full_name}`}
                                    triggerClass="btn"
                                    panelClass="w-[360px] max-w-[calc(100vw-16px)]"
                                >
                                    {#snippet trigger()}
                                        <FolderPlus class="h-3.5 w-3.5" />
                                        New project for it
                                    {/snippet}
                                    {@render newProjectForm(notice, repository)}
                                </Popover>
                            {:else}
                                <!-- No icon on a phone: both actions fit one line. -->
                                <button
                                    type="button"
                                    class="btn"
                                    aria-haspopup="dialog"
                                    aria-expanded={creating === key}
                                    disabled={busy !== null}
                                    onclick={(e) =>
                                        openSheet(e, notice, repository)}
                                >
                                    New project for it
                                </button>
                            {/if}
                        </div>
                    </li>
                {/each}
            </ul>
        {/if}
    </section>
{/each}

{#if creatingRow && !wide.current}
    <div use:portal class="fixed inset-0 z-50">
        <button
            type="button"
            aria-label="Close the new project form"
            tabindex="-1"
            class="absolute inset-0 cursor-default bg-black/40"
            onclick={stopCreating}
        ></button>
        <div
            bind:this={sheetEl}
            role="dialog"
            aria-modal="true"
            aria-label={`New project for ${creatingRow.repository.full_name}`}
            tabindex="-1"
            class="popover absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-b-none px-2 pt-2 pb-[max(8px,env(safe-area-inset-bottom))] focus:outline-none"
            onkeydown={onSheetKey}
        >
            {@render newProjectForm(creatingRow.notice, creatingRow.repository)}
        </div>
    </div>
{/if}
