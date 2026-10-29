<script lang="ts">
    import { Hourglass, Link2 } from '@lucide/svelte';
    import { taskCode } from '../../lib/taskCode';
    import type { Task, TaskRef } from '../../lib/types';

    /**
     * What a task waits for and what waits for it, at a glance on board cards
     * and list rows. Red when the work it waits for is late.
     */
    let { task, projectCode }: { task: Task; projectCode?: string | null } =
        $props();

    function code(t: TaskRef): string {
        return taskCode(projectCode, t.number);
    }

    function list(tasks: TaskRef[]): string {
        const codes = tasks.slice(0, 2).map(code).join(', ');

        return tasks.length > 2 ? `${codes} +${tasks.length - 2}` : codes;
    }

    function titles(tasks: TaskRef[]): string {
        return tasks.map((t) => `${code(t)} ${t.title}`).join('\n');
    }

    const waitsFor = $derived(task.dependencies ?? []);
    const unfinished = $derived(waitsFor.filter((t) => !t.is_complete));
    const lateBefore = $derived(unfinished.some((t) => t.is_overdue));
    // Once this task is finished it holds nothing up any more.
    const holdsUp = $derived(
        task.completed_at
            ? []
            : (task.dependents ?? []).filter((t) => !t.is_complete),
    );
</script>

{#if waitsFor.length > 0 || holdsUp.length > 0}
    <span class="flex min-w-0 flex-wrap items-center gap-1">
        {#if unfinished.length > 0}
            <span
                class={`chip max-w-full ${lateBefore ? 'text-danger' : ''}`}
                title={`Waits for:\n${titles(unfinished)}`}
            >
                <Hourglass class="h-3 w-3 shrink-0" />
                <span class="truncate">Waits for {list(unfinished)}</span>
            </span>
        {:else if waitsFor.length > 0}
            <span
                class="chip max-w-full text-success"
                title={`Done first:\n${titles(waitsFor)}`}
            >
                <Hourglass class="h-3 w-3 shrink-0" />
                <span class="truncate">Ready, {list(waitsFor)} done</span>
            </span>
        {/if}
        {#if holdsUp.length > 0}
            <span
                class={`chip max-w-full ${task.is_overdue ? 'text-danger' : ''}`}
                title={`Holding up:\n${titles(holdsUp)}`}
            >
                <Link2 class="h-3 w-3 shrink-0" />
                <span class="truncate">Holds up {list(holdsUp)}</span>
            </span>
        {/if}
    </span>
{/if}
