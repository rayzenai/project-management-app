<script lang="ts">
    import { initials } from '../lib/format';
    import type { Member } from '../lib/types';

    let {
        team,
        selectedIds = $bindable([]),
        placeholder = 'Assign...',
        max = 1,
        flow = false,
    }: {
        team: Member[];
        selectedIds: number[];
        placeholder?: string;
        max?: number;
        /** Render the dropdown in-flow (expands the container) instead of as a clipped absolute popover. Use inside modals. */
        flow?: boolean;
    } = $props();

    let open = $state(false);
    let query = $state('');

    const filtered = $derived(
        query.trim() === ''
            ? team
            : team.filter(
                  (u) =>
                      u.name.toLowerCase().includes(query.toLowerCase()) ||
                      (u.email ?? '')
                          .toLowerCase()
                          .includes(query.toLowerCase()),
              ),
    );
    const selected = $derived(team.filter((u) => selectedIds.includes(u.id)));

    function toggle(memberId: number) {
        if (selectedIds.includes(memberId)) {
            selectedIds = selectedIds.filter((id) => id !== memberId);
        } else if (max === 1) {
            selectedIds = [memberId];
            open = false;
        } else if (selectedIds.length < max) {
            selectedIds = [...selectedIds, memberId];
        }
    }

    function remove(memberId: number) {
        selectedIds = selectedIds.filter((id) => id !== memberId);
    }
</script>

<div class="relative">
    <div
        role="button"
        tabindex="0"
        aria-haspopup="listbox"
        aria-expanded={open}
        class="flex min-h-[32px] w-full flex-wrap items-center gap-1.5 rounded-md border border-line bg-surface px-2 py-1 text-left text-sm shadow-sm hover:border-line focus:border-accent focus:ring-2 focus:ring-accent/30 focus:outline-none"
        onclick={() => (open = !open)}
        onkeydown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open = !open;
            }
        }}
    >
        {#if selected.length === 0}
            <span class="text-fg-faint">{placeholder}</span>
        {/if}
        {#each selected as user (user.id)}
            <span
                class="inline-flex items-center gap-1.5 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent"
            >
                <span
                    class="flex h-4 w-4 items-center justify-center rounded-full bg-accent/20 text-[10px] font-semibold text-accent"
                >
                    {initials(user.name)}
                </span>
                {user.name}
                <button
                    type="button"
                    aria-label="Remove"
                    class="text-accent hover:text-accent-dim"
                    onclick={(e) => {
                        e.stopPropagation();
                        remove(user.id);
                    }}>×</button
                >
            </span>
        {/each}
    </div>

    {#if open}
        <div
            class={flow
                ? 'mt-1 max-h-72 overflow-auto rounded-md border border-line bg-surface shadow-sm'
                : 'absolute right-0 left-0 z-30 mt-1 max-h-72 overflow-auto rounded-md border border-line bg-surface shadow-lg'}
        >
            <input
                type="text"
                bind:value={query}
                placeholder="Search people..."
                class="w-full border-b border-line bg-transparent px-3 py-2 text-sm focus:outline-none"
            />
            <ul class="max-h-56 overflow-auto py-1">
                {#each filtered as user (user.id)}
                    {@const sel = selectedIds.includes(user.id)}
                    <li>
                        <button
                            type="button"
                            class={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-alt ${
                                sel ? 'bg-accent/10' : ''
                            }`}
                            onclick={() => toggle(user.id)}
                        >
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full bg-surface-alt text-xs font-semibold text-fg-muted"
                            >
                                {initials(user.name)}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">
                                    {user.name}
                                </div>
                                <div class="truncate text-xs text-fg-muted">
                                    {user.email}
                                </div>
                            </div>
                            {#if selectedIds.includes(user.id)}
                                <span class="text-accent">✓</span>
                            {/if}
                        </button>
                    </li>
                {:else}
                    <li class="px-3 py-3 text-sm text-fg-muted">No matches.</li>
                {/each}
            </ul>
        </div>
    {/if}
</div>
