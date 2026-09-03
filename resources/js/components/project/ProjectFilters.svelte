<script module lang="ts">
    export interface ProjectFiltersState {
        assigneeIds: number[];
        overdueOnly: boolean;
        category: string | null;
    }

    export interface CategoryOption {
        value: string;
        label: string;
        color: string | null;
    }
</script>

<script lang="ts">
    import { initials } from '../../lib/format';
    import type { Member } from '../../lib/types';

    let {
        filters = $bindable(),
        teammates,
        categories,
        shownCount,
        totalCount,
    }: {
        filters: ProjectFiltersState;
        teammates: Member[];
        categories: CategoryOption[];
        shownCount: number;
        totalCount: number;
    } = $props();

    const anyActive = $derived(
        filters.assigneeIds.length > 0 ||
            filters.overdueOnly ||
            filters.category !== null,
    );

    function toggleAssignee(id: number) {
        filters.assigneeIds = filters.assigneeIds.includes(id)
            ? filters.assigneeIds.filter((x) => x !== id)
            : [...filters.assigneeIds, id];
    }

    function clear() {
        filters = { assigneeIds: [], overdueOnly: false, category: null };
    }
</script>

<section
    class="mb-6 flex flex-wrap items-center gap-x-5 gap-y-3 rounded-xl border border-line bg-surface px-4 py-3"
>
    {#if teammates.length > 0}
        <div class="flex items-center gap-2">
            <span class="ws-eyebrow text-fg-muted">Assignees</span>
            <div class="flex items-center gap-1">
                {#each teammates as user (user.id)}
                    {@const active = filters.assigneeIds.includes(user.id)}
                    <button
                        type="button"
                        aria-pressed={active}
                        title={user.name}
                        class={`flex h-7 w-7 items-center justify-center rounded-full text-[10px] font-semibold transition ${
                            active
                                ? 'bg-accent/20 text-accent ring-2 ring-accent'
                                : 'bg-surface-alt text-fg-muted ring-1 ring-transparent hover:ring-accent/50'
                        }`}
                        onclick={() => toggleAssignee(user.id)}
                    >
                        {initials(user.name)}
                    </button>
                {/each}
            </div>
        </div>
    {/if}

    <button
        type="button"
        aria-pressed={filters.overdueOnly}
        class={`rounded-md border px-2.5 py-1 text-xs font-medium transition ${
            filters.overdueOnly
                ? 'border-accent bg-accent/10 text-accent'
                : 'border-line text-fg-muted hover:border-accent hover:text-accent'
        }`}
        onclick={() => (filters.overdueOnly = !filters.overdueOnly)}
    >
        ⚠ Overdue only
    </button>

    {#if categories.length > 0}
        <label class="flex items-center gap-2">
            <span class="ws-eyebrow text-fg-muted">Category</span>
            <select
                value={filters.category ?? ''}
                onchange={(e) =>
                    (filters.category =
                        (e.currentTarget as HTMLSelectElement).value || null)}
                class="rounded-md border border-line bg-surface px-2 py-1 text-xs text-fg-muted"
            >
                <option value="">All</option>
                {#each categories as category (category.value)}
                    <option value={category.value}>{category.label}</option>
                {/each}
            </select>
        </label>
    {/if}

    {#if anyActive}
        <div class="ml-auto flex items-center gap-2 font-mono text-xs">
            <span class={shownCount === 0 ? 'text-danger' : 'text-fg-muted'}>
                {shownCount} of {totalCount} shown
            </span>
            <span class="text-fg-faint">·</span>
            <button
                type="button"
                class="text-fg-muted hover:text-fg"
                onclick={clear}
            >
                Clear ✕
            </button>
        </div>
    {/if}
</section>
