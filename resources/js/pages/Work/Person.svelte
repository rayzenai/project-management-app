<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import { CircleCheck, Clock, Code, GitMerge } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import StatusGlyph from '../../components/StatusGlyph.svelte';
    import RangePicker from '../../components/work/RangePicker.svelte';
    import {
        hoursLabel,
        personWorkHref,
        plural,
        rangeLabel,
        taskHref,
        weekday,
    } from '../../lib/work';
    import type { WorkPersonProps, WorkRangeFilters } from '../../lib/work';

    /**
     * One person's work over a range (this week unless chosen): their
     * attendance when it is on, the tasks they finished and started, what
     * is overdue for them now, their code and merged pull requests.
     */
    let {
        member,
        range,
        attendance,
        tasks,
        code,
        pull_requests_merged,
        hidden_pull_requests,
        filters,
    }: WorkPersonProps = $props();

    let loading = $state(false);
    let error = $state<string | null>(null);

    function visit(next: WorkRangeFilters) {
        router.get(
            personWorkHref(member.id),
            { from: next.from, to: next.to },
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

    const fmt = (n: number) => n.toLocaleString();
    const merged = $derived(pull_requests_merged.length + hidden_pull_requests);

    const deadline = (iso: string) =>
        new Date(iso).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
        });
</script>

<svelte:head><title>{member.name} · Work · Workspace</title></svelte:head>

{#snippet kv(label: string, value: string, tone = '')}
    <div class="flex items-baseline justify-between gap-3 py-0.5 text-[13px]">
        <span class="text-fg-muted">{label}</span>
        <span class={`font-mono tabular-nums ${tone}`}>{value}</span>
    </div>
{/snippet}

{#snippet hiddenLine(count: number, noun: string)}
    {#if count > 0}
        <p class="px-5 py-2 text-xs text-fg-faint lg:px-6">
            {plural(count, noun)} in projects you can't see
        </p>
    {/if}
{/snippet}

<AppShell>
    {#snippet bar()}
        <span class="truncate text-fg-muted">Work</span>
        <span class="text-fg-faint">/</span>
        <span class="truncate font-medium">{member.name}</span>
    {/snippet}

    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <Avatar name={member.name} size="lg" />
                <div class="min-w-0">
                    <h1 class="truncate text-[15px] font-semibold">
                        {member.name}
                    </h1>
                    <p class="truncate text-[13px] text-fg-muted">
                        {[
                            member.designation,
                            member.reports_to
                                ? `reports to ${member.reports_to.name}`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ') || rangeLabel(range)}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <RangePicker
                    range={{ from: filters.from, to: filters.to }}
                    onchange={visit}
                />
                {#if loading}
                    <Spinner size={14} />
                {/if}
            </div>
        </div>
        {#if error}
            <p role="alert" class="text-xs text-danger">{error}</p>
        {/if}

        <div
            class="grid gap-3 transition-opacity sm:grid-cols-2 lg:grid-cols-3"
            class:opacity-60={loading}
        >
            {#if attendance}
                <section class="panel px-4 py-3">
                    <h2 class="section-title mb-1.5">
                        <Clock class="h-3.5 w-3.5 self-center text-fg-muted" />
                        Attendance
                    </h2>
                    {@render kv(
                        'Days in',
                        `${attendance.days_present} of ${attendance.days_expected}`,
                    )}
                    {#if attendance.usual_in}
                        {@render kv('Usually in at', attendance.usual_in)}
                    {/if}
                    {@render kv(
                        'Came late',
                        plural(attendance.days_late, 'day'),
                        attendance.days_late > 0 ? 'text-warn' : '',
                    )}
                    {@render kv(
                        'Hours at work',
                        hoursLabel(attendance.minutes_worked),
                    )}
                    {#if attendance.no_clock_out_days > 0}
                        {@render kv(
                            'No clock-out',
                            plural(attendance.no_clock_out_days, 'day'),
                            'text-warn',
                        )}
                    {/if}
                    {#if attendance.outside_punches > 0}
                        {@render kv(
                            'Outside office',
                            plural(
                                attendance.outside_punches,
                                'punch',
                                'punches',
                            ),
                        )}
                    {/if}
                    {#if attendance.leave_days > 0}
                        {@render kv(
                            'Leave',
                            plural(attendance.leave_days, 'day'),
                        )}
                    {/if}
                    {#if attendance.kaaj_days > 0}
                        {@render kv(
                            'Kaaj',
                            plural(attendance.kaaj_days, 'day'),
                        )}
                    {/if}
                </section>
            {/if}
            <section class="panel px-4 py-3">
                <h2 class="section-title mb-1.5">
                    <CircleCheck
                        class="h-3.5 w-3.5 self-center text-fg-muted"
                    />
                    Tasks
                </h2>
                {@render kv('Finished', fmt(tasks.counts.closed))}
                {@render kv('Started', fmt(tasks.counts.started))}
                {@render kv(
                    'Overdue now',
                    fmt(tasks.counts.overdue),
                    tasks.counts.overdue > 0 ? 'text-danger' : '',
                )}
            </section>
            <section class="panel px-4 py-3">
                <h2 class="section-title mb-1.5">
                    <Code class="h-3.5 w-3.5 self-center text-fg-muted" />
                    Code
                </h2>
                {@render kv('Commits', fmt(code.count))}
                {@render kv(
                    'Code lines',
                    `+${fmt(code.code_added)} −${fmt(code.code_removed)}`,
                )}
                {@render kv(
                    'Comment lines',
                    `+${fmt(code.comment_added)} −${fmt(code.comment_removed)}`,
                )}
                {@render kv('Pull requests merged', fmt(merged))}
                {#if code.repositories.length > 0}
                    <p class="mt-1 truncate text-xs text-fg-muted">
                        {code.repositories
                            .map((r) => `${r.name} ${r.count}`)
                            .join(', ')}{code.hidden_commits > 0
                            ? `, ${fmt(code.hidden_commits)} elsewhere`
                            : ''}
                    </p>
                {:else if code.hidden_commits > 0}
                    <p class="mt-1 text-xs text-fg-faint">
                        In repositories you can't see
                    </p>
                {/if}
            </section>
        </div>

        <section class="panel overflow-hidden">
            <div class="group-head">
                Finished
                <span class="section-count">{tasks.counts.closed}</span>
            </div>
            {#each tasks.closed as task (task.id)}
                <a
                    href={taskHref(task)}
                    use:inertia
                    class="row h-9 text-[13px]"
                >
                    <StatusGlyph status={task.status} size={13} />
                    <span
                        class="w-24 shrink-0 truncate font-mono text-[11px] text-fg-faint"
                        >{task.code}</span
                    >
                    <span class="min-w-0 flex-1 truncate">{task.title}</span>
                    <span
                        class="hidden shrink-0 text-xs text-fg-muted sm:inline"
                        >{task.project} · {weekday(task.at)}</span
                    >
                </a>
            {:else}
                {#if tasks.counts.hidden_closed === 0}
                    <p class="px-5 py-2 text-[13px] text-fg-faint lg:px-6">
                        Nothing finished {rangeLabel(range)}.
                    </p>
                {/if}
            {/each}
            {@render hiddenLine(tasks.counts.hidden_closed, 'task')}

            <div class="group-head">
                Started
                <span class="section-count">{tasks.counts.started}</span>
            </div>
            {#each tasks.started as task (task.id)}
                <a
                    href={taskHref(task)}
                    use:inertia
                    class="row h-9 text-[13px]"
                >
                    <StatusGlyph status={task.status} size={13} />
                    <span
                        class="w-24 shrink-0 truncate font-mono text-[11px] text-fg-faint"
                        >{task.code}</span
                    >
                    <span class="min-w-0 flex-1 truncate">{task.title}</span>
                    <span
                        class="hidden shrink-0 text-xs text-fg-muted sm:inline"
                        >{task.project} · {weekday(task.at)}</span
                    >
                </a>
            {:else}
                {#if tasks.counts.hidden_started === 0}
                    <p class="px-5 py-2 text-[13px] text-fg-faint lg:px-6">
                        Nothing started {rangeLabel(range)}.
                    </p>
                {/if}
            {/each}
            {@render hiddenLine(tasks.counts.hidden_started, 'task')}

            <div class="group-head">
                Overdue now
                <span class="section-count">{tasks.counts.overdue}</span>
            </div>
            {#each tasks.overdue as task (task.id)}
                <a
                    href={taskHref(task)}
                    use:inertia
                    class="row h-9 text-[13px]"
                >
                    <StatusGlyph status={task.status} size={13} />
                    <span
                        class="w-24 shrink-0 truncate font-mono text-[11px] text-fg-faint"
                        >{task.code}</span
                    >
                    <span class="min-w-0 flex-1 truncate">{task.title}</span>
                    <span class="shrink-0 text-xs text-danger"
                        >due {deadline(task.deadline_at)}</span
                    >
                </a>
            {:else}
                {#if tasks.counts.hidden_overdue === 0}
                    <p class="px-5 py-2 text-[13px] text-fg-faint lg:px-6">
                        Nothing overdue.
                    </p>
                {/if}
            {/each}
            {@render hiddenLine(tasks.counts.hidden_overdue, 'task')}

            {#if merged > 0}
                <div class="group-head">
                    Pull requests merged
                    <span class="section-count">{merged}</span>
                </div>
                {#each pull_requests_merged as pr (pr.url)}
                    <a
                        href={pr.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        class="row h-9 text-[13px]"
                    >
                        <GitMerge
                            class="h-[13px] w-[13px] shrink-0 text-fg-muted"
                        />
                        <span
                            class="w-24 shrink-0 truncate font-mono text-[11px] text-fg-faint"
                            >#{pr.number}</span
                        >
                        <span class="min-w-0 flex-1 truncate">{pr.title}</span>
                        <span
                            class="hidden shrink-0 text-xs text-fg-muted sm:inline"
                            >{pr.repository} · {weekday(pr.merged_at)}</span
                        >
                    </a>
                {/each}
                {#if hidden_pull_requests > 0}
                    <p class="px-5 py-2 text-xs text-fg-faint lg:px-6">
                        {plural(hidden_pull_requests, 'pull request')} in repositories
                        you can't see
                    </p>
                {/if}
            {/if}
        </section>
        <p class="text-xs text-fg-muted">
            A task counts for whoever changed its status; commits for whoever
            made them, merges left out. Tasks and repositories you can't open
            are counted, not named.
        </p>
    </div>
</AppShell>
