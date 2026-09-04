<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Plus } from '@lucide/svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { toast } from '../../lib/toast.svelte';
    import type { Project, Status, Task } from '../../lib/types';
    import StatusGlyph from '../StatusGlyph.svelte';
    import BoardCard from './BoardCard.svelte';
    import ColumnComposer from './ColumnComposer.svelte';

    let {
        project,
        tasks,
        statuses,
        filtersActive = false,
    }: {
        project: Project;
        tasks: Task[];
        statuses: Status[];
        filtersActive?: boolean;
    } = $props();

    // Defensive trailing column for tasks whose status is not in the shared config; normally empty.
    const OTHER = '__other__';

    let dragState = $state<{ id: number; fromStatus: string } | null>(null);
    let hoverTarget = $state<{ status: string; index: number } | null>(null);
    let pendingMove = $state<{
        taskId: number;
        toStatus: string;
        orderedIds: number[];
    } | null>(null);
    let composerOpenFor = $state<string | null>(null);

    const knownValues = $derived(new SvelteSet(statuses.map((s) => s.value)));
    const columnDefs = $derived.by(() => {
        const defs = statuses.map((s) => ({
            value: s.value,
            label: s.label,
            color: s.color,
        }));

        if (tasks.some((t) => !knownValues.has(t.status))) {
            defs.push({ value: OTHER, label: 'Other', color: '#9CA3AF' });
        }

        return defs;
    });

    const columns = $derived.by(() => {
        const map = new SvelteMap<string, Task[]>();

        for (const def of columnDefs) {
            map.set(def.value, []);
        }

        for (const t of tasks) {
            map.get(knownValues.has(t.status) ? t.status : OTHER)?.push(t);
        }

        for (const list of map.values()) {
            list.sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
        }

        // Optimistic overlay applied last: pull the moved card out of its server column,
        // then lay out the target column exactly per orderedIds. Clearing pendingMove
        // (success or error) snaps the board back to whatever the props say.
        const pm = pendingMove;

        if (pm) {
            const moved = tasks.find((t) => t.id === pm.taskId);

            if (moved) {
                const pool = new SvelteMap<number, Task>();

                for (const t of map.get(pm.toStatus) ?? []) {
                    pool.set(t.id, t);
                }

                pool.set(moved.id, moved);

                for (const [key, list] of map) {
                    map.set(
                        key,
                        list.filter((t) => t.id !== pm.taskId),
                    );
                }

                map.set(
                    pm.toStatus,
                    pm.orderedIds
                        .map((id) => pool.get(id))
                        .filter((t): t is Task => t !== undefined),
                );
            }
        }

        return map;
    });

    /*
     * Pointer-based drag and drop. HTML5 drag events are not delivered inside
     * embedded browsers (CEF hosts, most WebViews) and never on touch, so the
     * board tracks the pointer itself: press, move past a small threshold to
     * lift the card into a ghost, hit-test columns under the pointer, drop.
     */
    const DRAG_THRESHOLD = 6;
    const EDGE_SCROLL_ZONE = 48;
    const EDGE_SCROLL_STEP = 14;

    let boardEl = $state<HTMLElement | null>(null);
    let press: {
        task: Task;
        el: HTMLElement;
        x: number;
        y: number;
        offsetX: number;
        offsetY: number;
    } | null = null;
    let ghost: HTMLElement | null = null;
    let justDragged = false;

    function onCardPointerDown(task: Task, event: PointerEvent) {
        if (event.button !== 0 || !event.isPrimary) {
            return;
        }

        // Interactive children (assignee picker, date chip, priority) keep
        // their own behaviour; only the card body starts a drag.
        const target = event.target as HTMLElement | null;

        if (target?.closest('button, a, input, textarea, select')) {
            return;
        }

        const el = event.currentTarget as HTMLElement;
        const rect = el.getBoundingClientRect();
        press = {
            task,
            el,
            x: event.clientX,
            y: event.clientY,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
        };

        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', cancelDrag);
        window.addEventListener('keydown', onDragKey);
    }

    function beginDrag(event: PointerEvent) {
        if (!press) {
            return;
        }

        const rect = press.el.getBoundingClientRect();
        ghost = press.el.cloneNode(true) as HTMLElement;
        ghost.setAttribute('aria-hidden', 'true');
        ghost.removeAttribute('tabindex');
        ghost.className = press.el.className;
        ghost.style.cssText = `position:fixed;left:0;top:0;width:${rect.width}px;margin:0;pointer-events:none;z-index:60;opacity:.95;box-shadow:0 12px 32px rgba(0,0,0,.18);transition:none;will-change:transform;`;
        document.body.appendChild(ghost);
        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'grabbing';

        dragState = { id: press.task.id, fromStatus: press.task.status };
        moveGhost(event.clientX, event.clientY);
    }

    function moveGhost(x: number, y: number) {
        if (!ghost || !press) {
            return;
        }

        ghost.style.transform = `translate(${x - press.offsetX}px, ${y - press.offsetY}px) rotate(1.5deg)`;
    }

    /** Which column and slot the pointer is over, from real element geometry. */
    function targetAt(
        x: number,
        y: number,
    ): { status: string; index: number } | null {
        const under = document.elementFromPoint(x, y);
        const column = under?.closest<HTMLElement>('[data-column]');
        const status = column?.dataset.column;

        if (!column || !status || status === OTHER) {
            return null;
        }

        const cards = Array.from(
            column.querySelectorAll<HTMLElement>('[data-card-index]'),
        );

        for (const card of cards) {
            const r = card.getBoundingClientRect();

            if (y < r.top + r.height / 2) {
                return { status, index: Number(card.dataset.cardIndex) };
            }
        }

        return { status, index: cards.length };
    }

    function edgeScroll(x: number) {
        if (!boardEl) {
            return;
        }

        const rect = boardEl.getBoundingClientRect();

        if (x < rect.left + EDGE_SCROLL_ZONE) {
            boardEl.scrollLeft -= EDGE_SCROLL_STEP;
        } else if (x > rect.right - EDGE_SCROLL_ZONE) {
            boardEl.scrollLeft += EDGE_SCROLL_STEP;
        }
    }

    function onPointerMove(event: PointerEvent) {
        if (!press) {
            return;
        }

        if (!dragState) {
            if (
                Math.hypot(event.clientX - press.x, event.clientY - press.y) <
                DRAG_THRESHOLD
            ) {
                return;
            }

            beginDrag(event);
        }

        event.preventDefault();
        moveGhost(event.clientX, event.clientY);
        hoverTarget = targetAt(event.clientX, event.clientY);
        edgeScroll(event.clientX);
    }

    function onPointerUp(event: PointerEvent) {
        if (!dragState) {
            release();

            return; // a plain click: the card's own click handler opens the peek
        }

        const target = targetAt(event.clientX, event.clientY) ?? hoverTarget;
        const state = dragState;
        release();

        // The click that follows pointerup must not open the peek.
        justDragged = true;
        setTimeout(() => (justDragged = false), 0);

        if (target) {
            drop(state, target);
        }
    }

    function onDragKey(event: KeyboardEvent) {
        if (event.key === 'Escape' && dragState) {
            event.preventDefault();
            cancelDrag();
        }
    }

    function cancelDrag() {
        release();
    }

    /** Tears down listeners, ghost and transient state; never posts. */
    function release() {
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', cancelDrag);
        window.removeEventListener('keydown', onDragKey);
        ghost?.remove();
        ghost = null;
        document.body.style.userSelect = '';
        document.body.style.cursor = '';
        press = null;
        dragState = null;
        hoverTarget = null;
    }

    function swallowClickAfterDrag(event: MouseEvent) {
        if (justDragged) {
            event.stopPropagation();
            event.preventDefault();
        }
    }

    $effect(() => release);

    function unchangedOrder(orderedIds: number[], status: string): boolean {
        const current = (columns.get(status) ?? []).map((t) => t.id);

        return (
            current.length === orderedIds.length &&
            current.every((id, i) => id === orderedIds[i])
        );
    }

    function drop(
        state: { id: number; fromStatus: string },
        target: { status: string; index: number },
    ) {
        const { id, fromStatus } = state;
        const toStatus = target.status;
        const column = columns.get(toStatus) ?? [];

        // `target.index` counts the dragged card if it sits in this column;
        // remove it first so the index lands on the right neighbour.
        const before = column.slice(0, target.index).filter((t) => t.id !== id);
        const after = column.slice(target.index).filter((t) => t.id !== id);
        const orderedIds = [
            ...before.map((t) => t.id),
            id,
            ...after.map((t) => t.id),
        ];

        if (fromStatus === toStatus && unchangedOrder(orderedIds, toStatus)) {
            return;
        } // no-op drop

        pendingMove = { taskId: id, toStatus, orderedIds }; // optimistic
        router.post(
            `/workspace/projects/${project.slug}/tasks/reorder`,
            fromStatus === toStatus
                ? { task_ids: orderedIds } // within-column: pure subset reorder
                : { task_ids: orderedIds, status: toStatus }, // cross-column: order + status
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (pendingMove = null), // fresh props are truth
                onError: () => {
                    pendingMove = null; // clearing = rollback
                    toast.error('Move failed');
                },
            },
        );
    }
</script>

<div
    bind:this={boardEl}
    onclickcapture={swallowClickAfterDrag}
    class="flex min-h-0 flex-1 gap-3.5 overflow-x-auto border-t border-line bg-surface-alt px-4 py-4"
>
    {#each columnDefs as def (def.value)}
        {@const colTasks = columns.get(def.value) ?? []}
        {@const isHover = hoverTarget?.status === def.value}
        {@const droppable = def.value !== OTHER}
        {@const canAdd = droppable && !(filtersActive && colTasks.length === 0)}
        <div
            class={`flex min-h-[200px] w-[272px] shrink-0 flex-col rounded-md transition ${
                isHover ? 'bg-accent-soft' : ''
            }`}
            data-column={def.value}
            role="list"
            aria-label={def.label}
        >
            <header class="flex h-8 shrink-0 items-center gap-2 px-1">
                <StatusGlyph status={def.value} size={14} />
                <h3 class="truncate font-medium text-fg">{def.label}</h3>
                <span class="section-count">{colTasks.length}</span>
                {#if canAdd}
                    <button
                        type="button"
                        class="btn-icon ml-auto"
                        aria-label={`Add task to ${def.label}`}
                        title="Add task"
                        onclick={() => (composerOpenFor = def.value)}
                    >
                        <Plus class="h-4 w-4" />
                    </button>
                {/if}
            </header>

            <div class="flex flex-1 flex-col gap-2 px-0.5 pt-1 pb-2">
                {#if colTasks.length === 0}
                    <p class="px-1 py-2 text-xs text-fg-faint">
                        {filtersActive ? 'No matches' : 'No tasks'}
                    </p>
                {:else}
                    {#each colTasks as task, index (task.id)}
                        {#if isHover && hoverTarget?.index === index}
                            <div
                                class="-mb-1 h-0.5 rounded-full bg-accent"
                            ></div>
                        {/if}
                        <BoardCard
                            {task}
                            projectSlug={project.slug}
                            {index}
                            isDragging={dragState?.id === task.id}
                            onpointerdown={droppable
                                ? (e) => onCardPointerDown(task, e)
                                : undefined}
                        />
                    {/each}
                    {#if isHover && hoverTarget?.index === colTasks.length}
                        <div class="-mt-1 h-0.5 rounded-full bg-accent"></div>
                    {/if}
                {/if}

                {#if canAdd && composerOpenFor === def.value}
                    <ColumnComposer
                        {project}
                        status={def.value}
                        onClose={() => (composerOpenFor = null)}
                    />
                {/if}
            </div>
        </div>
    {/each}
</div>
