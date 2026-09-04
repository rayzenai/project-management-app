<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import {
        ChevronDown,
        ChevronRight,
        Clock,
        Folder,
        Star,
    } from '@lucide/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import AppShell from '../components/AppShell.svelte';
    import AssignmentRow from '../components/AssignmentRow.svelte';
    import ContactChips from '../components/ContactChips.svelte';
    import NotesStrip from '../components/NotesStrip.svelte';
    import OpenTodos from '../components/OpenTodos.svelte';
    import QuickAddBar from '../components/QuickAddBar.svelte';
    import { peek } from '../lib/peek.svelte';
    import { quickAdd } from '../lib/quickAdd.svelte';
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

{#snippet metric(value: number, label: string, danger = false)}
    <div class="px-4 py-3">
        <div
            class={`text-[22px] font-semibold tracking-[-0.02em] tabular-nums ${danger && value > 0 ? 'text-danger' : ''}`}
        >
            {value}
        </div>
        <div class="text-xs text-fg-muted">{label}</div>
    </div>
{/snippet}

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">My Workspace</span>
        </div>
        <div class="flex items-center gap-1.5">
            {#if snoozedCount > 0}
                <span class="chip tabular-nums">{snoozedCount} snoozed</span>
            {/if}
            <button
                type="button"
                class="btn-primary"
                onclick={() => quickAdd.open({})}
            >
                New task
                <kbd class="kbd border-white/30 bg-transparent text-white/80"
                    >N</kbd
                >
            </button>
        </div>
    {/snippet}

    <div class="grid items-start xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0">
            <div class="space-y-4 px-4 pt-4 pb-4 lg:px-6">
                <div
                    class="grid grid-cols-2 divide-x divide-line overflow-hidden rounded-lg border border-line bg-surface-alt sm:grid-cols-3 lg:grid-cols-5"
                >
                    {@render metric(due.length, 'Due soon', true)}
                    {@render metric(focused.length, 'Focused')}
                    {@render metric(others.length, 'Everything else')}
                    {@render metric(openTodos.length, 'Open todos')}
                    {@render metric(snoozedCount, 'Snoozed')}
                </div>

                <QuickAddBar {projects} {team} {currentMemberId} />
            </div>

            {#if allClear}
                <section
                    role="group"
                    aria-label="Focused tasks drop zone"
                    ondragover={(e) => onZoneDragOver('focused', e)}
                    ondragleave={onZoneDragLeave}
                    ondrop={(e) => onZoneDrop('focused', e)}
                    class={`border-t border-line px-4 py-12 text-center transition ${
                        dropZone === 'focused' ? 'bg-accent-soft' : ''
                    }`}
                >
                    <p class="font-medium text-fg">All clear.</p>
                    <p class="mt-1 text-xs text-fg-muted">
                        No open assignments. Press <kbd class="kbd">N</kbd> to add
                        a task.
                    </p>
                </section>
            {:else}
                <section>
                    <div class="group-head">
                        <Clock
                            class={`h-3.5 w-3.5 shrink-0 ${due.length > 0 ? 'text-danger' : 'text-fg-faint'}`}
                        />
                        Due
                        <span class="section-count">{due.length}</span>
                        <span class="ml-auto text-xs font-normal text-fg-faint"
                            >Next {DUE_WINDOW_DAYS} days</span
                        >
                    </div>

                    {#if due.length === 0}
                        <p class="px-4 py-3 text-xs text-fg-muted">
                            Nothing due within {DUE_WINDOW_DAYS} days.
                        </p>
                    {:else}
                        {#each due as a (a.id)}
                            <AssignmentRow assignment={a} lane="due" />
                        {/each}
                    {/if}
                </section>

                <section
                    role="group"
                    aria-label="Focused tasks drop zone"
                    ondragover={(e) => onZoneDragOver('focused', e)}
                    ondragleave={onZoneDragLeave}
                    ondrop={(e) => onZoneDrop('focused', e)}
                    class={`transition ${dropZone === 'focused' ? 'bg-accent-soft' : ''}`}
                >
                    <div class="group-head">
                        <Star class="h-3.5 w-3.5 shrink-0 text-accent" />
                        Focused
                        <span class="section-count">{focused.length}</span>
                        <span class="ml-auto text-xs font-normal text-fg-faint"
                            >Drag here to pin</span
                        >
                    </div>

                    {#if focused.length === 0}
                        <p class="px-4 py-3 text-xs text-fg-muted">
                            Nothing pinned. Drag a task here, or use the star on
                            a row.
                        </p>
                    {:else}
                        {#each focused as a (a.id)}
                            <AssignmentRow assignment={a} lane="focused" />
                        {/each}
                    {/if}
                </section>

                <div class="px-4 py-4 lg:px-6">
                    <OpenTodos todos={openTodos} />
                </div>

                <section
                    role="group"
                    aria-label="Everything else drop zone"
                    ondragover={(e) => onZoneDragOver('others', e)}
                    ondragleave={onZoneDragLeave}
                    ondrop={(e) => onZoneDrop('others', e)}
                    class={`transition ${dropZone === 'others' ? 'bg-hover' : ''}`}
                >
                    <button
                        type="button"
                        class="group-head w-full text-left"
                        aria-expanded={showOthers}
                        onclick={() => (showOthers = !showOthers)}
                    >
                        {#if showOthers}
                            <ChevronDown
                                class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                            />
                        {:else}
                            <ChevronRight
                                class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                            />
                        {/if}
                        Everything else
                        <span class="section-count">{others.length}</span>
                        <span class="ml-auto text-xs font-normal text-fg-faint"
                            >Drag here to unpin</span
                        >
                    </button>

                    {#if showOthers}
                        {#if othersGrouped.length === 0}
                            <p class="px-4 py-3 text-xs text-fg-muted">
                                Everything assigned to you is due soon or
                                pinned.
                            </p>
                        {/if}

                        {#each othersGrouped as group (group.project.id)}
                            <div
                                class="flex items-center gap-2 border-b border-line-soft px-4 py-1.5 text-xs"
                            >
                                <Folder
                                    class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                />
                                <a
                                    href={`/workspace/projects/${group.project.slug}`}
                                    class="truncate font-medium text-fg-muted hover:text-fg"
                                    >{group.project.title}</a
                                >
                                <span class="section-count"
                                    >{group.assignments.length}</span
                                >
                            </div>
                            {#each group.assignments as a (a.id)}
                                <AssignmentRow assignment={a} lane="other" />
                            {/each}
                        {/each}
                    {/if}
                </section>
            {/if}
        </div>

        <aside
            class="border-t border-line xl:sticky xl:top-12 xl:border-t-0 xl:border-l"
        >
            <section class="px-5 py-6 lg:px-6">
                <h2 class="section-title mb-3">
                    My notes
                    <span class="section-count">{stickyNotes.length}</span>
                </h2>
                <NotesStrip {stickyNotes} taskNotes={[]} />
            </section>

            {#if recentNotes.length > 0}
                <section class="border-t border-line px-5 py-6 lg:px-6">
                    <h2 class="section-title mb-3">
                        From my tasks
                        <span class="section-count">{recentNotes.length}</span>
                    </h2>
                    <NotesStrip taskNotes={recentNotes} compose={false} />
                </section>
            {/if}

            <section class="border-t border-line px-5 py-6 lg:px-6">
                <ContactChips contacts={recentContacts} />
            </section>
        </aside>
    </div>
</AppShell>
