<script lang="ts">
    import { ChevronRight, LocateFixed, X } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { MediaQuery, SvelteSet } from 'svelte/reactivity';
    import type { ProgressMember, ProgressTask, Status } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import PillGroup from '../PillGroup.svelte';
    import StatusGlyph from '../StatusGlyph.svelte';

    /**
     * The team's tasks on a timeline. A report: clicking a bar or a row
     * selects the task (the page shows it read-only), nothing here edits.
     */
    let {
        tasks,
        members,
        statuses = [],
        selectedId = null,
        memberFilter = null,
        show = $bindable('all'),
        onselect,
        onclearmember,
    }: {
        tasks: ProgressTask[];
        members: ProgressMember[];
        statuses?: Status[];
        selectedId?: number | null;
        memberFilter?: number | null;
        /** all, open, soon (due within 7 days), late or done. */
        show?: string;
        onselect: (task: ProgressTask) => void;
        onclearmember: () => void;
    } = $props();

    const DAY = 86_400_000;
    const HEADER_HEIGHT = 52;

    /** Pixels per day for each zoom; "fit" squeezes the whole span into view. */
    const PX_PER_DAY: Record<string, number> = {
        days: 34,
        weeks: 10,
        months: 3.2,
    };

    // Stripes for overdue time, drawn in the theme's danger colour.
    const HATCH =
        'repeating-linear-gradient(-45deg, color-mix(in srgb, var(--ws-danger) 55%, transparent) 0 2px, transparent 2px 6px)';

    // ---- Viewer preferences, remembered in this browser only ----
    function remembered(key: string, allowed: string[], fallback: string) {
        try {
            const value = localStorage.getItem(key);

            return value !== null && allowed.includes(value) ? value : fallback;
        } catch {
            return fallback;
        }
    }

    let zoom = $state(
        remembered('ws.gantt.zoom', ['fit', 'days', 'weeks', 'months'], 'fit'),
    );
    let groupBy = $state(
        remembered('ws.gantt.group', ['project', 'person'], 'project'),
    );

    $effect(() => {
        try {
            localStorage.setItem('ws.gantt.zoom', zoom);
            localStorage.setItem('ws.gantt.group', groupBy);
        } catch {
            /* private browsing: the choice just isn't remembered */
        }
    });

    const wide = new MediaQuery('(min-width: 768px)', true);
    const labelWidth = $derived(wide.current ? 320 : 210);

    // ---- Dates (local days, as the server sends YYYY-MM-DD) ----
    function dayOf(iso: string): number {
        const [y, m, d] = iso.slice(0, 10).split('-').map(Number);

        return new Date(y, m - 1, d).getTime();
    }

    function daysBetween(from: number, to: number): number {
        return Math.round((to - from) / DAY);
    }

    // Recomputed with each live refresh, so a page left open overnight moves on.
    const today = $derived.by(() => {
        void tasks;
        const now = new Date();

        return new Date(
            now.getFullYear(),
            now.getMonth(),
            now.getDate(),
        ).getTime();
    });

    const fullDate = new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
    const monthYear = new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
    });
    const monthShort = new Intl.DateTimeFormat(undefined, { month: 'short' });

    function statusLabel(value: string): string {
        return statuses.find((s) => s.value === value)?.label ?? value;
    }

    // ---- Bars ----
    type BarState = 'done' | 'failed' | 'late' | 'active' | 'idle';

    interface Bar {
        task: ProgressTask;
        from: number;
        to: number;
        start: number;
        deadline: number | null;
        completed: number | null;
        state: BarState;
        openEnded: boolean;
        daysLate: number;
        daysEarly: number;
        dueIn: number | null;
    }

    function toBar(task: ProgressTask): Bar {
        const start = task.start ? dayOf(task.start) : today;
        const deadline = task.deadline_at ? dayOf(task.deadline_at) : null;
        const completed = task.completed_at ? dayOf(task.completed_at) : null;
        const failed = task.status === 'failed';
        const end = task.is_complete
            ? (completed ?? deadline ?? start)
            : (deadline ?? Math.max(today, start));
        const openLate =
            !task.is_complete &&
            !failed &&
            deadline !== null &&
            deadline < today;

        let state: BarState = 'idle';

        if (task.is_complete) {
            state = 'done';
        } else if (failed) {
            state = 'failed';
        } else if (openLate) {
            state = 'late';
        } else if (task.status === 'in_progress' || task.progress > 0) {
            state = 'active';
        }

        return {
            task,
            start,
            from: Math.min(start, end),
            to: Math.max(start, end),
            deadline,
            completed,
            state,
            openEnded: !task.is_complete && !failed && deadline === null,
            daysLate: openLate
                ? daysBetween(deadline!, today)
                : task.is_complete && completed !== null && deadline !== null
                  ? Math.max(0, daysBetween(deadline, completed))
                  : 0,
            daysEarly:
                task.is_complete && completed !== null && deadline !== null
                    ? Math.max(0, daysBetween(completed, deadline))
                    : 0,
            dueIn:
                !task.is_complete && deadline !== null && deadline >= today
                    ? daysBetween(today, deadline)
                    : null,
        };
    }

    /** The last day a bar reaches, overdue stripes included. */
    function reach(bar: Bar): number {
        return bar.state === 'late' ? Math.max(bar.to, today) : bar.to;
    }

    function dueSoon(task: ProgressTask): boolean {
        if (task.is_complete || !task.deadline_at) {
            return false;
        }

        const due = dayOf(task.deadline_at);

        return due >= today && due <= today + 7 * DAY;
    }

    const filtered = $derived(
        tasks.filter(
            (t) =>
                (memberFilter === null ||
                    t.assignees.some((a) => a.id === memberFilter)) &&
                (show === 'all' ||
                    (show === 'open' && !t.is_complete) ||
                    (show === 'soon' && dueSoon(t)) ||
                    (show === 'late' &&
                        !t.is_complete &&
                        t.is_late &&
                        t.status !== 'failed') ||
                    (show === 'done' && t.is_complete)),
        ),
    );

    const bars = $derived(filtered.map(toBar));

    // ---- Time range and scale ----
    const range = $derived.by(() => {
        if (bars.length === 0) {
            return null;
        }

        let min = Math.min(today, ...bars.map((b) => b.from)) - 3 * DAY;
        let max = Math.max(today, ...bars.map(reach)) + 10 * DAY;

        // Zoomed views start and end on whole months, so the header reads cleanly.
        if (zoom !== 'fit') {
            const a = new Date(min);
            const b = new Date(max);
            min = new Date(a.getFullYear(), a.getMonth(), 1).getTime();
            max = new Date(b.getFullYear(), b.getMonth() + 1, 0).getTime();
        }

        return { min, max, days: daysBetween(min, max) + 1 };
    });

    /** Rendered width of the time scale, to know which labels fit. */
    let scaleWidth = $state(0);

    const timelineWidth = $derived(
        range && zoom !== 'fit' ? range.days * PX_PER_DAY[zoom] : null,
    );

    const columns = $derived(
        `${labelWidth}px ${timelineWidth ? `${timelineWidth}px` : 'minmax(560px, 1fr)'}`,
    );

    /** Left edge of a day, in percent of the timeline. */
    function pos(time: number): number {
        return range ? ((time - range.min) / (range.days * DAY)) * 100 : 0;
    }

    /** Width of whole days from `from` to `to` inclusive, in percent. */
    function span(from: number, to: number): number {
        return range ? ((to - from + DAY) / (range.days * DAY)) * 100 : 0;
    }

    interface Segment {
        key: string;
        left: number;
        width: number;
        label: string;
    }

    interface Tick {
        key: string;
        left: number;
        width: number;
        label: string;
        today: boolean;
        cell: boolean;
    }

    const scale = $derived.by(() => {
        const empty = { top: [] as Segment[], bottom: [] as Tick[] };

        if (!range) {
            return empty;
        }

        const { min, max, days } = range;
        // The lower row is one step finer than the upper one: days or weeks
        // under months, months under years.
        const bottomKind =
            zoom === 'days'
                ? 'days'
                : zoom === 'weeks'
                  ? 'weeks'
                  : zoom === 'months'
                    ? 'months'
                    : days <= 42
                      ? 'days'
                      : days <= 420
                        ? 'weeks'
                        : 'months';
        const topByYear = bottomKind === 'months';
        const first = new Date(min);
        const top: Segment[] = [];

        for (let i = 0; ; i++) {
            const start = topByYear
                ? new Date(first.getFullYear() + i, 0, 1)
                : new Date(first.getFullYear(), first.getMonth() + i, 1);

            if (start.getTime() > max) {
                break;
            }

            const next = topByYear
                ? new Date(first.getFullYear() + i + 1, 0, 1)
                : new Date(first.getFullYear(), first.getMonth() + i + 1, 1);
            const from = Math.max(start.getTime(), min);
            const to = Math.min(next.getTime(), max + DAY);

            top.push({
                key: `t${start.getTime()}`,
                left: pos(from),
                width: ((to - from) / (days * DAY)) * 100,
                label: topByYear
                    ? String(start.getFullYear())
                    : monthYear.format(start),
            });
        }

        const bottom: Tick[] = [];

        if (bottomKind === 'days') {
            for (let i = 0; i < days; i++) {
                const day = new Date(
                    first.getFullYear(),
                    first.getMonth(),
                    first.getDate() + i,
                );

                bottom.push({
                    key: `d${day.getTime()}`,
                    left: pos(day.getTime()),
                    width: span(day.getTime(), day.getTime()),
                    label: String(day.getDate()),
                    today: day.getTime() === today,
                    cell: true,
                });
            }
        } else if (bottomKind === 'weeks') {
            const offset = (8 - first.getDay()) % 7;

            for (let i = 0; ; i++) {
                const monday = new Date(
                    first.getFullYear(),
                    first.getMonth(),
                    first.getDate() + offset + i * 7,
                );

                if (monday.getTime() > max) {
                    break;
                }

                bottom.push({
                    key: `w${monday.getTime()}`,
                    left: pos(monday.getTime()),
                    width: 0,
                    label: String(monday.getDate()),
                    today: false,
                    cell: false,
                });
            }
        } else {
            for (let i = 0; ; i++) {
                const month = new Date(
                    first.getFullYear(),
                    first.getMonth() + i,
                    1,
                );

                if (month.getTime() > max) {
                    break;
                }

                if (month.getTime() >= min) {
                    bottom.push({
                        key: `m${month.getTime()}`,
                        left: pos(month.getTime()),
                        width: 0,
                        label: monthShort.format(month),
                        today: false,
                        cell: false,
                    });
                }
            }
        }

        return { top, bottom };
    });

    // ---- Groups ----
    interface Group {
        key: string;
        title: string;
        avatar: string | null;
        rows: Bar[];
        from: number;
        to: number;
        progress: number;
        late: number;
        done: number;
    }

    const collapsed = new SvelteSet<string>();

    function byStart(a: Bar, b: Bar): number {
        return (
            a.from - b.from ||
            a.to - b.to ||
            a.task.title.localeCompare(b.task.title)
        );
    }

    const groups = $derived.by((): Group[] => {
        const buckets: Record<
            string,
            { title: string; avatar: string | null; rows: Bar[] }
        > = {};
        const order: string[] = [];

        function put(
            key: string,
            title: string,
            avatar: string | null,
            bar: Bar,
        ) {
            if (!buckets[key]) {
                buckets[key] = { title, avatar, rows: [] };
                order.push(key);
            }

            buckets[key].rows.push(bar);
        }

        if (groupBy === 'person') {
            for (const bar of bars) {
                const people = bar.task.assignees.filter(
                    (a) =>
                        a.in_team &&
                        (memberFilter === null || a.id === memberFilter),
                );

                if (bar.task.assignees.length === 0) {
                    put('none', 'Not assigned yet', null, bar);
                } else if (people.length === 0) {
                    put('outside', 'Assigned outside the team', null, bar);
                } else {
                    for (const person of people) {
                        put(`m${person.id}`, person.name, person.name, bar);
                    }
                }
            }

            const rank = (key: string) => {
                const i = members.findIndex((m) => `m${m.id}` === key);

                return i === -1 ? members.length + (key === 'none' ? 1 : 0) : i;
            };

            order.sort((a, b) => rank(a) - rank(b));
        } else {
            for (const bar of bars) {
                put(
                    `p${bar.task.project.slug}`,
                    bar.task.project.title,
                    null,
                    bar,
                );
            }
        }

        return order.map((key) => {
            const bucket = buckets[key];
            const rows = [...bucket.rows].sort(byStart);

            return {
                key,
                title: bucket.title,
                avatar: bucket.avatar,
                rows,
                from: Math.min(...rows.map((r) => r.from)),
                to: Math.max(...rows.map(reach)),
                progress: Math.round(
                    rows.reduce((sum, r) => sum + r.task.progress, 0) /
                        rows.length,
                ),
                late: rows.filter((r) => r.state === 'late').length,
                done: rows.filter((r) => r.state === 'done').length,
            };
        });
    });

    /** Visible task ids top to bottom, each once, for keyboard stepping. */
    const ordered = $derived.by(() => {
        const ids: number[] = [];

        for (const group of groups) {
            if (collapsed.has(group.key)) {
                continue;
            }

            for (const row of group.rows) {
                if (!ids.includes(row.task.id)) {
                    ids.push(row.task.id);
                }
            }
        }

        return ids;
    });

    function toggle(key: string) {
        if (collapsed.has(key)) {
            collapsed.delete(key);
        } else {
            collapsed.add(key);
        }
    }

    // ---- Bar presentation ----
    function barClass(bar: Bar): string {
        const edge = bar.openEnded
            ? ' border border-dashed border-fg-faint [mask-image:linear-gradient(to_right,black_75%,transparent)]'
            : '';

        switch (bar.state) {
            case 'done':
                return 'bg-success';
            case 'failed':
                return 'border border-danger bg-danger-soft';
            case 'late':
                return 'bg-danger-soft';
            case 'active':
                return `bg-accent-soft${edge}`;
            default:
                return `border border-line bg-surface-alt${edge}`;
        }
    }

    function fillClass(bar: Bar): string {
        switch (bar.state) {
            case 'late':
                return 'bg-danger';
            case 'active':
                return 'bg-accent';
            default:
                return 'bg-fg-faint';
        }
    }

    function caption(bar: Bar): { text: string; tone: string }[] {
        const percent = {
            text: `${bar.task.progress}%`,
            tone: 'text-fg-muted',
        };

        switch (bar.state) {
            case 'done':
                return [
                    { text: 'Done', tone: 'text-success' },
                    ...(bar.daysLate > 0
                        ? [
                              {
                                  text: `${bar.daysLate}d late`,
                                  tone: 'text-danger',
                              },
                          ]
                        : bar.daysEarly > 0
                          ? [
                                {
                                    text: `${bar.daysEarly}d early`,
                                    tone: 'text-fg-faint',
                                },
                            ]
                          : []),
                ];
            case 'failed':
                return [{ text: 'Failed', tone: 'text-danger' }];
            case 'late':
                return [
                    percent,
                    { text: `${bar.daysLate}d late`, tone: 'text-danger' },
                ];
            default:
                if (bar.openEnded) {
                    return [
                        percent,
                        { text: 'no deadline', tone: 'text-fg-faint' },
                    ];
                }

                if (bar.dueIn !== null && bar.dueIn <= 7) {
                    return [
                        percent,
                        {
                            text:
                                bar.dueIn === 0
                                    ? 'due today'
                                    : `due in ${bar.dueIn}d`,
                            tone: 'text-warn',
                        },
                    ];
                }

                return [percent];
        }
    }

    function taskCode(task: ProgressTask): string {
        return task.item_number !== null
            ? `${task.project.code}-${task.item_number}`
            : task.project.code;
    }

    function describe(bar: Bar): string {
        return [
            taskCode(bar.task),
            bar.task.title,
            statusLabel(bar.task.status),
            ...caption(bar).map((c) => c.text),
        ].join(', ');
    }

    // ---- Hover card ----
    let hover = $state<{
        bar: Bar;
        left: number;
        top: number;
        above: boolean;
    } | null>(null);

    function showCard(event: MouseEvent | FocusEvent, bar: Bar) {
        const rect = (
            event.currentTarget as HTMLElement
        ).getBoundingClientRect();
        const pointer =
            event instanceof MouseEvent && event.clientX > 0
                ? event.clientX
                : rect.left + Math.min(rect.width, 240) / 2;
        const width = 300;
        const above = rect.bottom + 230 > window.innerHeight;

        hover = {
            bar,
            left: Math.min(
                window.innerWidth - width - 12,
                Math.max(12, pointer - width / 2),
            ),
            top: above ? rect.top - 8 : rect.bottom + 8,
            above,
        };
    }

    // ---- Scrolling ----
    let scroller = $state<HTMLDivElement | null>(null);

    function scrollToToday(smooth = true) {
        if (!scroller || !range || !timelineWidth) {
            return;
        }

        const x = (pos(today) / 100) * timelineWidth;
        const visible = scroller.clientWidth - labelWidth;

        scroller.scrollTo({
            left: Math.max(0, x - visible * 0.3),
            behavior: smooth ? 'smooth' : 'auto',
        });
    }

    // Each zoom change re-centres on today; live refreshes don't.
    $effect(() => {
        void zoom;
        untrack(() => requestAnimationFrame(() => scrollToToday(false)));
    });

    // Keep the selected row in view while stepping with the arrow keys.
    $effect(() => {
        const id = selectedId;

        if (id === null || !scroller) {
            return;
        }

        const row = scroller.querySelector<HTMLElement>(
            `[data-task-row="${id}"]`,
        );

        if (!row) {
            return;
        }

        const top = row.offsetTop - HEADER_HEIGHT;
        const bottom = row.offsetTop + row.offsetHeight;

        if (top < scroller.scrollTop) {
            scroller.scrollTop = top;
        } else if (bottom > scroller.scrollTop + scroller.clientHeight) {
            scroller.scrollTop = bottom - scroller.clientHeight;
        }
    });

    function onKey(event: KeyboardEvent) {
        if (
            selectedId === null ||
            (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')
        ) {
            return;
        }

        const target = event.target as HTMLElement | null;

        if (
            target &&
            (target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.tagName === 'SELECT' ||
                target.isContentEditable)
        ) {
            return;
        }

        const index = ordered.indexOf(selectedId);
        const next = ordered[index + (event.key === 'ArrowDown' ? 1 : -1)];
        const task =
            next === undefined ? null : tasks.find((t) => t.id === next);

        if (task) {
            event.preventDefault();
            onselect(task);
        }
    }

    const memberName = $derived(
        members.find((m) => m.id === memberFilter)?.name ?? null,
    );
</script>

<svelte:window onkeydown={onKey} />

<section>
    <div class="mb-2 flex flex-wrap items-center gap-2">
        <h2 class="section-title mr-1">
            Timeline
            <span class="section-count">{filtered.length}</span>
        </h2>
        {#if memberName}
            <button
                type="button"
                class="chip hover:text-fg"
                onclick={onclearmember}
                title="Show everyone's tasks"
                >{memberName}<X class="h-3 w-3" /></button
            >
        {/if}
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <PillGroup
                size="sm"
                bind:value={show}
                options={[
                    { value: 'all', label: 'All' },
                    { value: 'open', label: 'Open' },
                    { value: 'soon', label: 'Due soon' },
                    { value: 'late', label: 'Overdue' },
                    { value: 'done', label: 'Done' },
                ]}
            />
            <PillGroup
                size="sm"
                bind:value={groupBy}
                options={[
                    { value: 'project', label: 'By project' },
                    { value: 'person', label: 'By person' },
                ]}
            />
            <PillGroup
                size="sm"
                bind:value={zoom}
                options={[
                    { value: 'fit', label: 'Fit' },
                    { value: 'days', label: 'Days' },
                    { value: 'weeks', label: 'Weeks' },
                    { value: 'months', label: 'Months' },
                ]}
            />
            {#if zoom !== 'fit' && range}
                <button
                    type="button"
                    class="btn h-6 px-2 text-xs"
                    onclick={() => scrollToToday()}
                    ><LocateFixed class="h-3.5 w-3.5" />Today</button
                >
            {/if}
        </div>
    </div>

    <div
        class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-fg-muted"
    >
        <span class="inline-flex items-center gap-1.5"
            ><span class="h-2.5 w-5 rounded-sm bg-success"></span>Done</span
        >
        <span class="inline-flex items-center gap-1.5"
            ><span
                class="relative h-2.5 w-5 overflow-hidden rounded-sm bg-accent-soft"
                ><span class="absolute inset-y-0 left-0 w-1/2 bg-accent"
                ></span></span
            >In progress, filled part done</span
        >
        <span class="inline-flex items-center gap-1.5"
            ><span
                class="h-2.5 w-5 rounded-sm border border-line bg-surface-alt"
            ></span>Not started</span
        >
        <span class="inline-flex items-center gap-1.5"
            ><span
                class="h-2.5 w-5 rounded-sm bg-danger-soft"
                style:background-image={HATCH}
            ></span>Overdue</span
        >
        <span class="inline-flex items-center gap-1.5"
            ><span
                class="h-2.5 w-5 rounded-sm border border-dashed border-fg-faint"
            ></span>No deadline</span
        >
        <span class="inline-flex items-center gap-1.5"
            ><span class="h-3 w-px bg-accent"></span>Today</span
        >
    </div>

    {#if !range}
        <p class="border-t border-line py-6 text-center text-xs text-fg-muted">
            {tasks.length === 0
                ? 'No tasks yet on the projects this team works on.'
                : 'Nothing matches these filters.'}
        </p>
    {:else}
        <div
            bind:this={scroller}
            class="relative max-h-[min(74vh,960px)] overflow-auto rounded-md border border-line"
        >
            <div
                class="relative"
                style:width={timelineWidth
                    ? `${labelWidth + timelineWidth}px`
                    : '100%'}
                style:min-width={`${labelWidth + 560}px`}
            >
                <!-- Time scale: months (or years) over days, weeks or months -->
                <div
                    class="sticky top-0 z-20 grid border-b border-line bg-surface"
                    style:grid-template-columns={columns}
                    style:height={`${HEADER_HEIGHT}px`}
                >
                    <div
                        class="sticky left-0 z-30 flex items-end border-r border-line bg-surface px-3 pb-1.5"
                    >
                        <span class="col-head">Task</span>
                    </div>
                    <!-- overflow-clip, not hidden: hidden would trap the sticky month labels -->
                    <div
                        class="relative overflow-clip"
                        bind:clientWidth={scaleWidth}
                    >
                        {#each scale.top as segment (segment.key)}
                            <div
                                class="absolute top-0 h-[26px] overflow-clip border-l border-line pt-1.5 text-[12px] font-medium whitespace-nowrap text-fg"
                                style:left={`${segment.left}%`}
                                style:width={`${segment.width}%`}
                            >
                                <!-- A sliver of a month at the edge gets no label;
                                     the rest stay in view beside the task column. -->
                                {#if (segment.width / 100) * scaleWidth >= 64}
                                    <span
                                        class="sticky inline-block pl-2"
                                        style:left={`${labelWidth}px`}
                                        >{segment.label}</span
                                    >
                                {/if}
                            </div>
                        {/each}
                        <div
                            class="absolute inset-x-0 top-[26px] bottom-0 border-t border-line-soft"
                        >
                            {#each scale.bottom as tick (tick.key)}
                                {#if tick.cell}
                                    <div
                                        class={`absolute inset-y-0 grid place-items-center font-mono text-[11px] tabular-nums ${tick.today ? 'font-semibold text-accent' : 'text-fg-faint'}`}
                                        style:left={`${tick.left}%`}
                                        style:width={`${tick.width}%`}
                                    >
                                        {tick.label}
                                    </div>
                                {:else}
                                    <div
                                        class="absolute inset-y-0 border-l border-line-soft pt-1 pl-1 font-mono text-[11px] whitespace-nowrap text-fg-faint tabular-nums"
                                        style:left={`${tick.left}%`}
                                    >
                                        {tick.label}
                                    </div>
                                {/if}
                            {/each}
                        </div>
                        {#if zoom !== 'days'}
                            <!-- In the day view today's cell is highlighted instead -->
                            <div
                                class="absolute bottom-0.5 z-10 -translate-x-1/2 rounded-sm bg-accent px-1 text-[10px] leading-4 font-medium text-white"
                                style:left={`${pos(today) + span(today, today) / 2}%`}
                            >
                                Today
                            </div>
                        {/if}
                    </div>
                </div>

                <!-- Gridlines behind the bars -->
                <div
                    class="pointer-events-none absolute right-0 bottom-0"
                    style:left={`${labelWidth}px`}
                    style:top={`${HEADER_HEIGHT}px`}
                    aria-hidden="true"
                >
                    {#each scale.top as segment (segment.key)}
                        <div
                            class="absolute inset-y-0 w-px bg-line"
                            style:left={`${segment.left}%`}
                        ></div>
                    {/each}
                    {#each scale.bottom as tick (tick.key)}
                        <div
                            class="absolute inset-y-0 w-px bg-line-soft"
                            style:left={`${tick.left}%`}
                        ></div>
                    {/each}
                </div>

                {#each groups as group (group.key)}
                    {@const open = !collapsed.has(group.key)}
                    <div
                        class="grid h-9 border-b border-line bg-surface-alt/70"
                        style:grid-template-columns={columns}
                    >
                        <button
                            type="button"
                            class="sticky left-0 z-10 flex min-w-0 items-center gap-2 border-r border-line bg-surface-alt px-2 text-left"
                            aria-expanded={open}
                            onclick={() => toggle(group.key)}
                        >
                            <ChevronRight
                                class={`h-3.5 w-3.5 shrink-0 text-fg-faint transition-transform ${open ? 'rotate-90' : ''}`}
                            />
                            {#if group.avatar}
                                <Avatar name={group.avatar} size="sm" />
                            {/if}
                            <span
                                class="min-w-0 flex-1 truncate text-[13px] font-medium text-fg"
                                >{group.title}</span
                            >
                            <span
                                class="shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
                            >
                                {group.done}/{group.rows.length}
                                {#if group.late > 0}<span class="text-danger"
                                        >· {group.late} late</span
                                    >{/if}
                            </span>
                        </button>
                        <div class="relative">
                            <div
                                class="absolute top-1/2 h-1.5 -translate-y-1/2 overflow-hidden rounded-sm bg-fg-faint/25"
                                style:left={`${pos(group.from)}%`}
                                style:width={`${span(group.from, group.to)}%`}
                                title={`${group.title}: ${group.progress}% overall`}
                            >
                                <span
                                    class="block h-full bg-fg-muted/70"
                                    style:width={`${group.progress}%`}
                                ></span>
                            </div>
                        </div>
                    </div>

                    {#if open}
                        {#each group.rows as bar (bar.task.id)}
                            {@const selected = selectedId === bar.task.id}
                            {@const end =
                                pos(reach(bar)) + span(reach(bar), reach(bar))}
                            {@const flip = end > 82}
                            <div
                                data-task-row={bar.task.id}
                                class="group grid h-9 border-b border-line-soft"
                                style:grid-template-columns={columns}
                            >
                                <button
                                    type="button"
                                    class={`sticky left-0 z-10 flex min-w-0 items-center gap-2 border-r border-line pr-3 pl-7 text-left ${selected ? 'bg-accent-soft' : 'bg-surface group-hover:bg-hover'}`}
                                    aria-pressed={selected}
                                    onclick={() => onselect(bar.task)}
                                >
                                    <span
                                        class="shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
                                        >{taskCode(bar.task)}</span
                                    >
                                    <span
                                        class="min-w-0 flex-1 truncate text-[13px] text-fg"
                                        >{bar.task.title}</span
                                    >
                                    <span
                                        class="flex shrink-0 items-center gap-0.5"
                                    >
                                        {#each bar.task.assignees.slice(0, 2) as person (person.id)}
                                            <Avatar
                                                name={person.name}
                                                size="sm"
                                            />
                                        {/each}
                                        {#if bar.task.assignees.length > 2}
                                            <span
                                                class="font-mono text-[11px] text-fg-faint"
                                                >+{bar.task.assignees.length -
                                                    2}</span
                                            >
                                        {/if}
                                    </span>
                                </button>
                                <div
                                    class={`relative ${selected ? 'bg-accent-soft/50' : 'group-hover:bg-hover/50'}`}
                                >
                                    {#if bar.state === 'late'}
                                        <!-- Overdue: from the deadline to today -->
                                        <div
                                            class="absolute top-1/2 h-[18px] -translate-y-1/2 rounded-r-md bg-danger-soft"
                                            style:left={`${pos(bar.to + DAY)}%`}
                                            style:width={`${span(bar.to + DAY, today)}%`}
                                            style:background-image={HATCH}
                                        ></div>
                                    {/if}
                                    {#if bar.state === 'done' && bar.deadline !== null && bar.deadline !== bar.to}
                                        <!-- Where the deadline was, for work finished early or late -->
                                        <div
                                            class={`absolute top-1/2 h-[24px] w-0.5 -translate-y-1/2 rounded-sm ${bar.daysLate > 0 ? 'bg-danger' : 'bg-fg-faint'}`}
                                            style:left={`calc(${pos(bar.deadline) + span(bar.deadline, bar.deadline)}% - 1px)`}
                                            title={`Deadline ${fullDate.format(bar.deadline)}`}
                                        ></div>
                                    {/if}
                                    <button
                                        type="button"
                                        class={`absolute top-1/2 h-[18px] -translate-y-1/2 overflow-hidden rounded-md transition-[outline-color] outline-2 outline-offset-1 outline-transparent hover:outline-accent/50 focus-visible:outline-accent ${barClass(bar)} ${selected ? 'outline-accent' : ''}`}
                                        style:left={`${pos(bar.from)}%`}
                                        style:width={`max(6px, ${span(bar.from, bar.to)}%)`}
                                        aria-label={describe(bar)}
                                        onclick={() => onselect(bar.task)}
                                        onmouseenter={(e) => showCard(e, bar)}
                                        onmousemove={(e) => showCard(e, bar)}
                                        onmouseleave={() => (hover = null)}
                                        onfocus={(e) => showCard(e, bar)}
                                        onblur={() => (hover = null)}
                                    >
                                        {#if bar.state !== 'done' && bar.state !== 'failed'}
                                            <span
                                                class={`block h-full ${fillClass(bar)}`}
                                                style:width={`${bar.task.progress}%`}
                                            ></span>
                                        {/if}
                                    </button>
                                    <div
                                        class="pointer-events-none absolute top-1/2 flex -translate-y-1/2 items-center gap-1 font-mono text-[11px] whitespace-nowrap tabular-nums"
                                        style:left={flip
                                            ? undefined
                                            : `calc(${end}% + 6px)`}
                                        style:right={flip
                                            ? `calc(${100 - pos(bar.from)}% + 6px)`
                                            : undefined}
                                    >
                                        {#each caption(bar) as part, i (i)}
                                            {#if i > 0}<span
                                                    class="text-fg-faint"
                                                    >·</span
                                                >{/if}
                                            <span class={part.tone}
                                                >{part.text}</span
                                            >
                                        {/each}
                                    </div>
                                </div>
                            </div>
                        {/each}
                    {/if}
                {/each}

                <!-- Today, over the bars but under the sticky task column -->
                <div
                    class="pointer-events-none absolute right-0 bottom-0 z-[5]"
                    style:left={`${labelWidth}px`}
                    style:top={`${HEADER_HEIGHT}px`}
                    aria-hidden="true"
                >
                    <div
                        class="absolute inset-y-0 w-px bg-accent"
                        style:left={`${pos(today) + span(today, today) / 2}%`}
                    ></div>
                </div>
            </div>
        </div>
        <p class="mt-1.5 text-xs text-fg-muted">
            A bar runs from when the task was opened to when it was finished, or
            to its deadline while open. Click a bar or a row to read the task;
            use the arrow keys to move between tasks.
        </p>
    {/if}
</section>

{#if hover}
    {@const bar = hover.bar}
    <div
        class="popover pointer-events-none fixed z-50 w-[300px] p-3 text-[13px]"
        style:left={`${hover.left}px`}
        style:top={`${hover.top}px`}
        style:transform={hover.above ? 'translateY(-100%)' : undefined}
        role="tooltip"
    >
        <div class="flex items-center gap-1.5 text-xs text-fg-muted">
            <span class="font-mono tabular-nums">{taskCode(bar.task)}</span>
            <span class="truncate">{bar.task.project.title}</span>
        </div>
        <div class="mt-1 line-clamp-2 font-medium text-fg">
            {bar.task.title}
        </div>
        <div class="mt-2 flex items-center gap-2">
            <StatusGlyph status={bar.task.status} />
            <span>{statusLabel(bar.task.status)}</span>
            <span class="ml-auto font-mono text-xs tabular-nums text-fg-muted"
                >{bar.task.progress}%</span
            >
        </div>
        <div class="mt-1.5 h-1 overflow-hidden rounded-sm bg-surface-alt">
            <span
                class={`block h-full ${bar.state === 'done' ? 'bg-success' : fillClass(bar)}`}
                style:width={`${bar.state === 'done' ? 100 : bar.task.progress}%`}
            ></span>
        </div>
        <dl class="mt-2.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
            <dt class="text-fg-muted">Opened</dt>
            <dd>{fullDate.format(bar.start)}</dd>
            <dt class="text-fg-muted">Due</dt>
            <dd>
                {#if bar.deadline !== null}
                    {fullDate.format(bar.deadline)}
                    {#if bar.state === 'late'}<span class="text-danger"
                            >· {bar.daysLate} days late</span
                        >{:else if bar.dueIn !== null}<span
                            class={bar.dueIn <= 7
                                ? 'text-warn'
                                : 'text-fg-muted'}
                            >· {bar.dueIn === 0
                                ? 'today'
                                : `in ${bar.dueIn} days`}</span
                        >{/if}
                {:else}
                    <span class="text-fg-muted">No deadline</span>
                {/if}
            </dd>
            {#if bar.completed !== null}
                <dt class="text-fg-muted">Finished</dt>
                <dd>
                    {fullDate.format(bar.completed)}
                    {#if bar.daysLate > 0}<span class="text-danger"
                            >· {bar.daysLate} days late</span
                        >{:else if bar.daysEarly > 0}<span class="text-fg-muted"
                            >· {bar.daysEarly} days early</span
                        >{/if}
                </dd>
            {/if}
            {#if bar.task.assignees.length > 0}
                <dt class="text-fg-muted">People</dt>
                <dd class="truncate">
                    {bar.task.assignees.map((a) => a.name).join(', ')}
                </dd>
            {/if}
        </dl>
    </div>
{/if}
