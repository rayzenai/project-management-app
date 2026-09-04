<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { GripVertical, Moon, Star } from '@lucide/svelte';
    import { peek } from '../lib/peek.svelte';
    import type { Assignment, SharedProps } from '../lib/types';
    import AssigneeStack from './AssigneeStack.svelte';
    import CompleteCheckbox from './CompleteCheckbox.svelte';
    import DateChip from './DateChip.svelte';
    import Popover from './Popover.svelte';
    import PriorityFlag from './PriorityFlag.svelte';
    import StatusChip from './StatusChip.svelte';

    let {
        assignment,
        lane,
    }: { assignment: Assignment; lane: 'due' | 'focused' | 'other' } = $props();

    let snoozeOpen = $state(false);

    const task = $derived(assignment.task);
    const projectSlug = $derived(task?.project?.slug ?? '');
    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const team = $derived(shared.quickAddContext?.team ?? []);

    function patch(payload: Record<string, string | number | boolean | null>) {
        router.patch(`/workspace/assignments/${assignment.id}`, payload, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function toggleFocus() {
        patch({ is_focused: !assignment.is_focused });
    }

    function snooze(days: number | null) {
        if (days === null) {
            patch({ snoozed_until: null });
        } else {
            const target = new Date(Date.now() + days * 86_400_000);
            patch({ snoozed_until: target.toISOString().slice(0, 10) });
        }

        snoozeOpen = false;
    }

    function onDragStart(event: DragEvent) {
        if (!event.dataTransfer) {
            return;
        }

        event.dataTransfer.setData(
            'application/x-workspace-assignment',
            JSON.stringify({
                assignmentId: assignment.id,
                isFocused: assignment.is_focused,
            }),
        );
        event.dataTransfer.effectAllowed = 'move';
    }

    function openPeek() {
        if (task) {
            peek.open({ id: task.id, slug: task.slug });
        }
    }
</script>

{#if task}
    <div
        role="button"
        tabindex="0"
        draggable="true"
        ondragstart={onDragStart}
        onclick={openPeek}
        onkeydown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openPeek();
            }
        }}
        class="row group min-h-9 w-full cursor-pointer flex-wrap gap-2 px-2 py-1 text-left"
    >
        <span
            aria-hidden="true"
            class="hidden w-3 shrink-0 cursor-grab text-fg-faint opacity-0 group-hover:opacity-100 sm:block"
        >
            <GripVertical class="h-3.5 w-3.5" />
        </span>

        <CompleteCheckbox {task} {projectSlug} />
        <PriorityFlag {task} {projectSlug} quiet />

        <span class="flex min-w-0 flex-1 items-baseline gap-1.5">
            {#if task.item_number}
                <span
                    class="shrink-0 font-mono text-xs text-fg-faint tabular-nums"
                    >#{task.item_number}</span
                >
            {/if}
            <span class="truncate text-[13px] font-medium text-fg">
                {task.short_title || task.title}
            </span>
            {#if lane === 'due' && assignment.is_focused}
                <Star
                    aria-label="Pinned"
                    class="h-3 w-3 shrink-0 fill-current text-accent"
                />
            {/if}
        </span>

        <span
            class="flex shrink-0 items-center gap-1.5 max-sm:basis-full max-sm:pl-10"
            onclick={(e) => e.stopPropagation()}
            role="none"
        >
            {#if lane !== 'other' && task.project}
                <span class="max-w-32 truncate text-xs text-fg-muted"
                    >{task.project.title}</span
                >
            {/if}
            <StatusChip {task} {projectSlug} size="sm" />
            <DateChip {task} {projectSlug} size="sm" ghost />
            {#if (task.assignments?.length ?? 0) > 1}
                <AssigneeStack {task} {team} size="sm" />
            {/if}
        </span>

        <span
            class="flex w-12 shrink-0 items-center justify-end gap-0.5 opacity-60 sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100"
            role="none"
            onclick={(e) => e.stopPropagation()}
        >
            <button
                type="button"
                class={`btn-icon h-6 w-6 ${assignment.is_focused ? 'text-accent' : ''}`}
                title={assignment.is_focused
                    ? 'Unpin from focus'
                    : 'Pin to focus'}
                aria-pressed={assignment.is_focused}
                onclick={toggleFocus}
            >
                <Star
                    class={`h-3.5 w-3.5 ${assignment.is_focused ? 'fill-current' : ''}`}
                />
            </button>

            <Popover
                bind:open={snoozeOpen}
                align="right"
                triggerLabel="Snooze"
                triggerClass="btn-icon h-6 w-6"
            >
                {#snippet trigger()}
                    <Moon class="h-3.5 w-3.5" />
                {/snippet}
                <button
                    type="button"
                    data-popover-item
                    class="menu-item"
                    onclick={() => snooze(1)}
                >
                    Until tomorrow
                </button>
                <button
                    type="button"
                    data-popover-item
                    class="menu-item"
                    onclick={() => snooze(3)}
                >
                    3 days
                </button>
                <button
                    type="button"
                    data-popover-item
                    class="menu-item"
                    onclick={() => snooze(7)}
                >
                    1 week
                </button>
                <button
                    type="button"
                    data-popover-item
                    class="menu-item"
                    onclick={() => snooze(30)}
                >
                    1 month
                </button>
                {#if assignment.is_snoozed}
                    <button
                        type="button"
                        data-popover-item
                        class="menu-item mt-1 border-t border-line-soft text-accent"
                        onclick={() => snooze(null)}
                    >
                        Unsnooze
                    </button>
                {/if}
            </Popover>
        </span>
    </div>
{/if}
