<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Check, X } from '@lucide/svelte';
    import { formatDate } from '../lib/format';
    import { peek } from '../lib/peek.svelte';
    import type { Subtask } from '../lib/types';

    let { todos }: { todos: Subtask[] } = $props();

    let hidden = $state(false);

    function openTask(t: Subtask) {
        if (t.task) {
            peek.open({ id: t.task.id, slug: t.task.slug });
        }
    }

    type Group = { key: string; label: string; items: Subtask[] };

    const groups = $derived.by<Group[]>(() => {
        const now = new Date();
        const today = new Date(
            now.getFullYear(),
            now.getMonth(),
            now.getDate(),
        );
        const weekEnd = new Date(today.getTime() + 7 * 86_400_000);

        const overdue: Subtask[] = [];
        const thisWeek: Subtask[] = [];
        const later: Subtask[] = [];
        const noDate: Subtask[] = [];

        for (const t of todos) {
            if (!t.due_at) {
                noDate.push(t);
                continue;
            }

            const d = new Date(t.due_at);

            if (d < today) {
                overdue.push(t);
            } else if (d <= weekEnd) {
                thisWeek.push(t);
            } else {
                later.push(t);
            }
        }

        const out: Group[] = [];

        if (overdue.length) {
            out.push({ key: 'overdue', label: 'Overdue', items: overdue });
        }

        if (thisWeek.length) {
            out.push({ key: 'this-week', label: 'This week', items: thisWeek });
        }

        if (later.length) {
            out.push({ key: 'later', label: 'Later', items: later });
        }

        if (noDate.length) {
            out.push({ key: 'no-date', label: 'No date', items: noDate });
        }

        return out;
    });

    function toggle(t: Subtask) {
        router.patch(
            `/workspace/subtasks/${t.id}`,
            { is_done: !t.is_done },
            { preserveScroll: true, preserveState: true },
        );
    }

    function remove(t: Subtask) {
        router.delete(`/workspace/subtasks/${t.id}`, {
            preserveScroll: true,
            preserveState: true,
        });
    }
</script>

<section class="border-t border-line pt-4">
    <header class="mb-2 flex items-center justify-between">
        <h2 class="section-title">
            My open todos
            <span class="section-count">{todos.length}</span>
        </h2>
        <button
            type="button"
            class="btn-ghost h-6 px-1.5 text-xs"
            onclick={() => (hidden = !hidden)}
            >{hidden ? 'Show' : 'Hide'}</button
        >
    </header>

    {#if !hidden}
        {#if todos.length === 0}
            <p class="py-2 text-[13px] text-fg-muted">
                No open todos. Add some from any task.
            </p>
        {:else}
            <div class="space-y-4">
                {#each groups as group (group.key)}
                    <div>
                        <h3
                            class={`mb-0.5 flex items-baseline gap-2 text-xs font-medium ${
                                group.key === 'overdue'
                                    ? 'text-danger'
                                    : 'text-fg-muted'
                            }`}
                        >
                            {group.label}
                            <span class="section-count"
                                >{group.items.length}</span
                            >
                        </h3>
                        <ul
                            class="grid grid-cols-1 gap-x-6 sm:grid-cols-2 xl:grid-cols-3"
                        >
                            {#each group.items as t (t.id)}
                                <li class="row group h-8 gap-2.5 px-1">
                                    <button
                                        type="button"
                                        class={`inline-grid h-3.5 w-3.5 shrink-0 place-items-center rounded-sm border-[1.5px] transition ${
                                            t.is_done
                                                ? 'border-accent bg-accent'
                                                : 'border-line hover:border-accent'
                                        }`}
                                        aria-pressed={t.is_done}
                                        onclick={(e) => {
                                            e.stopPropagation();
                                            toggle(t);
                                        }}
                                        title="Mark done"
                                    >
                                        {#if t.is_done}
                                            <Check
                                                class="h-2.5 w-2.5 text-white"
                                            />
                                        {/if}
                                    </button>
                                    <button
                                        type="button"
                                        class="flex min-w-0 flex-1 items-baseline gap-2 text-left"
                                        onclick={() => openTask(t)}
                                        title="Open task"
                                    >
                                        <span
                                            class={`truncate text-[13px] ${
                                                t.is_done
                                                    ? 'text-fg-faint line-through'
                                                    : 'text-fg'
                                            }`}
                                        >
                                            {t.body}
                                        </span>
                                        {#if t.task}
                                            <span
                                                class="truncate text-xs text-fg-muted"
                                            >
                                                {t.task.short_title ||
                                                    t.task.title}
                                            </span>
                                        {/if}
                                        {#if t.due_at}
                                            <span
                                                class="ml-auto shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
                                                >{formatDate(t.due_at)}</span
                                            >
                                        {/if}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-icon h-6 w-6 opacity-0 group-hover:opacity-100 hover:text-danger focus-visible:opacity-100"
                                        onclick={(e) => {
                                            e.stopPropagation();
                                            remove(t);
                                        }}
                                        title="Remove"
                                        aria-label="Remove"
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </li>
                            {/each}
                        </ul>
                    </div>
                {/each}
            </div>
        {/if}
    {/if}
</section>
