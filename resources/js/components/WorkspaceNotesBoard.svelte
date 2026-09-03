<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Link2, StickyNote } from '@lucide/svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import { formatDate } from '../lib/format';
    import {
        NOTE_COLORS as COLORS,
        noteTypeColor,
        paperClass,
        swatchClass,
        tilt,
    } from '../lib/noteColors';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import type {
        Note,
        SharedProps,
        WorkspaceNote,
        WorkspaceNoteColor,
    } from '../lib/types';

    let { open = false, onClose }: { open?: boolean; onClose: () => void } =
        $props();

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const notes = $derived(shared.workspaceNotes ?? []);
    // Read-only task-anchored notes — shown alongside the draggable stickies so
    // the board surfaces every note the user authored, not just sticky notes.
    const taskNotes = $derived(shared.taskNotes ?? []);

    function taskNoteHref(note: Note): string | null {
        const task = note.task;

        if (!task?.project?.slug || !task.slug) {
            return null;
        }

        return `/workspace/projects/${task.project.slug}/tasks/${task.slug}?tab=notes`;
    }

    // Task notes have no saved position, so scatter them across the canvas
    // (deterministically, by id) — free-flowing like the stickies rather than
    // lined up. They sit beneath the draggable stickies so your own notes stay
    // grabbable.
    const TASK_CARD_W = 160;
    const TASK_CARD_H = 96;
    const TASK_MARGIN = 24;

    let viewport = $state({ w: 1200, h: 800 });
    $effect(() => {
        const measure = () =>
            (viewport = { w: window.innerWidth, h: window.innerHeight });
        measure();
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    });

    function scatter(id: number): { x: number; y: number } {
        const rand = (seed: number) => {
            const v = Math.sin(id * seed) * 43758.5453;

            return v - Math.floor(v);
        };

        return {
            x:
                TASK_MARGIN +
                rand(12.9898) *
                    Math.max(0, viewport.w - TASK_CARD_W - TASK_MARGIN * 2),
            y:
                84 +
                rand(78.233) *
                    Math.max(0, viewport.h - TASK_CARD_H - 84 - TASK_MARGIN),
        };
    }

    // Arrange / sort modes. "free" is the draggable canvas; the others switch to
    // a tidy, scannable grid (dragging off) grouped or sorted accordingly.
    type ArrangeMode = 'free' | 'task' | 'title' | 'type';
    const ARRANGE_OPTIONS: { value: ArrangeMode; label: string }[] = [
        { value: 'free', label: 'Free layout' },
        { value: 'task', label: 'Group by task' },
        { value: 'title', label: 'Sort by title' },
        { value: 'type', label: 'Sort by type' },
    ];
    let arrange = $state<ArrangeMode>('free');

    type ArrangeItem = { key: string; workspace?: WorkspaceNote; task?: Note };

    const arranged = $derived.by(
        (): { label: string; items: ArrangeItem[] }[] => {
            if (arrange === 'free') {
                return [];
            }

            const items: ArrangeItem[] = [
                ...notes.map((n) => ({ key: `w-${n.id}`, workspace: n })),
                ...taskNotes.map((n) => ({ key: `t-${n.id}`, task: n })),
            ];
            const text = (it: ArrangeItem) =>
                (it.workspace
                    ? it.workspace.title || it.workspace.body
                    : (it.task?.body ?? '')
                ).toLowerCase();

            if (arrange === 'title') {
                return [
                    {
                        label: '',
                        items: [...items].sort((a, b) =>
                            text(a).localeCompare(text(b)),
                        ),
                    },
                ];
            }

            const groups = new SvelteMap<string, ArrangeItem[]>();

            for (const it of items) {
                const label =
                    arrange === 'task'
                        ? it.task
                            ? it.task.task?.short_title ||
                              it.task.task?.title ||
                              'Untagged'
                            : 'My notes'
                        : it.task
                          ? it.task.type_label
                          : 'Sticky note';
                const bucket = groups.get(label);

                if (bucket) {
                    bucket.push(it);
                } else {
                    groups.set(label, [it]);
                }
            }

            return [...groups.entries()]
                .sort((a, b) => a[0].localeCompare(b[0]))
                .map(([label, items]) => ({ label, items }));
        },
    );

    // Local position overrides during/after a drag (id -> {x, y}).
    let pos = $state<Record<number, { x: number; y: number }>>({});
    function coords(note: WorkspaceNote): { x: number; y: number } {
        return pos[note.id] ?? { x: note.position_x, y: note.position_y };
    }

    // Task-note placement: draggable like stickies, but persisted client-side
    // (project_notes has no position columns) so an arrangement survives reloads
    // on this browser. Falls back to the deterministic scatter when unset.
    const TASK_POS_KEY = 'ws:taskNotePos';
    function loadTaskPos(): Record<number, { x: number; y: number }> {
        if (typeof localStorage === 'undefined') {
            return {};
        }

        try {
            return JSON.parse(localStorage.getItem(TASK_POS_KEY) ?? '{}');
        } catch {
            return {};
        }
    }
    let taskPos =
        $state<Record<number, { x: number; y: number }>>(loadTaskPos());
    function taskCoords(note: Note): { x: number; y: number } {
        return taskPos[note.id] ?? scatter(note.id);
    }

    let expandedId = $state<number | null>(null);
    let composing = $state(false);
    // True when the composer is the reason the board opened (opened via
    // `notesBoard.show({ compose: true })`, e.g. the workspace notes strip's
    // "New note"). In that case the user never asked for the full board, so
    // dismissing the composer must close the board too — see cancelCompose().
    let openedViaCompose = $state(false);
    let editingId = $state<number | null>(null);
    let saving = $state(false);

    let draftTitle = $state('');
    let draftBody = $state('');

    // Drag bookkeeping.
    let dragId = $state<number | null>(null);
    let startX = 0;
    let startY = 0;
    let originX = 0;
    let originY = 0;
    let moved = false;

    // Task-note drag bookkeeping (mirrors the sticky drag; persisted to localStorage).
    let taskDragId = $state<number | null>(null);
    let taskStartX = 0;
    let taskStartY = 0;
    let taskOriginX = 0;
    let taskOriginY = 0;
    let taskMoved = false;

    function beginTaskDrag(note: Note, e: PointerEvent) {
        if (e.button !== 0) {
            return;
        }

        // The link (icon + task name) navigates; the rest of the card drags.
        if ((e.target as HTMLElement)?.closest('[data-task-link]')) {
            return;
        }

        const p = taskCoords(note);
        taskDragId = note.id;
        taskStartX = e.clientX;
        taskStartY = e.clientY;
        taskOriginX = p.x;
        taskOriginY = p.y;
        taskMoved = false;
    }

    const visitOptions = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            saving = true;
        },
        onFinish: () => {
            saving = false;
        },
    };

    function clampX(x: number): number {
        const max =
            (typeof window !== 'undefined' ? window.innerWidth : 1200) - 70;

        return Math.max(8, Math.min(x, max));
    }
    function clampY(y: number): number {
        const max =
            (typeof window !== 'undefined' ? window.innerHeight : 800) - 70;

        return Math.max(64, Math.min(y, max));
    }

    function beginDrag(note: WorkspaceNote, e: PointerEvent) {
        if (e.button !== 0) {
            return;
        }

        if ((e.target as HTMLElement)?.closest('[data-no-drag]')) {
            return;
        }

        const p = coords(note);
        dragId = note.id;
        startX = e.clientX;
        startY = e.clientY;
        originX = p.x;
        originY = p.y;
        moved = false;
    }

    function onPointerMove(e: PointerEvent) {
        if (dragId !== null) {
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;

            if (Math.abs(dx) + Math.abs(dy) > 4) {
                moved = true;
            }

            pos = {
                ...pos,
                [dragId]: { x: clampX(originX + dx), y: clampY(originY + dy) },
            };

            return;
        }

        if (taskDragId !== null) {
            const dx = e.clientX - taskStartX;
            const dy = e.clientY - taskStartY;

            if (Math.abs(dx) + Math.abs(dy) > 4) {
                taskMoved = true;
            }

            taskPos = {
                ...taskPos,
                [taskDragId]: {
                    x: clampX(taskOriginX + dx),
                    y: clampY(taskOriginY + dy),
                },
            };
        }
    }

    function onPointerUp() {
        if (dragId !== null) {
            const id = dragId;
            dragId = null;

            if (moved) {
                const p = pos[id];

                if (p) {
                    router.patch(
                        `/workspace/my-notes/${id}/placement`,
                        {
                            position_x: Math.round(p.x),
                            position_y: Math.round(p.y),
                        },
                        visitOptions,
                    );
                }
            } else if (expandedId !== id) {
                expandedId = id;
            }

            return;
        }

        if (taskDragId !== null) {
            taskDragId = null;

            if (taskMoved && typeof localStorage !== 'undefined') {
                try {
                    localStorage.setItem(TASK_POS_KEY, JSON.stringify(taskPos));
                } catch {
                    /* ignore quota / serialization errors */
                }
            }
        }
    }

    function startCompose() {
        draftTitle = '';
        draftBody = '';
        composing = true;
        // Toolbar "+ New note" composes inside an already-open board, so backing
        // out returns to the board, not all the way out.
        openedViaCompose = false;
    }

    // Back out of the composer. If the board was opened purely to compose, this
    // closes the board as well so the user lands back where they started rather
    // than being stranded on an overlay they never opened.
    function cancelCompose() {
        composing = false;

        if (openedViaCompose) {
            onClose();
        }
    }

    function createNote() {
        if (!draftBody.trim() || saving) {
            return;
        }

        router.post(
            '/workspace/my-notes',
            { title: draftTitle.trim(), body: draftBody.trim() },
            {
                ...visitOptions,
                onSuccess: () => {
                    composing = false;

                    // Mirror cancel: a compose-only board session ends on save.
                    if (openedViaCompose) {
                        onClose();
                    }
                },
            },
        );
    }

    function startEdit(note: WorkspaceNote) {
        draftTitle = note.title ?? '';
        draftBody = note.body;
        editingId = note.id;
    }

    function saveEdit(note: WorkspaceNote) {
        if (!draftBody.trim() || saving) {
            return;
        }

        router.patch(
            `/workspace/my-notes/${note.id}`,
            { title: draftTitle.trim(), body: draftBody.trim() },
            {
                ...visitOptions,
                onSuccess: () => {
                    editingId = null;
                },
            },
        );
    }

    function recolor(note: WorkspaceNote, color: WorkspaceNoteColor) {
        if (note.color === color) {
            return;
        }

        router.patch(
            `/workspace/my-notes/${note.id}/placement`,
            { color },
            visitOptions,
        );
    }

    function deleteNote(note: WorkspaceNote) {
        if (!confirm('Delete this note?')) {
            return;
        }

        router.delete(`/workspace/my-notes/${note.id}`, {
            ...visitOptions,
            onSuccess: () => {
                if (expandedId === note.id) {
                    expandedId = null;
                }
            },
        });
    }

    function collapse() {
        expandedId = null;
        editingId = null;
    }

    // From a tidy/arranged card, jump back to the freeform canvas with the note
    // expanded so it can be edited/recoloured in place.
    function openWorkspaceNote(note: WorkspaceNote) {
        arrange = 'free';
        expandedId = note.id;
    }

    function onWindowKey(event: KeyboardEvent) {
        if (event.key !== 'Escape' || !open) {
            return;
        }

        event.preventDefault();

        // Compose is a modal sub-state, so ESC backs out of it first. Otherwise
        // ESC closes the board in a single press (no collapse-then-close).
        if (composing) {
            cancelCompose();
        } else {
            onClose();
        }
    }

    $effect(() => {
        if (!open) {
            expandedId = null;
            editingId = null;
            composing = false;
            openedViaCompose = false;

            return;
        }

        // Apply the intent the opener requested (focus a note / start composing).
        if (notesBoard.compose) {
            composing = true;
            openedViaCompose = true;
        } else if (notesBoard.focusId != null) {
            expandedId = notesBoard.focusId;
        }
    });
</script>

<svelte:window
    onkeydown={onWindowKey}
    onpointermove={onPointerMove}
    onpointerup={onPointerUp}
/>

{#if open}
    <div class="fixed inset-0 z-50 bg-black/30 backdrop-blur-[2px] select-none">
        <!-- Clicking the dimmed backdrop (outside any note) closes in one click. -->
        <button
            type="button"
            aria-label="Close notes board"
            class="absolute inset-0 h-full w-full cursor-default"
            onclick={onClose}
        ></button>

        <!-- toolbar -->
        <div
            class="pointer-events-none absolute inset-x-0 top-0 z-10 flex items-center justify-between px-4 py-3"
        >
            <div
                class="bg-surface/90 pointer-events-auto flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-semibold shadow-sm ring-1 ring-line"
            >
                <StickyNote class="h-4 w-4" />
                <span>My notes</span>
                <span
                    class="bg-surface-alt text-fg-muted rounded-full px-2 py-0.5 text-xs font-medium"
                    >{notes.length + taskNotes.length}</span
                >
            </div>
            <div class="pointer-events-auto flex items-center gap-2">
                <label
                    class="bg-surface/90 flex items-center gap-1.5 rounded-full py-1.5 pr-2 pl-3 text-sm shadow-sm ring-1 ring-line"
                >
                    <span class="text-fg-faint text-xs font-medium"
                        >Arrange</span
                    >
                    <select
                        bind:value={arrange}
                        class="text-fg-muted focus:text-fg cursor-pointer bg-transparent text-sm font-medium outline-none"
                        aria-label="Arrange notes"
                    >
                        {#each ARRANGE_OPTIONS as opt (opt.value)}
                            <option value={opt.value}>{opt.label}</option>
                        {/each}
                    </select>
                </label>
                <button
                    type="button"
                    onclick={startCompose}
                    class="bg-accent text-bg hover:bg-accent-dim rounded-full px-4 py-1.5 text-sm font-semibold shadow-sm"
                    >+ New note</button
                >
                <button
                    type="button"
                    onclick={onClose}
                    aria-label="Close"
                    class="bg-surface/90 text-fg-muted hover:text-fg rounded-full p-2 shadow-sm ring-1 ring-line"
                    >✕</button
                >
            </div>
        </div>

        {#if notes.length === 0 && taskNotes.length === 0 && !composing}
            <div
                class="pointer-events-none absolute inset-0 flex items-center justify-center"
            >
                <p
                    class="bg-surface/80 text-fg-muted rounded-xl px-4 py-2 text-sm shadow-sm"
                >
                    No notes yet — tap “+ New note” to pin one.
                </p>
            </div>
        {/if}

        <!-- notes -->
        {#if arrange === 'free'}
            {#each notes as note (note.id)}
                {@const p = coords(note)}
                {@const isExpanded = expandedId === note.id}
                {@const isDragging = dragId === note.id}
                <div
                    class="absolute touch-none"
                    style:left={`${p.x}px`}
                    style:top={`${p.y}px`}
                    style:transform={`rotate(${isExpanded || isDragging ? 0 : tilt(note.id)}deg)`}
                    style:z-index={isExpanded ? 40 : isDragging ? 35 : 20}
                    style:transition={isDragging
                        ? 'none'
                        : 'transform 150ms ease, width 150ms ease'}
                    onpointerdown={(e) => beginDrag(note, e)}
                    role="button"
                    tabindex="-1"
                >
                    <div
                        class={`flex flex-col rounded-md border shadow-lg ${paperClass[note.color]} ${isExpanded ? 'w-72 cursor-default p-3' : 'w-44 cursor-grab p-2.5 active:cursor-grabbing'}`}
                    >
                        {#if isExpanded}
                            <div class="mb-1 flex items-center justify-between">
                                <div
                                    class="flex items-center gap-1"
                                    data-no-drag
                                >
                                    {#each COLORS as c (c)}
                                        <button
                                            type="button"
                                            aria-label={`Colour ${c}`}
                                            onclick={() => recolor(note, c)}
                                            class={`h-4 w-4 rounded-full ring-1 ring-black/10 transition ${swatchClass[c]} ${note.color === c ? 'ring-2 ring-neutral-900/50 dark:ring-white/60' : ''}`}
                                        ></button>
                                    {/each}
                                </div>
                                <button
                                    type="button"
                                    data-no-drag
                                    onclick={collapse}
                                    aria-label="Collapse note"
                                    class="rounded p-0.5 text-neutral-600/70 hover:bg-black/10 hover:text-neutral-900 dark:text-neutral-200/70"
                                    >⤡</button
                                >
                            </div>

                            {#if editingId === note.id}
                                <input
                                    type="text"
                                    data-no-drag
                                    bind:value={draftTitle}
                                    placeholder="Heading (optional)"
                                    class="mb-2 w-full rounded border border-black/10 bg-white/70 px-2 py-1.5 text-sm font-semibold outline-none focus:border-black/30 dark:bg-neutral-900/40 dark:text-neutral-100"
                                />
                                <textarea
                                    data-no-drag
                                    bind:value={draftBody}
                                    rows="6"
                                    class="w-full resize-y rounded border border-black/10 bg-white/70 px-2 py-1.5 text-sm outline-none focus:border-black/30 dark:bg-neutral-900/40 dark:text-neutral-100"
                                ></textarea>
                                <div
                                    class="mt-2 flex justify-end gap-2"
                                    data-no-drag
                                >
                                    <button
                                        type="button"
                                        onclick={() => (editingId = null)}
                                        class="rounded px-2 py-1 text-xs font-medium text-neutral-700 hover:bg-black/10 dark:text-neutral-200"
                                        >Cancel</button
                                    >
                                    <button
                                        type="button"
                                        onclick={() => saveEdit(note)}
                                        disabled={!draftBody.trim() || saving}
                                        class="rounded bg-neutral-900 px-2.5 py-1 text-xs font-semibold text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-white dark:text-neutral-900"
                                        >Save</button
                                    >
                                </div>
                            {:else}
                                {#if note.title}
                                    <h3
                                        class="mb-1 text-sm font-bold text-neutral-900 dark:text-neutral-50"
                                        data-no-drag
                                    >
                                        {note.title}
                                    </h3>
                                {/if}
                                <p
                                    class="max-h-[40vh] overflow-y-auto text-sm leading-relaxed break-words whitespace-pre-wrap text-neutral-800 dark:text-neutral-100"
                                    data-no-drag
                                >
                                    {note.body}
                                </p>
                                <div
                                    class="mt-2 flex items-center justify-between border-t border-black/10 pt-1.5 dark:border-white/10"
                                    data-no-drag
                                >
                                    <span
                                        class="text-[10px] text-neutral-600/80 dark:text-neutral-300/70"
                                        >{formatDate(note.updated_at)}</span
                                    >
                                    <div class="flex items-center gap-1">
                                        <button
                                            type="button"
                                            onclick={() => startEdit(note)}
                                            class="rounded px-2 py-0.5 text-xs font-medium text-neutral-700 hover:bg-black/10 dark:text-neutral-200"
                                            >Edit</button
                                        >
                                        <button
                                            type="button"
                                            onclick={() => deleteNote(note)}
                                            class="rounded px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-500/15 dark:text-red-300"
                                            >Delete</button
                                        >
                                    </div>
                                </div>
                            {/if}
                        {:else}
                            {#if note.title}
                                <span
                                    class="mb-0.5 line-clamp-2 text-sm font-bold text-neutral-900 dark:text-neutral-50"
                                    >{note.title}</span
                                >
                            {/if}
                            <span
                                class="line-clamp-4 text-xs leading-snug break-words whitespace-pre-wrap text-neutral-800 dark:text-neutral-100"
                                >{note.body}</span
                            >
                            <span
                                class="mt-1.5 text-[10px] text-neutral-600/70 dark:text-neutral-300/60"
                                >{formatDate(note.updated_at)}</span
                            >
                        {/if}
                    </div>
                </div>
            {/each}

            <!-- Task-anchored notes blended onto the canvas: read-only (mutating them
             would touch project_notes), tagged with a link icon + "on: <task>" so
             they're distinguishable from your draggable stickies. Click opens the
             task's Notes tab. -->
            {#each taskNotes as note (`t-${note.id}`)}
                {@const p = taskCoords(note)}
                {@const href = taskNoteHref(note)}
                {@const isTaskDragging = taskDragId === note.id}
                <div
                    class={`absolute z-10 flex h-24 w-40 cursor-grab touch-none flex-col overflow-hidden rounded-md border p-2 text-left shadow-md active:cursor-grabbing ${paperClass[noteTypeColor(note.type)]}`}
                    style:left={`${p.x}px`}
                    style:top={`${p.y}px`}
                    style:transform={`rotate(${isTaskDragging ? 0 : tilt(note.id)}deg)`}
                    style:z-index={isTaskDragging ? 30 : 10}
                    style:transition={isTaskDragging
                        ? 'none'
                        : 'transform 150ms ease'}
                    onpointerdown={(e) => beginTaskDrag(note, e)}
                    role="button"
                    tabindex="-1"
                >
                    <svelte:element
                        this={href ? 'a' : 'div'}
                        {href}
                        data-task-link
                        title={href ? 'Open task notes' : note.type_label}
                        class="mb-0.5 inline-flex w-fit items-center gap-1 text-[9px] font-bold tracking-wider text-neutral-700/80 uppercase hover:text-neutral-900 dark:text-neutral-200/80 dark:hover:text-white"
                    >
                        <Link2 class="h-3 w-3 shrink-0" />
                        <span class="line-clamp-1">{note.type_label}</span>
                    </svelte:element>
                    <span
                        class="line-clamp-3 flex-1 text-[11px] leading-snug text-neutral-800 dark:text-neutral-100"
                        >{note.body}</span
                    >
                    {#if note.task}
                        <svelte:element
                            this={href ? 'a' : 'span'}
                            {href}
                            data-task-link
                            class="mt-0.5 line-clamp-1 w-fit text-[10px] font-medium text-neutral-700/70 hover:underline dark:text-neutral-200/70"
                        >
                            on: {note.task.short_title || note.task.title}
                        </svelte:element>
                    {/if}
                </div>
            {/each}
        {:else}
            <!-- Tidy, scannable layout: dragging off; notes grouped/sorted by the
                 chosen Arrange mode. Workspace notes open back on the canvas to edit. -->
            <div
                class="absolute inset-x-0 top-16 bottom-0 overflow-y-auto px-4 pb-6"
            >
                <div class="pointer-events-auto mx-auto max-w-5xl space-y-6">
                    {#each arranged as group (group.label || 'all')}
                        <div>
                            {#if group.label}
                                <div class="mb-2 flex items-center gap-2">
                                    <h3 class="ws-eyebrow text-fg-muted">
                                        {group.label}
                                    </h3>
                                    <span
                                        class="bg-surface-alt text-fg-muted rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                        >{group.items.length}</span
                                    >
                                </div>
                            {/if}
                            <div
                                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                            >
                                {#each group.items as it (it.key)}
                                    {#if it.workspace}
                                        {@const note = it.workspace}
                                        <div
                                            class={`flex h-36 flex-col rounded-md border p-3 shadow-sm ${paperClass[note.color]}`}
                                        >
                                            {#if note.title}
                                                <span
                                                    class="mb-0.5 line-clamp-1 text-sm font-bold text-neutral-900 dark:text-neutral-50"
                                                    >{note.title}</span
                                                >
                                            {/if}
                                            <span
                                                class="line-clamp-4 flex-1 text-xs leading-snug break-words whitespace-pre-wrap text-neutral-800 dark:text-neutral-100"
                                                >{note.body}</span
                                            >
                                            <div
                                                class="mt-1.5 flex items-center justify-between border-t border-black/10 pt-1 dark:border-white/10"
                                            >
                                                <span
                                                    class="text-[10px] text-neutral-600/70 dark:text-neutral-300/60"
                                                    >{formatDate(
                                                        note.updated_at,
                                                    )}</span
                                                >
                                                <div
                                                    class="flex items-center gap-1"
                                                >
                                                    <button
                                                        type="button"
                                                        onclick={() =>
                                                            openWorkspaceNote(
                                                                note,
                                                            )}
                                                        class="rounded px-1.5 py-0.5 text-[11px] font-medium text-neutral-700 hover:bg-black/10 dark:text-neutral-200"
                                                        >Open</button
                                                    >
                                                    <button
                                                        type="button"
                                                        onclick={() =>
                                                            deleteNote(note)}
                                                        class="rounded px-1.5 py-0.5 text-[11px] font-medium text-red-700 hover:bg-red-500/15 dark:text-red-300"
                                                        >Delete</button
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                    {:else if it.task}
                                        {@const note = it.task}
                                        {@const href = taskNoteHref(note)}
                                        <svelte:element
                                            this={href ? 'a' : 'div'}
                                            {href}
                                            title={note.body}
                                            class={`flex h-36 flex-col overflow-hidden rounded-md border p-3 shadow-sm transition hover:shadow-md ${paperClass[noteTypeColor(note.type)]}`}
                                        >
                                            <div
                                                class="mb-0.5 flex items-center gap-1 text-[9px] font-bold tracking-wider text-neutral-700/80 uppercase dark:text-neutral-200/80"
                                            >
                                                <Link2
                                                    class="h-3 w-3 shrink-0"
                                                />
                                                <span class="line-clamp-1"
                                                    >{note.type_label}</span
                                                >
                                            </div>
                                            <span
                                                class="line-clamp-4 flex-1 text-xs leading-snug text-neutral-800 dark:text-neutral-100"
                                                >{note.body}</span
                                            >
                                            {#if note.task}
                                                <span
                                                    class="mt-1 line-clamp-1 text-[10px] font-medium text-neutral-700/70 dark:text-neutral-200/70"
                                                >
                                                    on: {note.task
                                                        .short_title ||
                                                        note.task.title}
                                                </span>
                                            {/if}
                                        </svelte:element>
                                    {/if}
                                {/each}
                            </div>
                        </div>
                    {/each}
                </div>
            </div>
        {/if}

        <!-- compose -->
        {#if composing}
            <div
                class="absolute inset-0 z-50 flex items-center justify-center p-4"
            >
                <div
                    class="bg-surface w-full max-w-sm rounded-xl border border-line p-4 shadow-2xl"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold">New note</h3>
                        <button
                            type="button"
                            onclick={cancelCompose}
                            aria-label="Cancel"
                            class="text-fg-faint hover:bg-surface-alt hover:text-fg rounded-md p-1"
                            >✕</button
                        >
                    </div>
                    <form
                        onsubmit={(e) => {
                            e.preventDefault();
                            createNote();
                        }}
                        class="flex flex-col gap-2"
                    >
                        <input
                            type="text"
                            bind:value={draftTitle}
                            placeholder="Heading (optional)"
                            class="bg-surface-alt placeholder:text-fg-faint focus:border-accent w-full rounded-lg border border-line px-3 py-2 text-sm font-medium outline-none"
                        />
                        <textarea
                            bind:value={draftBody}
                            placeholder="Write a note…"
                            rows="5"
                            class="bg-surface-alt placeholder:text-fg-faint focus:border-accent w-full resize-y rounded-lg border border-line px-3 py-2 text-sm outline-none"
                        ></textarea>
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                onclick={cancelCompose}
                                class="text-fg-muted hover:bg-surface-alt rounded-lg px-3 py-1.5 text-sm font-medium"
                                >Cancel</button
                            >
                            <button
                                type="submit"
                                disabled={!draftBody.trim() || saving}
                                class="bg-accent text-bg hover:bg-accent-dim rounded-lg px-4 py-1.5 text-sm font-semibold shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                                >Add note</button
                            >
                        </div>
                    </form>
                </div>
            </div>
        {/if}
    </div>
{/if}
