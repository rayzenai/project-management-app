<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import AppShell from '../components/AppShell.svelte';
    import AssignmentRow from '../components/AssignmentRow.svelte';
    import ContactChips from '../components/ContactChips.svelte';
    import NotesStrip from '../components/NotesStrip.svelte';
    import OpenTodos from '../components/OpenTodos.svelte';
    import QuickAddBar from '../components/QuickAddBar.svelte';
    import { palette } from '../lib/palette.svelte';
    import { peek } from '../lib/peek.svelte';
    import type {
        Assignment,
        Contact,
        Member,
        Note,
        ProjectSummary,
        SharedProps,
        Subtask,
        Task,
    } from '../lib/types';

    let {
        assignments,
        snoozedCount,
        openTodos,
        recentNotes,
        recentContacts,
        projects,
        team,
    }: {
        assignments: Assignment[];
        snoozedCount: number;
        openTodos: Subtask[];
        recentNotes: Note[];
        recentContacts: Contact[];
        projects: ProjectSummary[];
        team: Member[];
    } = $props();

    const DUE_WINDOW_DAYS = 7;

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const currentMemberId = $derived(
        shared.quickAddContext?.currentMemberId ?? null,
    );
    const stickyNotes = $derived(shared.workspaceNotes ?? []);

    const completeStatuses = $derived(
        new Set(
            (shared.statuses ?? [])
                .filter((s) => s.is_complete)
                .map((s) => s.value),
        ),
    );
    const isComplete = (t: Task) => completeStatuses.has(t.status);

    // The controller already excludes completed tasks; the filter here keeps
    // rows correct mid-flight after an optimistic complete.
    const open = $derived(
        assignments.filter((a) => a.task && !isComplete(a.task)),
    );

    const due = $derived.by(() => {
        const todayStart = new Date(new Date().toDateString());
        const horizon = new Date(
            todayStart.getTime() + DUE_WINDOW_DAYS * 86_400_000,
        );

        return open
            .filter(
                (a) =>
                    a.task!.deadline_at &&
                    new Date(a.task!.deadline_at) <= horizon,
            )
            .toSorted((x, y) =>
                x.task!.deadline_at!.localeCompare(y.task!.deadline_at!),
            );
    });
    const dueTaskIds = $derived(new Set(due.map((a) => a.task_id)));

    const focused = $derived(
        open.filter((a) => a.is_focused && !dueTaskIds.has(a.task_id)),
    );
    const others = $derived(
        open.filter((a) => !a.is_focused && !dueTaskIds.has(a.task_id)),
    );

    const othersGrouped = $derived.by(() => {
        const map = new SvelteMap<
            number,
            { project: ProjectSummary; assignments: Assignment[] }
        >();

        for (const a of others) {
            if (!a.task?.project) {
                continue;
            }

            const p = a.task.project;

            if (!map.has(p.id)) {
                map.set(p.id, {
                    project: { id: p.id, slug: p.slug, title: p.title },
                    assignments: [],
                });
            }

            map.get(p.id)!.assignments.push(a);
        }

        return Array.from(map.values());
    });

    const allClear = $derived(open.length === 0);

    let showOthers = $state(true);
    let dropZone = $state<'focused' | 'others' | null>(null);

    onMount(() => {
        peek.openFromUrl(
            assignments.flatMap((a) =>
                a.task ? [{ id: a.task.id, slug: a.task.slug }] : [],
            ),
        );
    });

    function readPayload(
        event: DragEvent,
    ): { assignmentId: number; isFocused: boolean } | null {
        const raw = event.dataTransfer?.getData(
            'application/x-workspace-assignment',
        );

        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch {
            return null;
        }
    }

    function onZoneDragOver(zone: 'focused' | 'others', event: DragEvent) {
        if (
            !event.dataTransfer?.types.includes(
                'application/x-workspace-assignment',
            )
        ) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        dropZone = zone;
    }

    function onZoneDragLeave(event: DragEvent) {
        const related = event.relatedTarget as Node | null;

        if (related && (event.currentTarget as HTMLElement).contains(related)) {
            return;
        }

        dropZone = null;
    }

    function onZoneDrop(zone: 'focused' | 'others', event: DragEvent) {
        event.preventDefault();
        const payload = readPayload(event);
        dropZone = null;

        if (!payload) {
            return;
        }

        const wantFocused = zone === 'focused';

        if (payload.isFocused === wantFocused) {
            return;
        }

        router.patch(
            `/workspace/assignments/${payload.assignmentId}`,
            { is_focused: wantFocused },
            { preserveScroll: true, preserveState: true },
        );
    }
</script>

<svelte:head><title>My Workspace</title></svelte:head>

<AppShell>
    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0 space-y-6">
            <div class="space-y-2">
                <button
                    type="button"
                    onclick={() => palette.open()}
                    class="flex w-full items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-left text-sm text-fg-faint hover:border-line"
                >
                    <span>⌕</span>
                    <span class="flex-1">Search or jump to anything…</span>
                    <kbd
                        class="rounded border border-line px-1.5 py-0.5 text-[10px] text-fg-muted"
                        >⌘K</kbd
                    >
                </button>
                <QuickAddBar {projects} {team} {currentMemberId} />
            </div>

            {#if allClear}
                <section
                    role="group"
                    aria-label="Focused tasks drop zone"
                    ondragover={(e) => onZoneDragOver('focused', e)}
                    ondragleave={onZoneDragLeave}
                    ondrop={(e) => onZoneDrop('focused', e)}
                    class={`rounded-xl border border-dashed border-accent/40 bg-accent/5 p-10 text-center transition ${
                        dropZone === 'focused' ? 'ring-2 ring-accent/40' : ''
                    }`}
                >
                    <p
                        class="font-display text-2xl font-bold tracking-tight text-accent"
                    >
                        All clear.
                    </p>
                    <p class="mt-2 font-mono text-xs text-accent/70">
                        No open assignments. Press <kbd
                            class="rounded border border-current/30 px-1">q</kbd
                        > to add a task.
                    </p>
                </section>
            {:else}
                <section>
                    <header class="mb-2 flex items-baseline justify-between">
                        <h2
                            class={`ws-eyebrow ${due.length > 0 ? 'text-danger' : 'text-fg-muted'}`}
                        >
                            ⚠ Due
                        </h2>
                        <span class="font-mono text-[11px] text-fg-muted"
                            >{due.length} · next {DUE_WINDOW_DAYS} days</span
                        >
                    </header>

                    {#if due.length === 0}
                        <p class="font-mono text-xs text-fg-muted">
                            ✓ Nothing due within {DUE_WINDOW_DAYS} days
                        </p>
                    {:else}
                        <div class="space-y-2">
                            {#each due as a (a.id)}
                                <AssignmentRow assignment={a} lane="due" />
                            {/each}
                        </div>
                    {/if}
                </section>

                <section
                    role="group"
                    aria-label="Focused tasks drop zone"
                    ondragover={(e) => onZoneDragOver('focused', e)}
                    ondragleave={onZoneDragLeave}
                    ondrop={(e) => onZoneDrop('focused', e)}
                    class={`rounded-xl transition ${dropZone === 'focused' ? 'bg-accent/5 ring-2 ring-accent/40' : ''}`}
                >
                    <header class="mb-2 flex items-baseline justify-between">
                        <h2 class="ws-eyebrow text-accent">★ Focused</h2>
                        <span class="font-mono text-[11px] text-fg-muted"
                            >{focused.length} pinned · drag here to pin</span
                        >
                    </header>

                    {#if focused.length === 0}
                        <div
                            class="rounded-xl border border-dashed border-accent/40 bg-accent/5 p-4 text-center"
                        >
                            <p class="font-mono text-xs text-accent/80">
                                Nothing pinned — drag any task here, or hover
                                and click ☆.
                            </p>
                        </div>
                    {:else}
                        <div class="space-y-2">
                            {#each focused as a (a.id)}
                                <AssignmentRow assignment={a} lane="focused" />
                            {/each}
                        </div>
                    {/if}
                </section>

                <OpenTodos todos={openTodos} />

                <section
                    role="group"
                    aria-label="Everything else drop zone"
                    ondragover={(e) => onZoneDragOver('others', e)}
                    ondragleave={onZoneDragLeave}
                    ondrop={(e) => onZoneDrop('others', e)}
                    class={`rounded-xl transition ${dropZone === 'others' ? 'bg-surface-alt ring-2 ring-line' : ''}`}
                >
                    <header class="mb-2 flex items-baseline justify-between">
                        <button
                            type="button"
                            class="ws-eyebrow text-fg-muted hover:text-fg"
                            onclick={() => (showOthers = !showOthers)}
                        >
                            {showOthers ? '▾' : '▸'} Everything else
                        </button>
                        <span class="font-mono text-[11px] text-fg-muted"
                            >{others.length} task{others.length === 1
                                ? ''
                                : 's'} · drag here to unpin</span
                        >
                    </header>

                    {#if showOthers}
                        {#if othersGrouped.length === 0}
                            <p
                                class="rounded-xl border border-dashed border-line bg-surface p-4 text-center font-mono text-xs text-fg-muted"
                            >
                                Everything assigned to you is due soon or
                                pinned.
                            </p>
                        {/if}

                        <div class="space-y-5">
                            {#each othersGrouped as group (group.project.id)}
                                <div>
                                    <h3
                                        class="mb-2 font-mono text-[11px] font-semibold tracking-wider text-fg-muted uppercase"
                                    >
                                        <a
                                            href={`/workspace/projects/${group.project.slug}`}
                                            class="hover:text-accent"
                                            >{group.project.title}</a
                                        >
                                        <span class="ml-1 text-fg-faint"
                                            >· {group.assignments.length}</span
                                        >
                                    </h3>
                                    <div class="space-y-2">
                                        {#each group.assignments as a (a.id)}
                                            <AssignmentRow
                                                assignment={a}
                                                lane="other"
                                            />
                                        {/each}
                                    </div>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </section>
            {/if}
        </div>

        <aside class="space-y-6 xl:sticky xl:top-20">
            <section class="rounded-xl border border-line bg-surface p-3">
                <h2 class="ws-eyebrow mb-2 text-fg-muted">Notes</h2>
                <NotesStrip {stickyNotes} taskNotes={recentNotes} />
            </section>

            <section class="rounded-xl border border-line bg-surface p-3">
                <ContactChips contacts={recentContacts} />
            </section>

            {#if snoozedCount > 0}
                <p class="px-1 font-mono text-[11px] text-fg-muted">
                    💤 {snoozedCount} snoozed
                </p>
            {/if}
        </aside>
    </div>
</AppShell>
