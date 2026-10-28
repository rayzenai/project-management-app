<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import { CircleCheck, Circle, ExternalLink, X } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import { fly } from 'svelte/transition';
    import { formatTimeAgo } from '../../lib/format';
    import type {
        Assignment,
        Priority,
        ProgressTask,
        Status,
        TaskPreview,
    } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import PriorityBars from '../PriorityBars.svelte';
    import ProgressRing from '../ProgressRing.svelte';
    import StatusGlyph from '../StatusGlyph.svelte';

    /**
     * The task behind a Gantt bar, read-only: this is a report, so nothing
     * here changes the task. It shows what the chart already knows at once,
     * then fills in the details from the task preview endpoint, and refreshes
     * them whenever the page's live data refreshes (`refreshKey`).
     */
    let {
        task,
        statuses = [],
        refreshKey,
        onclose,
    }: {
        task: ProgressTask | null;
        statuses?: Status[];
        refreshKey: string;
        onclose: () => void;
    } = $props();

    const DAY = 86_400_000;

    const cache = new SvelteMap<number, TaskPreview>();
    let preview = $state<TaskPreview | null>(null);
    let failed = $state(false);
    let panel = $state<HTMLElement | null>(null);
    let seq = 0;

    async function load(id: number) {
        const mine = ++seq;

        try {
            const response = await fetch(`/workspace/tasks/${id}/preview`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = (await response.json()) as { data: TaskPreview };

            if (mine !== seq) {
                return;
            }

            cache.set(id, json.data);
            preview = json.data;
            failed = false;
        } catch {
            if (mine === seq && preview?.task.id !== id) {
                failed = true;
            }
        }
    }

    // Another task: show what's cached straight away, then fetch it fresh.
    let wasOpen = false;

    $effect(() => {
        const id = task?.id;

        untrack(() => {
            if (id === undefined) {
                preview = null;
                wasOpen = false;

                return;
            }

            preview = cache.get(id) ?? null;
            failed = false;
            void load(id);

            if (!wasOpen) {
                wasOpen = true;
                requestAnimationFrame(() => panel?.focus());
            }
        });
    });

    // The page refreshed its live data: refresh the open task's details too.
    let lastKey = untrack(() => refreshKey);

    $effect(() => {
        const key = refreshKey;

        if (key === lastKey) {
            return;
        }

        lastKey = key;
        const id = untrack(() => task?.id);

        if (id !== undefined) {
            void load(id);
        }
    });

    const details = $derived(
        task && preview && preview.task.id === task.id ? preview : null,
    );

    function onKey(event: KeyboardEvent) {
        if (task && event.key === 'Escape' && !event.defaultPrevented) {
            event.preventDefault();
            onclose();
        }
    }

    // ---- Dates ----
    function dayOf(iso: string): number {
        const [y, m, d] = iso.slice(0, 10).split('-').map(Number);

        return new Date(y, m - 1, d).getTime();
    }

    function daysBetween(from: number, to: number): number {
        return Math.round((to - from) / DAY);
    }

    const today = $derived.by(() => {
        void refreshKey;
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

    function plural(n: number, word: string): string {
        return `${n} ${word}${n === 1 ? '' : 's'}`;
    }

    const facts = $derived.by(() => {
        if (!task) {
            return null;
        }

        const opened = task.start ? dayOf(task.start) : null;
        const due = task.deadline_at ? dayOf(task.deadline_at) : null;
        const finished = task.completed_at ? dayOf(task.completed_at) : null;
        const failed = task.status === 'failed';
        const late =
            !task.is_complete && !failed && due !== null && due < today
                ? daysBetween(due, today)
                : 0;
        const finishedLate =
            finished !== null && due !== null ? daysBetween(due, finished) : 0;

        let dueNote: { text: string; tone: string } | null = null;

        if (due !== null && !task.is_complete) {
            if (late > 0) {
                dueNote = {
                    text: `${plural(late, 'day')} late`,
                    tone: 'text-danger',
                };
            } else {
                const left = daysBetween(today, due);
                dueNote = {
                    text: left === 0 ? 'today' : `in ${plural(left, 'day')}`,
                    tone: left <= 7 ? 'text-warn' : 'text-fg-muted',
                };
            }
        }

        let finishNote: { text: string; tone: string } | null = null;

        if (finished !== null && due !== null) {
            finishNote =
                finishedLate > 0
                    ? {
                          text: `${plural(finishedLate, 'day')} late`,
                          tone: 'text-danger',
                      }
                    : finishedLate < 0
                      ? {
                            text: `${plural(-finishedLate, 'day')} early`,
                            tone: 'text-fg-muted',
                        }
                      : { text: 'on the day', tone: 'text-fg-muted' };
        }

        // A small timeline of this task alone, with today on it.
        const points = [opened, due, finished, today].filter(
            (p): p is number => p !== null,
        );
        const lo = Math.min(...points);
        const hi = Math.max(...points);
        const pad = Math.max(hi - lo, 6 * DAY) * 0.08;
        const min = lo - pad;
        const total = Math.max(hi - lo, 6 * DAY) + pad * 2;
        const at = (t: number) => ((t - min) / total) * 100;
        const barStart = opened ?? today;
        const barEnd = finished ?? due ?? today;

        return {
            opened,
            due,
            finished,
            late,
            dueNote,
            finishNote,
            tone: task.is_complete
                ? 'bg-success'
                : failed || late > 0
                  ? 'bg-danger'
                  : task.status === 'in_progress' || task.progress > 0
                    ? 'bg-accent'
                    : 'bg-fg-faint',
            track: {
                start: at(Math.min(barStart, barEnd)),
                width: Math.max(
                    1.5,
                    at(Math.max(barStart, barEnd)) -
                        at(Math.min(barStart, barEnd)),
                ),
                overdue:
                    late > 0 && due !== null
                        ? { left: at(due), width: at(today) - at(due) }
                        : null,
                today: at(today),
                due: due === null ? null : at(due),
            },
        };
    });

    const statusLabel = $derived(
        details?.task.status_label ??
            statuses.find((s) => s.value === task?.status)?.label ??
            task?.status ??
            '',
    );

    const todos = $derived(details?.subtasks ?? []);
    const todosDone = $derived(todos.filter((t) => t.is_done).length);

    function personalNote(assignment: Assignment): string {
        return [
            assignment.personal_due_at
                ? `Own due date ${fullDate.format(dayOf(assignment.personal_due_at))}`
                : null,
            assignment.personal_status_note,
        ]
            .filter(Boolean)
            .join(' · ');
    }

    function taskCode(t: ProgressTask): string {
        return t.item_number !== null
            ? `${t.project.code}-${t.item_number}`
            : t.project.code;
    }

    const HATCH =
        'repeating-linear-gradient(-45deg, color-mix(in srgb, var(--ws-danger) 55%, transparent) 0 2px, transparent 2px 6px)';
</script>

<svelte:window onkeydown={onKey} />

{#if task && facts}
    <aside
        bind:this={panel}
        tabindex="-1"
        aria-label={`Task details, read-only: ${task.title}`}
        transition:fly={{ x: 40, duration: 180 }}
        class="fixed inset-y-0 right-0 z-40 flex w-[min(460px,94vw)] flex-col border-l border-line bg-surface outline-none"
    >
        <header
            class="flex h-11 shrink-0 items-center gap-2 border-b border-line px-4"
        >
            <span
                class="shrink-0 font-mono text-[11.5px] text-fg-faint tabular-nums"
                >{taskCode(task)}</span
            >
            <span class="min-w-0 flex-1 truncate text-fg-muted"
                >{task.project.title}</span
            >
            <span class="chip">Read-only</span>
            <a
                href={`/workspace/projects/${task.project.slug}/tasks/${task.slug}`}
                use:inertia
                class="btn-ghost"
                title="Open the task itself to change it"
                ><ExternalLink class="h-3.5 w-3.5" />Open</a
            >
            <button
                type="button"
                aria-label="Close"
                class="btn-icon"
                onclick={onclose}><X class="h-4 w-4" /></button
            >
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="space-y-6 px-5 py-5">
                <section>
                    <h2 class="text-[17px] leading-snug font-semibold text-fg">
                        {task.title}
                    </h2>
                    {#if details?.task.title_np}
                        <p class="font-np mt-1 text-fg-muted">
                            {details.task.title_np}
                        </p>
                    {/if}
                    <div
                        class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px]"
                    >
                        <span class="inline-flex items-center gap-1.5"
                            ><StatusGlyph
                                status={task.status}
                            />{statusLabel}</span
                        >
                        <span
                            class="inline-flex items-center gap-1.5 text-fg-muted"
                            ><PriorityBars
                                priority={task.priority as Priority}
                            /><span class="capitalize">{task.priority}</span> priority</span
                        >
                        {#if facts.late > 0}
                            <span class="text-danger"
                                >{plural(facts.late, 'day')} overdue</span
                            >
                        {/if}
                    </div>
                </section>

                <section class="panel p-4">
                    <div class="flex items-center gap-3">
                        <ProgressRing percent={task.progress} size={30} />
                        <div>
                            <div
                                class="font-mono text-[22px] leading-none text-fg tabular-nums"
                            >
                                {task.progress}%
                            </div>
                            <div class="mt-1 text-xs text-fg-muted">
                                {task.is_complete ? 'Finished' : 'Complete'}
                            </div>
                        </div>
                    </div>
                    <div class="relative mt-4 h-6" aria-hidden="true">
                        <div
                            class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-line"
                        ></div>
                        <div
                            class={`absolute top-1/2 h-2.5 -translate-y-1/2 rounded-sm ${facts.tone}`}
                            style:left={`${facts.track.start}%`}
                            style:width={`${facts.track.width}%`}
                        ></div>
                        {#if facts.track.overdue}
                            <div
                                class="absolute top-1/2 h-2.5 -translate-y-1/2 rounded-r-sm bg-danger-soft"
                                style:left={`${facts.track.overdue.left}%`}
                                style:width={`${facts.track.overdue.width}%`}
                                style:background-image={HATCH}
                            ></div>
                        {/if}
                        {#if facts.track.due !== null}
                            <div
                                class="absolute top-0 bottom-0 w-0.5 rounded-sm bg-fg-muted"
                                style:left={`${facts.track.due}%`}
                            ></div>
                        {/if}
                        <div
                            class="absolute -top-1 -bottom-1 w-px bg-accent"
                            style:left={`${facts.track.today}%`}
                        ></div>
                    </div>
                    <div
                        class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-fg-muted"
                    >
                        <span class="inline-flex items-center gap-1"
                            ><span class={`h-1.5 w-3 rounded-sm ${facts.tone}`}
                            ></span>{task.is_complete
                                ? 'Opened to finished'
                                : 'Opened to due'}</span
                        >
                        {#if facts.track.due !== null}
                            <span class="inline-flex items-center gap-1"
                                ><span
                                    class="h-2.5 w-0.5 rounded-sm bg-fg-muted"
                                ></span>Deadline</span
                            >
                        {/if}
                        <span class="inline-flex items-center gap-1"
                            ><span class="h-2.5 w-px bg-accent"
                            ></span>Today</span
                        >
                    </div>
                </section>

                <dl
                    class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[13px]"
                >
                    <dt class="text-fg-muted">Opened</dt>
                    <dd>
                        {facts.opened === null
                            ? 'Unknown'
                            : fullDate.format(facts.opened)}
                    </dd>
                    <dt class="text-fg-muted">Due</dt>
                    <dd>
                        {#if facts.due === null}
                            <span class="text-fg-muted">No deadline</span>
                        {:else}
                            {fullDate.format(facts.due)}
                            {#if facts.dueNote}<span class={facts.dueNote.tone}
                                    >· {facts.dueNote.text}</span
                                >{/if}
                        {/if}
                        {#if details?.task.deadline_label}
                            <span class="block text-xs text-fg-muted"
                                >Plan deadline: {details.task
                                    .deadline_label}</span
                            >
                        {/if}
                    </dd>
                    {#if facts.finished !== null}
                        <dt class="text-fg-muted">Finished</dt>
                        <dd>
                            {fullDate.format(facts.finished)}
                            {#if facts.finishNote}<span
                                    class={facts.finishNote.tone}
                                    >· {facts.finishNote.text}</span
                                >{/if}
                        </dd>
                    {/if}
                    {#if details?.task.status_updated_at}
                        <dt class="text-fg-muted">Status set</dt>
                        <dd>{formatTimeAgo(details.task.status_updated_at)}</dd>
                    {/if}
                    {#if details?.task.category_label}
                        <dt class="text-fg-muted">Category</dt>
                        <dd>{details.task.category_label}</dd>
                    {/if}
                    {#if details?.task.responsible_ministry}
                        <dt class="text-fg-muted">Responsible</dt>
                        <dd>{details.task.responsible_ministry}</dd>
                    {/if}
                </dl>

                {#if details?.task.status_note}
                    <section>
                        <h3 class="section-title mb-1.5">Status note</h3>
                        <p
                            class="border-l-2 border-line pl-3 text-[13px] whitespace-pre-line text-fg"
                        >
                            {details.task.status_note}
                        </p>
                    </section>
                {/if}

                <section>
                    <h3 class="section-title mb-2">
                        People
                        <span class="section-count"
                            >{details?.assignments.length ??
                                task.assignees.length}</span
                        >
                    </h3>
                    {#if details}
                        {#if details.assignments.length === 0}
                            <p class="text-xs text-fg-muted">
                                Nobody is assigned.
                            </p>
                        {/if}
                        <ul class="space-y-3">
                            {#each details.assignments as assignment (assignment.id)}
                                <li class="flex gap-2.5">
                                    <Avatar
                                        name={assignment.member?.name ?? '?'}
                                        size="md"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-baseline gap-2 text-[13px]"
                                        >
                                            <span
                                                class="truncate font-medium text-fg"
                                                >{assignment.member?.name}</span
                                            >
                                            {#if assignment.role}<span
                                                    class="truncate text-xs text-fg-muted"
                                                    >{assignment.role}</span
                                                >{/if}
                                            <span
                                                class="ml-auto shrink-0 font-mono text-xs text-fg-muted tabular-nums"
                                                >{assignment.personal_progress}%</span
                                            >
                                        </div>
                                        <div
                                            class="mt-1 h-1 overflow-hidden rounded-sm bg-surface-alt"
                                        >
                                            <span
                                                class={`block h-full ${task.is_complete ? 'bg-success' : 'bg-accent'}`}
                                                style:width={`${assignment.personal_progress}%`}
                                            ></span>
                                        </div>
                                        {#if personalNote(assignment)}
                                            <p
                                                class="mt-1 text-xs text-fg-muted"
                                            >
                                                {personalNote(assignment)}
                                            </p>
                                        {/if}
                                    </div>
                                </li>
                            {/each}
                        </ul>
                    {:else}
                        <ul class="space-y-2">
                            {#each task.assignees as person (person.id)}
                                <li class="flex items-center gap-2 text-[13px]">
                                    <Avatar name={person.name} size="md" />
                                    {person.name}
                                </li>
                            {/each}
                        </ul>
                    {/if}
                </section>

                {#if !details && !failed}
                    <div class="space-y-2" aria-busy="true">
                        <div
                            class="h-3 w-2/3 animate-pulse rounded-sm bg-surface-alt"
                        ></div>
                        <div
                            class="h-3 w-1/2 animate-pulse rounded-sm bg-surface-alt"
                        ></div>
                        <div
                            class="h-3 w-3/5 animate-pulse rounded-sm bg-surface-alt"
                        ></div>
                    </div>
                {:else if failed && !details}
                    <div class="text-[13px] text-fg-muted">
                        The details could not be loaded.
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => task && load(task.id)}
                            >Try again</button
                        >
                    </div>
                {/if}

                {#if details?.task.description || details?.task.description_np}
                    <section>
                        <h3 class="section-title mb-1.5">Description</h3>
                        {#if details.task.description}
                            <p
                                class="text-[13px] leading-relaxed whitespace-pre-line text-fg"
                            >
                                {details.task.description}
                            </p>
                        {/if}
                        {#if details.task.description_np}
                            <p
                                class="font-np mt-2 text-[13px] leading-relaxed whitespace-pre-line text-fg-muted"
                            >
                                {details.task.description_np}
                            </p>
                        {/if}
                    </section>
                {/if}

                {#if todos.length > 0}
                    <section>
                        <h3 class="section-title mb-2">
                            To-dos
                            <span class="section-count"
                                >{todosDone} of {todos.length} done</span
                            >
                        </h3>
                        <ul class="space-y-1.5">
                            {#each todos.slice(0, 10) as todo (todo.id)}
                                <li class="flex items-start gap-2 text-[13px]">
                                    {#if todo.is_done}
                                        <CircleCheck
                                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-success"
                                        />
                                    {:else}
                                        <Circle
                                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-fg-faint"
                                        />
                                    {/if}
                                    <span
                                        class={todo.is_done
                                            ? 'text-fg-muted line-through'
                                            : 'text-fg'}>{todo.body}</span
                                    >
                                </li>
                            {/each}
                        </ul>
                        {#if todos.length > 10}
                            <p class="mt-1.5 text-xs text-fg-muted">
                                and {todos.length - 10} more
                            </p>
                        {/if}
                    </section>
                {/if}

                {#if details && details.notes.length > 0}
                    <section>
                        <h3 class="section-title mb-2">
                            Notes
                            <span class="section-count"
                                >{details.notes.length}</span
                            >
                        </h3>
                        <ul class="space-y-3">
                            {#each details.notes.slice(0, 5) as note (note.id)}
                                <li class="text-[13px]">
                                    <div
                                        class="flex items-center gap-2 text-xs text-fg-muted"
                                    >
                                        <span class="font-medium text-fg"
                                            >{note.type_label}</span
                                        >
                                        <span>{note.user?.name ?? ''}</span>
                                        <span class="ml-auto text-fg-faint"
                                            >{formatTimeAgo(
                                                note.happened_at ??
                                                    note.created_at,
                                            )}</span
                                        >
                                    </div>
                                    <p
                                        class="mt-1 line-clamp-4 whitespace-pre-line text-fg"
                                    >
                                        {note.body}
                                    </p>
                                </li>
                            {/each}
                        </ul>
                    </section>
                {/if}

                {#if details && details.contacts.length > 0}
                    <section>
                        <h3 class="section-title mb-2">Contacts</h3>
                        <ul class="space-y-2 text-[13px]">
                            {#each details.contacts as contact (contact.id)}
                                <li>
                                    <span class="font-medium text-fg"
                                        >{contact.name}</span
                                    >
                                    {#if contact.role || contact.organization}
                                        <span class="text-fg-muted">
                                            ·
                                            {[
                                                contact.role,
                                                contact.organization,
                                            ]
                                                .filter(Boolean)
                                                .join(', ')}</span
                                        >
                                    {/if}
                                    {#if contact.email || contact.phone}
                                        <div class="text-xs text-fg-muted">
                                            {[contact.email, contact.phone]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    {/if}
                                </li>
                            {/each}
                        </ul>
                    </section>
                {/if}

                {#if details && details.activity.length > 0}
                    <section>
                        <h3 class="section-title mb-2">Recent activity</h3>
                        <ul class="space-y-2">
                            {#each details.activity.slice(0, 8) as entry (entry.id)}
                                <li class="flex gap-2 text-[13px]">
                                    <span class="min-w-0 flex-1">
                                        <span class="font-medium text-fg"
                                            >{entry.user?.name ??
                                                'Someone'}</span
                                        >
                                        <span class="text-fg-muted"
                                            >{entry.description}</span
                                        >
                                    </span>
                                    <span class="shrink-0 text-xs text-fg-faint"
                                        >{formatTimeAgo(entry.created_at)}</span
                                    >
                                </li>
                            {/each}
                        </ul>
                    </section>
                {/if}

                <p class="border-t border-line pt-3 text-xs text-fg-faint">
                    A read-only view for this report. Open the task to change it{#if details && details.comments_count > 0};
                        it has {plural(details.comments_count, 'comment')}{/if}.
                </p>
            </div>
        </div>
    </aside>
{/if}
