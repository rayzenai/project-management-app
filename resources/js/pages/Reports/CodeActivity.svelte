<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { ArrowDown, ArrowUp, GitCommitHorizontal } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import DailyCodeChart, {
        SERIES_BG,
    } from '../../components/reports/DailyCodeChart.svelte';
    import type { ChartSeries } from '../../components/reports/DailyCodeChart.svelte';
    import PersonCommitsDrawer from '../../components/reports/PersonCommitsDrawer.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import { isoDay } from '../../lib/dates';
    import type {
        CodeActivityFilters,
        CodeActivityOptions,
        CodeActivityPerson,
        CodeActivitySeries,
        Id,
    } from '../../lib/types';

    /**
     * Code activity: each person's commits and lines in a range, within
     * what the viewer runs. Tick up to four people to compare them side by
     * side, with their code lines per day; click a person for their commits.
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
    const DAY = 86_400_000;

    // ---- Filters: every change is a visit with the query string ----
    let loading = $state(false);
    let error = $state<string | null>(null);
    /** The people being asked for, ahead of the server's answer. */
    let pending = $state<Id[] | null>(null);
    let visits = 0;

    /** The range and the project or program, as the query string has them. */
    function scopeParams(query: CodeActivityFilters): Record<string, string> {
        return {
            from: query.from,
            to: query.to,
            ...(query.project ? { project: query.project } : {}),
            ...(query.program ? { program: query.program } : {}),
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

    function dayNumber(iso: string): number {
        const [y, m, d] = iso.split('-').map(Number);

        return Date.UTC(y, m - 1, d) / DAY;
    }

    const today = isoDay(new Date());

    const PRESETS = [
        { value: '7', label: '7 days' },
        { value: '30', label: '30 days' },
        { value: '90', label: '90 days' },
    ];

    const preset = $derived.by(() => {
        const span = dayNumber(filters.to) - dayNumber(filters.from) + 1;

        return filters.to === today &&
            PRESETS.some((p) => p.value === String(span))
            ? String(span)
            : '';
    });

    function applyPreset(value: string) {
        const end = new Date();
        const start = new Date(
            end.getFullYear(),
            end.getMonth(),
            end.getDate() - (Number(value) - 1),
        );
        visit({ from: isoDay(start), to: isoDay(end) });
    }

    function setDate(field: 'from' | 'to', value: string) {
        if (value !== '' && value !== filters[field]) {
            visit({ [field]: value });
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

    /** The compare table's rows, in the order of the people table. */
    const STATS: { label: string; value: (p: CodeActivityPerson) => number }[] =
        [
            { label: 'Commits', value: (p) => p.commits },
            { label: 'Active days', value: (p) => p.active_days },
            { label: 'Repositories', value: (p) => p.repositories },
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

    // ---- Sorting: by name unless the viewer picks a column ----
    type SortKey =
        | 'name'
        | 'commits'
        | 'active_days'
        | 'code'
        | 'comments'
        | 'blank'
        | 'docs'
        | 'pull_requests'
        | 'tests'
        | 'tasks';

    const SORT_VALUE: Record<
        Exclude<SortKey, 'name'>,
        (p: CodeActivityPerson) => number
    > = {
        commits: (p) => p.commits,
        active_days: (p) => p.active_days,
        code: (p) => p.code_added + p.code_removed,
        comments: (p) => p.comment_added + p.comment_removed,
        blank: (p) => p.blank_added + p.blank_removed,
        docs: (p) => p.docs_added + p.docs_removed,
        pull_requests: (p) => p.pull_requests_opened,
        tests: (p) => p.checks_failed,
        tasks: (p) => p.tasks,
    };

    let sort = $state<{ key: SortKey; dir: 'asc' | 'desc' }>({
        key: 'name',
        dir: 'asc',
    });

    function sortBy(key: SortKey) {
        sort = {
            key,
            dir: sort.key === key && sort.dir === 'asc' ? 'desc' : 'asc',
        };
    }

    const byName = (a: CodeActivityPerson, b: CodeActivityPerson) =>
        a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });

    /** The people in the chosen order; the unmatched row always last. */
    const rows = $derived.by(() => {
        const sign = sort.dir === 'asc' ? 1 : -1;
        const key = sort.key;

        return [...people].sort((a, b) => {
            if ((a.user_id === null) !== (b.user_id === null)) {
                return a.user_id === null ? 1 : -1;
            }

            const order =
                key === 'name'
                    ? byName(a, b)
                    : SORT_VALUE[key](a) - SORT_VALUE[key](b);

            return sign * order || byName(a, b);
        });
    });

    // ---- One person's commits ----
    let openId = $state<Id | null>(null);

    // Someone who leaves the report (another range or project) closes
    // their drawer for good, rather than reopening it if they come back.
    $effect(() => {
        if (openId !== null && !people.some((p) => p.user_id === openId)) {
            openId = null;
        }
    });

    const openPerson = $derived(
        people.find((p) => p.user_id !== null && p.user_id === openId) ?? null,
    );
    const drawerQuery = $derived(
        new URLSearchParams(scopeParams(filters)).toString(),
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

    const rangeLabel = $derived.by(() => {
        const format = new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
        const local = (iso: string) => {
            const [y, m, d] = iso.split('-').map(Number);

            return new Date(y, m - 1, d);
        };

        return `${format.format(local(filters.from))} and ${format.format(local(filters.to))}`;
    });

    const tone = (n: number) => (n === 0 ? 'text-fg-faint' : '');
</script>

<svelte:head><title>Code activity · Workspace</title></svelte:head>

{#snippet sortHead(
    key: SortKey,
    label: string,
    align: 'left' | 'right' = 'right',
)}
    {@const active = sort.key === key}
    {@const Arrow = sort.dir === 'asc' ? ArrowUp : ArrowDown}
    <th
        class={`col-head py-1.5 font-normal ${align === 'left' ? 'pr-3 text-left' : 'px-3 text-right last:pr-0'}`}
        aria-sort={active
            ? sort.dir === 'asc'
                ? 'ascending'
                : 'descending'
            : undefined}
    >
        <button
            type="button"
            class={`inline-flex items-center gap-1 hover:text-fg ${active ? 'text-fg-muted' : ''}`}
            onclick={() => sortBy(key)}
        >
            {#if active && align === 'right'}
                <Arrow class="h-3 w-3" />
            {/if}
            {label}
            {#if active && align === 'left'}
                <Arrow class="h-3 w-3" />
            {/if}
        </button>
    </th>
{/snippet}

<AppShell>
    {#snippet bar()}
        <span class="truncate font-medium">Code activity</span>
    {/snippet}

    <div class="space-y-6">
        <!-- Filters, one row above everything they scope. -->
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <PillGroup
                    options={PRESETS}
                    bind:value={() => preset, applyPreset}
                />
                <div class="flex items-center gap-1.5">
                    <input
                        type="date"
                        class="input h-7 w-auto py-0"
                        aria-label="From"
                        value={filters.from}
                        max={filters.to}
                        onchange={(e) => setDate('from', e.currentTarget.value)}
                    />
                    <span class="text-xs text-fg-faint">to</span>
                    <input
                        type="date"
                        class="input h-7 w-auto py-0"
                        aria-label="To"
                        value={filters.to}
                        min={filters.from}
                        onchange={(e) => setDate('to', e.currentTarget.value)}
                    />
                </div>
                {#if options.projects.length > 0 || options.programs.length > 0}
                    <select
                        class="input h-7 w-auto max-w-64 py-0"
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
                {#if loading}
                    <Spinner size={14} />
                {/if}
            </div>
            {#if error}
                <p role="alert" class="mt-1.5 text-xs text-danger">{error}</p>
            {/if}
            {#if uncounted > 0}
                <p class="mt-1.5 text-xs text-fg-muted">
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
                    <p class="mt-3 font-medium">
                        No commits between {rangeLabel}.
                    </p>
                    <p class="mt-1 max-w-md text-[13px] text-fg-muted">
                        Try a longer range. A commit counts for a person when
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
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-[13px]">
                        <thead>
                            <tr class="border-b border-line text-left">
                                <th class="w-8 py-1.5"
                                    ><span class="sr-only">Compare</span></th
                                >
                                {@render sortHead('name', 'Person', 'left')}
                                {@render sortHead('commits', 'Commits')}
                                {@render sortHead('active_days', 'Active days')}
                                {@render sortHead('code', 'Code')}
                                {@render sortHead('comments', 'Comments')}
                                {@render sortHead('blank', 'Blank')}
                                {@render sortHead('docs', 'Docs')}
                                {@render sortHead(
                                    'pull_requests',
                                    'Pull requests',
                                )}
                                {@render sortHead('tests', 'Tests')}
                                {@render sortHead('tasks', 'Tasks')}
                            </tr>
                        </thead>
                        <tbody>
                            {#each rows as person (person.user_id ?? 'unmatched')}
                                {@const id = person.user_id}
                                {@const ticked =
                                    id !== null && chosen.includes(id)}
                                <tr
                                    class={`border-b border-line-soft ${
                                        id !== null && id === openId
                                            ? 'bg-accent-soft'
                                            : id !== null
                                              ? 'cursor-pointer hover:bg-hover'
                                              : ''
                                    }`}
                                    onclick={(e) => onRowClick(e, person)}
                                >
                                    <td class="py-1.5">
                                        {#if id !== null}
                                            <label
                                                class="flex h-7 w-7 cursor-pointer items-center justify-center"
                                            >
                                                <input
                                                    type="checkbox"
                                                    class="accent-accent"
                                                    checked={ticked}
                                                    disabled={!ticked &&
                                                        chosen.length >=
                                                            MAX_COMPARED}
                                                    aria-label={`Compare ${person.name}`}
                                                    title={!ticked &&
                                                    chosen.length >=
                                                        MAX_COMPARED
                                                        ? `Compare at most ${MAX_COMPARED} people`
                                                        : undefined}
                                                    onchange={() => toggle(id)}
                                                />
                                            </label>
                                        {/if}
                                    </td>
                                    <td class="py-1.5 pr-3">
                                        {#if id !== null}
                                            <button
                                                type="button"
                                                class="flex w-full min-w-0 items-center gap-2 text-left"
                                                title={`See ${person.name}'s commits`}
                                                onclick={() => (openId = id)}
                                            >
                                                <Avatar
                                                    name={person.name}
                                                    size="md"
                                                />
                                                <span class="min-w-0">
                                                    <span
                                                        class="block truncate font-medium hover:text-accent"
                                                        >{person.name}</span
                                                    >
                                                    {#if person.designation}
                                                        <span
                                                            class="block truncate text-xs text-fg-muted"
                                                            >{person.designation}</span
                                                        >
                                                    {/if}
                                                </span>
                                            </button>
                                        {:else}
                                            <div
                                                class="flex min-w-0 items-center gap-2"
                                            >
                                                <Avatar name="?" size="md" />
                                                <span class="min-w-0">
                                                    <span
                                                        class="block truncate text-fg-muted"
                                                        >{person.name}</span
                                                    >
                                                    <span
                                                        class="block truncate text-xs text-fg-faint"
                                                        >Author emails on no
                                                        SDOC Git account</span
                                                    >
                                                </span>
                                            </div>
                                        {/if}
                                    </td>
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.commits)}`}
                                        >{fmt(person.commits)}</td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.active_days)}`}
                                        >{fmt(person.active_days)}</td
                                    >
                                    <td
                                        class="px-3 text-right font-mono whitespace-nowrap tabular-nums"
                                        ><span class={tone(person.code_added)}
                                            >+{fmt(person.code_added)}</span
                                        >
                                        <span class="text-fg-muted"
                                            >&minus;{fmt(
                                                person.code_removed,
                                            )}</span
                                        ></td
                                    >
                                    <td
                                        class="px-3 text-right font-mono whitespace-nowrap tabular-nums"
                                        ><span
                                            class={tone(person.comment_added)}
                                            >+{fmt(person.comment_added)}</span
                                        >
                                        <span class="text-fg-muted"
                                            >&minus;{fmt(
                                                person.comment_removed,
                                            )}</span
                                        ></td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.blank_added + person.blank_removed)}`}
                                        title={`+${fmt(person.blank_added)} −${fmt(person.blank_removed)}`}
                                        >{fmt(
                                            person.blank_added +
                                                person.blank_removed,
                                        )}</td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(person.docs_added + person.docs_removed)}`}
                                        title={`+${fmt(person.docs_added)} −${fmt(person.docs_removed)}`}
                                        >{fmt(
                                            person.docs_added +
                                                person.docs_removed,
                                        )}</td
                                    >
                                    <td
                                        class="px-3 text-right font-mono whitespace-nowrap tabular-nums"
                                        title="Opened / merged"
                                        ><span
                                            class={tone(
                                                person.pull_requests_opened,
                                            )}
                                            >{fmt(
                                                person.pull_requests_opened,
                                            )}</span
                                        ><span class="text-fg-faint">
                                            /
                                        </span><span
                                            class={tone(
                                                person.pull_requests_merged,
                                            )}
                                            >{fmt(
                                                person.pull_requests_merged,
                                            )}</span
                                        ></td
                                    >
                                    <td
                                        class="px-3 text-right font-mono tabular-nums"
                                        title="Commits whose tests passed / failed"
                                        ><span
                                            class={tone(person.checks_passed)}
                                            >{fmt(person.checks_passed)}</span
                                        ><span class="text-fg-faint">
                                            /
                                        </span><span
                                            class={person.checks_failed > 0
                                                ? 'text-danger'
                                                : tone(0)}
                                            >{fmt(person.checks_failed)}</span
                                        ></td
                                    >
                                    <td
                                        class={`pl-3 text-right font-mono tabular-nums ${tone(person.tasks)}`}
                                        >{fmt(person.tasks)}</td
                                    >
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
                <p class="mt-1.5 text-xs text-fg-muted">
                    Lines are added and removed. Code, comment and blank lines
                    are a best guess from each file's language; generated files
                    and merge commits are left out. Pull requests: opened /
                    merged. Tests: commits whose tests passed / failed. Click a
                    person to see their commits, or tick up to {MAX_COMPARED}
                    to compare them.
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
