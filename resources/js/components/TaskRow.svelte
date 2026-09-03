<script lang="ts">
    import { initials } from '../lib/format';
    import { peek } from '../lib/peek.svelte';
    import type { Task } from '../lib/types';
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
    class="group block w-full cursor-pointer rounded-lg border border-line bg-surface p-3 text-left transition hover:border-accent hover:shadow-sm"
    class:p-2={compact}
    onclick={openPeek}
    onkeydown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openPeek();
        }
    }}
>
    <div class="flex items-start gap-3">
        {#if task.item_number}
            <span
                class="mt-0.5 rounded bg-surface-alt px-1.5 py-0.5 font-mono text-xs text-fg-muted select-none"
            >
                #{task.item_number}
            </span>
        {/if}
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-2">
                <h3
                    class="truncate text-sm font-medium text-fg group-hover:text-accent"
                >
                    {task.short_title || task.title}
                </h3>
                {#if showProject && task.project}
                    <span class="text-xs text-fg-muted"
                        >in {task.project.title}</span
                    >
                {/if}
            </div>

            {#if !compact && task.description}
                <p class="mt-1 line-clamp-2 text-sm text-fg-muted">
                    {task.description}
                </p>
            {/if}

            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                {#if projectSlug}
                    <StatusChip {task} {projectSlug} />
                    <PriorityFlag {task} {projectSlug} quiet />
                    <DateChip {task} {projectSlug} ghost />
                {:else}
                    <StatusBadge
                        status={task.status}
                        label={task.status_label}
                    />
                {/if}
                {#if task.progress > 0}
                    <span class="text-fg-muted">{task.progress}%</span>
                {/if}
                {#if task.category_label}
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                        style="background-color: {task.category_color}15; color: {task.category_color}; --tw-ring-color: {task.category_color}40;"
                        >{task.category_label}</span
                    >
                {/if}
                {#if task.responsible_ministry}
                    <span class="text-fg-muted"
                        >{task.responsible_ministry}</span
                    >
                {/if}
            </div>
        </div>

        {#if assignees.length > 0}
            <div class="flex -space-x-1.5">
                {#each assignees.slice(0, 3) as a (a.id)}
                    <span
                        class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-surface bg-surface-alt text-[10px] font-semibold text-fg-muted"
                        title={a.member?.name}>{initials(a.member?.name)}</span
                    >
                {/each}
                {#if assignees.length > 3}
                    <span
                        class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-surface bg-surface-alt text-[10px] font-semibold text-fg-faint"
                    >
                        +{assignees.length - 3}
                    </span>
                {/if}
            </div>
        {/if}
    </div>
</div>
