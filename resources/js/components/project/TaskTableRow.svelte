<script lang="ts">
    import { page } from '@inertiajs/svelte';
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

<tr
    tabindex="0"
    class="group cursor-pointer border-b border-line-soft transition last:border-b-0 hover:bg-surface-alt"
    onclick={openPeek}
    onkeydown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openPeek();
        }
    }}
>
    <td class="w-8 px-3 py-2">
        <CompleteCheckbox {task} projectSlug={project.slug} />
    </td>
    <td class="w-14 px-2 py-2 font-mono text-xs text-fg-muted">
        {#if task.item_number}#{task.item_number}{/if}
    </td>
    <td class="px-2 py-2">
        <span class="text-sm font-medium text-fg group-hover:text-accent">
            {task.short_title || task.title}
        </span>
        {#if task.notes_count}
            <span class="ml-1.5 font-mono text-[11px] text-fg-faint"
                >✎{task.notes_count}</span
            >
        {/if}
        {#if task.contacts_count}
            <span class="ml-1 font-mono text-[11px] text-fg-faint"
                >☎{task.contacts_count}</span
            >
        {/if}
    </td>
    <td class="w-36 px-2 py-2">
        <StatusChip {task} projectSlug={project.slug} size="sm" />
    </td>
    <td class="w-10 px-2 py-2 text-center">
        <PriorityFlag {task} projectSlug={project.slug} quiet />
    </td>
    <td class="w-28 px-2 py-2">
        <DateChip {task} projectSlug={project.slug} size="sm" ghost />
    </td>
    <td class="w-24 px-3 py-2">
        <AssigneeStack {task} {team} size="sm" />
    </td>
</tr>
