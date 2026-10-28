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
    let hoverTarget = $state<{
        status: string;
        index: number;
        nestId?: number;
    } | null>(null);
    /** The card the dragged one is held over, to make it wait for that task. */
    let nestTarget = $state<{ id: number; ok: boolean; label: string } | null>(
        null,
    );
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
        // The wrapper follows the pointer; the card inside it tilts, and
        // shrinks around the pointer when held over another card.
        const card = press.el.cloneNode(true) as HTMLElement;
        card.removeAttribute('tabindex');
        card.className = press.el.className;
        card.style.cssText = `width:${rect.width}px;margin:0;opacity:.95;box-shadow:0 12px 32px rgba(0,0,0,.18);rotate:1.5deg;transform-origin:${press.offsetX}px ${press.offsetY}px;transition:${reducedMotion() ? 'none' : 'scale 160ms ease, opacity 160ms ease'};`;
        ghost = document.createElement('div');
        ghost.setAttribute('aria-hidden', 'true');
        ghost.style.cssText =
            'position:fixed;left:0;top:0;pointer-events:none;z-index:60;will-change:transform;';
        ghost.appendChild(card);
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

        ghost.style.transform = `translate(${x - press.offsetX}px, ${y - press.offsetY}px)`;
    }

    /**
     * Which column and slot the pointer is over, from real element geometry.
     * The middle half of another card means "drop it in": the dragged task
     * will wait for that one (`nestId`). Its top and bottom quarters, and the
     * gaps, mean "move it here".
     */
    function targetAt(
        x: number,
        y: number,
    ): { status: string; index: number; nestId?: number } | null {
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
            const index = Number(card.dataset.cardIndex);

            if (y < r.top + r.height / 4) {
                return { status, index };
            }

            if (y <= r.bottom - r.height / 4) {
                const id = Number(card.dataset.taskId);

                if (id !== dragState?.id && x >= r.left && x <= r.right) {
                    return { status, index, nestId: id };
                }

                return {
                    status,
                    index: y < r.top + r.height / 2 ? index : index + 1,
                };
            }

            if (y < r.bottom) {
                return { status, index: index + 1 };
            }
        }

        return { status, index: cards.length };
    }

    function reducedMotion(): boolean {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function codeOf(task: Task): string {
        return task.item_number !== null && task.item_number !== undefined
            ? `${project.code}-${task.item_number}`
            : `#${task.id}`;
    }

    /** Whether `from` already waits for `to`, directly or through others. */
    function waitsFor(from: number, to: number): boolean {
        const seen: number[] = [];
        let frontier = [from];

        while (frontier.length > 0) {
            if (frontier.includes(to)) {
                return true;
            }

            seen.push(...frontier);
            frontier = frontier
                .flatMap(
                    (id) =>
                        tasks
                            .find((t) => t.id === id)
                            ?.dependencies?.map((d) => d.id) ?? [],
                )
                .filter((id) => !seen.includes(id));
        }

        return false;
    }

    /** What letting go over another card would do, in words. */
    function describeNest(
        draggedId: number,
        targetId: number,
    ): { id: number; ok: boolean; label: string } {
        const dragged = tasks.find((t) => t.id === draggedId);
        const target = tasks.find((t) => t.id === targetId);
        const a = dragged ? codeOf(dragged) : 'This task';
        const b = target ? codeOf(target) : 'that task';

        if (dragged?.dependencies?.some((d) => d.id === targetId)) {
            return {
                id: targetId,
                ok: false,
                label: `${a} already waits for ${b}`,
            };
        }

        if (waitsFor(targetId, draggedId)) {
            return {
                id: targetId,
                ok: false,
                label: `${b} already waits for ${a}`,
            };
        }

        return { id: targetId, ok: true, label: `${a} will wait for ${b}` };
    }

    /** The ghost shrinks while held over a card, as if going into it. */
    function shrinkGhost(on: boolean) {
        const card = ghost?.firstElementChild as HTMLElement | null;

        if (card) {
            card.style.scale = on ? '0.55' : '1';
            card.style.opacity = on ? '0.8' : '0.95';
        }
    }

    /**
     * The let-go: the ghost flies into the middle of the card and vanishes,
     * and the card gives a small pulse as it takes it in.
     */
    function absorb(
        el: HTMLElement | null,
        targetId: number,
        origin: { x: number; y: number },
    ) {
        const card = boardEl?.querySelector<HTMLElement>(
            `[data-task-id="${targetId}"]`,
        );

        if (!el || !card || reducedMotion()) {
            el?.remove();

            return;
        }

        const r = card.getBoundingClientRect();

        const inner = el.firstElementChild as HTMLElement | null;

        el.style.transition = 'transform 260ms cubic-bezier(.3,.7,.2,1)';

        if (inner) {
            inner.style.transition =
                'scale 260ms ease-in, opacity 260ms ease-in';
        }

        requestAnimationFrame(() => {
            el.style.transform = `translate(${r.left + r.width / 2 - origin.x}px, ${r.top + r.height / 2 - origin.y}px)`;

            if (inner) {
                inner.style.scale = '0.06';
                inner.style.opacity = '0';
            }
        });
        setTimeout(() => el.remove(), 300);
        card.animate(
            [
                { transform: 'scale(1)' },
                { transform: 'scale(1.035)' },
                { transform: 'scale(1)' },
            ],
            { duration: 360, delay: 180, easing: 'ease-out' },
        );
    }

    /** The dragged task now waits for the one it was dropped into. */
    function link(taskId: number, dependsOnId: number) {
        router.post(
            `/workspace/tasks/${taskId}/dependencies`,
            { depends_on_task_id: dependsOnId },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    toast.error(
                        (errors as Record<string, string>).depends_on_task_id ??
                            'Could not link the tasks',
                    ),
            },
        );
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

        const target = targetAt(event.clientX, event.clientY);

        if (target?.nestId !== undefined && dragState) {
            nestTarget = describeNest(dragState.id, target.nestId);
            hoverTarget = null;
            shrinkGhost(true);
        } else {
            nestTarget = null;
            hoverTarget = target;
            shrinkGhost(false);
        }

        edgeScroll(event.clientX);
    }

    function onPointerUp(event: PointerEvent) {
        if (!dragState) {
            release();

            return; // a plain click: the card's own click handler opens the peek
        }

        const target = targetAt(event.clientX, event.clientY) ?? hoverTarget;
        const state = dragState;
        const verdict =
            target?.nestId !== undefined
                ? describeNest(state.id, target.nestId)
                : null;
        const flying = ghost;
        const origin = press
            ? { x: press.offsetX, y: press.offsetY }
            : { x: 0, y: 0 };

        // Keep the ghost for the drop-in animation; release() would remove it.
        if (verdict?.ok) {
            ghost = null;
        }

        release();

        // The click that follows pointerup must not open the peek.
        justDragged = true;
        setTimeout(() => (justDragged = false), 0);

        if (verdict) {
            if (verdict.ok) {
                absorb(flying, verdict.id, origin);
                link(state.id, verdict.id);
            } else {
                toast.error(verdict.label);
            }

            return;
        }

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
        nestTarget = null;
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
    class="flex min-h-0 flex-1 overflow-x-auto border-t border-line bg-surface-alt px-3 py-7"
>
    {#each columnDefs as def, columnIndex (def.value)}
        {@const colTasks = columns.get(def.value) ?? []}
        {@const isHover = hoverTarget?.status === def.value}
        {@const droppable = def.value !== OTHER}
        {@const canAdd = droppable && !(filtersActive && colTasks.length === 0)}
        <div
            class={`flex min-h-[240px] w-[336px] shrink-0 flex-col px-5 transition ${
                columnIndex > 0 ? 'border-l border-line' : ''
            } ${isHover ? 'bg-accent-soft' : ''}`}
            data-column={def.value}
            role="list"
            aria-label={def.label}
        >
            <header
                class="mb-4 flex h-9 shrink-0 items-center gap-2 border-b border-line pb-3"
            >
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

            <div class="flex flex-1 flex-col gap-3.5 pb-2">
                {#if colTasks.length === 0}
                    <p class="py-2 text-xs text-fg-faint">
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
                            projectCode={project.code}
                            {index}
                            isDragging={dragState?.id === task.id}
                            nest={nestTarget?.id === task.id
                                ? nestTarget
                                : null}
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
