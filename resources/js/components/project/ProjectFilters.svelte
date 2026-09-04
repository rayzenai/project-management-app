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
    import type { Member } from '../../lib/types';
    import Avatar from '../Avatar.svelte';

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

<div class="ml-auto flex shrink-0 items-center gap-1.5">
    {#if teammates.length > 0}
        <div class="flex items-center gap-1">
            {#each teammates as user (user.id)}
                {@const active = filters.assigneeIds.includes(user.id)}
                <button
                    type="button"
                    aria-pressed={active}
                    title={user.name}
                    class={`rounded-md transition ${
                        active
                            ? 'ring-2 ring-accent'
                            : 'opacity-70 hover:opacity-100'
                    }`}
                    onclick={() => toggleAssignee(user.id)}
                >
                    <Avatar name={user.name} size="md" />
                </button>
            {/each}
        </div>
    {/if}

    <button
        type="button"
        aria-pressed={filters.overdueOnly}
        class={`btn ${
            filters.overdueOnly
                ? 'border-accent/40 bg-accent-soft text-accent hover:bg-accent-soft'
                : ''
        }`}
        onclick={() => (filters.overdueOnly = !filters.overdueOnly)}
    >
        Overdue only
    </button>

    {#if categories.length > 0}
        <select
            aria-label="Category"
            value={filters.category ?? ''}
            onchange={(e) =>
                (filters.category =
                    (e.currentTarget as HTMLSelectElement).value || null)}
            class="input h-7 w-auto py-0"
        >
            <option value="">All categories</option>
            {#each categories as category (category.value)}
                <option value={category.value}>{category.label}</option>
            {/each}
        </select>
    {/if}

    {#if anyActive}
        <span
            class={`font-mono text-xs tabular-nums ${shownCount === 0 ? 'text-danger' : 'text-fg-muted'}`}
        >
            {shownCount}/{totalCount}
        </span>
        <button type="button" class="btn-ghost" onclick={clear}>Clear</button>
    {/if}
</div>
