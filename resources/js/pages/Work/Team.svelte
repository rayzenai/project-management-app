<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import { ArrowDown, ArrowUp, ListChecks } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import ScopeSelects from '../../components/filters/ScopeSelects.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import RangePicker from '../../components/work/RangePicker.svelte';
    import { dayRange, hoursLabel } from '../../lib/dates';
    import { plural } from '../../lib/format';
    import type { Id } from '../../lib/types';
    import { personWorkHref } from '../../lib/work';
    import type {
        WorkRangeFilters,
        WorkTeamFilters,
        WorkTeamProps,
        WorkTeamRow,
    } from '../../lib/work';

    /**
     * A team's week: one row per person, listed by name. The viewer may
     * sort by a column; a row opens that person's week.
     */
    let { organization_id, under, people, options, filters }: WorkTeamProps =
        $props();

    let loading = $state(false);
    let error = $state<string | null>(null);

    function visit(change: Partial<WorkTeamFilters>) {
        const next = { ...filters, ...change };

        router.get(
            '/workspace/work/team',
            {
                from: next.from,
                to: next.to,
                ...(options.organizations.length > 0
                    ? { organization: next.organization }
                    : {}),
                ...(next.under !== null ? { under: next.under } : {}),
            },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => (loading = true),
                onSuccess: () => (error = null),
                onError: (errors) => (error = Object.values(errors)[0] ?? null),
                onFinish: () => (loading = false),
            },
        );
    }

    const withAttendance = $derived(people.some((p) => p.attendance !== null));

    // ---- Sorting: by name unless the viewer picks a column ----
    type SortKey =
        | 'name'
        | 'days_in'
        | 'late'
        | 'hours'
        | 'closed'
        | 'started'
        | 'overdue'
        | 'commits'
        | 'code'
        | 'pull_requests';

    const SORT_VALUE: Record<
        Exclude<SortKey, 'name'>,
        (row: WorkTeamRow) => number
    > = {
        days_in: (r) => r.attendance?.days_present ?? -1,
        late: (r) => r.attendance?.days_late ?? -1,
        hours: (r) => r.attendance?.minutes_worked ?? -1,
        closed: (r) => r.tasks_closed,
        started: (r) => r.tasks_started,
        overdue: (r) => r.overdue,
        commits: (r) => r.commits,
        code: (r) => r.code_added + r.code_removed,
        pull_requests: (r) => r.pull_requests_merged,
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

    const byName = (a: WorkTeamRow, b: WorkTeamRow) =>
        a.member.name.localeCompare(b.member.name, undefined, {
            sensitivity: 'base',
        });

    const rows = $derived.by(() => {
        const sign = sort.dir === 'asc' ? 1 : -1;
        const key = sort.key;

        return [...people].sort((a, b) => {
            const order =
                key === 'name'
                    ? byName(a, b)
                    : SORT_VALUE[key](a) - SORT_VALUE[key](b);

            return sign * order || byName(a, b);
        });
    });

    function open(id: Id) {
        router.visit(
            `${personWorkHref(id)}?${new URLSearchParams({ from: filters.from, to: filters.to }).toString()}`,
        );
    }

    const fmt = (n: number) => n.toLocaleString();
    const tone = (n: number) => (n === 0 ? 'text-fg-faint' : '');

    const dayHref = $derived(
        `/workspace/work?${new URLSearchParams({
            ...(options.organizations.length > 0
                ? { organization: String(organization_id) }
                : {}),
            ...(under ? { under: String(under.id) } : {}),
        }).toString()}`,
    );
</script>

<svelte:head><title>Team's work · Workspace</title></svelte:head>

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
        <span class="truncate font-medium"
            >{under ? `${under.name}'s team` : "Team's work"}</span
        >
        <span class="ml-auto flex items-center gap-1">
            <a href={dayHref} use:inertia class="btn-ghost">
                <ListChecks class="h-3.5 w-3.5" />
                <span class="hidden sm:inline">Today</span>
            </a>
        </span>
    {/snippet}

    <div class="space-y-5">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <ScopeSelects
                    {options}
                    organization={organization_id}
                    {under}
                    label="Whose work"
                    onchange={(change: {
                        organization?: Id;
                        under: Id | null;
                    }) => visit(change)}
                />
                <RangePicker
                    range={{ from: filters.from, to: filters.to }}
                    onchange={(range: WorkRangeFilters) => visit(range)}
                />
                {#if loading}
                    <Spinner size={14} />
                {/if}
            </div>
            {#if error}
                <p role="alert" class="mt-1.5 text-xs text-danger">{error}</p>
            {/if}
        </div>

        {#if people.length === 0}
            <section class="panel px-6 py-12 text-center">
                <p class="font-medium">Nobody to show.</p>
                <p class="mt-1 text-[13px] text-fg-muted">
                    The people listed here are the ones below you, or everyone
                    in the organization for its admins.
                </p>
            </section>
        {:else}
            <section class="transition-opacity" class:opacity-60={loading}>
                <div class="overflow-x-auto">
                    <table
                        class={`w-full text-[13px] ${withAttendance ? 'min-w-[860px]' : 'min-w-[640px]'}`}
                    >
                        <thead>
                            <tr class="border-b border-line text-left">
                                {@render sortHead('name', 'Person', 'left')}
                                {#if withAttendance}
                                    {@render sortHead('days_in', 'Days in')}
                                    {@render sortHead('late', 'Came late')}
                                    {@render sortHead('hours', 'Hours')}
                                {/if}
                                {@render sortHead('closed', 'Finished')}
                                {@render sortHead('started', 'Started')}
                                {@render sortHead('overdue', 'Overdue')}
                                {@render sortHead('commits', 'Commits')}
                                {@render sortHead('code', 'Code lines')}
                                {@render sortHead(
                                    'pull_requests',
                                    'Pull requests',
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {#each rows as row (row.member.id)}
                                {@const a = row.attendance}
                                <tr
                                    class="cursor-pointer border-b border-line-soft hover:bg-hover"
                                    onclick={() => open(row.member.id)}
                                >
                                    <td class="py-1.5 pr-3">
                                        <a
                                            href={personWorkHref(row.member.id)}
                                            use:inertia
                                            class="flex min-w-0 items-center gap-2"
                                            onclick={(e) => e.stopPropagation()}
                                        >
                                            <Avatar
                                                name={row.member.name}
                                                size="md"
                                            />
                                            <span class="min-w-0">
                                                <span
                                                    class="block truncate font-medium hover:text-accent"
                                                    >{row.member.name}</span
                                                >
                                                {#if row.member.designation}
                                                    <span
                                                        class="block truncate text-xs text-fg-muted"
                                                        >{row.member
                                                            .designation}</span
                                                    >
                                                {/if}
                                            </span>
                                        </a>
                                    </td>
                                    {#if withAttendance}
                                        <td
                                            class="px-3 text-right whitespace-nowrap tabular-nums"
                                        >
                                            {#if a}
                                                {a.days_present} of {a.days_expected}
                                            {:else}
                                                <span class="text-fg-faint"
                                                    >Not tracked</span
                                                >
                                            {/if}
                                        </td>
                                        <td
                                            class={`px-3 text-right font-mono tabular-nums ${a && a.days_late > 0 ? 'text-warn' : 'text-fg-faint'}`}
                                            >{a ? a.days_late : ''}</td
                                        >
                                        <td
                                            class="px-3 text-right font-mono whitespace-nowrap tabular-nums"
                                            >{a
                                                ? hoursLabel(a.minutes_worked)
                                                : ''}</td
                                        >
                                    {/if}
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(row.tasks_closed)}`}
                                        >{fmt(row.tasks_closed)}</td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(row.tasks_started)}`}
                                        >{fmt(row.tasks_started)}</td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${row.overdue > 0 ? 'text-danger' : 'text-fg-faint'}`}
                                        >{fmt(row.overdue)}</td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${tone(row.commits)}`}
                                        >{fmt(row.commits)}</td
                                    >
                                    <td
                                        class="px-3 text-right font-mono whitespace-nowrap tabular-nums"
                                        ><span class={tone(row.code_added)}
                                            >+{fmt(row.code_added)}</span
                                        >
                                        <span
                                            class={row.code_removed === 0
                                                ? 'text-fg-faint'
                                                : 'text-fg-muted'}
                                            >&minus;{fmt(
                                                row.code_removed,
                                            )}</span
                                        ></td
                                    >
                                    <td
                                        class={`pl-3 text-right font-mono tabular-nums ${tone(row.pull_requests_merged)}`}
                                        >{fmt(row.pull_requests_merged)}</td
                                    >
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
                <div
                    class="mt-1.5 flex flex-wrap justify-between gap-2 text-xs text-fg-muted"
                >
                    <span
                        >Listed by name; click a heading to sort. Overdue is as
                        of today; the rest is {dayRange(
                            filters.from,
                            filters.to,
                        )}.</span
                    >
                    <span
                        >{plural(people.length, 'person', 'people')}{under
                            ? ` with ${under.name}`
                            : ''}</span
                    >
                </div>
            </section>
        {/if}
    </div>
</AppShell>
