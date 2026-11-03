<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { ChevronDown, GitCommitHorizontal } from '@lucide/svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import ChecksMark from '../../components/ChecksMark.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import DailyCodeChart, {
        SERIES_BG,
    } from '../../components/reports/DailyCodeChart.svelte';
    import type { ChartSeries } from '../../components/reports/DailyCodeChart.svelte';
    import PersonCommitsDrawer from '../../components/reports/PersonCommitsDrawer.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import type {
        Checks,
        CodeActivityFilters,
        CodeActivityOptions,
        CodeActivityPeriod,
        CodeActivityPerson,
        CodeActivitySeries,
        CodeActivitySort,
        Id,
    } from '../../lib/types';

    /**
     * Code activity: each person's commits, lines, tests, pull requests and
     * repositories created in a period, within what the viewer runs, ranked
     * by the figure chosen (or by name). Every filter lives in the URL, so
     * "this week, Developers, by commits" is a link. Tick up to four people
     * to compare them side by side, with their code lines per day; click a
     * person for their commits.
     */
    let {
        people,
        series,
        uncounted,
        repositories,
        options,
        filters,
    }: {
        people: CodeActivityPerson[];
        series: CodeActivitySeries[];
        uncounted: number;
        /** Linked repositories whose code the viewer may see. */
        repositories: number;
        options: CodeActivityOptions;
        filters: CodeActivityFilters;
    } = $props();

    const MAX_COMPARED = 4;

    const SORTS: { value: CodeActivitySort; label: string }[] = [
        { value: 'commits', label: 'Most commits' },
        { value: 'lines', label: 'Most lines changed' },
        { value: 'tests', label: 'Most tests passed' },
        { value: 'active_days', label: 'Most active days' },
        { value: 'name', label: 'By name' },
    ];

    // ---- Filters: every change is a visit with the query string ----
    let loading = $state(false);
    let error = $state<string | null>(null);
    /** The people being asked for, ahead of the server's answer. */
    let pending = $state<Id[] | null>(null);
    let visits = 0;

    /**
     * The filters as the query string has them: a custom period carries its
     * days, the others are worked out by the server from today.
     */
    function scopeParams(query: CodeActivityFilters): Record<string, string> {
        return {
            period: query.period,
            ...(query.period === 'custom'
                ? { from: query.from, to: query.to }
                : {}),
            ...(query.project ? { project: query.project } : {}),
            ...(query.program ? { program: query.program } : {}),
            ...(query.designation !== null
                ? { designation: String(query.designation) }
                : {}),
            sort: query.sort,
        };
    }

    function visit(change: Partial<CodeActivityFilters>) {
        const next = { ...filters, members: chosen, ...change };
        const mine = ++visits;
        pending = next.members;

        router.get(
            '/workspace/reports/code',
            {
                ...scopeParams(next),
                ...(next.members.length > 0 ? { members: next.members } : {}),
            },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => (loading = true),
                onSuccess: () => (error = null),
                onError: (errors) => (error = Object.values(errors)[0] ?? null),
                // A newer visit cancels this one: leave the state to it.
                onFinish: () => {
                    if (mine === visits) {
                        loading = false;
                        pending = null;
                    }
                },
            },
        );
    }

    function setPeriod(value: string) {
        if (value !== filters.period) {
            // Custom starts from the days on screen.
            visit({ period: value as CodeActivityPeriod });
        }
    }

    function setDate(field: 'from' | 'to', value: string) {
        if (value !== '' && value !== filters[field]) {
            visit({ period: 'custom', [field]: value });
        }
    }

    const scope = $derived(
        filters.program
            ? `program:${filters.program}`
            : filters.project
              ? `project:${filters.project}`
              : '',
    );

    function setScope(value: string) {
        const [kind, slug] = value.split(':');
        visit({
            project: kind === 'project' ? slug : null,
            program: kind === 'program' ? slug : null,
        });
    }

    function setDesignation(value: string) {
        visit({ designation: value === '' ? null : Number(value) });
    }

    function setSort(value: string) {
        visit({ sort: value as CodeActivitySort });
    }

    const ranked = $derived(filters.sort !== 'name');

    // ---- Compare ----
    const visibleIds = $derived(
        people.flatMap((p) => (p.user_id === null ? [] : [p.user_id])),
    );
    /** Those compared as the server answered, among the people shown. */
    const applied = $derived(
        filters.members.filter((id) => visibleIds.includes(id)),
    );
    /** The ticked boxes, ahead of the server. */
    const chosen = $derived(pending ?? applied);

    function toggle(id: Id) {
        visit({
            members: chosen.includes(id)
                ? chosen.filter((m) => m !== id)
                : [...chosen, id],
        });
    }

    // Colour follows the person: whoever stays keeps their slot while
    // others come and go (for as long as the page is open).
    let lastSlots: (Id | null)[] = [];

    const slots = $derived.by(() => {
        const next: (Id | null)[] = Array(MAX_COMPARED).fill(null);

        for (const id of applied) {
            const slot = lastSlots.indexOf(id);

            if (slot !== -1) {
                next[slot] = id;
            }
        }

        for (const id of applied) {
            if (!next.includes(id)) {
                next[next.indexOf(null)] = id;
            }
        }

        lastSlots = next;

        return next;
    });

    const compared = $derived(
        slots.flatMap((id, slot) => {
            const person = people.find((p) => p.user_id === id);

            return id === null || !person ? [] : [{ id, slot, person }];
        }),
    );

    const chartSeries = $derived<ChartSeries[]>(
        compared.flatMap(({ id, slot, person }) => {
            const days = series.find((s) => s.user_id === id)?.days;

            return days ? [{ key: id, name: person.name, slot, days }] : [];
        }),
    );

    let compareSection = $state<HTMLElement | null>(null);

    const number = new Intl.NumberFormat();
    const fmt = (n: number) => number.format(n);

    /** The compare table's rows. */
    const STATS: { label: string; value: (p: CodeActivityPerson) => number }[] =
        [
            { label: 'Commits', value: (p) => p.commits },
            { label: 'Active days', value: (p) => p.active_days },
            { label: 'Repositories', value: (p) => p.repositories },
            {
                label: 'Repositories created',
                value: (p) => p.repositories_created,
            },
            { label: 'Files changed', value: (p) => p.files_changed },
            { label: 'Code lines added', value: (p) => p.code_added },
            { label: 'Code lines removed', value: (p) => p.code_removed },
            { label: 'Comment lines added', value: (p) => p.comment_added },
            {
                label: 'Comment lines removed',
                value: (p) => p.comment_removed,
            },
            {
                label: 'Blank lines',
                value: (p) => p.blank_added + p.blank_removed,
            },
            {
                label: 'Docs lines',
                value: (p) => p.docs_added + p.docs_removed,
            },
            {
                label: 'Pull requests opened',
                value: (p) => p.pull_requests_opened,
            },
            {
                label: 'Pull requests merged',
                value: (p) => p.pull_requests_merged,
            },
            {
                label: 'Commits whose tests passed',
                value: (p) => p.checks_passed,
            },
            {
                label: 'Commits whose tests failed',
                value: (p) => p.checks_failed,
            },
            { label: 'Tasks', value: (p) => p.tasks },
        ];

    // ---- One person's commits ----
    let openId = $state<Id | null>(null);

    // Someone who leaves the report (another period or project) closes
    // their drawer for good, rather than reopening it if they come back.
    $effect(() => {
        if (openId !== null && !people.some((p) => p.user_id === openId)) {
            openId = null;
        }
    });

    const openPerson = $derived(
        people.find((p) => p.user_id !== null && p.user_id === openId) ?? null,
    );
    // The drawer asks for exactly the days on screen.
    const drawerQuery = $derived(
        new URLSearchParams(
            scopeParams({ ...filters, period: 'custom' }),
        ).toString(),
    );

    function onRowClick(event: MouseEvent, person: CodeActivityPerson) {
        // The compare box and the name's button answer for themselves.
        if (
            person.user_id !== null &&
            !(event.target as Element).closest('input, label, button')
        ) {
            openId = person.user_id;
        }
    }

    // ---- Phone cards: the rest of a person's figures behind More ----
    const expanded = new SvelteSet<Id | null>();

    function toggleMore(id: Id | null) {
        if (!expanded.delete(id)) {
            expanded.add(id);
        }
    }

    const rangeText = $derived.by(() => {
        const format = new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
        const local = (iso: string) => {
            const [y, m, d] = iso.split('-').map(Number);

            return new Date(y, m - 1, d);
        };

        return filters.from === filters.to
            ? `on ${format.format(local(filters.from))}`
            : `between ${format.format(local(filters.from))} and ${format.format(local(filters.to))}`;
    });

    const tone = (n: number) => (n === 0 ? 'text-fg-faint' : '');

    const PASSED: Checks = { state: 'passed', checks: [] };
    const FAILED: Checks = { state: 'failed', checks: [] };
</script>

<svelte:head><title>Code activity · Workspace</title></svelte:head>

{#snippet lines(person: CodeActivityPerson)}
    <span
        class="font-mono whitespace-nowrap tabular-nums"
        title={`${fmt(person.lines_changed)} code lines changed`}
        ><span class={person.code_added > 0 ? 'text-success' : 'text-fg-faint'}
            >+{fmt(person.code_added)}</span
        >
        <span class={person.code_removed > 0 ? 'text-danger' : 'text-fg-faint'}
            >&minus;{fmt(person.code_removed)}</span
        ></span
    >
{/snippet}

{#snippet tests(person: CodeActivityPerson)}
    <span
        class="inline-flex items-center gap-1 font-mono whitespace-nowrap tabular-nums"
        title="Commits whose tests passed / failed"
    >
        {#if person.checks_passed > 0}
            <ChecksMark checks={PASSED} link={false} />
        {/if}
        <span class={tone(person.checks_passed)}
            >{fmt(person.checks_passed)}</span
        >
        <span class="text-fg-faint">/</span>
        {#if person.checks_failed > 0}
            <ChecksMark checks={FAILED} link={false} />
        {/if}
        <span class={person.checks_failed > 0 ? 'text-danger' : tone(0)}
            >{fmt(person.checks_failed)}</span
        >
    </span>
{/snippet}

{#snippet pullRequests(person: CodeActivityPerson)}
    <span
        class="inline-flex items-center gap-1 font-mono whitespace-nowrap tabular-nums"
        title="Pull requests opened / merged"
    >
        <span class={tone(person.pull_requests_opened)}
            >{fmt(person.pull_requests_opened)}</span
        >
        <span class="text-fg-faint">/</span>
        <span class={tone(person.pull_requests_merged)}
            >{fmt(person.pull_requests_merged)}</span
        >
    </span>
{/snippet}

{#snippet compareBox(person: CodeActivityPerson, id: Id)}
    {@const ticked = chosen.includes(id)}
    <label
        class="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center"
    >
        <input
            type="checkbox"
            class="accent-accent"
            checked={ticked}
            disabled={!ticked && chosen.length >= MAX_COMPARED}
            aria-label={`Compare ${person.name}`}
            title={!ticked && chosen.length >= MAX_COMPARED
                ? `Compare at most ${MAX_COMPARED} people`
                : undefined}
            onchange={() => toggle(id)}
        />
    </label>
{/snippet}

{#snippet who(person: CodeActivityPerson)}
    {#if person.user_id !== null}
        {@const id = person.user_id}
        <button
            type="button"
            class="flex w-full min-w-0 items-center gap-2 text-left"
            title={`See ${person.name}'s commits`}
            onclick={() => (openId = id)}
        >
            <Avatar name={person.name} size="md" />
            <span class="min-w-0">
                <span class="block truncate font-medium hover:text-accent"
                    >{person.name}</span
                >
                {#if person.designation}
                    <span class="block truncate text-xs text-fg-muted"
                        >{person.designation}</span
                    >
                {/if}
            </span>
        </button>
    {:else}
        <div class="flex min-w-0 items-center gap-2">
            <Avatar name="?" size="md" />
            <span class="min-w-0">
                <span class="block truncate text-fg-muted">{person.name}</span>
                <span class="block truncate text-xs text-fg-faint"
                    >Author emails on no SDOC Git account</span
                >
            </span>
        </div>
    {/if}
{/snippet}

<AppShell>
    {#snippet bar()}
        <span class="truncate font-medium">Code activity</span>
    {/snippet}

    <div class="space-y-6">
        <!-- Filters, above everything they scope. -->
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <div
                    class="-mx-5 max-w-[100vw] overflow-x-auto px-5 md:mx-0 md:px-0"
                >
                    <PillGroup
                        options={options.periods}
                        bind:value={() => filters.period, setPeriod}
                    />
                </div>
                {#if filters.period === 'custom'}
                    <div class="flex items-center gap-1.5">
                        <input
                            type="date"
                            class="input h-9 w-auto py-0"
                            aria-label="From"
                            value={filters.from}
                            max={filters.to}
                            onchange={(e) =>
                                setDate('from', e.currentTarget.value)}
                        />
                        <span class="text-xs text-fg-faint">to</span>
                        <input
                            type="date"
                            class="input h-9 w-auto py-0"
                            aria-label="To"
                            value={filters.to}
                            min={filters.from}
                            onchange={(e) =>
                                setDate('to', e.currentTarget.value)}
                        />
                    </div>
                {/if}
                {#if loading}
                    <Spinner size={14} />
                {/if}
            </div>
            <div class="flex flex-wrap items-center gap-2">
                {#if options.designations.length > 0}
                    <select
                        class="input h-9 w-full py-0 md:w-auto md:max-w-64"
                        aria-label="Designation"
                        value={filters.designation === null
                            ? ''
                            : String(filters.designation)}
                        onchange={(e) => setDesignation(e.currentTarget.value)}
                    >
                        <option value="">All designations</option>
                        {#each options.designations as designation (designation.id)}
                            <option value={String(designation.id)}
                                >{designation.name}</option
                            >
                        {/each}
                    </select>
                {/if}
                <select
                    class="input h-9 w-full py-0 md:w-auto"
                    aria-label="Sort"
                    value={filters.sort}
                    onchange={(e) => setSort(e.currentTarget.value)}
                >
                    {#each SORTS as sort (sort.value)}
                        <option value={sort.value}>{sort.label}</option>
                    {/each}
                </select>
                {#if options.projects.length > 0 || options.programs.length > 0}
                    <select
                        class="input h-9 w-full py-0 md:w-auto md:max-w-64"
                        aria-label="Project or program"
                        value={scope}
                        onchange={(e) => setScope(e.currentTarget.value)}
                    >
                        <option value="">All projects</option>
                        {#if options.programs.length > 0}
                            <optgroup label="Programs">
                                {#each options.programs as program (program.slug)}
                                    <option value={`program:${program.slug}`}
                                        >{program.name}</option
                                    >
                                {/each}
                            </optgroup>
                        {/if}
                        {#if options.projects.length > 0}
                            <optgroup label="Projects">
                                {#each options.projects as project (project.slug)}
                                    <option value={`project:${project.slug}`}
                                        >{project.label}</option
                                    >
                                {/each}
                            </optgroup>
                        {/if}
                    </select>
                {/if}
            </div>
            {#if error}
                <p role="alert" class="text-xs text-danger">{error}</p>
            {/if}
            {#if uncounted > 0}
                <p class="text-xs text-fg-muted">
                    <span class="font-mono tabular-nums">{fmt(uncounted)}</span>
                    {uncounted === 1 ? 'commit is' : 'commits are'} still being counted.
                </p>
            {/if}
        </div>

        {#if people.length === 0}
            <section
                class="panel flex flex-col items-center px-6 py-12 text-center"
            >
                <GitCommitHorizontal class="h-5 w-5 text-fg-faint" />
                {#if repositories === 0}
                    <p class="mt-3 font-medium">No repositories linked yet.</p>
                    <p class="mt-1 max-w-md text-[13px] text-fg-muted">
                        Only commits in a repository linked to a project count
                        here. Create one, or link one from the forge, on the
                        project's Code tab.
                    </p>
                {:else}
                    <p class="mt-3 font-medium">No commits {rangeText}.</p>
                    <p class="mt-1 max-w-md text-[13px] text-fg-muted">
                        Try a longer period. A commit counts for a person when
                        git signs it with the email they sign in with.
                    </p>
                {/if}
            </section>
        {:else}
            <section class="transition-opacity" class:opacity-60={loading}>
                <div class="mb-2 flex items-center gap-2">
                    <h2 class="section-title">
                        People
                        <span class="section-count">{people.length}</span>
                    </h2>
                    {#if chosen.length > 0}
                        <span class="ml-auto text-xs text-fg-muted"
                            ><span class="font-mono tabular-nums"
                                >{chosen.length}</span
                            >
                            of {MAX_COMPARED} chosen</span
                        >
                        {#if compared.length >= 2}
                            <button
                                type="button"
                                class="btn"
                                onclick={() =>
                                    compareSection?.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'start',
                                    })}>Compare</button
                            >
                        {/if}
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => visit({ members: [] })}>Clear</button
                        >
                    {/if}
                </div>

                <!-- From 768px: one row per person, bounded, scrolling under its head. -->
                <div
                    class="hidden max-h-[min(70vh,880px)] overflow-auto rounded-md border border-line md:block"
                >
                    <table class="w-full min-w-[880px] text-[13.5px]">
                        <thead class="sticky top-0 z-10 bg-surface">
                            <tr class="border-b border-line text-left">
                                {#if ranked}
                                    <th
                                        class="col-head w-12 py-2 pl-3 text-right"
                                        >Rank</th
                                    >
                                {/if}
                                <th class="w-11 py-2"
                                    ><span class="sr-only">Compare</span></th
                                >
                                <th class="col-head py-2 pr-3">Person</th>
                                <th class="col-head px-3 py-2 text-right"
                                    >Commits</th
                                >
                                <th class="col-head px-3 py-2 text-right"
                                    >Lines changed</th
                                >
                                <th class="col-head px-3 py-2 text-right"
                                    >Files changed</th
                                >
                                <th class="col-head px-3 py-2 text-right"
                                    >Tests</th
                                >
                                <th class="col-head px-3 py-2 text-right"
                                    >Pull requests</th
                                >
                                <th class="col-head px-3 py-2 text-right"
                                    >Repositories created</th
                                >
                                <th class="col-head py-2 pr-3 pl-3 text-right"
                                    >Active days</th
                                >
                            </tr>
                        </thead>
                        <tbody>
                            {#each people as person (person.user_id ?? 'unmatched')}
                                {@const id = person.user_id}
                                <tr
                                    class={`border-b border-line-soft last:border-0 ${
                                        id !== null && id === openId
                                            ? 'bg-accent-soft'
                                            : id !== null
                                              ? 'cursor-pointer hover:bg-hover'
                                              : ''
                                    }`}
                                    onclick={(e) => onRowClick(e, person)}
                                >
                                    {#if ranked}
                                        <td
                                            class="py-2 pl-3 text-right font-mono text-fg-muted tabular-nums"
                                            >{person.rank ?? ''}</td
                                        >
                                    {/if}
                                    <td class="py-1">
                                        {#if id !== null}
                                            {@render compareBox(person, id)}
                                        {/if}
                                    </td>
                                    <td class="py-2 pr-3">
                                        {@render who(person)}
                                    </td>
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.commits)}`}
                                        >{fmt(person.commits)}</td
                                    >
                                    <td class="px-3 text-right">
                                        {@render lines(person)}
                                    </td>
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.files_changed)}`}
                                        >{fmt(person.files_changed)}</td
                                    >
                                    <td class="px-3 text-right">
                                        {@render tests(person)}
                                    </td>
                                    <td class="px-3 text-right">
                                        {@render pullRequests(person)}
                                    </td>
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.repositories_created)}`}
                                        >{fmt(person.repositories_created)}</td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.active_days)}`}
                                        >{fmt(person.active_days)}</td
                                    >
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>

                <!-- Below 768px: a card per person, the four main figures, the rest behind More. -->
                <ul
                    class="panel max-h-[70vh] divide-y divide-line-soft overflow-y-auto md:hidden"
                >
                    {#each people as person (person.user_id ?? 'unmatched')}
                        {@const id = person.user_id}
                        {@const open = expanded.has(id)}
                        <li
                            class={`px-3 py-3 ${id !== null && id === openId ? 'bg-accent-soft' : ''}`}
                        >
                            <div class="flex items-center gap-2">
                                {#if ranked}
                                    <span
                                        class="w-7 shrink-0 text-right font-mono text-fg-muted tabular-nums"
                                        >{person.rank ?? ''}</span
                                    >
                                {/if}
                                <div class="min-w-0 flex-1">
                                    {@render who(person)}
                                </div>
                                {#if id !== null}
                                    {@render compareBox(person, id)}
                                {/if}
                            </div>
                            <dl
                                class={`mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[13.5px] ${ranked ? 'pl-9' : ''}`}
                            >
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-fg-muted">Commits</dt>
                                    <dd
                                        class={`font-mono tabular-nums ${tone(person.commits)}`}
                                    >
                                        {fmt(person.commits)}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-fg-muted">Lines</dt>
                                    <dd>{@render lines(person)}</dd>
                                </div>
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-fg-muted">Tests</dt>
                                    <dd>{@render tests(person)}</dd>
                                </div>
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-fg-muted">Repositories</dt>
                                    <dd
                                        class={`font-mono tabular-nums ${tone(person.repositories_created)}`}
                                    >
                                        {fmt(person.repositories_created)}
                                    </dd>
                                </div>
                                {#if open}
                                    <div
                                        class="flex items-baseline justify-between gap-2"
                                    >
                                        <dt class="text-fg-muted">Files</dt>
                                        <dd
                                            class={`font-mono tabular-nums ${tone(person.files_changed)}`}
                                        >
                                            {fmt(person.files_changed)}
                                        </dd>
                                    </div>
                                    <div
                                        class="flex items-baseline justify-between gap-2"
                                    >
                                        <dt class="text-fg-muted">
                                            Pull requests
                                        </dt>
                                        <dd>{@render pullRequests(person)}</dd>
                                    </div>
                                    <div
                                        class="flex items-baseline justify-between gap-2"
                                    >
                                        <dt class="text-fg-muted">
                                            Active days
                                        </dt>
                                        <dd
                                            class={`font-mono tabular-nums ${tone(person.active_days)}`}
                                        >
                                            {fmt(person.active_days)}
                                        </dd>
                                    </div>
                                {/if}
                            </dl>
                            <button
                                type="button"
                                class={`btn-ghost mt-1 h-9 px-2 text-xs text-fg-muted ${ranked ? 'ml-7' : '-ml-2'}`}
                                aria-expanded={open}
                                onclick={() => toggleMore(id)}
                            >
                                {open ? 'Less' : 'More'}
                                <ChevronDown
                                    class={`h-3.5 w-3.5 transition ${open ? 'rotate-180' : ''}`}
                                />
                            </button>
                        </li>
                    {/each}
                </ul>

                <p class="mt-1.5 text-xs text-fg-muted">
                    Lines are code lines added and removed, a best guess from
                    each file's language; generated files and merge commits are
                    left out. Tests: commits whose tests passed / failed. Pull
                    requests: opened / merged. Repositories created: linked, or
                    created here, in the period. Click a person to see their
                    commits, or tick up to {MAX_COMPARED} to compare them.
                </p>
            </section>

            {#if compared.length >= 2}
                <section
                    bind:this={compareSection}
                    class="scroll-mt-14 space-y-3 transition-opacity"
                    class:opacity-60={loading}
                >
                    <h2 class="section-title">
                        Compare
                        <span class="section-count">{compared.length}</span>
                    </h2>
                    <div class="panel overflow-x-auto">
                        <table class="w-full text-[13px]">
                            <thead>
                                <tr class="border-b border-line">
                                    <th class="px-4 py-2"
                                        ><span class="sr-only">Measure</span
                                        ></th
                                    >
                                    {#each compared as { id, slot, person } (id)}
                                        <th
                                            class="px-4 py-2 text-right font-medium whitespace-nowrap"
                                        >
                                            <span
                                                class="inline-flex items-center gap-1.5"
                                            >
                                                <span
                                                    class={`h-0.5 w-3.5 rounded-full ${SERIES_BG[slot]}`}
                                                ></span>
                                                {person.name}
                                            </span>
                                        </th>
                                    {/each}
                                </tr>
                            </thead>
                            <tbody>
                                {#each STATS as stat (stat.label)}
                                    <tr
                                        class="border-b border-line-soft last:border-0"
                                    >
                                        <th
                                            class="px-4 py-1.5 text-left font-normal whitespace-nowrap text-fg-muted"
                                            >{stat.label}</th
                                        >
                                        {#each compared as { id, person } (id)}
                                            {@const value = stat.value(person)}
                                            <td
                                                class={`px-4 py-1.5 text-right font-mono tabular-nums ${tone(value)}`}
                                                >{fmt(value)}</td
                                            >
                                        {/each}
                                    </tr>
                                {/each}
                            </tbody>
                        </table>
                    </div>
                    <div class="panel p-4">
                        <h3 class="text-[13px] font-medium">
                            Code lines changed per day
                        </h3>
                        <p class="mb-3 text-xs text-fg-muted">
                            Added plus removed, on the day of each commit.
                        </p>
                        <DailyCodeChart series={chartSeries} />
                    </div>
                </section>
            {:else if compared.length === 1}
                <p class="text-xs text-fg-muted">
                    Tick one more person to compare them side by side.
                </p>
            {/if}
        {/if}
    </div>
</AppShell>

<PersonCommitsDrawer
    person={openPerson}
    query={drawerQuery}
    onclose={() => (openId = null)}
/>
