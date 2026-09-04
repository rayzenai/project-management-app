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

    function onDragStart(task: Task, event: DragEvent) {
        // getData is unreadable during dragover, so local state is authoritative;
        // dataTransfer is set for completeness/devtools.
        event.dataTransfer?.setData(
            'application/x-pm-task',
            JSON.stringify({ id: task.id, fromStatus: task.status }),
        );
        event.dataTransfer?.setData('text/plain', String(task.id));

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
        }

        dragState = { id: task.id, fromStatus: task.status };
    }

    function onDragEnd() {
        dragState = null;
        hoverTarget = null;
    }

    function onCardDragOver(status: string, index: number, event: DragEvent) {
        event.preventDefault();
        event.stopPropagation();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }

        // Decide above-or-below based on cursor position within the target card.
        const rect = (
            event.currentTarget as HTMLElement
        ).getBoundingClientRect();
        const above = event.clientY < rect.top + rect.height / 2;
        hoverTarget = { status, index: above ? index : index + 1 };
    }

    function onColumnDragOver(status: string, event: DragEvent) {
        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }

        if (!hoverTarget || hoverTarget.status !== status) {
            hoverTarget = { status, index: (columns.get(status) ?? []).length };
        }
    }

    function onColumnDragLeave(event: DragEvent) {
        const related = event.relatedTarget as Node | null;

        if (related && (event.currentTarget as HTMLElement).contains(related)) {
            return;
        }

        hoverTarget = null;
    }

    function unchangedOrder(orderedIds: number[], status: string): boolean {
        const current = (columns.get(status) ?? []).map((t) => t.id);

        return (
            current.length === orderedIds.length &&
            current.every((id, i) => id === orderedIds[i])
        );
    }

    function onDrop(toStatus: string, event: DragEvent) {
        event.preventDefault();
        const target = hoverTarget;
        const state = dragState;
        hoverTarget = null;
        dragState = null;

        if (!state) {
            return;
        }

        const { id, fromStatus } = state;
        const column = columns.get(toStatus) ?? [];
        const index =
            target?.status === toStatus ? target.index : column.length;

        const orderedIds = column.map((t) => t.id).filter((tid) => tid !== id);
        orderedIds.splice(Math.min(index, orderedIds.length), 0, id);

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
            ondragover={droppable
                ? (e) => onColumnDragOver(def.value, e)
                : undefined}
            ondragleave={droppable ? onColumnDragLeave : undefined}
            ondrop={droppable ? (e) => onDrop(def.value, e) : undefined}
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
                            isDragging={dragState?.id === task.id}
                            ondragstart={(e) => onDragStart(task, e)}
                            ondragend={onDragEnd}
                            ondragover={droppable
                                ? (e) => onCardDragOver(def.value, index, e)
                                : () => {}}
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
