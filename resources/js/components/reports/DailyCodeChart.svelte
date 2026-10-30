<script module lang="ts">
    import type { CodeActivityDay, Id } from '../../lib/types';

    /**
     * Series colours by slot, in the fixed order of app.css's `--ws-series-*`
     * tokens (blue, magenta, purple, teal-blue). Whole class names, so Tailwind
     * sees them; the slot follows the person, never their rank.
     */
    export const SERIES_STROKE = [
        'stroke-series-1',
        'stroke-series-2',
        'stroke-series-3',
        'stroke-series-4',
    ];
    export const SERIES_FILL = [
        'fill-series-1',
        'fill-series-2',
        'fill-series-3',
        'fill-series-4',
    ];
    export const SERIES_BG = [
        'bg-series-1',
        'bg-series-2',
        'bg-series-3',
        'bg-series-4',
    ];

    export interface ChartSeries {
        key: Id;
        name: string;
        /** 0 to 3: which colour. */
        slot: number;
        /** Every day of the range, the same days for every series. */
        days: CodeActivityDay[];
    }
</script>

<script lang="ts">
    /**
     * Code lines changed (added plus removed) per day, one 2px line per
     * person compared, on one axis. A legend names every line; the end of
     * each line carries its name when there is room; a crosshair (pointer,
     * or arrow keys once focused) reads every person's value for that day.
     */
    let { series }: { series: ChartSeries[] } = $props();

    const HEIGHT = 220;
    const TOP = 12;
    const BOTTOM = 24;
    const LEFT = 40;
    const LABEL_ROOM = 72;
    const LABEL_GAP = 13;

    let width = $state(0);
    let picked = $state<number | null>(null);
    let svg = $state<SVGSVGElement | null>(null);

    const number = new Intl.NumberFormat();
    const compact = new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    });
    const tickDate = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    });
    const fullDate = new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

    function localDay(iso: string): Date {
        const [y, m, d] = iso.split('-').map(Number);

        return new Date(y, m - 1, d);
    }

    const days = $derived(series[0]?.days ?? []);
    const count = $derived(days.length);

    /** The crosshair's day, kept inside the range when the data changes under it. */
    const active = $derived(
        picked === null || count === 0 ? null : Math.min(picked, count - 1),
    );

    // End labels only where the plot keeps its width; a phone has the legend.
    const labelled = $derived(width >= 480);
    const right = $derived(width - (labelled ? LABEL_ROOM : 12));
    const bottom = HEIGHT - BOTTOM;

    const totals = $derived(
        series.map((s) => s.days.reduce((sum, d) => sum + d.code_changed, 0)),
    );

    const peak = $derived(
        Math.max(
            0,
            ...series.flatMap((s) => s.days.map((d) => d.code_changed)),
        ),
    );

    /** Clean steps (1, 2 or 5 times a power of ten), about four of them. */
    const yTicks = $derived.by(() => {
        if (peak === 0) {
            return [0, 1];
        }

        const rough = peak / 4;
        const power = 10 ** Math.floor(Math.log10(rough));
        const step = Math.max(
            1,
            [1, 2, 5, 10].map((n) => n * power).find((s) => s >= rough) ??
                10 * power,
        );
        const ticks: number[] = [];

        for (let v = 0; v < peak + step; v += step) {
            ticks.push(v);
        }

        return ticks;
    });

    const yMax = $derived(yTicks[yTicks.length - 1]);

    function x(i: number): number {
        return count <= 1
            ? (LEFT + right) / 2
            : LEFT + (i * (right - LEFT)) / (count - 1);
    }

    function y(value: number): number {
        return bottom - (value / yMax) * (bottom - TOP);
    }

    const xTicks = $derived.by(() => {
        if (count === 0) {
            return [];
        }

        const room = Math.max(2, Math.floor((right - LEFT) / 84));
        const step = Math.max(1, Math.ceil((count - 1) / (room - 1)));
        const ticks: number[] = [];

        for (let i = 0; i < count; i += step) {
            ticks.push(i);
        }

        return ticks;
    });

    const paths = $derived(
        series.map((s) =>
            s.days
                .map(
                    (d, i) =>
                        `${i === 0 ? 'M' : 'L'}${x(i)},${y(d.code_changed)}`,
                )
                .join(''),
        ),
    );

    /**
     * A name at the end of each line that ends clear of the others. Lines
     * ending close together get none (their markers overlap, so a name
     * could point at the wrong one); the legend and tooltip name them.
     */
    const endLabels = $derived.by(() => {
        if (!labelled || count === 0) {
            return [];
        }

        const ends = series.map((s) => ({
            key: s.key,
            name: s.name.split(/\s+/)[0],
            y: y(s.days[count - 1].code_changed),
        }));

        return ends.filter((end) =>
            ends.every(
                (other) =>
                    other === end || Math.abs(other.y - end.y) >= LABEL_GAP,
            ),
        );
    });

    function pick(clientX: number) {
        if (!svg || count === 0) {
            return;
        }

        const offset = clientX - svg.getBoundingClientRect().left;
        const i =
            count <= 1
                ? 0
                : Math.round(((offset - LEFT) / (right - LEFT)) * (count - 1));

        picked = Math.min(count - 1, Math.max(0, i));
    }

    function onKey(event: KeyboardEvent) {
        if (count === 0) {
            return;
        }

        const current = active ?? count - 1;
        const next =
            event.key === 'ArrowLeft'
                ? current - 1
                : event.key === 'ArrowRight'
                  ? current + 1
                  : event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? count - 1
                      : null;

        if (next === null) {
            return;
        }

        event.preventDefault();
        picked = Math.min(count - 1, Math.max(0, next));
    }

    /** The day under the crosshair (the last when none), read aloud. */
    const reading = $derived.by(() => {
        const at = active ?? count - 1;

        if (at < 0) {
            return '';
        }

        return `${fullDate.format(localDay(days[at].date))}: ${series
            .map(
                (s) =>
                    `${s.name} ${number.format(s.days[at].code_changed)} lines`,
            )
            .join(', ')}`;
    });
</script>

<div>
    <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
        {#each series as s, i (s.key)}
            <li class="inline-flex items-center gap-1.5">
                <span class={`h-0.5 w-3.5 rounded-full ${SERIES_BG[s.slot]}`}
                ></span>
                <span class="text-fg">{s.name}</span>
                <span class="font-mono text-xs text-fg-muted tabular-nums"
                    >{number.format(totals[i])}</span
                >
            </li>
        {/each}
    </ul>

    {#if peak === 0}
        <p class="mb-2 text-xs text-fg-muted">
            No code lines changed in this range.
        </p>
    {/if}

    <!-- A slider over the days: arrow keys move the crosshair. -->
    <div
        class="relative rounded-md"
        bind:clientWidth={width}
        role="slider"
        aria-label="Code lines changed per day"
        aria-valuemin={0}
        aria-valuemax={Math.max(0, count - 1)}
        aria-valuenow={active ?? Math.max(0, count - 1)}
        aria-valuetext={reading}
        tabindex="0"
        onkeydown={onKey}
        onfocus={() => (picked ??= count - 1)}
        onblur={() => (picked = null)}
    >
        {#if width > 0 && count > 0}
            <svg
                bind:this={svg}
                {width}
                height={HEIGHT}
                class="block touch-pan-y select-none"
                aria-hidden="true"
                onpointermove={(e) => pick(e.clientX)}
                onpointerdown={(e) => pick(e.clientX)}
                onpointerleave={() => (picked = null)}
            >
                {#each yTicks as tick (tick)}
                    <line
                        x1={LEFT}
                        x2={right}
                        y1={y(tick)}
                        y2={y(tick)}
                        class={tick === 0 ? 'stroke-line' : 'stroke-line-soft'}
                        stroke-width="1"
                        shape-rendering="crispEdges"
                    />
                    <text
                        x={LEFT - 8}
                        y={y(tick)}
                        dy="0.32em"
                        text-anchor="end"
                        class="fill-fg-faint font-mono text-[10.5px] tabular-nums"
                        >{tick >= 1000
                            ? compact.format(tick)
                            : number.format(tick)}</text
                    >
                {/each}

                {#each xTicks as i (i)}
                    <text
                        x={x(i)}
                        y={HEIGHT - 6}
                        text-anchor={i === 0
                            ? 'start'
                            : x(i) > right - 24
                              ? 'end'
                              : 'middle'}
                        class="fill-fg-faint font-mono text-[10.5px] tabular-nums"
                        >{tickDate.format(localDay(days[i].date))}</text
                    >
                {/each}

                {#if active !== null}
                    <line
                        x1={x(active)}
                        x2={x(active)}
                        y1={TOP}
                        y2={bottom}
                        class="stroke-fg-faint"
                        stroke-width="1"
                        shape-rendering="crispEdges"
                    />
                {/if}

                {#each series as s, i (s.key)}
                    <path
                        d={paths[i]}
                        fill="none"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        class={SERIES_STROKE[s.slot]}
                    />
                {/each}

                {#each series as s (s.key)}
                    {@const at = active ?? count - 1}
                    <circle
                        cx={x(at)}
                        cy={y(s.days[at].code_changed)}
                        r="4"
                        stroke-width="2"
                        class={`stroke-surface ${SERIES_FILL[s.slot]}`}
                    />
                {/each}

                {#if active === null}
                    {#each endLabels as label (label.key)}
                        <text
                            x={right + 9}
                            y={label.y}
                            dy="0.32em"
                            class="fill-fg-muted text-[11px]">{label.name}</text
                        >
                    {/each}
                {/if}
            </svg>

            {#if active !== null}
                {@const flip = x(active) > width / 2}
                <div
                    class="popover pointer-events-none absolute top-1 min-w-40 px-2.5 py-2 text-xs"
                    style:left={`${x(active)}px`}
                    style:transform={flip
                        ? 'translateX(calc(-100% - 10px))'
                        : 'translateX(10px)'}
                >
                    <div class="mb-1.5 text-fg-muted">
                        {fullDate.format(localDay(days[active].date))}
                    </div>
                    <ul class="space-y-1">
                        {#each series as s (s.key)}
                            {@const day = s.days[active]}
                            <li class="flex items-center gap-2">
                                <span
                                    class={`h-0.5 w-3 shrink-0 rounded-full ${SERIES_BG[s.slot]}`}
                                ></span>
                                <span
                                    class="font-mono font-medium text-fg tabular-nums"
                                    >{number.format(day.code_changed)}</span
                                >
                                <span
                                    class="min-w-0 flex-1 truncate text-fg-muted"
                                    >{s.name}</span
                                >
                                <span
                                    class="font-mono text-fg-faint tabular-nums"
                                    >{day.commits}
                                    {day.commits === 1
                                        ? 'commit'
                                        : 'commits'}</span
                                >
                            </li>
                        {/each}
                    </ul>
                </div>
            {/if}
        {:else}
            <div style:height={`${HEIGHT}px`}></div>
        {/if}
    </div>
</div>
