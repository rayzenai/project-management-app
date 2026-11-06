<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Search } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import DocumentList from '../../components/documents/DocumentList.svelte';
    import DocumentTrash from '../../components/documents/DocumentTrash.svelte';
    import NewDocument from '../../components/documents/NewDocument.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import type { DocumentItem, DocumentLibrary } from '../../lib/types';

    /**
     * The Documents page: every document the viewer may open (their
     * projects', their own and those shared with them), most recently edited
     * first, 50 at a time. The filters live in the URL; New and Upload go to
     * Just me or a project.
     */
    let props: DocumentLibrary = $props();

    const uid = $props.id();

    const shows = $derived([
        { value: 'all', label: 'Everything' },
        { value: 'mine', label: 'Mine' },
        { value: 'shared', label: 'Shared with me' },
        // "Whole office": an organization's name can be a ministry's full title.
        ...(props.organization
            ? [{ value: 'organization', label: 'Whole office' }]
            : []),
        { value: 'projects', label: 'In projects' },
    ]);

    // svelte-ignore state_referenced_locally
    let show = $state<string>(props.filters.show);
    // svelte-ignore state_referenced_locally
    let project = $state(props.filters.project ?? '');
    // svelte-ignore state_referenced_locally
    let type = $state<string>(props.filters.type ?? '');
    // svelte-ignore state_referenced_locally
    let q = $state(props.filters.q ?? '');
    let searchTimer: ReturnType<typeof setTimeout> | undefined;

    // Show more: rows after the first 50, until the filters change.
    let more = $state<DocumentItem[]>([]);
    // svelte-ignore state_referenced_locally
    let cursor = $state<string | null>(props.next_cursor);
    let loadingMore = $state(false);
    let moreFailed = $state(false);

    $effect(() => {
        void props.documents;
        more = [];
        cursor = props.next_cursor;
    });

    const rows = $derived([...props.documents, ...more]);
    const filtered = $derived(
        props.filters.show !== 'all' ||
            props.filters.project !== null ||
            props.filters.type !== null ||
            props.filters.q !== null,
    );

    function params(): Record<string, string> {
        return Object.fromEntries(
            Object.entries({
                show: show === 'all' ? '' : show,
                project: show === 'projects' ? project : '',
                type,
                q: q.trim(),
            }).filter(([, value]) => value !== ''),
        );
    }

    function apply() {
        router.get('/workspace/documents', params(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['documents', 'next_cursor', 'filters'],
        });
    }

    function searchSoon() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(apply, 300);
    }

    async function showMore() {
        if (cursor === null) {
            return;
        }

        loadingMore = true;
        moreFailed = false;

        try {
            const query = new URLSearchParams({ ...params(), cursor });
            const response = await fetch(`/workspace/documents/list?${query}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const json = (await response.json()) as {
                data: DocumentItem[];
                next_cursor: string | null;
            };
            more = [...more, ...json.data];
            cursor = json.next_cursor;
        } catch {
            moreFailed = true;
        } finally {
            loadingMore = false;
        }
    }

    $effect(() => {
        // The segmented control writes `show`; follow it.
        if (show !== props.filters.show) {
            apply();
        }
    });
</script>

<svelte:head><title>Documents</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Documents</span>
        </div>
        <NewDocument
            types={props.types}
            maxUploadMb={props.max_upload_mb}
            places={props.projects}
            canCreatePersonal={props.can_create_personal}
        />
    {/snippet}

    <div class="mx-auto w-full max-w-4xl">
        <div class="flex flex-col gap-2.5">
            <div class="-mx-1 overflow-x-auto px-1 [scrollbar-width:none]">
                <PillGroup options={shows} bind:value={show} />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 basis-48">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                    />
                    <label for="{uid}-q" class="sr-only">Search titles</label>
                    <input
                        id="{uid}-q"
                        bind:value={q}
                        oninput={searchSoon}
                        placeholder="Search titles"
                        autocomplete="off"
                        class="input pl-8"
                    />
                </div>
                {#if show === 'projects'}
                    <label for="{uid}-project" class="sr-only">Project</label>
                    <select
                        id="{uid}-project"
                        bind:value={project}
                        onchange={apply}
                        class="input w-auto max-w-full"
                    >
                        <option value="">Any project</option>
                        {#each props.projects as choice (choice.slug)}
                            <option value={choice.slug}>{choice.label}</option>
                        {/each}
                    </select>
                {/if}
                <label for="{uid}-type" class="sr-only">Kind</label>
                <select
                    id="{uid}-type"
                    bind:value={type}
                    onchange={apply}
                    class="input w-auto"
                >
                    <option value="">All kinds</option>
                    {#each props.types as kind (kind.value)}
                        <option value={kind.value}>{kind.label}</option>
                    {/each}
                </select>
            </div>
        </div>

        <div class="mt-4">
            {#if rows.length === 0}
                {#if filtered}
                    <p class="text-[13px] text-fg-muted">
                        Nothing matches. Try other filters.
                    </p>
                {:else}
                    <div class="text-[13px] text-fg-muted">
                        <p class="font-medium text-fg">No documents yet.</p>
                        <p class="mt-1 max-w-xl">
                            Start a document, spreadsheet or presentation with
                            New, or upload a .docx, .xlsx or .pptx file. One
                            made for Just me is yours until you share it: with
                            everyone in your office, or with chosen people. One
                            in a project is its team's.
                        </p>
                    </div>
                {/if}
            {:else}
                <DocumentList
                    documents={rows}
                    showWhere
                    moveTargets={props.projects}
                />
                {#if cursor !== null}
                    <div class="mt-3 flex items-center gap-3">
                        <button
                            type="button"
                            class="btn"
                            disabled={loadingMore}
                            onclick={showMore}
                        >
                            {#if loadingMore}
                                <Spinner size={14} />Loading
                            {:else}
                                Show more
                            {/if}
                        </button>
                        {#if moreFailed}
                            <p class="text-xs text-danger">
                                The next ones didn't load. Try again.
                            </p>
                        {/if}
                    </div>
                {/if}
            {/if}
        </div>

        <DocumentTrash
            url="/workspace/documents/trash"
            count={props.trashed_count}
        />
    </div>
</AppShell>
