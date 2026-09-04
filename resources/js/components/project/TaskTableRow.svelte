<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { MessageSquare, Phone } from '@lucide/svelte';
    import { peek } from '../../lib/peek.svelte';
    import type { Project, SharedProps, Task } from '../../lib/types';
    import AssigneeStack from '../AssigneeStack.svelte';
    import CompleteCheckbox from '../CompleteCheckbox.svelte';
    import DateChip from '../DateChip.svelte';
    import PriorityFlag from '../PriorityFlag.svelte';
    import StatusChip from '../StatusChip.svelte';

    let { task, project }: { task: Task; project: Project } = $props();

    const team = $derived(
        ((page.props ?? {}) as unknown as SharedProps).quickAddContext?.team ??
            [],
    );

    function openPeek() {
        peek.open({ id: task.id, slug: task.slug });
    }
</script>

<div
    role="button"
    tabindex="0"
    class="group grid h-11 cursor-pointer grid-cols-[16px_44px_minmax(0,1fr)_150px_130px_88px] items-center gap-3 border-b border-line-soft px-4 text-left transition hover:bg-hover"
    onclick={openPeek}
    onkeydown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openPeek();
        }
    }}
>
    <CompleteCheckbox {task} projectSlug={project.slug} />

    <span class="truncate font-mono text-xs text-fg-faint tabular-nums">
        {task.item_number ?? ''}
    </span>

    <div class="flex min-w-0 items-baseline gap-2">
        <span class="truncate font-medium text-fg">
            {task.short_title || task.title}
        </span>
        {#if task.title_np}
            <span class="font-np truncate text-xs text-fg-muted"
                >{task.title_np}</span
            >
        {/if}
        {#if task.notes_count}
            <span
                class="inline-flex shrink-0 items-center gap-0.5 font-mono text-[11px] text-fg-faint tabular-nums"
                title={`${task.notes_count} notes`}
            >
                <MessageSquare class="h-3 w-3" />
                {task.notes_count}
            </span>
        {/if}
        {#if task.contacts_count}
            <span
                class="inline-flex shrink-0 items-center gap-0.5 font-mono text-[11px] text-fg-faint tabular-nums"
                title={`${task.contacts_count} contacts`}
            >
                <Phone class="h-3 w-3" />
                {task.contacts_count}
            </span>
        {/if}
    </div>

    <div class="min-w-0">
        <StatusChip {task} projectSlug={project.slug} size="sm" />
    </div>

    <div class="min-w-0">
        <DateChip {task} projectSlug={project.slug} size="sm" ghost />
    </div>

    <div class="flex items-center justify-end gap-1.5">
        <PriorityFlag {task} projectSlug={project.slug} quiet />
        <AssigneeStack {task} {team} size="sm" />
    </div>
</div>
