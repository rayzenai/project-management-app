<script lang="ts">
    import { inertia, page } from '@inertiajs/svelte';
    import {
        Briefcase,
        CalendarDays,
        CircleCheck,
        CircleDashed,
        Clock,
        Coffee,
        LogOut,
        MapPin,
        TriangleAlert,
        Users,
        Wifi,
    } from '@lucide/svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import DayPicker from '../../components/filters/DayPicker.svelte';
    import ScopeSelects from '../../components/filters/ScopeSelects.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import type { DaySchedule } from '../../lib/attendance';
    import type {
        RegisterFilters,
        RegisterRow,
        RegisterState,
        RegisterSummary,
    } from '../../lib/attendanceRegister';
    import { clockTime, dayRange, isoDay, longDay } from '../../lib/dates';
    import { formatDistance } from '../../lib/format';
    import { ReportVisit } from '../../lib/reportVisit.svelte';
    import type { Id, ReportOptions, SharedProps } from '../../lib/types';

    /**
     * The register: on one day, how many of those expected are in, then
     * everyone in groups (not in yet first, then in with time and place,
     * at lunch, clocked out, on leave or kaaj, off). A super-admin picks
     * the organization; anyone may narrow to someone's team.
     */
    let {
        schedule,
        under,
        summary,
        people,
        options,
        filters,
    }: {
        schedule: DaySchedule;
        under: { id: Id; name: string } | null;
        summary: RegisterSummary;
        people: RegisterRow[];
        options: ReportOptions;
        filters: RegisterFilters;
    } = $props();

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const organizationName = $derived(
        options.organizations.find((o) => o.id === filters.organization)
            ?.name ??
            shared.organization?.name ??
            '',
    );

    const today = isoDay(new Date());
    const isToday = $derived(filters.date === today);

    // ---- Filters: every change is a visit with the query string ----
    const report = new ReportVisit();

    function visit(change: Partial<RegisterFilters>) {
        const next = { ...filters, ...change };

        report.go(
            '/workspace/attendance',
            {
                date: next.date,
                under: next.under,
                organization: next.organization,
            },
            options.organizations,
        );
    }

    // ---- Groups ----
    const LIMIT = 8;
    const expanded = new SvelteSet<string>();

    const groups = $derived.by(() => {
        const list: {
            key: string;
            title: string;
            states: RegisterState[];
        }[] = [
            {
                key: 'not_in',
                title: isToday ? 'Not in yet' : 'Not in',
                states: ['not_in'],
            },
            { key: 'in', title: 'In', states: ['in'] },
            { key: 'at_lunch', title: 'At lunch', states: ['at_lunch'] },
            { key: 'out', title: 'Clocked out', states: ['out'] },
            {
                key: 'away',
                title: 'On leave or kaaj',
                states: ['on_leave', 'on_kaaj'],
            },
            { key: 'off', title: 'Off', states: ['off'] },
        ];

        return list
            .map((group) => ({
                ...group,
                rows: people.filter((row) => group.states.includes(row.state)),
            }))
            .filter((group) => group.rows.length > 0);
    });

    /** Designation, the clock-in's note, and lunch, under the name. */
    function detail(row: RegisterRow): string {
        return [
            row.member.designation,
            row.note ? `"${row.note}"` : null,
            row.state === 'at_lunch'
                ? `lunch since ${clockTime(row.lunch_start_at)}`
                : null,
        ]
            .filter(Boolean)
            .join(' · ');
    }

    const width = $derived(
        summary.percentage === null ? 0 : Math.min(100, summary.percentage),
    );
</script>

<svelte:head><title>Attendance · Workspace</title></svelte:head>

{#snippet place(row: RegisterRow)}
    {#if row.place === 'office_network'}
        <Wifi class="h-3.5 w-3.5 shrink-0" />Office network
    {:else if row.place === 'near_office'}
        <MapPin class="h-3.5 w-3.5 shrink-0" />Near office{row.distance_m !==
        null
            ? `, ${formatDistance(row.distance_m)}`
            : ''}
    {:else if row.place === 'outside'}
        <span class="inline-flex items-center gap-1.5 text-warn">
            <TriangleAlert class="h-3.5 w-3.5 shrink-0" />Outside office{row.distance_m !==
            null
                ? `, ${formatDistance(row.distance_m)}`
                : ''}
        </span>
    {:else if row.place === 'unknown'}
        <span class="inline-flex items-center gap-1.5 text-warn">
            <TriangleAlert class="h-3.5 w-3.5 shrink-0" />Place unknown
        </span>
    {/if}
{/snippet}

{#snippet away(row: RegisterRow)}
    {#if row.leave}
        {#if row.leave.kind === 'kaaj'}
            <Briefcase class="h-3.5 w-3.5 shrink-0" />Kaaj{row.leave.place
                ? ` in ${row.leave.place}`
                : ''}, {dayRange(row.leave.starts_on, row.leave.ends_on)}
        {:else}
            <CalendarDays class="h-3.5 w-3.5 shrink-0" />Leave, {dayRange(
                row.leave.starts_on,
                row.leave.ends_on,
            )}
        {/if}
    {/if}
{/snippet}

{#snippet when(row: RegisterRow)}
    {#if row.state === 'out'}
        {clockTime(row.in_at)} to {clockTime(row.out_at)}
    {:else if row.in_at}
        <span class={row.late ? 'text-warn' : ''}
            >{clockTime(row.in_at)}{#if row.late}<span class="sr-only">
                    late</span
                >{/if}</span
        >
    {/if}
{/snippet}

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            {#if organizationName}
                <span class="hidden truncate text-fg-muted sm:inline"
                    >{organizationName}</span
                >
                <span class="hidden text-fg-faint sm:inline">/</span>
            {/if}
            <span class="truncate font-medium">Attendance</span>
        </div>
        <a href="/workspace/leave" use:inertia class="btn">Leave and kaaj</a>
    {/snippet}

    <div class="space-y-5">
        <!-- Filters -->
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <DayPicker
                    date={filters.date}
                    onchange={(value) => visit({ date: value })}
                />
                <ScopeSelects
                    {options}
                    organization={filters.organization}
                    {under}
                    label="Whose attendance"
                    onchange={(change) => visit(change)}
                />
                {#if report.loading}
                    <Spinner size={14} />
                {/if}
            </div>
            {#if report.error}
                <p role="alert" class="mt-1.5 text-xs text-danger">
                    {report.error}
                </p>
            {/if}
        </div>

        <!-- The number first -->
        <section class="transition-opacity" class:opacity-60={report.loading}>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs text-fg-muted">{longDay(filters.date)}</p>
                    {#if schedule.expects_work}
                        <p
                            class="mt-1 text-[28px] leading-none font-semibold tracking-tight"
                        >
                            <span class="tabular-nums">{summary.present}</span>
                            of
                            <span class="tabular-nums">{summary.expected}</span>
                            in
                        </p>
                    {:else}
                        <p
                            class="mt-1 text-[28px] leading-none font-semibold tracking-tight"
                        >
                            {schedule.holiday ?? 'Day off'}
                        </p>
                    {/if}
                    <p class="mt-2 text-[13px] text-fg-muted">
                        {summary.staff} staff
                        {#if summary.on_leave > 0}
                            · {summary.on_leave} on leave{/if}
                        {#if summary.on_kaaj > 0}
                            · {summary.on_kaaj} on kaaj{/if}
                        {#if schedule.expects_work}
                            · hours {schedule.start} to {schedule.end}
                        {:else}
                            · nobody is expected{summary.present > 0
                                ? `, ${summary.present} came in`
                                : ''}
                        {/if}
                    </p>
                </div>
                {#if summary.percentage !== null}
                    <p
                        class="text-[28px] leading-none font-semibold text-accent tabular-nums"
                    >
                        {summary.percentage}%
                    </p>
                {/if}
            </div>

            {#if schedule.expects_work}
                <div
                    class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-alt"
                    role="meter"
                    aria-label="Share of those expected who are in"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={width}
                >
                    <div
                        class="h-full bg-accent"
                        style:width={`${width}%`}
                    ></div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2 text-[13px]">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-md border border-line bg-surface px-2 py-1"
                        ><CircleCheck class="h-3.5 w-3.5 text-success" />In
                        <b class="font-semibold tabular-nums"
                            >{summary.present}</b
                        ></span
                    >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-md border border-line bg-surface px-2 py-1"
                        ><Clock class="h-3.5 w-3.5 text-warn" />Came late
                        <b class="font-semibold tabular-nums">{summary.late}</b
                        ></span
                    >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-md border border-line bg-surface px-2 py-1"
                        ><CircleDashed
                            class="h-3.5 w-3.5 text-fg-faint"
                        />{isToday ? 'Not in yet' : 'Not in'}
                        <b class="font-semibold tabular-nums"
                            >{summary.not_in}</b
                        ></span
                    >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-md border border-line bg-surface px-2 py-1"
                        ><TriangleAlert class="h-3.5 w-3.5 text-warn" />Outside
                        office
                        <b class="font-semibold tabular-nums"
                            >{summary.outside_office}</b
                        ></span
                    >
                </div>
            {/if}
        </section>

        <!-- Everyone, in groups -->
        {#if people.length === 0}
            <section
                class="panel flex flex-col items-center px-6 py-12 text-center"
            >
                <Users class="h-5 w-5 text-fg-faint" />
                <p class="mt-3 font-medium">Nobody to show.</p>
                <p class="mt-1 max-w-md text-[13px] text-fg-muted">
                    The register lists the people below you, or everyone in the
                    organization for its admins. People exempt from attendance
                    are left out.
                </p>
            </section>
        {:else}
            <section
                class="panel overflow-hidden transition-opacity"
                class:opacity-60={report.loading}
            >
                {#each groups as group, i (group.key)}
                    {@const open = expanded.has(group.key)}
                    {@const shown = open
                        ? group.rows
                        : group.rows.slice(0, LIMIT)}
                    <h2 class={`group-head ${i === 0 ? 'border-t-0' : ''}`}>
                        {#if group.key === 'not_in'}
                            <CircleDashed class="h-3.5 w-3.5 text-fg-faint" />
                        {:else if group.key === 'in'}
                            <CircleCheck class="h-3.5 w-3.5 text-success" />
                        {:else if group.key === 'at_lunch'}
                            <Coffee class="h-3.5 w-3.5 text-fg-muted" />
                        {:else if group.key === 'out'}
                            <LogOut class="h-3.5 w-3.5 text-fg-muted" />
                        {:else}
                            <CalendarDays class="h-3.5 w-3.5 text-fg-muted" />
                        {/if}
                        {group.title}
                        <span class="section-count">{group.rows.length}</span>
                    </h2>
                    {#each shown as row (row.member.id)}
                        <div
                            class="row flex-wrap gap-y-0.5 py-2 hover:bg-transparent"
                        >
                            <Avatar name={row.member.name} size="md" />
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate text-[13px]"
                                    >{row.member.name}</span
                                >
                                {#if detail(row)}
                                    <span
                                        class="text-xs break-words text-fg-muted sm:truncate"
                                        >{detail(row)}</span
                                    >
                                {/if}
                            </div>
                            <span
                                class="w-24 shrink-0 text-right font-mono text-xs text-fg-muted tabular-nums"
                            >
                                {@render when(row)}
                            </span>
                            <span
                                class="flex w-full items-center gap-1.5 pl-9 text-xs text-fg-muted sm:w-60 sm:shrink-0 sm:pl-0"
                            >
                                {#if row.in_at}
                                    {@render place(row)}
                                {:else if row.leave}
                                    {@render away(row)}
                                {:else if row.state === 'not_in'}
                                    No leave or kaaj recorded
                                {/if}
                            </span>
                        </div>
                    {/each}
                    {#if group.rows.length > LIMIT}
                        <button
                            type="button"
                            class="w-full border-b border-line px-5 py-2 text-left text-[13px] text-accent hover:bg-hover lg:px-6"
                            onclick={() =>
                                open
                                    ? expanded.delete(group.key)
                                    : expanded.add(group.key)}
                        >
                            {open
                                ? 'Show fewer'
                                : `${group.rows.length - LIMIT} more`}
                        </button>
                    {/if}
                {/each}
            </section>
        {/if}
    </div>
</AppShell>
