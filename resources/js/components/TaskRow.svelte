<script lang="ts">
    import { peek } from '../lib/peek.svelte';
    import type { Task } from '../lib/types';
    import Avatar from './Avatar.svelte';
    import DateChip from './DateChip.svelte';
    import PriorityFlag from './PriorityFlag.svelte';
    import StatusBadge from './StatusBadge.svelte';
    import StatusChip from './StatusChip.svelte';

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

<div
    role="button"
    tabindex="0"
    class="group row cursor-pointer text-left"
    class:min-h-11={!compact}
    class:min-h-9={compact}
    class:py-2={!compact}
    class:py-1={compact}
    onclick={openPeek}
    onkeydown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openPeek();
        }
    }}
>
    {#if task.item_number}
        <span class="w-9 shrink-0 font-mono text-xs text-fg-faint tabular-nums">
            {task.item_number}
        </span>
    {/if}

    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-baseline gap-2.5">
            <span class="truncate text-[13px] font-medium text-fg">
                {task.short_title || task.title}
            </span>
            {#if task.title_np && !compact}
                <span class="font-np truncate text-xs text-fg-muted"
                    >{task.title_np}</span
                >
            {/if}
            {#if showProject && task.project}
                <span class="shrink-0 text-xs text-fg-faint"
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

    <!-- Every trailing cell keeps its width whether or not it has content, so
         the columns line up down the register instead of sliding around. -->
    <span class="hidden w-36 shrink-0 truncate text-xs text-fg-muted lg:block">
        {task.category_label ?? ''}
    </span>
    <span class="hidden w-40 shrink-0 truncate text-xs text-fg-muted xl:block">
        {task.responsible_ministry ?? ''}
    </span>

    <div class="flex shrink-0 items-center gap-2">
        {#if projectSlug}
            <span class="flex w-[92px] shrink-0 justify-start">
                <StatusChip {task} {projectSlug} size="sm" />
            </span>
            <span class="flex w-3.5 shrink-0 justify-center">
                <PriorityFlag {task} {projectSlug} quiet />
            </span>
            <span class="flex w-[86px] shrink-0 justify-end">
                <DateChip {task} {projectSlug} size="sm" ghost />
            </span>
        {:else}
            <span class="flex w-[92px] shrink-0 justify-start">
                <StatusBadge status={task.status} label={task.status_label} />
            </span>
        {/if}
        <span
            class="w-8 shrink-0 text-right font-mono text-xs text-fg-faint tabular-nums"
        >
            {task.progress > 0 ? `${task.progress}%` : ''}
        </span>
    </div>

    <div class="flex w-[70px] shrink-0 justify-end -space-x-0.5">
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
