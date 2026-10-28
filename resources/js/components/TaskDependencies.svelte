<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import { Plus, X } from '@lucide/svelte';
    import type { Task, TaskRef } from '../lib/types';
    import StatusGlyph from './StatusGlyph.svelte';

    /**
     * The task page's dependency editor: what this task cannot start before
     * (finish-to-start, same project), and, read-only, what waits for it. The
     * team Gantt draws these as arrows.
     */
    let {
        task,
        projectSlug,
        projectCode,
        candidates,
        onchanged,
    }: {
        task: Task;
        projectSlug: string;
        projectCode: string;
        /** The rest of the project's tasks. */
        candidates: TaskRef[];
        /** After a link is added or removed, e.g. to refresh a slide-over. */
        onchanged?: () => void;
    } = $props();

    const uid = $props.id();
    let adding = $state(false);
    let query = $state('');
    let busy = $state(false);

    const dependencies = $derived(task.dependencies ?? []);
    const dependents = $derived(task.dependents ?? []);
    const error = $derived(
        ((page.props as any).errors?.depends_on_task_id as
            string | undefined) ?? null,
    );

    function code(t: TaskRef): string {
        return t.item_number !== null
            ? `${projectCode}-${t.item_number}`
            : projectCode;
    }

    function note(t: TaskRef): { text: string; tone: string } {
        if (t.is_complete) {
            return { text: 'Done', tone: 'text-success' };
        }

        if (t.is_late && t.status !== 'failed') {
            return { text: 'Late', tone: 'text-danger' };
        }

        return { text: t.status_label, tone: 'text-fg-muted' };
    }

    const options = $derived.by(() => {
        const q = query.trim().toLowerCase();

        return candidates
            .filter((c) => !dependencies.some((d) => d.id === c.id))
            .filter(
                (c) =>
                    q === '' ||
                    c.title.toLowerCase().includes(q) ||
                    code(c).toLowerCase().includes(q),
            )
            .slice(0, 8);
    });

    function add(candidate: TaskRef) {
        busy = true;
        router.post(
            `/workspace/tasks/${task.id}/dependencies`,
            { depends_on_task_id: candidate.id },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    query = '';
                    adding = false;
                    onchanged?.();
                },
                onFinish: () => (busy = false),
            },
        );
    }

    function remove(dependency: TaskRef) {
        router.delete(
            `/workspace/tasks/${task.id}/dependencies/${dependency.id}`,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onchanged?.(),
            },
        );
    }
</script>

{#snippet item(other: TaskRef, removable: boolean)}
    {@const status = note(other)}
    <li class="group flex min-h-[30px] items-center gap-2 text-[12.5px]">
        <StatusGlyph status={other.status} />
        <span class="shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
            >{code(other)}</span
        >
        <a
            href={`/workspace/projects/${projectSlug}/tasks/${other.slug}`}
            use:inertia
            class="min-w-0 flex-1 truncate text-fg hover:text-accent"
            >{other.title}</a
        >
        <span class={`shrink-0 text-xs ${status.tone}`}>{status.text}</span>
        {#if removable}
            <button
                type="button"
                class="btn-icon h-6 w-6 shrink-0"
                aria-label={`Stop waiting for ${other.title}`}
                title="Remove this dependency"
                onclick={() => remove(other)}><X class="h-3.5 w-3.5" /></button
            >
        {/if}
    </li>
{/snippet}

<h3 class="section-title mt-5 mb-1">Depends on</h3>
<p class="mb-1 text-xs text-fg-muted">It can start once these are done.</p>
{#if dependencies.length > 0}
    <ul>
        {#each dependencies as dependency (dependency.id)}
            {@render item(dependency, true)}
        {/each}
    </ul>
{/if}

{#if adding}
    <div class="mt-1.5">
        <label for="{uid}-search" class="sr-only">Find a task</label>
        <input
            id="{uid}-search"
            type="search"
            bind:value={query}
            placeholder="Find a task in this project"
            autocomplete="off"
            class="input"
            onkeydown={(e) => {
                if (e.key === 'Escape') {
                    adding = false;
                }
            }}
        />
        <ul class="mt-1">
            {#each options as option (option.id)}
                <li>
                    <button
                        type="button"
                        disabled={busy}
                        class="flex w-full min-w-0 items-center gap-2 rounded-md px-1.5 py-1 text-left text-[12.5px] hover:bg-hover"
                        onclick={() => add(option)}
                    >
                        <StatusGlyph status={option.status} />
                        <span
                            class="shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
                            >{code(option)}</span
                        >
                        <span class="min-w-0 flex-1 truncate text-fg"
                            >{option.title}</span
                        >
                    </button>
                </li>
            {:else}
                <li class="px-1.5 py-1 text-xs text-fg-muted">
                    No other task matches.
                </li>
            {/each}
        </ul>
        <button
            type="button"
            class="btn-ghost mt-1"
            onclick={() => (adding = false)}>Done</button
        >
    </div>
{:else}
    <button type="button" class="btn-ghost mt-1" onclick={() => (adding = true)}
        ><Plus class="h-3.5 w-3.5" />Add a task it waits for</button
    >
{/if}
{#if error}
    <p class="mt-1 text-xs text-danger">{error}</p>
{/if}

{#if dependents.length > 0}
    <h3 class="section-title mt-5 mb-1">Holding up</h3>
    <p class="mb-1 text-xs text-fg-muted">These wait for this task.</p>
    <ul>
        {#each dependents as dependent (dependent.id)}
            {@render item(dependent, false)}
        {/each}
    </ul>
{/if}
