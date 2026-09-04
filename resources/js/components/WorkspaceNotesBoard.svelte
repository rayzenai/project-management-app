<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Link2, Minimize2, Plus, StickyNote, X } from '@lucide/svelte';
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
    // Read-only task-anchored notes, shown alongside the draggable stickies so
    // the board surfaces every note the user authored, not just sticky notes.
    const taskNotes = $derived(shared.taskNotes ?? []);

    // The paper card shell shared by every sticky on the board.
    const PAPER = 'rounded-md border shadow-[0_1px_2px_rgba(0,0,0,0.08)]';

    function taskNoteHref(note: Note): string | null {
        const task = note.task;

        if (!task?.project?.slug || !task.slug) {
            return null;
        }

        return `/workspace/projects/${task.project.slug}/tasks/${task.slug}?tab=notes`;
    }

    // Task notes have no saved position, so scatter them across the canvas
    // (deterministically, by id), free-flowing like the stickies rather than
    // lined up. They sit beneath the draggable stickies so your own notes stay
    // grabbable.
    const TASK_CARD_W = 160;
    const TASK_CARD_H = 96;
    const TASK_MARGIN = 24;

    // Positions are relative to the drawer's canvas, so clamps and the scatter
    // measure the canvas element (falling back to the window before it mounts).
    let canvasEl = $state<HTMLDivElement | null>(null);
    let viewport = $state({ w: 720, h: 800 });
    $effect(() => {
        const el = canvasEl;
        const measure = () =>
            (viewport = {
                w: el?.clientWidth ?? window.innerWidth,
                h: el?.clientHeight ?? window.innerHeight,
            });
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
                TASK_MARGIN +
                rand(78.233) *
                    Math.max(0, viewport.h - TASK_CARD_H - TASK_MARGIN * 2),
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
    // dismissing the composer must close the board too; see cancelCompose().
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
        return Math.max(8, Math.min(x, viewport.w - 70));
    }
    function clampY(y: number): number {
        return Math.max(8, Math.min(y, viewport.h - 70));
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
        // The toolbar's "New note" composes inside an already-open board, so
        // backing out returns to the board, not all the way out.
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

{#snippet taskLabel(note: Note, href: string | null)}
    <svelte:element
        this={href ? 'a' : 'div'}
        {href}
        data-task-link
        title={href ? 'Open task notes' : note.type_label}
        class="mb-0.5 inline-flex w-fit items-center gap-1 text-[11px] font-medium text-fg-muted hover:text-fg"
    >
        <Link2 class="h-3 w-3 shrink-0" />
        <span class="line-clamp-1">{note.type_label}</span>
    </svelte:element>
{/snippet}

{#if open}
    <div class="fixed inset-0 z-50 select-none">
        <!-- Clicking the dimmed backdrop (outside the drawer) closes in one click. -->
        <button
            type="button"
            aria-label="Close notes board"
            class="absolute inset-0 h-full w-full cursor-default bg-black/40"
            onclick={onClose}
        ></button>

        <div
            class="fixed inset-y-0 right-0 flex w-[min(720px,94vw)] flex-col border-l border-line bg-surface"
            role="dialog"
            aria-label="Sticky notes"
            tabindex="-1"
        >
            <!-- toolbar -->
            <div
                class="flex h-11 shrink-0 items-center gap-2 border-b border-line px-4"
            >
                <StickyNote class="h-[15px] w-[15px] shrink-0 text-fg-faint" />
                <span class="section-title">
                    Sticky notes
                    <span class="section-count"
                        >{notes.length + taskNotes.length}</span
                    >
                </span>
                <div class="ml-auto flex items-center gap-1.5">
                    <label
                        class="flex items-center gap-1.5 text-xs text-fg-muted"
                    >
                        Arrange
                        <select
                            bind:value={arrange}
                            class="input h-7 w-auto py-0"
                            aria-label="Arrange notes"
                        >
                            {#each ARRANGE_OPTIONS as opt (opt.value)}
                                <option value={opt.value}>{opt.label}</option>
                            {/each}
                        </select>
                    </label>
                    <button type="button" onclick={startCompose} class="btn">
                        <Plus class="h-3.5 w-3.5" />
                        New note
                    </button>
                    <button
                        type="button"
                        onclick={onClose}
                        aria-label="Close"
                        class="btn-icon"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- canvas -->
            <div
                bind:this={canvasEl}
                class="relative min-h-0 flex-1 overflow-auto bg-surface-alt"
            >
                {#if notes.length === 0 && taskNotes.length === 0 && !composing}
                    <div
                        class="absolute inset-0 flex flex-col items-center justify-center gap-3"
                    >
                        <p class="text-fg-muted">No notes yet.</p>
                        <button
                            type="button"
                            onclick={startCompose}
                            class="btn"
                        >
                            <Plus class="h-3.5 w-3.5" />
                            New note
                        </button>
                    </div>
                {/if}

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
                            style:z-index={isExpanded
                                ? 40
                                : isDragging
                                  ? 35
                                  : 20}
                            style:transition={isDragging
                                ? 'none'
                                : 'transform 150ms ease, width 150ms ease'}
                            onpointerdown={(e) => beginDrag(note, e)}
                            role="button"
                            tabindex="-1"
                        >
                            <div
                                class={`flex flex-col ${PAPER} ${paperClass[note.color]} ${isExpanded ? 'w-72 cursor-default p-3' : 'w-44 cursor-grab p-2.5 active:cursor-grabbing'}`}
                            >
                                {#if isExpanded}
                                    <div
                                        class="mb-2 flex items-center justify-between"
                                    >
                                        <div
                                            class="flex items-center gap-1.5"
                                            data-no-drag
                                        >
                                            {#each COLORS as c (c)}
                                                <button
                                                    type="button"
                                                    aria-label={`Colour ${c}`}
                                                    aria-pressed={note.color ===
                                                        c}
                                                    onclick={() =>
                                                        recolor(note, c)}
                                                    class={`h-3.5 w-3.5 rounded-sm transition ${swatchClass[c]} ${note.color === c ? 'ring-2 ring-accent' : ''}`}
                                                ></button>
                                            {/each}
                                        </div>
                                        <button
                                            type="button"
                                            data-no-drag
                                            onclick={collapse}
                                            aria-label="Collapse note"
                                            class="btn-icon h-6 w-6"
                                        >
                                            <Minimize2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>

                                    {#if editingId === note.id}
                                        <input
                                            type="text"
                                            data-no-drag
                                            bind:value={draftTitle}
                                            placeholder="Heading (optional)"
                                            class="input mb-2 font-medium"
                                        />
                                        <textarea
                                            data-no-drag
                                            bind:value={draftBody}
                                            rows="6"
                                            class="input resize-y"
                                        ></textarea>
                                        <div
                                            class="mt-2 flex justify-end gap-1.5"
                                            data-no-drag
                                        >
                                            <button
                                                type="button"
                                                onclick={() =>
                                                    (editingId = null)}
                                                class="btn-ghost">Cancel</button
                                            >
                                            <button
                                                type="button"
                                                onclick={() => saveEdit(note)}
                                                disabled={!draftBody.trim() ||
                                                    saving}
                                                class="btn-primary">Save</button
                                            >
                                        </div>
                                    {:else}
                                        {#if note.title}
                                            <h3
                                                class="mb-1 text-[13px] font-medium text-fg"
                                                data-no-drag
                                            >
                                                {note.title}
                                            </h3>
                                        {/if}
                                        <p
                                            class="max-h-[40vh] overflow-y-auto text-[13px] leading-relaxed break-words whitespace-pre-wrap text-fg"
                                            data-no-drag
                                        >
                                            {note.body}
                                        </p>
                                        <div
                                            class="mt-2 flex items-center justify-between border-t border-line-soft pt-1.5"
                                            data-no-drag
                                        >
                                            <span
                                                class="font-mono text-[11px] text-fg-faint tabular-nums"
                                                >{formatDate(
                                                    note.updated_at,
                                                )}</span
                                            >
                                            <div
                                                class="flex items-center gap-0.5"
                                            >
                                                <button
                                                    type="button"
                                                    onclick={() =>
                                                        startEdit(note)}
                                                    class="btn-ghost h-6 px-1.5 text-xs"
                                                    >Edit</button
                                                >
                                                <button
                                                    type="button"
                                                    onclick={() =>
                                                        deleteNote(note)}
                                                    class="btn-ghost h-6 px-1.5 text-xs text-danger hover:text-danger"
                                                    >Delete</button
                                                >
                                            </div>
                                        </div>
                                    {/if}
                                {:else}
                                    {#if note.title}
                                        <span
                                            class="mb-0.5 line-clamp-2 text-[13px] font-medium text-fg"
                                            >{note.title}</span
                                        >
                                    {/if}
                                    <span
                                        class="line-clamp-4 text-[13px] leading-snug break-words whitespace-pre-wrap text-fg-muted"
                                        >{note.body}</span
                                    >
                                    <span
                                        class="mt-1.5 font-mono text-[11px] text-fg-faint tabular-nums"
                                        >{formatDate(note.updated_at)}</span
                                    >
                                {/if}
                            </div>
                        </div>
                    {/each}

                    <!-- Task-anchored notes blended onto the canvas: read-only (mutating
                     them would touch project_notes), tagged with a link icon and the
                     task name so they're distinguishable from your draggable stickies.
                     Click opens the task's Notes tab. -->
                    {#each taskNotes as note (`t-${note.id}`)}
                        {@const p = taskCoords(note)}
                        {@const href = taskNoteHref(note)}
                        {@const isTaskDragging = taskDragId === note.id}
                        <div
                            class={`absolute z-10 flex h-24 w-40 cursor-grab touch-none flex-col overflow-hidden p-2 text-left active:cursor-grabbing ${PAPER} ${paperClass[noteTypeColor(note.type)]}`}
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
                            {@render taskLabel(note, href)}
                            <span
                                class="line-clamp-3 flex-1 text-xs leading-snug text-fg"
                                >{note.body}</span
                            >
                            {#if note.task}
                                <svelte:element
                                    this={href ? 'a' : 'span'}
                                    {href}
                                    data-task-link
                                    class="mt-0.5 line-clamp-1 w-fit text-[11px] text-fg-faint hover:underline"
                                >
                                    {note.task.short_title || note.task.title}
                                </svelte:element>
                            {/if}
                        </div>
                    {/each}
                {:else}
                    <!-- Tidy, scannable layout: dragging off; notes grouped/sorted by the
                         chosen Arrange mode. Workspace notes open back on the canvas to edit. -->
                    <div class="space-y-6 px-4 py-4">
                        {#each arranged as group (group.label || 'all')}
                            <div>
                                {#if group.label}
                                    <h3 class="section-title mb-2">
                                        {group.label}
                                        <span class="section-count"
                                            >{group.items.length}</span
                                        >
                                    </h3>
                                {/if}
                                <div
                                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                                >
                                    {#each group.items as it (it.key)}
                                        {#if it.workspace}
                                            {@const note = it.workspace}
                                            <div
                                                class={`flex h-36 flex-col p-3 ${PAPER} ${paperClass[note.color]}`}
                                            >
                                                {#if note.title}
                                                    <span
                                                        class="mb-0.5 line-clamp-1 text-[13px] font-medium text-fg"
                                                        >{note.title}</span
                                                    >
                                                {/if}
                                                <span
                                                    class="line-clamp-4 flex-1 text-xs leading-snug break-words whitespace-pre-wrap text-fg-muted"
                                                    >{note.body}</span
                                                >
                                                <div
                                                    class="mt-1.5 flex items-center justify-between border-t border-line-soft pt-1"
                                                >
                                                    <span
                                                        class="font-mono text-[11px] text-fg-faint tabular-nums"
                                                        >{formatDate(
                                                            note.updated_at,
                                                        )}</span
                                                    >
                                                    <div
                                                        class="flex items-center gap-0.5"
                                                    >
                                                        <button
                                                            type="button"
                                                            onclick={() =>
                                                                openWorkspaceNote(
                                                                    note,
                                                                )}
                                                            class="btn-ghost h-6 px-1.5 text-xs"
                                                            >Open</button
                                                        >
                                                        <button
                                                            type="button"
                                                            onclick={() =>
                                                                deleteNote(
                                                                    note,
                                                                )}
                                                            class="btn-ghost h-6 px-1.5 text-xs text-danger hover:text-danger"
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
                                                class={`flex h-36 flex-col overflow-hidden p-3 transition hover:-translate-y-0.5 ${PAPER} ${paperClass[noteTypeColor(note.type)]}`}
                                            >
                                                <div
                                                    class="mb-0.5 flex items-center gap-1 text-[11px] font-medium text-fg-muted"
                                                >
                                                    <Link2
                                                        class="h-3 w-3 shrink-0"
                                                    />
                                                    <span class="line-clamp-1"
                                                        >{note.type_label}</span
                                                    >
                                                </div>
                                                <span
                                                    class="line-clamp-4 flex-1 text-xs leading-snug text-fg"
                                                    >{note.body}</span
                                                >
                                                {#if note.task}
                                                    <span
                                                        class="mt-1 line-clamp-1 text-[11px] text-fg-faint"
                                                    >
                                                        {note.task
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
                {/if}
            </div>

            <!-- compose -->
            {#if composing}
                <div
                    class="absolute inset-0 z-50 flex items-start justify-center bg-black/20 p-4 pt-[12vh]"
                >
                    <div class="popover w-full max-w-sm px-4 py-3">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="font-medium text-fg">New note</h3>
                            <button
                                type="button"
                                onclick={cancelCompose}
                                aria-label="Cancel"
                                class="btn-icon"
                            >
                                <X class="h-4 w-4" />
                            </button>
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
                                class="input font-medium"
                            />
                            <textarea
                                bind:value={draftBody}
                                placeholder="Write a note"
                                rows="5"
                                class="input resize-y"
                            ></textarea>
                            <div class="flex justify-end gap-1.5">
                                <button
                                    type="button"
                                    onclick={cancelCompose}
                                    class="btn-ghost">Cancel</button
                                >
                                <button
                                    type="submit"
                                    disabled={!draftBody.trim() || saving}
                                    class="btn-primary">Add note</button
                                >
                            </div>
                        </form>
                    </div>
                </div>
            {/if}
        </div>
    </div>
{/if}
