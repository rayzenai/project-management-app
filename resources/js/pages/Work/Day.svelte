<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import { CalendarDays } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import DayPicker from '../../components/filters/DayPicker.svelte';
    import ScopeSelects from '../../components/filters/ScopeSelects.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import StatusGlyph from '../../components/StatusGlyph.svelte';
    import { isoDay, longDay } from '../../lib/dates';
    import { plural } from '../../lib/format';
    import { ReportVisit } from '../../lib/reportVisit.svelte';
    import type { Id } from '../../lib/types';
    import {
        attendanceDayLabel,
        personWorkHref,
        taskHref,
    } from '../../lib/work';
    import type {
        WorkDayFilters,
        WorkDayProps,
        WorkDayRow,
        WorkTask,
    } from '../../lib/work';

    /**
     * Today's work, by person: what each person finished, started and
     * committed on a day, listed by name. Someone with nothing recorded
     * shows plainly. Any day; everyone in view, or one person's team.
     */
    let {
        date,
        organization_id,
        under,
        summary,
        people,
        options,
        filters,
    }: WorkDayProps = $props();

    const today = isoDay(new Date());
    const isToday = $derived(date === today);

    const report = new ReportVisit();

    function visit(change: Partial<WorkDayFilters>) {
        const next = { ...filters, ...change };

        report.go(
            '/workspace/work',
            {
                date: next.date,
                organization: next.organization,
                under: next.under,
            },
            options.organizations,
        );
    }

    function setScope(change: { organization?: Id; under: Id | null }) {
        visit(change);
    }

    const fmt = (n: number) => n.toLocaleString();

    function codeLine(row: WorkDayRow): string {
        const repos = row.commits.repositories.map((r) => r.name);
        const where =
            repos.length > 0
                ? ` to ${repos.slice(0, 3).join(', ')}${repos.length > 3 ? ` and ${repos.length - 3} more` : ''}`
                : '';

        return `${plural(row.commits.count, 'commit')}${where}`;
    }

    function merged(row: WorkDayRow): number {
        return row.pull_requests_merged.length + row.hidden_pull_requests;
    }

    function pullRequestLine(row: WorkDayRow): string {
        const count = merged(row);

        if (count === 0) {
            return '';
        }

        return `${row.commits.count > 0 ? ', ' : ''}${plural(count, 'pull request')} merged`;
    }

    const teamHref = $derived(
        `/workspace/work/team?${new URLSearchParams({
            ...(options.organizations.length > 0
                ? { organization: String(organization_id) }
                : {}),
            ...(under ? { under: String(under.id) } : {}),
        }).toString()}`,
    );
</script>

<svelte:head><title>Today's work · Workspace</title></svelte:head>

{#snippet taskLines(label: string, tasks: WorkTask[], hidden: number)}
    {#each tasks as task, i (task.id)}
        <div class="flex min-w-0 items-baseline gap-2 py-0.5">
            <span class="w-16 shrink-0 text-xs text-fg-muted"
                >{i === 0 ? label : ''}</span
            >
            <StatusGlyph status={task.status} size={12} class="self-center" />
            <span class="shrink-0 font-mono text-[11px] text-fg-faint"
                >{task.code}</span
            >
            <a
                href={taskHref(task)}
                use:inertia
                class="min-w-0 truncate hover:text-accent"
                title={`${task.title} · ${task.project}`}>{task.title}</a
            >
        </div>
    {/each}
    {#if hidden > 0}
        <div class="flex items-baseline gap-2 py-0.5">
            <span class="w-16 shrink-0 text-xs text-fg-muted"
                >{tasks.length === 0 ? label : ''}</span
            >
            <span class="text-xs text-fg-faint"
                >{tasks.length === 0
                    ? plural(hidden, 'task')
                    : `${plural(hidden, 'more task')}`} in projects you can't see</span
            >
        </div>
    {/if}
{/snippet}

<AppShell>
    {#snippet bar()}
        <span class="truncate font-medium"
            >{under ? `${under.name}'s team` : "Today's work"}</span
        >
        <span class="ml-auto flex items-center gap-1">
            <a href={teamHref} use:inertia class="btn-ghost">
                <CalendarDays class="h-3.5 w-3.5" />
                <span class="hidden sm:inline">Week</span>
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
                    onchange={setScope}
                />
                <DayPicker
                    {date}
                    onchange={(value) => visit({ date: value })}
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

        <div>
            <h1 class="text-[15px] font-semibold">{longDay(date)}</h1>
            <div
                class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-[13px] text-fg-muted"
            >
                <span
                    >Tasks finished <b class="font-mono text-fg tabular-nums"
                        >{fmt(summary.tasks_closed)}</b
                    ></span
                >
                <span
                    >Tasks started <b class="font-mono text-fg tabular-nums"
                        >{fmt(summary.tasks_started)}</b
                    ></span
                >
                <span
                    >Commits <b class="font-mono text-fg tabular-nums"
                        >{fmt(summary.commits)}</b
                    >
                    {#if summary.committers > 0}
                        <span class="text-fg-faint"
                            >by {plural(
                                summary.committers,
                                'person',
                                'people',
                            )}</span
                        >
                    {/if}
                </span>
                <span
                    >Pull requests merged <b
                        class="font-mono text-fg tabular-nums"
                        >{fmt(summary.pull_requests_merged)}</b
                    ></span
                >
                <span
                    >Nothing recorded <b class="font-mono text-fg tabular-nums"
                        >{fmt(summary.nothing_recorded)}</b
                    ></span
                >
            </div>
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
            <section
                class="panel transition-opacity"
                class:opacity-60={report.loading}
            >
                {#each people as row (row.member.id)}
                    {@const attendance = row.attendance
                        ? attendanceDayLabel(row.attendance)
                        : null}
                    <div
                        class="flex flex-col gap-2 border-b border-line-soft px-4 py-3 last:border-0 sm:flex-row sm:gap-4"
                    >
                        <a
                            href={personWorkHref(row.member.id)}
                            use:inertia
                            class="flex w-full min-w-0 shrink-0 items-start gap-2 sm:w-52"
                        >
                            <Avatar name={row.member.name} size="md" />
                            <span class="min-w-0">
                                <span
                                    class="block truncate font-medium hover:text-accent"
                                    >{row.member.name}</span
                                >
                                {#if attendance}
                                    <span
                                        class={`block truncate text-xs ${attendance.warn ? 'text-warn' : 'text-fg-muted'}`}
                                        >{attendance.text}</span
                                    >
                                {:else if row.member.designation}
                                    <span
                                        class="block truncate text-xs text-fg-muted"
                                        >{row.member.designation}</span
                                    >
                                {/if}
                            </span>
                        </a>
                        <div class="min-w-0 flex-1 text-[13px]">
                            {#if row.nothing_recorded}
                                <p class="py-0.5 text-fg-faint">
                                    Nothing recorded {isToday
                                        ? 'today'
                                        : 'this day'}
                                </p>
                            {:else}
                                {@render taskLines(
                                    'Finished',
                                    row.closed,
                                    row.hidden_closed,
                                )}
                                {@render taskLines(
                                    'Started',
                                    row.started,
                                    row.hidden_started,
                                )}
                                <div
                                    class="flex min-w-0 items-baseline gap-2 py-0.5"
                                >
                                    <span
                                        class="w-16 shrink-0 text-xs text-fg-muted"
                                        >Code</span
                                    >
                                    {#if row.commits.count === 0 && merged(row) === 0}
                                        <span class="text-fg-faint"
                                            >No commits</span
                                        >
                                    {:else}
                                        <span class="min-w-0"
                                            >{#if row.commits.count > 0}{codeLine(
                                                    row,
                                                )}
                                                <span
                                                    class="font-mono text-xs text-fg-muted tabular-nums"
                                                    >+{fmt(
                                                        row.commits.code_added,
                                                    )} &minus;{fmt(
                                                        row.commits
                                                            .code_removed,
                                                    )}</span
                                                >{/if}{pullRequestLine(
                                                row,
                                            )}</span
                                        >
                                    {/if}
                                </div>
                            {/if}
                        </div>
                    </div>
                {/each}
            </section>
            <p class="text-xs text-fg-muted">
                Listed by name. A task counts for whoever changed its status;
                commits for whoever made them, merges left out. Tasks and
                repositories you can't open are counted, not named.
            </p>
        {/if}
    </div>
</AppShell>
