<script lang="ts">
    import { peek } from '../lib/peek.svelte';
    import type { Task } from '../lib/types';
    import Avatar from './Avatar.svelte';
    import DateChip from './DateChip.svelte';
    import PriorityFlag from './PriorityFlag.svelte';
    import StatusBadge from './StatusBadge.svelte';
    import StatusChip from './StatusChip.svelte';
    import TaskCode from './TaskCode.svelte';

    let {
        task,
        project,
        showProject = false,
        compact = false,
    }: {
        task: Task;
        project?: { slug: string } | null;
        showProject?: boolean;
        compact?: boolean;
    } = $props();

    // Chips PATCH /workspace/projects/{project:slug}/tasks/{task:slug}; prefer
    // an explicit `project` prop, then the nested resource. Without either the
    // row degrades to read-only badges.
    const projectSlug = $derived(project?.slug ?? task.project?.slug ?? null);

    const assignees = $derived(task.assignments ?? []);

    function openPeek() {
        peek.open({ id: task.id, slug: task.slug });
    }
</script>

<!-- One grid cell per column, in the order `.task-cols` declares. Cells stay
     in place whether or not they hold anything, so the register reads down as
     well as across; `TaskRegisterHead` renders the matching header. -->
<div
    role="button"
    tabindex="0"
    class="group task-cols task-row"
    class:h-16={!compact}
    class:h-[52px]={compact}
    onclick={openPeek}
    onkeydown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openPeek();
        }
    }}
>
    <span class="flex min-w-0">
        {#if task.item_number}
            <TaskCode {task} projectSlug={projectSlug ?? undefined} />
        {/if}
    </span>

    <div class="min-w-0">
        <div class="flex min-w-0 items-baseline gap-2.5">
            <span class="truncate text-sm font-medium text-fg">
                {task.short_title || task.title}
            </span>
            {#if task.title_np && !compact}
                <span class="font-np truncate text-xs text-fg-muted"
                    >{task.title_np}</span
                >
            {/if}
            {#if showProject && task.project}
                <span class="max-w-[28%] truncate text-[13px] text-fg-faint"
                    >{task.project.title}</span
                >
            {/if}
        </div>
        {#if !compact && task.description}
            <p class="mt-0.5 line-clamp-1 text-xs text-fg-muted">
                {task.description}
            </p>
        {/if}
    </div>

    <span class="hidden truncate text-[13px] text-fg-muted xl:block">
        {task.category_label ?? ''}
    </span>
    <span class="hidden truncate text-[13px] text-fg-muted 2xl:block">
        {task.responsible_ministry ?? ''}
    </span>

    <span class="flex min-w-0 justify-start">
        {#if projectSlug}
            <StatusChip {task} {projectSlug} size="sm" />
        {:else}
            <StatusBadge status={task.status} label={task.status_label} />
        {/if}
    </span>
    <span class="flex justify-center">
        {#if projectSlug}
            <PriorityFlag {task} {projectSlug} quiet />
        {/if}
    </span>
    <span class="flex justify-end">
        {#if projectSlug}
            <DateChip {task} {projectSlug} size="sm" ghost />
        {/if}
    </span>

    <span class="text-right font-mono text-[13px] text-fg-faint tabular-nums">
        {task.progress > 0 ? `${task.progress}%` : ''}
    </span>

    <div class="flex justify-end -space-x-0.5">
        {#each assignees.slice(0, 3) as a (a.id)}
            <Avatar name={a.member?.name} class="ring-2 ring-surface" />
        {/each}
        {#if assignees.length > 3}
            <span
                class="inline-grid h-5 w-5 place-items-center rounded-[5px] border border-line bg-surface-alt font-mono text-[9.5px] text-fg-faint ring-2 ring-surface"
            >
                +{assignees.length - 3}
            </span>
        {/if}
    </div>
</div>
