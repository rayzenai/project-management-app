<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { peek } from '../../lib/peek.svelte';
    import type { SharedProps, Task } from '../../lib/types';
    import AssigneeStack from '../AssigneeStack.svelte';
    import DateChip from '../DateChip.svelte';
    import PriorityFlag from '../PriorityFlag.svelte';
    import TaskCode from '../TaskCode.svelte';
    import DependencyChips from './DependencyChips.svelte';

    let {
        task,
        projectSlug,
        projectCode = null,
        index,
        isDragging,
        related = false,
        nest = null,
        onpointerdown,
    }: {
        task: Task;
        projectSlug: string;
        /** The board knows the project, so cards can show CODE-123. */
        projectCode?: string | null;
        index: number;
        isDragging: boolean;
        /** Linked by a dependency to the card being hovered or focused. */
        related?: boolean;
        /**
         * Another card is being dragged onto this one: whether letting go
         * would make it wait for this task, and the sentence saying so.
         */
        nest?: { ok: boolean; label: string } | null;
        /** Board-level pointer drag; undefined for non-droppable columns. */
        onpointerdown?: (e: PointerEvent) => void;
    } = $props();

    const team = $derived(
        ((page.props ?? {}) as unknown as SharedProps).quickAddContext?.team ??
            [],
    );
</script>

<!-- svelte-ignore a11y_no_noninteractive_tabindex, a11y_no_noninteractive_element_interactions -->
<div
    role="listitem"
    data-card-index={index}
    data-task-id={task.id}
    {onpointerdown}
    onclick={() => peek.open({ id: task.id, slug: task.slug })}
    onkeydown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            peek.open({ id: task.id, slug: task.slug });
        }
    }}
    tabindex="0"
    class={`group relative cursor-grab touch-pan-y rounded-lg border bg-raised px-4 py-3.5 transition select-none hover:border-accent active:cursor-grabbing ${
        isDragging ? 'opacity-40' : ''
    } ${
        nest
            ? nest.ok
                ? 'border-accent bg-accent-soft ring-2 ring-accent/40'
                : 'border-danger ring-2 ring-danger/30'
            : related
              ? 'border-accent'
              : 'border-line'
    }`}
>
    <div class="flex h-5 items-center justify-between gap-2">
        <TaskCode {task} {projectCode} {projectSlug} />
        <AssigneeStack {task} {team} size="sm" />
    </div>

    <div class="mt-1.5 leading-snug font-medium text-fg">
        {task.short_title || task.title}
    </div>
    {#if task.title_np}
        <div class="font-np mt-0.5 text-xs leading-snug text-fg-muted">
            {task.title_np}
        </div>
    {/if}

    {#if (task.dependencies ?? []).length > 0 || (task.dependents ?? []).length > 0}
        <div class="mt-2">
            <DependencyChips {task} {projectCode} />
        </div>
    {/if}

    <div class="mt-2.5 flex items-center gap-1.5">
        <DateChip {task} {projectSlug} size="sm" ghost />
        <PriorityFlag {task} {projectSlug} quiet />
    </div>

    {#if nest}
        <div
            class={`pointer-events-none absolute inset-x-2 -bottom-3 truncate rounded-md px-2 py-1 text-center text-xs font-medium text-white ${nest.ok ? 'bg-accent' : 'bg-danger'}`}
            role="status"
        >
            {nest.label}
        </div>
    {/if}
</div>
