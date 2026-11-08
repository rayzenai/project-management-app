<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Search } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import DocumentList from '../../components/documents/DocumentList.svelte';
    import DocumentProjectCards from '../../components/documents/DocumentProjectCards.svelte';
    import DocumentStart from '../../components/documents/DocumentStart.svelte';
    import DocumentTrash from '../../components/documents/DocumentTrash.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import { msg, t } from '../../lib/i18n';
    import type {
        DocumentItem,
        DocumentLibrary,
        DocumentTab,
    } from '../../lib/types';

    /**
     * The Documents page: start a document with one click or drop files on
     * it; then four tabs, Recent (everything the viewer may open, most
     * recently edited first, 50 at a time), Personal, Shared with me (by
     * name, and open to the whole office) and Projects (a card per project,
     * opening its Documents tab). Search shows once there is enough to
     * search. The tab and search live in the URL.
     */
    let props: DocumentLibrary = $props();

    const uid = $props.id();

    /** Search shows once there are more documents than fit at a glance. */
    const SEARCH_FROM = 9;

    const tabs: { value: DocumentTab; label: string }[] = [
        { value: 'recent', label: msg('Recent') },
        { value: 'personal', label: msg('Personal') },
        { value: 'shared', label: msg('Shared with me') },
        { value: 'projects', label: msg('Projects') },
    ];

    // svelte-ignore state_referenced_locally
    let show = $state<string>(props.filters.show);
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
    const showSearch = $derived(
        props.total >= SEARCH_FROM || props.filters.q !== null,
    );
    const cards = $derived(
        props.filters.show === 'projects' &&
            props.filters.q === null &&
            props.filters.project === null &&
            props.filters.type === null,
    );

    function params(): Record<string, string> {
        return Object.fromEntries(
            Object.entries({
                show: show === 'recent' ? '' : show,
                q: q.trim(),
            }).filter(([, value]) => value !== ''),
        );
    }

    function apply() {
        router.get('/workspace/documents', params(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['documents', 'next_cursor', 'project_cards', 'filters'],
        });
    }

    function searchSoon() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(apply, 300);
    }

    /**
     * The filters the rows on screen came from (the server's), not what is
     * typed: the cursor belongs to them, and a search waiting out its 300 ms
     * hasn't been applied yet.
     */
    function shownParams(): Record<string, string> {
        const { show, project, type, q } = props.filters;

        return Object.fromEntries(
            Object.entries({
                show: show === 'recent' ? '' : show,
                project: project ?? '',
                type: type ?? '',
                q: q ?? '',
            }).filter(([, value]) => value !== ''),
        );
    }

    async function showMore() {
        if (cursor === null) {
            return;
        }

        loadingMore = true;
        moreFailed = false;
        // A filter change while this loads replaces the list; its rows
        // would not belong there.
        const list = props.documents;

        try {
            const query = new URLSearchParams({ ...shownParams(), cursor });
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

            if (props.documents !== list) {
                return;
            }

            more = [...more, ...json.data];
            cursor = json.next_cursor;
        } catch {
            if (props.documents === list) {
                moreFailed = true;
            }
        } finally {
            loadingMore = false;
        }
    }

    $effect(() => {
        // The tabs write `show`; follow them.
        if (show !== props.filters.show) {
            apply();
        }
    });

    const EMPTY: Record<DocumentTab, { title: string; body: string }> = {
        recent: {
            title: msg('No documents yet.'),
            body: msg(
                'Start a document, spreadsheet or presentation above, or drop a .docx, .xlsx or .pptx file anywhere on this page. One made for Just for me is yours until you share it: with everyone in your office, or with chosen people. One in a project belongs to its team.',
            ),
        },
        personal: {
            title: msg('No personal documents yet.'),
            body: msg(
                'Documents you make for Just for me are kept here, and only you see them until you share them.',
            ),
        },
        shared: {
            title: msg('Nothing is shared with you yet.'),
            body: msg(
                'Documents people share with you by name, and those open to your whole office, show here.',
            ),
        },
        projects: {
            title: msg('No projects here yet.'),
            body: msg(
                "Each project you're on has its own documents, which its team edits together. Once you're on a project's team, it shows here.",
            ),
        },
    };
</script>

<svelte:head><title>{t('Documents')}</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">{t('Documents')}</span>
        </div>
    {/snippet}

    <div class="mx-auto w-full max-w-4xl">
        <DocumentStart
            types={props.types}
            maxUploadMb={props.max_upload_mb}
            places={props.places}
            canCreatePersonal={props.can_create_personal}
        />
        {#if !props.can_create_personal && props.places.projects.length === 0}
            <p class="text-[13px] text-fg-muted">
                {t(
                    "You can open the documents shared with you here. To make your own, you need a place in an office or on a project's team.",
                )}
            </p>
        {/if}

        <div
            class="mt-6 flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="-mx-1 overflow-x-auto px-1 [scrollbar-width:none]">
                <PillGroup
                    options={tabs.map((tab) => ({
                        ...tab,
                        label: t(tab.label),
                    }))}
                    bind:value={show}
                />
            </div>
            {#if showSearch}
                <div class="relative min-w-0 sm:w-64">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                    />
                    <label for="{uid}-q" class="sr-only"
                        >{t('Search titles')}</label
                    >
                    <input
                        id="{uid}-q"
                        type="search"
                        bind:value={q}
                        oninput={searchSoon}
                        placeholder={t('Search titles')}
                        autocomplete="off"
                        class="input pl-8"
                    />
                </div>
            {/if}
        </div>

        <div class="mt-3">
            {#if cards}
                {#if props.project_cards.length === 0}
                    {@render empty(EMPTY.projects)}
                {:else}
                    <DocumentProjectCards cards={props.project_cards} />
                {/if}
            {:else if rows.length === 0}
                {#if props.filters.q !== null}
                    <p class="text-[13px] text-fg-muted">
                        {t('No titles match ":q".', { q: props.filters.q })}
                    </p>
                {:else}
                    {@render empty(EMPTY[props.filters.show])}
                {/if}
            {:else}
                <DocumentList
                    documents={rows}
                    showWhere
                    moveTargets={props.places}
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
                                <Spinner size={14} />{t('Loading')}
                            {:else}
                                {t('Show more')}
                            {/if}
                        </button>
                        {#if moreFailed}
                            <p class="text-xs text-danger">
                                {t("The next ones didn't load. Try again.")}
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

{#snippet empty(copy: { title: string; body: string })}
    <div class="text-[13px] text-fg-muted">
        <p class="font-medium text-fg">{t(copy.title)}</p>
        <p class="mt-1 max-w-xl">{t(copy.body)}</p>
    </div>
{/snippet}
