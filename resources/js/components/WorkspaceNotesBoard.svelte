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
    // Read-only task-anchored notes. They live in their own lane rather than on
    // the sticky canvas: they are records on a task, not a personal scratchpad,
    // and scattering dozens of them over the stickies buried both.
    const taskNotes = $derived(shared.taskNotes ?? []);

    type Lane = 'mine' | 'tasks';
    let lane = $state<Lane>('mine');

    // The paper card shell shared by every sticky on the board.
    const PAPER = 'rounded-md border shadow-[0_1px_2px_rgba(0,0,0,0.08)]';

    function taskNoteHref(note: Note): string | null {
        const task = note.task;

        if (!task?.project?.slug || !task.slug) {
            return null;
        }

        return `/workspace/projects/${task.project.slug}/tasks/${task.slug}?tab=notes`;
    }

    // Sticky positions are relative to the drawer's canvas, so the drag clamps
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

    // How the task lane is ordered. The sticky lane is always the free canvas —
    // that is the whole point of a sticky.
    type ArrangeMode = 'task' | 'title' | 'type';
    const ARRANGE_OPTIONS: { value: ArrangeMode; label: string }[] = [
        { value: 'task', label: 'Group by task' },
        { value: 'title', label: 'Sort by title' },
        { value: 'type', label: 'Sort by type' },
    ];
    let arrange = $state<ArrangeMode>('task');

    type ArrangeItem = { key: string; task: Note };

    const arranged = $derived.by(
        (): { label: string; items: ArrangeItem[] }[] => {
            const items: ArrangeItem[] = taskNotes.map((n) => ({
                key: `t-${n.id}`,
                task: n,
            }));
            const text = (it: ArrangeItem) => it.task.body.toLowerCase();

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
                        ? (it.task.task?.short_title ??
                          it.task.task?.title ??
                          'Untagged')
                        : it.task.type_label;
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
        // Both act on stickies, so make sure that lane is the one on screen.
        if (notesBoard.compose) {
            lane = 'mine';
            composing = true;
            openedViaCompose = true;
        } else if (notesBoard.focusId != null) {
            lane = 'mine';
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
                <div
                    class="flex items-center rounded-md border border-line bg-surface-alt p-[2px]"
                    role="group"
                    aria-label="Which notes"
                >
                    {#each [{ key: 'mine', label: 'My notes', count: notes.length }, { key: 'tasks', label: 'From tasks', count: taskNotes.length }] as opt (opt.key)}
                        <button
                            type="button"
                            aria-pressed={lane === opt.key}
                            onclick={() => (lane = opt.key as Lane)}
                            class={`flex h-[22px] items-center gap-1.5 rounded-[5px] px-2 text-xs font-medium transition ${
                                lane === opt.key
                                    ? 'bg-surface text-fg'
                                    : 'text-fg-muted hover:text-fg'
                            }`}
                        >
                            {opt.label}
                            <span class="font-mono text-fg-faint tabular-nums"
                                >{opt.count}</span
                            >
                        </button>
                    {/each}
                </div>
                <div class="ml-auto flex items-center gap-1.5">
                    {#if lane === 'tasks'}
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
                                    <option value={opt.value}
                                        >{opt.label}</option
                                    >
                                {/each}
                            </select>
                        </label>
                    {:else}
                        <button
                            type="button"
                            onclick={startCompose}
                            class="btn"
                        >
                            <Plus class="h-3.5 w-3.5" />
                            New note
                        </button>
                    {/if}
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
                {#if lane === 'mine' && notes.length === 0 && !composing}
                    <div
                        class="absolute inset-0 flex flex-col items-center justify-center gap-3"
                    >
                        <p class="text-fg-muted">No sticky notes yet.</p>
                        <button
                            type="button"
                            onclick={startCompose}
                            class="btn"
                        >
                            <Plus class="h-3.5 w-3.5" />
                            New note
                        </button>
                    </div>
                {:else if lane === 'tasks' && taskNotes.length === 0}
                    <div
                        class="absolute inset-0 flex flex-col items-center justify-center gap-2"
                    >
                        <p class="text-fg-muted">No notes on your tasks yet.</p>
                        <p class="text-xs text-fg-faint">
                            Notes you add to a task show up here.
                        </p>
                    </div>
                {/if}

                {#if lane === 'mine'}
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
                {:else}
                    <!-- Task lane: read-only records grouped or sorted by the Arrange
                         control. Always a tidy grid — these are notes ON something,
                         not a scratchpad, and there can be dozens of them. -->
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
                                        {#if it.task}
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
